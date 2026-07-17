<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Seo_Service_Head — the resolver that maps a page row's `meta.seo` onto the shared head registry
 * (TigerZF's headTitle/headMeta/headLink placeholder containers), which the layout renders.
 *
 * The single seam TigerSEO uses to contribute to the <head>: it never renders markup itself and never
 * touches the theme — it appends typed entries and the layout prints them. Reached two ways for the two
 * content paths: Seo_Plugin_Head for CMS pages (dispatched via PageDispatch → cms_page_id), and a direct,
 * class_exists-guarded call from the blog article controller (which has its own dispatch). Phase 1 emits
 * title / description / robots / canonical; Open Graph + Twitter are Phase 2. Internal (NOT a /api service).
 */
class Seo_Service_Head
{
    /**
     * Populate the head containers from a page row's SEO metadata. Fail-soft — SEO never breaks a render.
     *
     * @param  mixed                            $page      a `page`/`post` row (Zend_Db_Table_Row) with a JSON `meta`
     * @param  Zend_Controller_Request_Abstract $request   the current request (for a self-referencing canonical)
     * @param  array                            $overrides caller fallbacks that fill BLANKS only (e.g. a blog
     *                                                     article's excerpt → description); an author-set value wins
     * @return void
     */
    public static function forRow($page, Zend_Controller_Request_Abstract $request = null, array $overrides = [])
    {
        if (!$page) {
            return;
        }
        $meta = self::_meta($page);
        $seo  = (isset($meta['seo']) && is_array($meta['seo'])) ? $meta['seo'] : [];
        foreach ($overrides as $k => $v) {
            if ($v !== null && $v !== '' && empty($seo[$k])) { $seo[$k] = $v; }
        }
        $view = self::_view();
        if (!$view) {
            return;
        }

        // Title — an author-set SEO title overrides the page title the layout would otherwise seed.
        $title = trim((string) ($seo['title'] ?? ''));
        if ($title !== '') {
            $view->headTitle()->set($title);
        }

        // Meta description.
        $desc = trim((string) ($seo['description'] ?? ''));
        if ($desc !== '') {
            $view->headMeta()->setName('description', $desc);
        }

        // Robots — the absence of the tag means index,follow; emit a directive ONLY when restricted.
        $robots = self::_robots($seo);
        if ($robots !== '') {
            $view->headMeta()->setName('robots', $robots);
        }

        // Canonical — explicit if the author set one, else self-referencing (clean path, no query).
        $canonical = trim((string) ($seo['canonical'] ?? ''));
        if ($canonical === '' && $request) {
            $canonical = self::_currentUrl($request);
        }
        if ($canonical !== '') {
            $view->headLink(['rel' => 'canonical', 'href' => $canonical]);
        }
    }

    // -- internals -----------------------------------------------------------------------------------

    /** Decode a row's JSON `meta` to an array (tolerates an already-decoded array). */
    private static function _meta($page)
    {
        $raw = $page->meta ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = $raw ? json_decode((string) $raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** Build the robots content from `seo.robots.{index,follow}`; '' means the default (index,follow). */
    private static function _robots(array $seo)
    {
        $r = (isset($seo['robots']) && is_array($seo['robots'])) ? $seo['robots'] : [];
        $parts = [];
        if (array_key_exists('index', $r) && !$r['index'])   { $parts[] = 'noindex'; }
        if (array_key_exists('follow', $r) && !$r['follow']) { $parts[] = 'nofollow'; }
        return implode(', ', $parts);
    }

    /** The current request's absolute URL, path only (a stable self-referencing canonical). */
    private static function _currentUrl(Zend_Controller_Request_Abstract $request)
    {
        if (!method_exists($request, 'getScheme')) {
            return '';
        }
        $path = (string) parse_url((string) $request->getRequestUri(), PHP_URL_PATH);
        return $request->getScheme() . '://' . $request->getHttpHost() . ($path !== '' ? $path : '/');
    }

    /** A Zend_View to reach the head helpers. Any instance shares the process-wide placeholder registry. */
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
}
