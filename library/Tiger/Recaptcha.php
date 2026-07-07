<?php
/**
 * Tiger_Recaptcha — the Google reCAPTCHA integration hub (config + server verify).
 *
 * Shared by the form CONTROL (Tiger_View_Helper_FormRecaptcha, which renders the
 * widget) and the VALIDATOR (Tiger_Validate_Recaptcha, which checks the token
 * server-side). Keeping the config reads and the HTTP call in one place keeps those
 * two in lock-step.
 *
 * Config (`tiger.recaptcha.*`, cascade like everything else):
 *   - enabled    on/off. OFF = the control renders nothing and the validator passes,
 *                so forms work in dev with no keys.
 *   - version    "v2" (checkbox) | "v3" (invisible score).
 *   - site_key   PUBLIC — rendered into the page (fine to commit in application.ini).
 *   - secret_key SECRET — server-side only; belongs in local.ini, never committed.
 *   - min_score  v3 pass threshold (0..1).
 *   - fail_open  when Google can't be REACHED (transport error, not a bot verdict),
 *                pass (1, default — a reCAPTCHA outage shouldn't lock out your login)
 *                or fail (0). A definitive "not a human" verdict always fails closed.
 *
 * @api
 */
class Tiger_Recaptcha
{
    const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    const SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';

    /** Is reCAPTCHA switched on for this install? */
    public static function isEnabled()
    {
        return (bool) self::_get('enabled', 0);
    }

    /** The public site key (rendered in the widget), or '' if unset. */
    public static function siteKey()
    {
        return (string) self::_get('site_key', '');
    }

    /** The server-side secret key, or '' if unset. */
    public static function secretKey()
    {
        return (string) self::_get('secret_key', '');
    }

    /** "v2" (checkbox) or "v3" (score). */
    public static function version()
    {
        $v = strtolower((string) self::_get('version', 'v2'));
        return ($v === 'v3') ? 'v3' : 'v2';
    }

    /** v3 minimum passing score. */
    public static function minScore()
    {
        return (float) self::_get('min_score', 0.5);
    }

    /** Pass on a transport failure (can't reach Google)? Default yes (availability). */
    public static function failOpen()
    {
        return (bool) self::_get('fail_open', 1);
    }

    /**
     * Verify a token with Google. Returns the decoded response array (with `success`,
     * and for v3 `score`/`action`), or NULL on a transport failure (couldn't reach the
     * service) — the caller decides fail-open vs fail-closed for that case.
     *
     * @return array|null
     */
    public static function verify($token, $remoteIp = null)
    {
        $secret = self::secretKey();
        if ($secret === '' || (string) $token === '') {
            return ['success' => false];
        }
        $params = ['secret' => $secret, 'response' => (string) $token];
        if ($remoteIp) {
            $params['remoteip'] = (string) $remoteIp;
        }
        $body = self::_post(self::VERIFY_URL, $params);
        if ($body === null) {
            return null;   // transport failure
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['success' => false];
    }

    /** POST form-encoded params; returns the response body, or null on any transport error. */
    protected static function _post($url, array $params)
    {
        $data = http_build_query($params);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $body = curl_exec($ch);
            $ok   = ($body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 200 && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300);
            curl_close($ch);
            return $ok ? $body : null;
        }

        // Fallback: stream context (only if allow_url_fopen is on).
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $data,
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body === false) ? null : $body;
    }

    /** Read a tiger.recaptcha.* value from the config cascade. */
    protected static function _get($key, $default)
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        if ($cfg && $cfg->get('tiger') && $cfg->tiger->get('recaptcha')) {
            $val = $cfg->tiger->recaptcha->get($key);
            if ($val !== null && $val !== '') {
                return $val;
            }
        }
        return $default;
    }
}
