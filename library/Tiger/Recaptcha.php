<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
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

    /**
     * Is reCAPTCHA switched on for this install?
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return (bool) self::_get('enabled', 0);
    }

    /**
     * The public site key (rendered in the widget), or '' if unset.
     *
     * @return string
     */
    public static function siteKey()
    {
        return (string) self::_get('site_key', '');
    }

    /**
     * The server-side secret key, or '' if unset.
     *
     * @return string
     */
    public static function secretKey()
    {
        $plain = (string) self::_get('secret_key', '');
        if ($plain !== '') {
            return $plain;                                    // plaintext (local.ini / application.ini)
        }
        // Admin-screen path: the secret is stored ENCRYPTED at rest in the config tier (Tiger_Crypto;
        // key in local.ini) — so the no-shell operator can set it in the UI without a plaintext DB row.
        $enc = (string) self::_get('secret_key_enc', '');
        if ($enc !== '' && class_exists('Tiger_Crypto') && Tiger_Crypto::isConfigured()) {
            try { return (string) Tiger_Crypto::decrypt($enc); } catch (Throwable $e) { return ''; }
        }
        return '';
    }

    /**
     * "v2" (checkbox) or "v3" (score).
     *
     * @return string
     */
    public static function version()
    {
        $v = strtolower((string) self::_get('version', 'v2'));
        return ($v === 'v3') ? 'v3' : 'v2';
    }

    /**
     * v3 minimum passing score.
     *
     * @return float
     */
    public static function minScore()
    {
        return (float) self::_get('min_score', 0.5);
    }

    /**
     * Pass on a transport failure (can't reach Google)? Default yes (availability).
     *
     * @return bool
     */
    public static function failOpen()
    {
        return (bool) self::_get('fail_open', 1);
    }

    /**
     * Verify a token with Google. Returns the decoded response array (with `success`,
     * and for v3 `score`/`action`), or NULL on a transport failure (couldn't reach the
     * service) — the caller decides fail-open vs fail-closed for that case.
     *
     * @param  string      $token    the reCAPTCHA response token from the client
     * @param  string|null $remoteIp optional client IP to include in the verify call
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

    /**
     * v3 only: should the floating reCAPTCHA badge be hidden? Google permits it *if* the reCAPTCHA
     * legal notice is shown instead (see legalNotice()).
     *
     * @return bool
     */
    public static function hideBadge()
    {
        return (bool) self::_get('hide_badge', 0);
    }

    /**
     * The `<style>` that hides the v3 badge — emit it wherever the widget renders. Empty unless v3 +
     * hide_badge (v2 has no floating badge to hide).
     *
     * @return string
     */
    public static function badgeCss()
    {
        if (!self::hideBadge() || self::version() !== 'v3') {
            return '';
        }
        return '<style>.grecaptcha-badge{visibility:hidden !important;}</style>';
    }

    /**
     * The reCAPTCHA legal notice Google REQUIRES when the badge is hidden. Render it near the protected
     * form. Returns '' unless the badge is actually being hidden.
     *
     * @return string
     */
    public static function legalNotice()
    {
        if (!self::hideBadge() || self::version() !== 'v3') {
            return '';
        }
        return '<p class="grecaptcha-terms" style="font-size:.75rem;line-height:1.4;opacity:.7;margin:.6em 0 0">'
             . 'This site is protected by reCAPTCHA and the Google '
             . '<a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Privacy Policy</a> and '
             . '<a href="https://policies.google.com/terms" target="_blank" rel="noopener">Terms of Service</a> apply.</p>';
    }

    /**
     * Current settings for prefilling an admin form. The secret is NEVER returned — only a
     * `has_secret` flag — so a configured secret is not echoed back into the page.
     *
     * @return array{enabled:int, version:string, site_key:string, has_secret:bool, min_score:float, fail_open:int, hide_badge:int}
     */
    public static function settings()
    {
        return [
            'enabled'    => self::isEnabled() ? 1 : 0,
            'version'    => self::version(),
            'site_key'   => self::siteKey(),
            'has_secret' => self::secretKey() !== '',
            'min_score'  => self::minScore(),
            'fail_open'  => self::failOpen() ? 1 : 0,
            'hide_badge' => self::hideBadge() ? 1 : 0,
        ];
    }

    /**
     * Persist reCAPTCHA settings to the config tier (scope=global, live-override, no deploy). Only the
     * keys present in $values are written. The secret is written ONLY when a non-empty value is supplied
     * (blank = keep the current one) and is stored ENCRYPTED at rest (Tiger_Crypto). Shared by the core
     * System settings screen and any module that surfaces the same controls (e.g. TigerShield).
     *
     * @param  array $values enabled, version, site_key, secret_key, min_score, fail_open, hide_badge
     * @return void
     */
    public static function saveSettings(array $values)
    {
        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;

        if (array_key_exists('enabled', $values))   { $cfg->set($g, '', 'tiger.recaptcha.enabled', !empty($values['enabled']) ? '1' : '0'); }
        if (array_key_exists('version', $values))   { $cfg->set($g, '', 'tiger.recaptcha.version', ((string) $values['version'] === 'v3') ? 'v3' : 'v2'); }
        if (array_key_exists('site_key', $values))  { $cfg->set($g, '', 'tiger.recaptcha.site_key', trim((string) $values['site_key'])); }
        if (array_key_exists('min_score', $values)) { $cfg->set($g, '', 'tiger.recaptcha.min_score', (string) max(0, min(1, (float) $values['min_score']))); }
        if (array_key_exists('fail_open', $values)) { $cfg->set($g, '', 'tiger.recaptcha.fail_open', !empty($values['fail_open']) ? '1' : '0'); }
        if (array_key_exists('hide_badge', $values)){ $cfg->set($g, '', 'tiger.recaptcha.hide_badge', !empty($values['hide_badge']) ? '1' : '0'); }

        // Secret — only when a new value is provided (blank keeps the current). Encrypt at rest.
        if (!empty($values['secret_key'])) {
            $secret = trim((string) $values['secret_key']);
            if (class_exists('Tiger_Crypto') && Tiger_Crypto::isConfigured()) {
                $cfg->set($g, '', 'tiger.recaptcha.secret_key_enc', Tiger_Crypto::encrypt($secret));
            } else {
                $cfg->set($g, '', 'tiger.recaptcha.secret_key', $secret);   // no crypto available → plaintext fallback
            }
        }
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
