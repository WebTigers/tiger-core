<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Google_Analytics — a tiny, dependency-free client for the Google Analytics 4 **reporting**
 * side (pulling stats back for the in-app dashboard). Pure HTTPS (curl) — no Google SDK, no JWT.
 *
 * Auth has **two modes**, chosen by `tiger.analytics.oauth.mode`:
 *
 *  - **broker** (default) — one-click "Connect with Google" via the WebTigers-hosted OAuth broker
 *    (`connect.webtigers.com`, see the TigerConnect Lambda). The install never registers a Google
 *    Cloud project: it bounces the admin to the broker, which runs the consent flow with WebTigers'
 *    own OAuth client and hands the **refresh token** back over a single-use, PKCE-bound handoff.
 *    The install stores that refresh token (encrypted) and mints access tokens through the broker's
 *    `/google/token` endpoint. GA report data is fetched **directly** from Google — never via the
 *    broker.
 *  - **byo** — bring-your-own OAuth client: the operator registers their own Google Cloud OAuth
 *    credentials (self-hosted, no phone-home). We store `client_id` + an encrypted `client_secret`
 *    and refresh tokens ourselves against Google's token endpoint.
 *
 * Either way the refresh token is long-lived, stored encrypted (Tiger_Crypto, like Tiger_Recaptcha),
 * and exchanged for short-lived access tokens on demand to call the GA4 Data API (`runReport`).
 * Report results are file-cached (GA has quotas).
 *
 * Config (tiger.analytics.*): oauth.mode, connect.base_url (broker), oauth.client_id,
 * oauth.client_secret_enc (byo), property_id, oauth.refresh_token_enc.
 *
 * @api
 */
class Tiger_Google_Analytics
{
    const SCOPE     = 'https://www.googleapis.com/auth/analytics.readonly';
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const DATA_URL  = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';
    const CACHE_TTL = 1800;   // 30 min — GA data isn't real-time and the API is quota-limited

    const MODE_BROKER = 'broker';   // one-click via the WebTigers broker (default)
    const MODE_BYO    = 'byo';      // bring-your-own Google OAuth client
    const DEFAULT_BROKER_BASE = 'https://connect.webtigers.com';

    /** @var string|null memoized access token for this request */
    private static $_access = null;

    // =====================================================================================
    //  Connection state + config
    // =====================================================================================

    /** The connect mode: MODE_BROKER (default) or MODE_BYO. */
    public static function mode()
    {
        return self::_config('analytics.oauth.mode', self::MODE_BROKER) === self::MODE_BYO
            ? self::MODE_BYO : self::MODE_BROKER;
    }

    /** The WebTigers connect broker base URL (broker mode), trailing slash stripped. */
    public static function brokerBase()
    {
        $base = trim((string) self::_config('analytics.connect.base_url', self::DEFAULT_BROKER_BASE));
        return rtrim($base !== '' ? $base : self::DEFAULT_BROKER_BASE, '/');
    }

    /** Is the reporting side fully wired (a property + a stored refresh token, + client creds in BYO)? */
    public static function isConnected()
    {
        if (self::propertyId() === '' || self::_refreshToken() === '') {
            return false;
        }
        if (self::mode() === self::MODE_BROKER) {
            return true;                          // the broker holds the OAuth client
        }
        return self::clientId() !== '' && self::_clientSecret() !== '';
    }

    /** True once the connect flow can produce a usable connection (property set; + client creds in BYO). */
    public static function isConfigurable()
    {
        if (self::propertyId() === '') {
            return false;                         // need the property id to report, either mode
        }
        if (self::mode() === self::MODE_BROKER) {
            return true;                          // the broker supplies the OAuth client
        }
        return self::clientId() !== '' && self::_clientSecret() !== '';
    }

    /** The OAuth client id (public) — BYO mode only. */
    public static function clientId()
    {
        return trim((string) self::_config('analytics.oauth.client_id', ''));
    }

    /** The numeric GA4 property id (e.g. 123456789), digits only. */
    public static function propertyId()
    {
        return preg_replace('/\D+/', '', (string) self::_config('analytics.property_id', ''));
    }

    /**
     * Persist the connect mode (broker | byo).
     *
     * @param  string $mode
     * @return void
     */
    public static function saveMode($mode)
    {
        $mode = ($mode === self::MODE_BYO) ? self::MODE_BYO : self::MODE_BROKER;
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.analytics.oauth.mode', $mode);
    }

    /**
     * Persist the BYO OAuth client creds + property id (secret encrypted; blank secret keeps the
     * current). Property id is saved in both modes; the client creds are only used in BYO mode.
     *
     * @param  string $clientId
     * @param  string $clientSecret blank = keep existing
     * @param  string $propertyId
     * @return void
     */
    public static function saveOauthConfig($clientId, $clientSecret, $propertyId)
    {
        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $cfg->set($g, '', 'tiger.analytics.oauth.client_id', trim((string) $clientId));
        $cfg->set($g, '', 'tiger.analytics.property_id',     preg_replace('/\D+/', '', (string) $propertyId));
        $secret = trim((string) $clientSecret);
        if ($secret !== '' && class_exists('Tiger_Crypto') && Tiger_Crypto::isConfigured()) {
            $cfg->set($g, '', 'tiger.analytics.oauth.client_secret_enc', (string) Tiger_Crypto::encrypt($secret));
        }
    }

    /** Forget the OAuth connection (drop the refresh token). Client creds + property id stay. */
    public static function disconnect()
    {
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.analytics.oauth.refresh_token_enc', '');
        self::$_access = null;
        @unlink(self::_cacheFile());
    }

    // =====================================================================================
    //  OAuth flow — broker mode
    // =====================================================================================

    /** PKCE S256 challenge for a verifier: base64url(sha256(verifier)), unpadded. */
    public static function pkceChallenge($verifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * The broker consent URL to send the admin to (broker mode). The broker runs the Google consent
     * flow with WebTigers' OAuth client and redirects back to $callbackUrl with a one-time ?handoff.
     *
     * @param  string $callbackUrl where the broker redirects back (the install's callback action)
     * @param  string $challenge   the PKCE S256 challenge (pkceChallenge() of a secret verifier)
     * @return string
     */
    public static function brokerAuthUrl($callbackUrl, $challenge)
    {
        return self::brokerBase() . '/google/start?' . http_build_query([
            'callback'  => (string) $callbackUrl,
            'challenge' => (string) $challenge,
        ]);
    }

    /**
     * Redeem a broker handoff (broker mode): POST it + the PKCE verifier to the broker's /google/exchange
     * server-to-server, and store the returned refresh token (encrypted).
     *
     * @param  string $handoff  the one-time code from the broker's redirect
     * @param  string $verifier the PKCE verifier whose challenge started the flow
     * @return array{ok:bool,error:?string}
     */
    public static function exchangeHandoff($handoff, $verifier)
    {
        $res = self::_http(self::brokerBase() . '/google/exchange', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query(['handoff' => (string) $handoff, 'verifier' => (string) $verifier]),
        ]);
        if ($res === null) {
            return ['ok' => false, 'error' => 'Could not complete the connection — the request may have expired. Please try again.'];
        }
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['refresh_token'])) {
            return ['ok' => false, 'error' => 'The connect service did not return a token. Please try again.'];
        }
        if (class_exists('Tiger_Crypto') && Tiger_Crypto::isConfigured()) {
            (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '',
                'tiger.analytics.oauth.refresh_token_enc', (string) Tiger_Crypto::encrypt((string) $data['refresh_token']));
        }
        self::$_access = null;
        @unlink(self::_cacheFile());
        return ['ok' => true, 'error' => null];
    }

    // =====================================================================================
    //  OAuth flow — BYO mode
    // =====================================================================================

    /**
     * The Google consent URL to send the admin to (BYO mode: offline access, forced consent so a
     * refresh token is always returned).
     *
     * @param  string $redirectUri must EXACTLY match the URI registered in the Google OAuth client
     * @param  string $state       an anti-CSRF token (verified in the callback)
     * @return string
     */
    public static function authUrl($redirectUri, $state)
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => self::clientId(),
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => (string) $state,
        ]);
    }

    /**
     * Exchange the authorization code for tokens and store the refresh token (encrypted) — BYO mode.
     *
     * @param  string $code        the ?code from Google's redirect
     * @param  string $redirectUri the same URI used in authUrl()
     * @return array{ok:bool,error:?string}
     */
    public static function exchangeCode($code, $redirectUri)
    {
        $res = self::_tokenRequest([
            'code'          => (string) $code,
            'client_id'     => self::clientId(),
            'client_secret' => self::_clientSecret(),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
        if (!is_array($res)) {
            return ['ok' => false, 'error' => 'Could not reach Google to exchange the code.'];
        }
        if (empty($res['refresh_token'])) {
            // Google only returns a refresh token on the first consent; prompt=consent should force it.
            return ['ok' => false, 'error' => $res['error_description'] ?? ($res['error'] ?? 'No refresh token returned — revoke the app in your Google account and try again.')];
        }
        if (class_exists('Tiger_Crypto') && Tiger_Crypto::isConfigured()) {
            (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '',
                'tiger.analytics.oauth.refresh_token_enc', (string) Tiger_Crypto::encrypt((string) $res['refresh_token']));
        }
        self::$_access = $res['access_token'] ?? null;
        @unlink(self::_cacheFile());
        return ['ok' => true, 'error' => null];
    }

    /** A fresh access token (from the stored refresh token, via the broker or Google), or '' if unavailable. */
    public static function accessToken()
    {
        if (self::$_access !== null) {
            return (string) self::$_access;
        }
        $refresh = self::_refreshToken();
        if ($refresh === '') {
            return '';
        }
        if (self::mode() === self::MODE_BROKER) {
            $res = self::_http(self::brokerBase() . '/google/token', [
                'method'  => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body'    => http_build_query(['refresh_token' => $refresh]),
            ]);
            $decoded = ($res !== null) ? json_decode($res, true) : null;
            self::$_access = (is_array($decoded) && !empty($decoded['access_token'])) ? $decoded['access_token'] : '';
            return (string) self::$_access;
        }
        $res = self::_tokenRequest([
            'refresh_token' => $refresh,
            'client_id'     => self::clientId(),
            'client_secret' => self::_clientSecret(),
            'grant_type'    => 'refresh_token',
        ]);
        self::$_access = (is_array($res) && !empty($res['access_token'])) ? $res['access_token'] : '';
        return (string) self::$_access;
    }

    // =====================================================================================
    //  Reporting (GA4 Data API)
    // =====================================================================================

    /**
     * A normalized summary for the dashboard: top-line totals, a daily time series, and top pages +
     * sources — for the given window. File-cached for CACHE_TTL. Returns null when not connected or
     * on an API error (the caller shows an empty/disconnected state).
     *
     * @param  int  $days  trailing days (default 28)
     * @param  bool $fresh bypass the cache
     * @return array|null  ['range'=>..,'totals'=>..,'series'=>[..],'top_pages'=>[..],'top_sources'=>[..]]
     */
    public static function summary($days = 28, $fresh = false)
    {
        $days = max(1, min(365, (int) $days));
        if (!self::isConnected()) {
            return null;
        }
        $cacheFile = self::_cacheFile($days);
        if (!$fresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) { return $cached; }
        }

        $token = self::accessToken();
        if ($token === '') {
            return null;
        }
        $range = ['startDate' => ($days - 1) . 'daysAgo', 'endDate' => 'today'];

        $totalsRes = self::runReport($token, ['dateRanges' => [$range],
            'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']]]);
        $seriesRes = self::runReport($token, ['dateRanges' => [$range],
            'dimensions' => [['name' => 'date']], 'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
            'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']]]);
        $pagesRes = self::runReport($token, ['dateRanges' => [$range],
            'dimensions' => [['name' => 'pagePath']], 'metrics' => [['name' => 'screenPageViews']],
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]], 'limit' => 8]);
        $srcRes = self::runReport($token, ['dateRanges' => [$range],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']], 'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 6]);

        if ($totalsRes === null) {
            return null;   // hard API failure — don't cache
        }

        $out = [
            'range'       => ['days' => $days, 'start' => $range['startDate'], 'end' => 'today'],
            'totals'      => self::_rowMetrics($totalsRes),
            'series'      => self::_series($seriesRes),
            'top_pages'   => self::_dimRows($pagesRes),
            'top_sources' => self::_dimRows($srcRes),
            'fetched_at'  => date('c'),
        ];
        @file_put_contents($cacheFile, json_encode($out), LOCK_EX);
        return $out;
    }

    /** Low-level runReport call. Returns the decoded response, or null on transport/HTTP error. */
    public static function runReport($token, array $body)
    {
        $url = sprintf(self::DATA_URL, self::propertyId());
        $res = self::_http($url, [
            'method'  => 'POST',
            'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            'body'    => json_encode($body),
        ]);
        if ($res === null) { return null; }
        $decoded = json_decode($res, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Run a live connection self-test: mint a token and make one small GA4 report call, translating the
     * outcome into a plain diagnosis for the Troubleshooting UI — ok / ok_empty / not_connected /
     * no_token / api_disabled / permission / reauth / quota / other.
     *
     * @return array{ok:bool,code:string,message:string,hint?:string,detail?:string}
     */
    public static function testConnection()
    {
        if (!self::isConnected()) {
            return ['ok' => false, 'code' => 'not_connected',
                'message' => 'Not connected yet.',
                'hint'    => 'Enter your GA4 Property ID above and click "Connect Google Analytics".'];
        }
        $token = self::accessToken();
        if ($token === '') {
            return ['ok' => false, 'code' => 'no_token',
                'message' => 'Connected, but Google would not issue an access token.',
                'hint'    => 'Click Disconnect, then Connect again to refresh the authorization.'];
        }

        [$code, $body] = self::_probeReport($token);
        $decoded = json_decode((string) $body, true);
        $gErr    = (is_array($decoded) && isset($decoded['error']['message'])) ? (string) $decoded['error']['message'] : '';

        if ($code === 200) {
            $hasRows = is_array($decoded) && !empty($decoded['rows']);
            return ['ok' => true, 'code' => $hasRows ? 'ok' : 'ok_empty',
                'message' => $hasRows
                    ? 'Success — Google Analytics is connected and returning data.'
                    : 'Success — connected. No traffic has been recorded for this property yet (normal for a new property; numbers appear within about a day of your first visitors).'];
        }
        if ($code === 403 && (stripos($gErr, 'has not been used') !== false || stripos($gErr, 'is disabled') !== false || stripos($gErr, 'SERVICE_DISABLED') !== false)) {
            return ['ok' => false, 'code' => 'api_disabled',
                'message' => 'The Google Analytics Data API is not enabled for the Google project.',
                'hint'    => 'Google Cloud Console → APIs & Services → Library → search "Google Analytics Data API" → Enable, then test again.',
                'detail'  => $gErr];
        }
        if ($code === 403) {
            return ['ok' => false, 'code' => 'permission',
                'message' => 'Google denied access to property ' . self::propertyId() . '.',
                'hint'    => 'Two usual causes: (1) that number is the Stream ID, not the Property ID — use Admin → Property Settings → Property ID. (2) The connected Google account needs Viewer access under Admin → Property Access Management.',
                'detail'  => $gErr];
        }
        if ($code === 401) {
            return ['ok' => false, 'code' => 'reauth',
                'message' => 'Google rejected the authorization.',
                'hint'    => 'Click Disconnect, then Connect again to re-authorize.',
                'detail'  => $gErr];
        }
        if ($code === 429) {
            return ['ok' => false, 'code' => 'quota',
                'message' => 'Google Analytics is rate-limiting requests right now.',
                'hint'    => 'Wait a few minutes and try again.',
                'detail'  => $gErr];
        }
        return ['ok' => false, 'code' => 'http_' . $code,
            'message' => 'Google returned HTTP ' . $code . '.',
            'hint'    => ($code === 400 || $code === 404)
                ? 'Double-check the Property ID, and make sure the Google Analytics Data API is enabled for the project.'
                : 'Please try again shortly.',
            'detail'  => $gErr];
    }

    // =====================================================================================
    //  Response shaping
    // =====================================================================================

    /** First row's metric values as floats, keyed 0..n (activeUsers, sessions, pageViews). */
    private static function _rowMetrics(array $res)
    {
        $vals = $res['rows'][0]['metricValues'] ?? [];
        return array_map(static function ($m) { return (float) ($m['value'] ?? 0); }, $vals);
    }

    /** Daily series: [ ['date'=>'YYYY-MM-DD','users'=>n,'views'=>n], … ]. */
    private static function _series(?array $res)
    {
        $out = [];
        foreach (($res['rows'] ?? []) as $r) {
            $d = (string) ($r['dimensionValues'][0]['value'] ?? '');
            $m = $r['metricValues'] ?? [];
            $out[] = [
                'date'  => strlen($d) === 8 ? substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : $d,
                'users' => (int) ($m[0]['value'] ?? 0),
                'views' => (int) ($m[1]['value'] ?? 0),
            ];
        }
        return $out;
    }

    /** Dimension rows: [ ['label'=>..,'value'=>n], … ]. */
    private static function _dimRows(?array $res)
    {
        $out = [];
        foreach (($res['rows'] ?? []) as $r) {
            $out[] = [
                'label' => (string) ($r['dimensionValues'][0]['value'] ?? ''),
                'value' => (int) ($r['metricValues'][0]['value'] ?? 0),
            ];
        }
        return $out;
    }

    // =====================================================================================
    //  HTTP + config helpers
    // =====================================================================================

    /** POST form params to Google's token endpoint; returns the decoded JSON or null. */
    private static function _tokenRequest(array $params)
    {
        $res = self::_http(self::TOKEN_URL, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query($params),
        ]);
        if ($res === null) { return null; }
        $decoded = json_decode($res, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Minimal HTTPS client (curl). Returns the body on 2xx, else null. */
    private static function _http($url, array $opts)
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $opts['method'] ?? 'GET',
            CURLOPT_POSTFIELDS     => $opts['body'] ?? '',
            CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }

    /** One minimal GA4 runReport for the connection test — returns [httpCode, body] (body KEPT on error). */
    private static function _probeReport($token)
    {
        $ch = curl_init(sprintf(self::DATA_URL, self::propertyId()));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'activeUsers']]]),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body === false ? '' : (string) $body];
    }

    /** The decrypted OAuth client secret, or '' — BYO mode. */
    private static function _clientSecret()
    {
        return self::_decryptConfig('analytics.oauth.client_secret_enc');
    }

    /** The decrypted stored refresh token, or ''. */
    private static function _refreshToken()
    {
        return self::_decryptConfig('analytics.oauth.refresh_token_enc');
    }

    /** Decrypt a `_enc` config value via Tiger_Crypto, or '' if unset/undecryptable. */
    private static function _decryptConfig($dotKey)
    {
        $enc = (string) self::_config($dotKey, '');
        if ($enc === '' || !class_exists('Tiger_Crypto') || !Tiger_Crypto::isConfigured()) {
            return '';
        }
        try { return (string) Tiger_Crypto::decrypt($enc); } catch (Throwable $e) { return ''; }
    }

    /** Per-property report cache file (inside the app root — cPanel-friendly). */
    private static function _cacheFile($days = 0)
    {
        $base = (defined('APPLICATION_PATH') ? dirname(APPLICATION_PATH) : sys_get_temp_dir()) . '/var/cache/analytics';
        if (!is_dir($base)) { @mkdir($base, 0775, true); }
        return $base . '/ga-' . substr(md5(self::propertyId()), 0, 10) . ($days ? '-' . $days . 'd' : '') . '.json';
    }

    /** Read a `tiger.<dotKey>` config value with a default. */
    private static function _config($dotKey, $default = '')
    {
        if (!Zend_Registry::isRegistered('Zend_Config')) {
            return $default;
        }
        $node = Zend_Registry::get('Zend_Config')->get('tiger');
        foreach (explode('.', $dotKey) as $seg) {
            if (!($node instanceof Zend_Config)) { return $default; }
            $node = $node->get($seg);
            if ($node === null) { return $default; }
        }
        return is_scalar($node) ? (string) $node : $default;
    }
}
