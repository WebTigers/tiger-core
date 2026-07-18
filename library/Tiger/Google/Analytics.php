<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Google_Analytics — a tiny, dependency-free client for the Google Analytics 4 **reporting**
 * side (pulling stats back for the in-app dashboard). Pure HTTPS (curl) — no Google SDK, no JWT.
 *
 * Auth is **bring-your-own OAuth client**: the operator registers their own Google Cloud OAuth
 * credentials (works on any self-hosted domain, no phone-home). We store `client_id` + an encrypted
 * `client_secret` + the numeric `property_id` in config, run the OAuth consent flow once to get a
 * long-lived **refresh token** (stored encrypted), and exchange it for short-lived access tokens on
 * demand to call the GA4 Data API (`runReport`). Report results are file-cached (GA has quotas).
 *
 * Config (tiger.analytics.*): oauth.client_id, oauth.client_secret_enc, property_id,
 * oauth.refresh_token_enc. Secrets are encrypted at rest via Tiger_Crypto (like Tiger_Recaptcha).
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

    /** @var string|null memoized access token for this request */
    private static $_access = null;

    // =====================================================================================
    //  Connection state + config
    // =====================================================================================

    /** Is the reporting side fully wired (client creds + property + a stored refresh token)? */
    public static function isConnected()
    {
        return self::clientId() !== '' && self::_clientSecret() !== ''
            && self::propertyId() !== '' && self::_refreshToken() !== '';
    }

    /** True once the OAuth client creds + property id are set (ready to run the Connect flow). */
    public static function isConfigurable()
    {
        return self::clientId() !== '' && self::_clientSecret() !== '' && self::propertyId() !== '';
    }

    /** The OAuth client id (public). */
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
     * Persist the OAuth client creds + property id (secret encrypted; blank secret keeps the current).
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
    //  OAuth flow
    // =====================================================================================

    /**
     * The Google consent URL to send the admin to (offline access, forced consent so a refresh token
     * is always returned).
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
     * Exchange the authorization code for tokens and store the refresh token (encrypted).
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

    /** A fresh access token (refreshed from the stored refresh token), or '' if unavailable. */
    public static function accessToken()
    {
        if (self::$_access !== null) {
            return (string) self::$_access;
        }
        $refresh = self::_refreshToken();
        if ($refresh === '') {
            return '';
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

    /** POST form params to the token endpoint; returns the decoded JSON or null. */
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

    /** The decrypted OAuth client secret, or ''. */
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
