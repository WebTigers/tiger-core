<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_View_Helper_Asset — cache-busting asset URLs.
 *
 * Appends a `filemtime()`-based query token so browsers pick up changed CSS/JS after
 * a deploy without a hard refresh. Design notes:
 *
 *   - **Zero-build.** The token is the file's mtime — no manifest, no content-hash
 *     rename, no npm. Per-file precise: only files that actually changed get a new URL.
 *   - **Query string, not a versioned path.** `/_theme/app.css?v=1713900000`, not
 *     `app.1713900000.css`. So it needs NO server rewrite and can't 404 — portable
 *     across Apache / nginx / Caddy / shared hosting. (Path-fingerprint mode, with a
 *     rewrite, would only matter for a dumb query-stripping CDN — deferred.)
 *   - **Feature-flagged.** `tiger.assets.cache_bust` (config, default on, live-
 *     overridable per-deploy/org) turns it off — then paths pass through untouched.
 *   - **Graceful.** Remote URLs, protocol-relative, data URIs, and missing files pass
 *     through unchanged. mtime lookups are memoized per request.
 *
 *   $this->asset($this->themeAssets . '/css/default.css')
 *   // → /_theme/css/default.css?v=1713900000
 *
 * @api
 */
class Tiger_View_Helper_Asset extends Zend_View_Helper_Abstract
{
    /** @var bool|null resolved cache-bust flag (per request) */
    protected static $_enabled = null;

    /** @var array<string,string> path -> versioned url, memoized per request */
    protected static $_memo = [];

    public function asset($path)
    {
        $path = (string) $path;

        // Off, empty, or not a local same-origin path -> pass through untouched.
        if (!$this->_enabled() || $path === '' || $path[0] !== '/' || strpos($path, '//') === 0) {
            return $path;
        }
        if (isset(self::$_memo[$path])) {
            return self::$_memo[$path];
        }

        $out = $path;

        // Split off an existing ?query / #fragment so we stat the real file.
        $tail = strpbrk($path, '?#');                    // e.g. "?a=1" or "#frag", or false
        $file = ($tail === false) ? $path : substr($path, 0, -strlen($tail));
        $full = PUBLIC_PATH . $file;                     // symlink-transparent (_theme/_tiger)

        if (is_file($full) && ($mtime = @filemtime($full))) {
            if ($tail !== false && $tail[0] === '#') {
                $out = $file . '?v=' . $mtime . $tail;   // keep the fragment last
            } else {
                $sep = (strpos($path, '?') === false) ? '?' : '&';
                $out = $path . $sep . 'v=' . $mtime;     // preserves an existing query
            }
        }

        self::$_memo[$path] = $out;
        return $out;
    }

    /** The `tiger.assets.cache_bust` flag (config cascade); default ON when unset. */
    protected function _enabled()
    {
        if (self::$_enabled === null) {
            self::$_enabled = true;
            if (Zend_Registry::isRegistered('Zend_Config')) {
                $cfg    = Zend_Registry::get('Zend_Config');
                $assets = $cfg->get('tiger') ? $cfg->tiger->get('assets') : null;
                if ($assets && $assets->get('cache_bust') !== null) {
                    self::$_enabled = (bool) (int) $assets->cache_bust;
                }
            }
        }
        return self::$_enabled;
    }
}
