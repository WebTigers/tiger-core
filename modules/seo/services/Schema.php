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

    /**
     * Emit a per-page BreadcrumbList derived from the URL path (Home → each path segment). The leaf's
     * label is the page's own title (nicer than a humanized slug); intermediate segments humanize. A
     * page one level deep or the homepage produces no breadcrumb (nothing to show but "Home").
     *
     * @param  Zend_Controller_Request_Abstract|null $request  the current request (path + base URL)
     * @param  string                                $leafName the current page's title (leaf label)
     * @return void
     */
    public static function emitPageBreadcrumb(?Zend_Controller_Request_Abstract $request, $leafName)
    {
        try {
            $base = self::_base($request);
            $path = $request && method_exists($request, 'getPathInfo') ? (string) $request->getPathInfo() : '';
            $trail = self::_trail($path, (string) $leafName, $base);
            if (count($trail) < 2) {
                return;   // just "Home" — no breadcrumb worth emitting
            }
            self::_emit([self::_breadcrumbNode($trail, $base, $path)]);
        } catch (Throwable $e) {
            // fail-open
        }
    }

    /**
     * Emit an Article (BlogPosting) node + its BreadcrumbList for a blog article. Cross-references the
     * site graph by @id (publisher → Organization, isPartOf → WebSite). Fail-soft: any missing piece is
     * simply omitted.
     *
     * @param  object                                $row     the article `page` row (for updated_at)
     * @param  array                                 $article the presented article (title/slug/excerpt/…)
     * @param  Zend_Controller_Request_Abstract|null $request the current request
     * @return void
     */
    public static function emitArticle($row, array $article, ?Zend_Controller_Request_Abstract $request = null)
    {
        try {
            $base = self::_base($request);
            if ($base === '') {
                return;
            }
            $nodes = [self::_articleNode($row, $article, $base, $request)];

            // The article's breadcrumb: Home → Blog → <title> (path-derived, leaf = the real title).
            $path  = '/' . ltrim((string) ($article['url'] ?? ('/blog/' . ($article['slug'] ?? ''))), '/');
            $trail = self::_trail($path, (string) ($article['title'] ?? ''), $base);
            if (count($trail) >= 2) {
                $nodes[] = self::_breadcrumbNode($trail, $base, $path);
            }
            self::_emit($nodes);
        } catch (Throwable $e) {
            // fail-open
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

        // Description: an explicit tiger.site.description, else the site tagline.
        $desc = trim((string) self::_config('site.description', self::_config('site.tagline', '')));
        if ($desc !== '') {
            $node['description'] = $desc;
        }

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

        // Description: an explicit tiger.site.description, else the site tagline.
        $desc = trim((string) self::_config('site.description', self::_config('site.tagline', '')));
        if ($desc !== '') {
            $node['description'] = $desc;
        }

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

    /** A BlogPosting node — headline, image, dates, author, publisher/isPartOf → the site graph. */
    private static function _articleNode($row, array $article, $base, $request)
    {
        $slug = trim((string) ($article['slug'] ?? ''), '/');
        $url  = $base . '/blog/' . $slug;

        $node = [
            '@type'            => 'BlogPosting',
            '@id'              => $url . '#article',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'headline'         => (string) ($article['title'] ?? ''),
            'url'              => $url,
            'isPartOf'         => ['@id' => $base . '/#website'],
            'publisher'        => ['@id' => $base . '/#organization'],
        ];

        $seoDesc = isset($article['seo']['description']) ? trim((string) $article['seo']['description']) : '';
        $desc    = $seoDesc !== '' ? $seoDesc : trim((string) ($article['excerpt'] ?? ''));
        if ($desc !== '') {
            $node['description'] = $desc;
        }

        // Image: the feature image, resolved through the media row for a real absolute URL + dimensions.
        $imgRef = isset($article['feature']['id']) ? (string) $article['feature']['id'] : '';
        $img    = self::_image($imgRef !== '' ? $imgRef : self::_config('seo.og_image', ''), $request);
        if ($img) {
            $node['image'] = array_filter([
                '@type'  => 'ImageObject',
                'url'    => $img['url'],
                'width'  => $img['width'],
                'height' => $img['height'],
            ], static function ($v) { return $v !== null && $v !== ''; });
        }

        $pub = self::_iso8601($article['published_at'] ?? '');
        $mod = self::_iso8601(is_object($row) && isset($row->updated_at) ? $row->updated_at : '');
        if ($pub !== '') { $node['datePublished'] = $pub; }
        $node['dateModified'] = $mod !== '' ? $mod : ($pub !== '' ? $pub : null);
        if ($node['dateModified'] === null) { unset($node['dateModified']); }

        $author = trim((string) ($article['author']['name'] ?? ''));
        $node['author'] = $author !== ''
            ? ['@type' => 'Person', 'name' => $author]
            : ['@id' => $base . '/#organization'];

        return $node;
    }

    /** A BreadcrumbList node from a [ ['name','url'], … ] trail (each an absolute ListItem). */
    private static function _breadcrumbNode(array $trail, $base, $path)
    {
        $items = [];
        $pos   = 1;
        foreach ($trail as $step) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => (string) $step['name'],
                'item'     => (string) $step['url'],
            ];
        }
        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => $base . '/' . ltrim((string) $path, '/') . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    // =====================================================================================
    //  Helpers
    // =====================================================================================

    /**
     * Build a breadcrumb trail from a URL path: Home + one step per path segment, each with the
     * absolute URL to that point. The last segment's label is $leafName (the real page title) when
     * given, else a humanized slug; intermediate segments always humanize.
     *
     * @param  string $path     the request path (e.g. /blog/my-post)
     * @param  string $leafName the current page's title (leaf label; '' → humanize the slug)
     * @param  string $base     the absolute site base (scheme://host)
     * @return array            [ ['name','url'], … ] starting at Home
     */
    private static function _trail($path, $leafName, $base)
    {
        $segments = array_values(array_filter(explode('/', trim((string) $path, '/')), static function ($s) { return $s !== ''; }));
        $trail    = [['name' => 'Home', 'url' => $base . '/']];
        $acc      = '';
        $n        = count($segments);
        foreach ($segments as $i => $seg) {
            $acc  .= '/' . $seg;
            $isLast = ($i === $n - 1);
            $name = ($isLast && trim((string) $leafName) !== '') ? trim((string) $leafName) : self::_humanize($seg);
            $trail[] = ['name' => $name, 'url' => $base . $acc];
        }
        return $trail;
    }

    /** Turn a slug into a human label: "getting-started" → "Getting Started". */
    private static function _humanize($slug)
    {
        $s = str_replace(['-', '_'], ' ', (string) $slug);
        return ucwords(trim($s));
    }

    /** A DB DATETIME ('Y-m-d H:i:s') as an ISO-8601 string; '' if blank/unparseable. */
    private static function _iso8601($datetime)
    {
        $datetime = trim((string) $datetime);
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        return $ts !== false ? date('c', $ts) : '';
    }

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
