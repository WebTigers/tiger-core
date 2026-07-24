<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Ally;

use Ally_IndexController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Response_Http;
use Zend_Layout;
use Zend_Registry;
use Zend_Session;
use Zend_View;

/**
 * Ally_IndexController — the (thin) admin screen for the accessibility inspector. It renders the tool;
 * every scan is an /api call. init() sets the admin layout + the flash messenger; indexAction just
 * seeds the page title.
 *
 * The test stands up the minimal admin-controller context (front controller module dir + Zend_Layout +
 * an array-backed session for the FlashMessenger) so the real init() runs, then asserts the action.
 */
#[CoversClass(Ally_IndexController::class)]
final class IndexControllerTest extends IntegrationTestCase
{
    private bool $priorUnitTestMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;   // array-backed session so FlashMessenger runs under CLI
        $_SESSION = [];

        Zend_Registry::set('Zend_View', new Zend_View());
        Zend_Controller_Front::getInstance()->addControllerDirectory(TIGER_CORE_PATH . '/modules/ally/controllers', 'ally');
        Zend_Layout::startMvc();
    }

    protected function tearDown(): void
    {
        Zend_Layout::resetMvcInstance();
        Zend_Controller_Front::getInstance()->resetInstance();
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('Zend_View')) { $reg->offsetUnset('Zend_View'); }
        parent::tearDown();
    }

    #[Test]
    public function index_action_sets_the_page_title(): void
    {
        $req = new Zend_Controller_Request_Http();
        $req->setModuleName('ally')->setControllerName('index')->setActionName('index');
        $res = new Zend_Controller_Response_Http();

        $ctrl = new Ally_IndexController($req, $res);
        // Touch the ViewRenderer so it initializes the controller's view (a real dispatch does this
        // before the action runs); indexAction seeds $this->view->title.
        $ctrl->getHelper('viewRenderer');
        $ctrl->indexAction();

        $this->assertStringContainsString('Accessibility', (string) $ctrl->view->title);
        // init() set the admin layout for the shell.
        $this->assertSame('admin', $ctrl->getHelper('layout')->getLayout());
    }
}
