<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_IndexController — the PUBLIC blog front-end (index / article / archives / feed).
 *
 * Thin by design: it resolves DATA from the @api models (Blog_Model_Post,
 * Blog_Model_Taxonomy) and hands it to the view. It bakes no markup — so a theme can
 * override the default view scripts (view-path cascade), and a 3rd-party module can reuse
 * the same models (or a future public /api surface) with entirely its own views. Renders
 * in the active theme's PUBLIC layout (no admin chrome); a missing article/term 404s.
 *
 * Public in configs/acl.ini (guest-allowed). Routed under /blog by Blog_Bootstrap.
 */
class Blog_IndexController extends Tiger_Controller_Action
{
    /** @var Blog_Model_Post */  protected $_posts;
    /** @var Blog_Model_Taxonomy */ protected $_tax;

    /**
     * Initialize the controller and its post + taxonomy models.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->_posts = new Blog_Model_Post();
        $this->_tax   = new Blog_Model_Taxonomy();
    }

    /**
     * /blog — the latest published articles.
     *
     * @return void
     */
    public function indexAction()
    {
        $rows = $this->_posts->published(['locale' => $this->_locale(), 'limit' => 20]);
        $this->view->posts   = $this->_presentAll($rows);
        $this->view->heading = 'Latest';
        $this->view->title   = 'Blog';
    }

    /**
     * /blog/<slug> — a single article.
     *
     * @return void
     * @throws Zend_Controller_Action_Exception when the article slug resolves to nothing (404)
     */
    public function viewAction()
    {
        $post = $this->_posts->resolveArticle((string) $this->getParam('slug', ''), $this->_locale(), Tiger_Model_Org::siteOrgId());
        if (!$post) {
            throw new Zend_Controller_Action_Exception('Article not found', 404);
        }

        $article = $this->_posts->present($post);
        $article['body']       = (new Tiger_Cms_Renderer())->renderBody($post->body, $post->format);
        $article['categories'] = $this->_tax->forPage($post->page_id, Blog_Model_Taxonomy::VOCAB_CATEGORY);
        $article['tags']       = $this->_tax->forPage($post->page_id, Blog_Model_Taxonomy::VOCAB_TAG);

        $this->view->article         = $article;
        $this->view->title           = $article['seo']['title'] !== '' ? $article['seo']['title'] : $post->title;
        $this->view->metaDescription = $article['seo']['description'] !== '' ? $article['seo']['description'] : $article['excerpt'];

        // Contribute the article's SEO to the head registry (title/description/robots/canonical). An
        // article renders via this controller (not PageDispatch), so it can't rely on Seo_Plugin_Head —
        // it calls the resolver directly, guarded so the blog never hard-depends on the SEO module. The
        // excerpt is the description fallback when the author set no SEO description.
        if (class_exists('Seo_Service_Head')) {
            Seo_Service_Head::forRow($post, $this->getRequest(), ['description' => $article['excerpt']]);
        }
    }

    /**
     * /blog/category/<slug>
     *
     * @return void
     */
    public function categoryAction()
    {
        $this->_archive(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Category');
    }

    /**
     * /blog/tag/<slug>
     *
     * @return void
     */
    public function tagAction()
    {
        $this->_archive(Blog_Model_Taxonomy::VOCAB_TAG, 'Tagged');
    }

    /**
     * /blog/feed — an RSS 2.0 feed of recent articles (view owns the XML; theme-overridable).
     *
     * @return void
     */
    public function feedAction()
    {
        $rows = $this->_posts->published(['locale' => $this->_locale(), 'limit' => 20]);
        $this->view->posts = $this->_presentAll($rows);
        $this->view->site  = $this->_siteName();
        $this->getResponse()->setHeader('Content-Type', 'application/rss+xml; charset=utf-8', true);
        $this->_helper->layout()->disableLayout();
    }

    /** Shared archive listing for a category/tag term. */
    protected function _archive($vocabulary, $label)
    {
        $term = $this->_tax->resolveTermBySlug($vocabulary, (string) $this->getParam('slug', ''), $this->_locale(), '');
        if (!$term) {
            throw new Zend_Controller_Action_Exception('Not found', 404);
        }
        $pageIds = $this->_tax->pageIdsForTerm($term->taxonomy_id);
        $rows    = $pageIds ? $this->_posts->published(['locale' => $this->_locale(), 'limit' => 50, 'pageIds' => $pageIds]) : [];

        $this->view->posts   = $this->_presentAll($rows);
        $this->view->term    = ['name' => $term->name, 'slug' => $term->slug, 'vocabulary' => $vocabulary, 'description' => (string) $term->description];
        $this->view->heading = $label . ': ' . $term->name;
        $this->view->title   = $term->name;
        $this->_helper->viewRenderer->setScriptAction('archive');   // both category+tag render archive.phtml
    }

    /** present() every row — accepts a Zend_Db_Table_Rowset (Traversable) or an array. */
    protected function _presentAll($rows)
    {
        $out = [];
        foreach ($rows as $r) { $out[] = $this->_posts->present($r); }
        return $out;
    }

    protected function _locale()
    {
        return defined('LANG') ? LANG : 'en';
    }

    /** Site name from config (for the feed title), with a sane default. */
    protected function _siteName()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $t   = $cfg ? $cfg->get('tiger') : null;
        return ($t && $t->get('site') && $t->site->get('name')) ? (string) $t->site->name : 'Blog';
    }
}
