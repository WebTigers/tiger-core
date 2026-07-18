<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Analytics_Plugin_Tag — emits the Google Analytics (GA4) gtag.js snippet into the head, on public
 * pages, when it's configured AND consent allows it. It appends to a `tigerTracking` head placeholder
 * that the public layout renders high in <head> (per Google's guidance); the admin/auth layouts don't
 * render it, so admin traffic is never tagged. Fail-open — a broken lookup never breaks the page.
 *
 * Consent: the snippet is emitted only if Tiger_Consent::allows('analytics') — which is TRUE until the
 * cookie-consent feature sets a mode (so GA works standalone), then honors the visitor's choice.
 */
class Analytics_Plugin_Tag extends Zend_Controller_Plugin_Abstract
{
    /** Emit-once latch (the tag is identical on every dispatch/forward). */
    private static $_done = false;

    /**
     * @param  Zend_Controller_Request_Abstract $request
     * @return void
     */
    public function preDispatch(Zend_Controller_Request_Abstract $request)
    {
        if (self::$_done) {
            return;
        }
        self::$_done = true;

        try {
            $id = self::measurementId();
            if ($id === '') {
                return;   // not configured / disabled
            }
            // GDPR gate: load only when consent allows this category.
            if (class_exists('Tiger_Consent') && !Tiger_Consent::allows('analytics')) {
                return;
            }
            // Optionally skip signed-in staff (don't pollute stats with your own team).
            if (self::_excludeSignedIn() && self::_isSignedInStaff()) {
                return;
            }
            self::_view()->placeholder('tigerTracking')->append(self::_snippet($id));
        } catch (Throwable $e) {
            // fail-open — analytics must never take down a page render
        }
    }

    /** True when GA is enabled AND a valid GA4 Measurement ID is set. (Used by the tracker registry.) */
    public static function isConfigured()
    {
        return self::measurementId() !== '';
    }

    /** The GA4 Measurement ID to emit, or '' when disabled/unset/invalid (validated `G-XXXX`). */
    public static function measurementId()
    {
        if (!self::_boolConfig('analytics.enabled')) {
            return '';
        }
        $id = trim((string) self::_config('analytics.ga4.measurement_id', ''));
        return preg_match('/^G-[A-Z0-9]{4,}$/i', $id) ? $id : '';
    }

    /** The GA4 gtag.js snippet for a (already-validated) measurement id. */
    private static function _snippet($id)
    {
        $id = htmlspecialchars($id, ENT_QUOTES);   // belt-and-suspenders; the id is already regex-validated
        return "\n<!-- Google tag (gtag.js) — Tiger Analytics -->\n"
            . '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>' . "\n"
            . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            . "gtag('js',new Date());gtag('config','" . $id . "');</script>\n";
    }

    /** Whether to exclude signed-in staff from tracking (config, default on). */
    private static function _excludeSignedIn()
    {
        return self::_boolConfig('analytics.exclude_signed_in', true);
    }

    /** True if the current visitor is authenticated with a staff (manager+) role. */
    private static function _isSignedInStaff()
    {
        if (!class_exists('Zend_Auth') || !Zend_Auth::getInstance()->hasIdentity()) {
            return false;
        }
        $identity = Zend_Auth::getInstance()->getIdentity();
        $role     = is_object($identity) && isset($identity->role) ? strtolower((string) $identity->role) : '';
        return in_array($role, ['manager', 'supermanager', 'admin', 'superadmin', 'developer'], true);
    }

    /** A Zend_View to reach the placeholder helper (shares the process-wide registry). */
    private static function _view()
    {
        if (Zend_Registry::isRegistered('Zend_View')) {
            $v = Zend_Registry::get('Zend_View');
            if ($v instanceof Zend_View_Interface) {
                return $v;
            }
        }
        return new Zend_View();
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

    /** Read a boolean-ish `tiger.<dotKey>` config value. */
    private static function _boolConfig($dotKey, $default = false)
    {
        $v = self::_config($dotKey, $default ? '1' : '0');
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
