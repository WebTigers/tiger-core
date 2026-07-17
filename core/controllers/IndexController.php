<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Default-namespace Core controller.
 *
 * Lives in the `webtigers/tiger-core` package (vendor/), NOT in the app. The app's
 * Bootstrap points ZF1's default-module controller directory here. This is the proof
 * that a request resolves into Tiger-owned code shipped via Composer.
 */
class IndexController extends Zend_Controller_Action
{
    /**
     * Serve the home page at "/": an admin-chosen CMS page, else the active theme's shipped
     * home (`content/index.phtml`), else the built-in landing.
     *
     * @return void
     */
    public function indexAction()
    {
        // 1) An admin picked a CMS page as the home page (tiger.site.home_page)? Serve it.
        $homeId = $this->_homePageId();
        if ($homeId !== '') {
            $page = (new Tiger_Model_Page())->findById($homeId);
            if ($page && $page->status === Tiger_Model_Page::STATUS_PUBLISHED) {
                $this->_forward('view', 'page', null, ['cms_page_id' => $homeId]);
                return;
            }
        }

        // 2) The active theme ships its own home (content/index.phtml)? Serve that — so "/" and the
        //    theme's stock "/index.html" link resolve to the same page (via PageController).
        if (Tiger_Theme::dir() !== '' && is_file(Tiger_Theme::dir() . '/content/index.phtml')) {
            $this->_forward('theme-content', 'page', null, ['theme_content_slug' => 'index']);
            return;
        }

        // 3) Rendered via index/index.phtml, wrapped in the active theme's layout.
        // The app Bootstrap already put theme/skin/themeAssets on the view.
        $this->view->servedBy     = __FILE__;
        $this->view->tigerVersion = Tiger_Version::VERSION;
        $this->view->zendVersion  = Zend_Version::VERSION;
    }

    /**
     * `/vibe` — the SaaS-startup / "vibe coding" pitch (the former home page). A shipped marketing
     * page; the view owns its content. Routed via _initMarketingAliases.
     *
     * @return void
     */
    public function vibeAction()
    {
        // view: index/vibe.phtml — nothing to wire; it's static marketing.
    }

    /**
     * `/agency` — the agency story (one client or a hundred). A shipped marketing page.
     *
     * @return void
     */
    public function agencyAction()
    {
        // view: index/agency.phtml
    }

    /** The configured home-page id (tiger.site.home_page), or '' for the built-in landing. */
    protected function _homePageId()
    {
        if (!Zend_Registry::isRegistered('Zend_Config')) {
            return '';
        }
        $cfg  = Zend_Registry::get('Zend_Config');
        $site = $cfg->get('tiger') ? $cfg->tiger->get('site') : null;
        return $site ? (string) $site->get('home_page') : '';
    }
}
