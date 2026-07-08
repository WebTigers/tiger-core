<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Menu — the render/read facade for custom menus (Tiger_Model_Menu is the store).
 *
 * Two entry points, cleanly split:
 *
 *   Tiger_Menu::getHTML('primary')   -> rendered <ul>…</ul>. Parsed for AUTH (items the
 *       live role can't reach are hidden), labels run through Zend_Translate, hrefs
 *       resolved (page_key -> the page's current slug, else url), active item marked.
 *       Also the target of the {menu} view helper and the [menu] shortcode.
 *
 *   Tiger_Menu::getData('primary')   -> the raw nested array (labels translated, hrefs
 *       resolved, `active` flagged, `children` nested) for a developer to render however
 *       they like. Does NOT parse for auth — it's the unfiltered tree.
 *
 * getHTML compiles the tree to a Zend_Navigation (Page_Uri per item, carrying
 * resource/privilege) and hand-walks it — the same approach the reference app uses — so
 * ACL filtering + href + active-state ride the nav layer. The developer owns the outer
 * container; getHTML emits only the <ul> (place/wrap it yourself).
 *
 * @api
 */
class Tiger_Menu
{
    /** Rendered menu HTML (a <ul>), auth-filtered. $options: ['class'=>ulClass,'id'=>ulId,'org'=>orgId]. */
    public static function getHTML($menuKey, array $options = [])
    {
        [$orgId, $locale] = self::_context($options);
        $tree = (new Tiger_Model_Menu())->tree((string) $menuKey, $orgId, true);
        if (!$tree) {
            return '';
        }

        $nav       = new Zend_Navigation(self::_pages($tree, $locale, $orgId));
        $acl       = Zend_Registry::isRegistered('Zend_Acl') ? Zend_Registry::get('Zend_Acl') : null;
        $identity  = Zend_Auth::getInstance()->getIdentity();
        $role      = ($identity && !empty($identity->role)) ? $identity->role : 'guest';
        $translate = Zend_Registry::isRegistered('Zend_Translate') ? Zend_Registry::get('Zend_Translate') : null;
        $path      = self::_currentPath();

        $inner = self::_renderPages($nav->getPages(), $acl, $role, $translate, $path);
        if ($inner === '') {
            return '';
        }

        $ulClass = isset($options['class']) ? (string) $options['class'] : 'tiger-menu';
        $attr    = ' class="' . htmlspecialchars($ulClass, ENT_QUOTES) . '"';
        if (!empty($options['id'])) {
            $attr .= ' id="' . htmlspecialchars((string) $options['id'], ENT_QUOTES) . '"';
        }
        return '<ul' . $attr . '>' . $inner . '</ul>';
    }

    /** The raw menu tree as a nested array (labels translated, hrefs resolved); NO auth filter. */
    public static function getData($menuKey, $orgId = null)
    {
        [$org, $locale] = self::_context(['org' => $orgId]);
        $tree = (new Tiger_Model_Menu())->tree((string) $menuKey, $org, true);
        return self::_decorate($tree, $locale, $org);
    }

    // ----- rendering ---------------------------------------------------------

    /** Recursively render Zend_Navigation pages to nested <li>/<ul>, ACL-filtered. */
    protected static function _renderPages($pages, $acl, $role, $translate, $path)
    {
        $html = '';
        foreach ($pages as $page) {
            if (!self::_accept($page, $acl, $role)) {
                continue;
            }
            $childHtml   = self::_renderPages($page->getPages(), $acl, $role, $translate, $path);
            $hasChildren = ($childHtml !== '');

            $label = $translate ? $translate->translate($page->getLabel()) : $page->getLabel();
            $href  = (string) $page->getHref();
            $active = ($href !== '' && $href !== '#' && self::_isActive($href, $path));

            // css_class + state classes live on the <li>; dom_id is the <li> id.
            $classes = [];
            if ($page->getClass()) { $classes[] = (string) $page->getClass(); }
            if ($hasChildren)      { $classes[] = 'has-children'; }
            if ($active)           { $classes[] = 'active'; }

            $liAttr = '';
            if ($page->getId())  { $liAttr .= ' id="' . htmlspecialchars((string) $page->getId(), ENT_QUOTES) . '"'; }
            if ($classes)        { $liAttr .= ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES) . '"'; }

            $icon = $page->get('icon');
            $iconHtml = $icon ? '<i class="' . htmlspecialchars((string) $icon, ENT_QUOTES) . '" aria-hidden="true"></i> ' : '';
            $labelEsc = htmlspecialchars((string) $label, ENT_QUOTES);

            $html .= '<li' . $liAttr . '>';
            if ($href === '' || $href === '#') {
                // No link target -> a heading/label item.
                $html .= '<span class="tiger-menu-text">' . $iconHtml . $labelEsc . '</span>';
            } else {
                $target = $page->get('target');
                $rel    = $page->get('rel');
                if ($target === '_blank' && !$rel) { $rel = 'noopener noreferrer'; }
                $a  = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"';
                if ($target) { $a .= ' target="' . htmlspecialchars((string) $target, ENT_QUOTES) . '"'; }
                if ($rel)    { $a .= ' rel="' . htmlspecialchars((string) $rel, ENT_QUOTES) . '"'; }
                $a .= '>' . $iconHtml . $labelEsc . '</a>';
                $html .= $a;
            }
            if ($hasChildren) {
                $html .= '<ul class="tiger-menu-sub">' . $childHtml . '</ul>';
            }
            $html .= '</li>';
        }
        return $html;
    }

    /** ACL gate: an item with a known-but-denied resource is hidden; otherwise shown. */
    protected static function _accept($page, $acl, $role)
    {
        $resource = $page->getResource();
        if (!$resource || !$acl) {
            return true;
        }
        if (!$acl->has($resource)) {
            return true;   // unknown resource isn't gated (mirrors the admin-menu renderer)
        }
        return $acl->isAllowed($role, $resource, $page->getPrivilege() ?: null);
    }

    // ----- data --------------------------------------------------------------

    /** Decorate a raw tree with translated label, resolved href, and active flag. */
    protected static function _decorate(array $nodes, $locale, $orgId)
    {
        $translate = Zend_Registry::isRegistered('Zend_Translate') ? Zend_Registry::get('Zend_Translate') : null;
        $path      = self::_currentPath();
        $out       = [];
        foreach ($nodes as $node) {
            $href = self::_href($node, $locale, $orgId);
            $node['label']    = $translate ? $translate->translate((string) $node['label']) : $node['label'];
            $node['href']     = $href;
            $node['active']   = ($href !== null && $href !== '#' && self::_isActive($href, $path));
            $node['children'] = self::_decorate($node['children'] ?? [], $locale, $orgId);
            $out[] = $node;
        }
        return $out;
    }

    // ----- helpers -----------------------------------------------------------

    /** Build Zend_Navigation page config from the tree (Page_Uri, carrying ACL + custom props). */
    protected static function _pages(array $nodes, $locale, $orgId)
    {
        $pages = [];
        foreach ($nodes as $node) {
            $href = self::_href($node, $locale, $orgId);
            $page = [
                'type'    => 'uri',
                'label'   => (string) $node['label'],
                'uri'     => ($href === null) ? '' : $href,
                'visible' => true,
                'icon'    => $node['icon']        ?? null,   // custom props (via set())
                'target'  => $node['link_target'] ?? null,
                'rel'     => $node['link_rel']     ?? null,
            ];
            if (!empty($node['dom_id']))    { $page['id']        = $node['dom_id']; }
            if (!empty($node['css_class'])) { $page['class']     = $node['css_class']; }
            if (!empty($node['resource']))  { $page['resource']  = $node['resource']; }
            if (!empty($node['privilege'])) { $page['privilege'] = $node['privilege']; }
            $page['pages'] = self::_pages($node['children'] ?? [], $locale, $orgId);
            $pages[] = $page;
        }
        return $pages;
    }

    /** Resolve an item's href: page_key -> the page's slug (tenant/locale-aware), else url, else null. */
    protected static function _href(array $node, $locale, $orgId)
    {
        $pageKey = (string) ($node['page_key'] ?? '');
        if ($pageKey !== '') {
            $page = (new Tiger_Model_Page())->fetchByKey($pageKey, $locale, $orgId, Tiger_Model_Page::TYPE_PAGE);
            if ($page && $page->slug) {
                return '/' . ltrim((string) $page->slug, '/');
            }
            return '#';   // page_key set but unresolved -> a dead placeholder, never a wrong link
        }
        $url = (string) ($node['url'] ?? '');
        return ($url !== '') ? $url : null;   // null = heading (no link)
    }

    /** Active when the current path equals the href or sits beneath it ('/' matches home only). */
    protected static function _isActive($href, $path)
    {
        $h = rtrim((string) $href, '/');
        $p = rtrim((string) $path, '/');
        if ($h === '') {
            return $p === '';   // home
        }
        return $p === $h || strpos($p, $h . '/') === 0;
    }

    /** [orgId, locale] for this call — explicit org wins, else the current identity's org. */
    protected static function _context(array $options)
    {
        $orgId = (isset($options['org']) && $options['org'] !== null)
            ? (string) $options['org']
            : self::_currentOrg();
        return [$orgId, self::_currentLocale()];
    }

    /** The current tenant scope ('' = global when signed out or org-less). */
    protected static function _currentOrg()
    {
        $identity = Zend_Auth::getInstance()->getIdentity();
        return ($identity && !empty($identity->org_id)) ? (string) $identity->org_id : '';
    }

    /** The active request locale (language-only), matching how pages store `locale`. */
    protected static function _currentLocale()
    {
        if (defined('LANG') && LANG) {
            return (string) LANG;
        }
        if (Zend_Registry::isRegistered('Zend_Translate')) {
            $loc = (string) Zend_Registry::get('Zend_Translate')->getLocale();
            if ($loc !== '') {
                return strtok($loc, '_');
            }
        }
        return 'en';
    }

    /** The current request path (for active-state); '' outside an HTTP request (CLI). */
    protected static function _currentPath()
    {
        $front = Zend_Controller_Front::getInstance();
        $request = $front->getRequest();
        return $request ? (string) $request->getPathInfo() : '';
    }
}
