<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * PageController — renders a CMS `page` (default namespace).
 *
 * Reached only via Tiger_Controller_Plugin_PageDispatch, which resolves the slug and
 * passes the page id. It renders the page through Tiger_Cms_Renderer and outputs:
 *
 *   - page HAS a layout_key  -> the CMS layout is a full-page template, so the output
 *     is self-contained: disable the theme layout and send the HTML as the body.
 *   - page has NO layout_key -> the body is content only, so it renders inside the
 *     active theme's PUBLIC layout (site header/footer/chrome) via page/view.phtml.
 *
 * Public in acl.ini. A missing page throws a 404 (handled by ErrorController).
 */
class PageController extends Tiger_Controller_Action
{
    /**
     * Render the resolved CMS page, self-contained or wrapped in the theme's public layout.
     *
     * @return void
     * @throws Zend_Controller_Action_Exception when the page id resolves to no page (404).
     */
    public function viewAction()
    {
        $pageId = $this->getParam('cms_page_id');
        $page   = $pageId ? (new Tiger_Model_Page())->findById($pageId) : null;

        if (!$page) {
            throw new Zend_Controller_Action_Exception('Page not found', 404);
        }

        $html = (new Tiger_Cms_Renderer())->render($page);
        $this->getResponse()->setHeader('Content-Type', 'text/html; charset=utf-8');

        // Per-page head + body scripts (admin-authored, from the page `meta`). These are output
        // VERBATIM so external CSS/JS and inline scripts actually run on the public page.
        $meta = [];
        if (!empty($page->meta)) {
            $decoded = is_array($page->meta) ? $page->meta : json_decode((string) $page->meta, true);
            if (is_array($decoded)) { $meta = $decoded; }
        }
        $head    = (string) ($meta['head_html'] ?? '');
        $scripts = (string) ($meta['body_scripts'] ?? '');
        $desc    = trim((string) ($meta['description'] ?? ''));
        if ($desc !== '') {
            $head = '<meta name="description" content="' . htmlspecialchars($desc, ENT_QUOTES) . '">' . "\n" . $head;
        }

        if (!empty($page->layout_key)) {
            // Self-contained CMS layout owns the whole document — splice head/scripts into it.
            if ($head !== '')    { $html = self::_injectBefore($html, '</head>', $head); }
            if ($scripts !== '') { $html = self::_injectBefore($html, '</body>', $scripts); }
            $this->_helper->layout()->disableLayout();
            $this->_helper->viewRenderer->setNoRender(true);
            $this->getResponse()->setBody($html);
        } else {
            // Body only — wrap in the theme's public layout (see page/view.phtml).
            $this->view->title       = $page->title;
            $this->view->cmsContent  = $html;
            $this->view->pageHead    = $head;      // the theme layout emits this in <head>
            $this->view->pageScripts = $scripts;   // …and this before </body>
            $this->view->pageMeta    = $meta;      // whole meta -> the layout can read theme hints (e.g. skin)
        }
    }

    /**
     * Render a THEME's bundled static page (a `content/<slug>.phtml` body partial) inside the
     * active theme layout. Reached only via Tiger_Controller_Plugin_ThemeContent, which resolves
     * the slug and confirms no controller/CMS-page claimed it first. The partial may lead with a
     *   <!-- tiger:page title="…" skin="…" css="demos/x.css" view="view.foo" -->
     * hint line declaring the page's title + per-page head/scripts (the axes that vary across a
     * vendor theme's pages); the rest is the page body, wrapped by the shared layout.
     *
     * @return void
     * @throws Zend_Controller_Action_Exception when the slug resolves to no partial (404).
     */
    public function themeContentAction()
    {
        $slug = (string) $this->getParam('theme_content_slug', '');
        $dir  = Tiger_Theme::dir();
        $file = ($dir !== '' && $slug !== '') ? $dir . '/content/' . $slug . '.phtml' : '';

        if ($file === '' || !is_file($file)) {
            throw new Zend_Controller_Action_Exception('Page not found', 404);
        }

        $raw  = (string) file_get_contents($file);
        $meta = Tiger_Theme::hint($raw, 'tiger:page');
        $body = preg_replace('/^\s*<!--\s*tiger:page\b.*?-->\s*/s', '', $raw, 1);   // strip the hint line

        // Per-page head/scripts from the hint, resolved against the theme's own asset base.
        $base = Tiger_Theme::assetBase();
        $head = '';
        foreach (array_filter(array_map('trim', explode(',', (string) ($meta['css'] ?? '')))) as $css) {
            $head .= '<link rel="stylesheet" href="' . htmlspecialchars($base . '/css/' . $css, ENT_QUOTES) . '">' . "\n";
        }
        $scripts = '';
        if (!empty($meta['view'])) {
            $scripts .= '<script src="' . htmlspecialchars($base . '/js/views/' . $meta['view'] . '.js', ENT_QUOTES) . '"></script>' . "\n";
        }

        $this->view->title       = $meta['title'] ?? ucfirst(str_replace('-', ' ', $slug));
        $this->view->pageMeta     = $meta;         // 'skin' -> the layout selects the skin file
        $this->view->cmsContent   = $body;         // page/view.phtml echoes this; the theme layout wraps it
        $this->view->pageHead     = $head;
        $this->view->pageScripts  = $scripts;
        $this->_helper->viewRenderer->setScriptAction('view');   // reuse core/views/scripts/page/view.phtml
    }

    /** Splice a fragment immediately before a tag in an HTML string (append if the tag is absent). */
    protected static function _injectBefore($html, $tag, $fragment)
    {
        $pos = stripos($html, $tag);
        return $pos !== false
            ? substr($html, 0, $pos) . $fragment . "\n" . substr($html, $pos)
            : $html . $fragment;
    }
}
