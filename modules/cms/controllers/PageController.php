<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms_PageController — the CMS authoring surface, rendered in the PUMA admin shell.
 *
 * Two thin screens: a DataTables list of all content (index) and the page editor
 * (edit). Per the thin-controller rule this class only READS and RENDERS — every
 * mutation goes through the /api service Cms_Service_Page (save/delete/restore).
 * ACL-gated to admin+ (modules/cms/configs/acl.ini) by the unbypassable
 * Authorization plugin; a guest is bounced to login, a non-admin gets a themed 403.
 */
class Cms_PageController extends Tiger_Controller_Admin_Action
{
    /** @var Tiger_Model_Page */
    protected $_pages;

    /**
     * Set up the controller — every action renders inside the admin layout.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->_pages = new Tiger_Model_Page();
    }

    /**
     * The content list — just the shell. The rows load over AJAX from the /api
     * service (Cms_Service_Page::datatable), per the client/server paradigm: the
     * server renders the page, the browser fetches the data as a Tiger Webservice.
     *
     * @return void
     */
    public function indexAction()
    {
        $this->view->title         = 'Content — Tiger Admin';
        $this->view->useDataTables = true;   // the layout loads jQuery + DataTables when set

        // The ACTIVE theme's page templates (served from files) — surfaced so an author can
        // CUSTOMIZE one: fork it into an editable page that overrides the file. Flag which are
        // already customized (a published/draft page row claims the slug).
        $templates = Tiger_Theme::pages();
        if ($templates) {
            $bySlug = [];
            foreach ($this->_pages->fetchAll(
                $this->_pages->activeSelect()->where('type = ?', Tiger_Model_Page::TYPE_PAGE)
            ) as $p) {
                if ($p->slug !== null && $p->slug !== '') { $bySlug[$p->slug] = $p->page_id; }
            }
            foreach ($templates as &$t) { $t['page_id'] = $bySlug[$t['slug']] ?? ''; }
            unset($t);
        }
        $man = Tiger_Theme::manifest();
        $this->view->themeName      = (string) ($man['name'] ?? '');
        $this->view->themeTemplates = $templates;
    }

    /**
     * Create (no id) or edit (id) — renders the form; saving is an /api call, not a post-back.
     *
     * @return void
     */
    public function editAction()
    {
        $id   = (string) $this->getParam('id', '');
        $page = $id !== '' ? $this->_pages->findById($id) : null;

        $form = new Cms_Form_Page();
        if ($page) {
            $form->populate($this->_editValues($page));
        }

        $this->view->title    = ($page ? 'Edit' : 'New') . ' Page — Tiger Admin';
        $this->view->form     = $form;
        $this->view->page     = $page;
        $this->view->versions = $page
            ? (new Tiger_Model_PageVersion())->recentForPage($id)
            : [];
    }

    /**
     * Full-screen GrapesJS visual builder for an existing page. Renders its OWN minimal
     * document (admin shell disabled) so the builder owns the viewport; saving goes
     * through the /api service (Cms_Service_Page::saveDesign). The canvas restores
     * losslessly from meta.builder when present, else seeds from the page's current body.
     *
     * @return void
     */
    public function designAction()
    {
        $id   = (string) $this->getParam('id', '');
        $page = $id !== '' ? $this->_pages->findById($id) : null;
        if (!$page) {
            $this->_helper->redirector->gotoUrl('/cms/page');
            return;
        }

        $meta = [];
        if (!empty($page->meta)) {
            $decoded = is_array($page->meta) ? $page->meta : json_decode((string) $page->meta, true);
            if (is_array($decoded)) { $meta = $decoded; }
        }

        // Pre-render each menu so the builder's Menu component shows a LIVE preview in the canvas
        // (it still exports the [menu] shortcode, staying dynamic + auth-filtered at view time).
        $menus = [];
        foreach ((new Tiger_Model_Menu())->keys() as $key) {
            $menus[$key] = Tiger_Menu::getHTML($key);
        }

        // The ACTIVE theme's builder components (its components/*.phtml) + the CSS to load into
        // the GrapesJS canvas so those blocks preview in the theme's own style (THEMES.md Tier 2).
        $manifest = Tiger_Theme::manifest();

        $this->_helper->layout()->disableLayout();   // full-screen — the view is a complete document
        $this->view->title       = $page->title;
        $this->view->page        = $page;
        $this->view->projectData = !empty($meta['builder']) ? $meta['builder'] : null;
        $this->view->menus       = $menus;
        $this->view->themeBlocks = Tiger_Theme::components();
        $this->view->canvasCss   = isset($manifest['canvasCss']) ? (array) $manifest['canvasCss'] : [];
    }

    /** Map a page row to editor form values. */
    protected function _editValues($page)
    {
        $meta = [];
        if (!empty($page->meta)) {
            $decoded = is_array($page->meta) ? $page->meta : json_decode((string) $page->meta, true);
            if (is_array($decoded)) { $meta = $decoded; }
        }
        return [
            'page_id'          => $page->page_id,
            'title'            => $page->title,
            'slug'             => $page->slug,
            'page_key'         => $page->page_key,
            'type'             => $page->type,
            'format'           => $page->format,
            'status'           => $page->status,
            'locale'           => $page->locale,
            'layout_key'       => $page->layout_key,
            'published_at'     => $page->published_at,
            'body'             => $page->body,
            'meta_description' => $meta['seo']['description'] ?? ($meta['description'] ?? ''),
            'head_html'        => $meta['head_html']    ?? '',
            'body_scripts'     => $meta['body_scripts'] ?? '',
        ];
    }
}
