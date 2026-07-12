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
        }
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
