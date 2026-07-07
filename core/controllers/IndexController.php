<?php
/**
 * Default-namespace Core controller.
 *
 * Lives in the `webtigers/tiger-core` package (vendor/), NOT in the app. The app's
 * Bootstrap points ZF1's default-module controller directory here. This is the proof
 * that a request resolves into Tiger-owned code shipped via Composer.
 */
class IndexController extends Zend_Controller_Action
{
    public function indexAction()
    {
        // If an admin picked a CMS page as the home page (tiger.site.home_page), serve
        // it at "/" by forwarding to the CMS renderer — else the built-in landing.
        $homeId = $this->_homePageId();
        if ($homeId !== '') {
            $page = (new Tiger_Model_Page())->findById($homeId);
            if ($page && $page->status === Tiger_Model_Page::STATUS_PUBLISHED) {
                $this->_forward('view', 'page', null, ['cms_page_id' => $homeId]);
                return;
            }
        }

        // Rendered via index/index.phtml, wrapped in the active theme's layout.
        // The app Bootstrap already put theme/skin/themeAssets on the view.
        $this->view->servedBy     = __FILE__;
        $this->view->tigerVersion = Tiger_Version::VERSION;
        $this->view->zendVersion  = Zend_Version::VERSION;
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
