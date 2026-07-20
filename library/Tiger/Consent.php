<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Consent — the GDPR cookie-consent gate. Answers one question for any tracker: *may I load?*
 *
 * Three modes (config `tiger.consent.mode`, set from Settings → Security → Cookies):
 *   - `off`    — consent not required; trackers load freely (non-EU sites).
 *   - `auto`   — require consent only if a tracker is actually present (reads Tiger_Tracking); the
 *                banner auto-pops when tracking exists, and stays silent when it doesn't.
 *   - `always` — always require consent, even with no tracking present.
 *
 * When consent is required, a tracker loads only once the visitor has accepted (a signed-simple
 * cookie). This class is CORE and self-contained: with no consent module installed, `mode` is unset
 * → `off` → everything loads, so trackers (e.g. GA) work standalone. The consent MODULE adds the
 * settings UI + the banner that writes the cookie; the gate logic lives here.
 *
 * **GDPR + CCPA in one gate (one banner, one Accept).** GDPR is opt-IN (the mode/banner above);
 * California's CCPA/CPRA is opt-OUT, and its legally-recognized opt-out signal is **Global Privacy
 * Control** (the `Sec-GPC: 1` request header / `navigator.globalPrivacyControl`). We honor GPC as a
 * "do not sell or share" for non-essential trackers even when GDPR consent isn't required — so a
 * California visitor is opted out automatically, no second banner and no separate "Accept". An
 * explicit Accept here still wins (the visitor's own choice overrides the browser signal).
 *
 * @api
 * @see Tiger_Tracking
 */
class Tiger_Consent
{
    const MODE_OFF    = 'off';
    const MODE_AUTO   = 'auto';
    const MODE_ALWAYS = 'always';

    /** The visitor's consent cookie; value is a category CSV of grants, or 'all' / 'none'. */
    const COOKIE = 'tiger_consent';

    /**
     * The configured consent mode (`tiger.consent.mode`), defaulting to `off`.
     *
     * @return string one of MODE_OFF|MODE_AUTO|MODE_ALWAYS
     */
    public static function mode()
    {
        $m = strtolower(trim((string) self::_config('consent.mode', self::MODE_OFF)));
        return in_array($m, [self::MODE_AUTO, self::MODE_ALWAYS], true) ? $m : self::MODE_OFF;
    }

    /**
     * Is consent required before a tracker of this category may load?
     *
     * @param  string|null $category the tracking category (null = any)
     * @return bool
     */
    public static function required($category = null)
    {
        $mode = self::mode();
        if ($mode === self::MODE_ALWAYS) {
            return true;
        }
        if ($mode === self::MODE_AUTO) {
            return class_exists('Tiger_Tracking') && Tiger_Tracking::hasActive($category);
        }
        return false;   // off
    }

    /**
     * Has the visitor granted consent for this category? (Reads the consent cookie.)
     *
     * @param  string $category the tracking category
     * @return bool
     */
    public static function accepted($category = 'analytics')
    {
        $raw = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        if ($raw === '') {
            return false;
        }
        if ($raw === 'all') {
            return true;
        }
        if ($raw === 'none') {
            return false;
        }
        $grants = array_filter(array_map('trim', explode(',', strtolower($raw))));
        return in_array(strtolower((string) $category), $grants, true);
    }

    /**
     * May a tracker of this category load right now? An explicit opt-in always permits; otherwise a
     * GPC/CCPA opt-out signal blocks non-essential trackers; otherwise the GDPR mode decides.
     *
     * @param  string $category the tracking category (default 'analytics')
     * @return bool
     */
    public static function allows($category = 'analytics')
    {
        // A standing explicit decline ("Necessary only" → 'none') opts the visitor out for good —
        // honored regardless of GDPR mode (it is also the CCPA opt-out of sale/sharing).
        if ((string) ($_COOKIE[self::COOKIE] ?? '') === 'none') {
            return false;
        }
        // The visitor's own opt-in (Accept, or this category granted) always wins.
        if (self::accepted($category)) {
            return true;
        }
        // Global Privacy Control: a CCPA/CPRA opt-out. Honor it for non-essential trackers even when
        // GDPR consent isn't required here — unless the visitor has explicitly decided in the banner.
        if (self::honorGpc() && self::gpc() && !self::decided()) {
            return false;
        }
        // GDPR: permitted unless consent is required and not yet granted.
        return !self::required($category);
    }

    /**
     * Is a Global Privacy Control opt-out signal present on this request? GPC (`Sec-GPC: 1`) is the
     * CPRA-recognized "do not sell/share" browser signal. (`DNT` is a deprecated, non-binding hint and
     * is intentionally NOT treated as an opt-out.)
     *
     * @return bool
     */
    public static function gpc()
    {
        return isset($_SERVER['HTTP_SEC_GPC']) && trim((string) $_SERVER['HTTP_SEC_GPC']) === '1';
    }

    /**
     * Do we honor GPC as a CCPA opt-out? Config `tiger.consent.honor_gpc`, default ON (the compliant
     * behavior — set to 0 only if you have a specific reason not to).
     *
     * @return bool
     */
    public static function honorGpc()
    {
        $v = strtolower(trim((string) self::_config('consent.honor_gpc', '1')));
        return !in_array($v, ['0', 'off', 'false', 'no'], true);
    }

    /** Has the visitor made a choice (accept OR reject)? True once the consent cookie exists. */
    public static function decided()
    {
        return isset($_COOKIE[self::COOKIE]) && (string) $_COOKIE[self::COOKIE] !== '';
    }

    /** Should the consent banner show right now? (Consent is required and not yet decided.) */
    public static function showBanner()
    {
        return self::required(null) && !self::decided();
    }

    /** Default banner copy, used when the operator hasn't customized it. */
    const DEFAULTS = [
        'message'      => 'We use cookies to analyze traffic and improve your experience. Accept, or continue with only what\'s necessary.',
        'accept_label' => 'Accept',
        'reject_label' => 'Necessary only',
        'policy_url'   => '',
        'ccpa_notice'  => 'California residents: choosing "Necessary only" (or sending a Global Privacy Control signal) opts you out of any sale or sharing of your personal information.',
    ];

    /**
     * The current cookie-consent settings (for the admin form + the banner).
     *
     * @return array mode, message, accept_label, reject_label, policy_url
     */
    public static function settings()
    {
        $get = static function ($key, $default) {
            $v = trim((string) self::_config('consent.' . $key, ''));
            return $v !== '' ? $v : $default;
        };
        return [
            'mode'         => self::mode(),
            'message'      => $get('message', self::DEFAULTS['message']),
            'accept_label' => $get('accept_label', self::DEFAULTS['accept_label']),
            'reject_label' => $get('reject_label', self::DEFAULTS['reject_label']),
            'policy_url'   => $get('policy_url', self::DEFAULTS['policy_url']),
            'ccpa_notice'  => $get('ccpa_notice', self::DEFAULTS['ccpa_notice']),
            'honor_gpc'    => self::honorGpc(),   // whether we honor the GPC opt-out signal
            'gpc'          => self::gpc(),         // is a GPC signal present on THIS request?
        ];
    }

    /**
     * Persist the cookie-consent settings to the config tier (GLOBAL scope). Shared writer, called
     * from the System settings save (mirrors Tiger_Recaptcha::saveSettings).
     *
     * @param  array $data mode, message, accept_label, reject_label, policy_url
     * @return void
     */
    public static function saveSettings(array $data)
    {
        $cfg  = new Tiger_Model_Config();
        $g    = Tiger_Model_Config::SCOPE_GLOBAL;
        $mode = strtolower(trim((string) ($data['mode'] ?? self::MODE_OFF)));
        if (!in_array($mode, [self::MODE_OFF, self::MODE_AUTO, self::MODE_ALWAYS], true)) {
            $mode = self::MODE_OFF;
        }
        $cfg->set($g, '', 'tiger.consent.mode',         $mode);
        $cfg->set($g, '', 'tiger.consent.message',      trim((string) ($data['message'] ?? '')));
        $cfg->set($g, '', 'tiger.consent.accept_label', trim((string) ($data['accept_label'] ?? '')));
        $cfg->set($g, '', 'tiger.consent.reject_label', trim((string) ($data['reject_label'] ?? '')));
        $cfg->set($g, '', 'tiger.consent.policy_url',   trim((string) ($data['policy_url'] ?? '')));
        $cfg->set($g, '', 'tiger.consent.ccpa_notice',  trim((string) ($data['ccpa_notice'] ?? '')));
        $cfg->set($g, '', 'tiger.consent.honor_gpc',    !empty($data['honor_gpc']) ? '1' : '0');
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
