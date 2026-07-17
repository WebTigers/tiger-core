<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Seo_Service_Schema — contributes JSON-LD structured data (schema.org) to the head. Where the head
 * meta/OG tags (Seo_Service_Head) describe ONE page, this describes the SITE as an entity: the
 * Organization (brand), the WebSite, and the primary SiteNavigationElement — the graph Google reads to
 * build the brand knowledge panel and **sitelinks**. Emitted once per request as a single
 * `<script type="application/ld+json">` block holding an `@graph` of cross-referenced nodes.
 *
 * Like Seo_Service_Head it appends into a process-wide Zend_View placeholder (`tigerJsonLd`) which the
 * public layout renders — so no core edit, and it degrades to nothing when the module is absent. It is
 * NOT a Tiger_Service_Service, so it is unreachable over /api (the gateway's type guard blocks it); the
 * plugin calls it in-process. Everything is config-driven and fail-soft: a missing site name, logo, or
 * menu simply omits that part rather than breaking the graph or the page.
 *
 * @api
 * @see Seo_Service_Head
 */
class Seo_Service_Schema
{
    /** Emit-once latch: the site graph is identical for every page, so it renders a single time per request. */
    private static $_emitted = false;

    /**
     * Emit the site-identity graph (Organization + WebSite + SiteNavigationElement) into the head, once.
     *
     * @param  Zend_Controller_Request_Abstract|null $request the current request (for the absolute base URL)
     * @return void
     */
    public static function emitSite(?Zend_Controller_Request_Abstract $request = null)
    {
        if (self::$_emitted) {
            return;
        }
        self::$_emitted = true;

        try {
            $base = self::_base($request);
            if ($base === '') {
                return;   // no absolute base to anchor @id/url on — skip rather than emit relative nodes
            }
            $nodes = array_values(array_filter([
                self::_organization($base, $request),
                self::_website($base),
                self::_siteNavigation($base),
            ]));
            if ($nodes) {
                self::_emit($nodes);
            }
        } catch (Throwable $e) {
            // fail-open — structured data must never take down a page render
        }
    }

    /** The brand entity: name, url, logo (real dimensions via the media row), and social `sameAs` links. */
    private static function _organization($base, $request)
    {
        $node = [
            '@type' => 'Organization',
            '@id'   => $base . '/#organization',
            'name'  => self::_siteName(),
            'url'   => $base . '/',
        ];

        // Logo: the Site Identity logo (tiger.site.logo), else the SEO share image (tiger.seo.og_image).
        $logo = self::_image(self::_config('site.logo', self::_config('seo.og_image', '')), $request);
        if ($logo) {
            $node['logo'] = array_filter([
                '@type'  => 'ImageObject',
                '@id'    => $base . '/#logo',
                'url'    => $logo['url'],
                'width'  => $logo['width'],
                'height' => $logo['height'],
            ], static function ($v) { return $v !== null && $v !== ''; });
        }

        $sameAs = self::_sameAs();
        if ($sameAs) {
            $node['sameAs'] = $sameAs;
        }
        return $node;
    }

    /** The website node — publisher points at the Organization; an optional SearchAction = a sitelinks searchbox. */
    private static function _website($base)
    {
        $node = [
            '@type'     => 'WebSite',
            '@id'       => $base . '/#website',
            'url'       => $base . '/',
            'name'      => self::_siteName(),
            'publisher' => ['@id' => $base . '/#organization'],
        ];

        // Sitelinks searchbox — only when the operator has a public search URL template configured.
        // e.g. tiger.seo.schema.search_url = "/search?q={search_term_string}"
        $search = trim((string) self::_config('seo.schema.search_url', ''));
        if ($search !== '') {
            $target = preg_match('#^https?://#i', $search) ? $search : $base . '/' . ltrim($search, '/');
            $node['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $target],
                'query-input' => 'required name=search_term_string',
            ];
        }
        return $node;
    }

    /** The primary nav as a SiteNavigationElement — helps Google map site structure (→ sitelinks). */
    private static function _siteNavigation($base)
    {
        if (!class_exists('Tiger_Menu')) {
            return null;
        }
        $key = trim((string) self::_config('seo.schema.nav_menu', 'primary'));
        if ($key === '') {
            $key = 'primary';
        }
        try {
            $tree = Tiger_Menu::getData($key);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($tree) || !$tree) {
            return null;
        }

        $names = [];
        $urls  = [];
        foreach ($tree as $item) {                      // top-level items only — that's what nav sitelinks use
            $label = trim((string) ($item['label'] ?? ''));
            $href  = (string) ($item['href'] ?? '');
            if ($label === '' || $href === '' || $href === '#') {
                continue;                               // skip headings + dead placeholders
            }
            $names[] = $label;
            $urls[]  = preg_match('#^https?://#i', $href) ? $href : $base . '/' . ltrim($href, '/');
        }
        if (!$names) {
            return null;
        }
        return [
            '@type' => 'SiteNavigationElement',
            '@id'   => $base . '/#sitenav',
            'name'  => $names,
            'url'   => $urls,
        ];
    }

    // =====================================================================================
    //  Helpers
    // =====================================================================================

    /** Serialize the nodes as one `@graph` and append the ld+json <script> to the head placeholder. */
    private static function _emit(array $nodes)
    {
        $graph = ['@context' => 'https://schema.org', '@graph' => array_values($nodes)];
        // JSON_HEX_TAG encodes every < and > as </>, so a value can never break out of the
        // <script> element (parsers decode them back) — the safe way to inline JSON-LD.
        $json = json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        if ($json === false) {
            return;
        }
        self::_view()->placeholder('tigerJsonLd')->append(
            '<script type="application/ld+json">' . $json . '</script>'
        );
    }

    /** The site name (tiger.site.name), with a neutral fallback so the brand node is never nameless. */
    private static function _siteName()
    {
        $name = trim((string) self::_config('site.name', ''));
        return $name !== '' ? $name : 'Tiger';
    }

    /** Social profile URLs for Organization.sameAs, from tiger.seo.social.* (or tiger.site.social.*). */
    private static function _sameAs()
    {
        $out = [];
        foreach (['twitter', 'facebook', 'instagram', 'linkedin', 'youtube', 'github'] as $k) {
            $v = trim((string) self::_config('seo.social.' . $k, self::_config('site.social.' . $k, '')));
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    /** The absolute site base (scheme://host, no trailing slash) — request-derived, else tiger.site.url. */
    private static function _base(?Zend_Controller_Request_Abstract $request)
    {
        if ($request && method_exists($request, 'getScheme')) {
            return $request->getScheme() . '://' . $request->getHttpHost();
        }
        return rtrim((string) self::_config('site.url', ''), '/');
    }

    /** A Zend_View to reach the placeholder helper. Any instance shares the process-wide registry. */
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

    /** Read a `tiger.<dotKey>` config value (org-cascaded, live) with a default. */
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

    /**
     * Resolve an image reference to ['url','width','height','mime','alt'] — a `media_id` (looked up for a
     * real absolute URL + true pixel dimensions) or an already-absolute URL. Null when unresolvable.
     */
    private static function _image($ref, $request)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $ref)) {
            return ['url' => $ref, 'width' => null, 'height' => null, 'mime' => null, 'alt' => null];
        }
        try {
            if (!class_exists('Tiger_Model_Media')) { return null; }
            $model = new Tiger_Model_Media();
            $row   = $model->findById($ref);
            if (!$row) { return null; }
            $arr = $row->toArray();
            $url = (string) $model->url($arr);
            if ($url === '') { return null; }
            if (!preg_match('#^https?://#i', $url) && $request && method_exists($request, 'getScheme')) {
                $url = $request->getScheme() . '://' . $request->getHttpHost() . '/' . ltrim($url, '/');
            }
            return [
                'url'    => $url,
                'width'  => $arr['width'] ?? null,
                'height' => $arr['height'] ?? null,
                'mime'   => $arr['mime_type'] ?? null,
                'alt'    => $arr['alt_text'] ?? ($arr['title'] ?? null),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}
