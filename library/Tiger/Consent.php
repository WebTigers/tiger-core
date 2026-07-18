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
     * May a tracker of this category load right now? True unless consent is required and not granted.
     *
     * @param  string $category the tracking category (default 'analytics')
     * @return bool
     */
    public static function allows($category = 'analytics')
    {
        if (!self::required($category)) {
            return true;
        }
        return self::accepted($category);
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
