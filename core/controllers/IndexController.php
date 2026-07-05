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
        // Rendered via index/index.phtml, wrapped in the active theme's layout.
        // The app Bootstrap already put theme/skin/themeAssets on the view.
        $this->view->servedBy     = __FILE__;
        $this->view->tigerVersion = Tiger_Version::VERSION;
        $this->view->zendVersion  = Zend_Version::VERSION;
    }
}
