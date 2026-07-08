<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog module bootstrap.
 *
 * First-party blog/articles feature. An article is a `page` row (type='article') whose
 * scalar metadata rides in page.meta; this module is the authoring surface + the two
 * relational tables it owns (taxonomy, page_taxonomy). The content engine itself stays
 * in the platform layer (Tiger_Model_Page, the renderer, the page dispatcher).
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource autoloader,
 * so Blog_Model_* (models/), Blog_Service_* (services/) and Blog_Form_* (forms/) load by
 * convention; controllers load via the registered module dir; configs/acl.ini and
 * languages/ are picked up by the core globs.
 */
class Blog_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /**
     * Public front-end routes under /blog. The rewrite router checks routes newest-first,
     * so ORDER here matters: the admin route (/blog/post → the authoring controller) is added
     * LAST so it shadows the /blog/:slug article route for that one path. Everything else —
     * /blog (index, via the default module route), /blog/<slug>, /blog/category|tag/<slug>,
     * /blog/feed — resolves to Blog_IndexController. The words post/category/tag/feed are
     * therefore reserved article slugs (enforced in Blog_Service_Post::save).
     */
    protected function _initBlogRoutes()
    {
        $router = Zend_Controller_Front::getInstance()->getRouter();

        $router->addRoute('blog_single', new Zend_Controller_Router_Route(
            'blog/:slug', ['module' => 'blog', 'controller' => 'index', 'action' => 'view']));
        $router->addRoute('blog_category', new Zend_Controller_Router_Route(
            'blog/category/:slug', ['module' => 'blog', 'controller' => 'index', 'action' => 'category']));
        $router->addRoute('blog_tag', new Zend_Controller_Router_Route(
            'blog/tag/:slug', ['module' => 'blog', 'controller' => 'index', 'action' => 'tag']));
        $router->addRoute('blog_feed', new Zend_Controller_Router_Route(
            'blog/feed', ['module' => 'blog', 'controller' => 'index', 'action' => 'feed']));

        // Added last → checked first → /blog/post stays the admin list (not an article slug).
        $router->addRoute('blog_admin', new Zend_Controller_Router_Route(
            'blog/post', ['module' => 'blog', 'controller' => 'post', 'action' => 'index']));
    }
}
