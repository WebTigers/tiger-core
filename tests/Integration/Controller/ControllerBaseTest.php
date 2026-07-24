<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Controller_Action;
use Tiger_Controller_Admin_Action;
use Zend_Config;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Response_Http;
use Zend_Layout;
use Zend_Registry;
use Zend_Session;
use Zend_Translate;
use Zend_View;

/**
 * The project base controllers — Tiger_Controller_Action (shared conveniences: config/translate/flash
 * handles + a JSON responder) and Tiger_Controller_Admin_Action (sets the `admin` layout once in
 * init() so no admin controller hand-rolls it). Authorization is deliberately NOT here — that's the
 * unbypassable Authorization plugin — so these bases are pure convenience wiring.
 *
 * Driven with the same minimal admin-controller context the real dispatch provides (front-controller
 * module dir + Zend_Layout MVC + an array-backed session for helpers) so the real init() cascade runs
 * under CLI. Thin subclasses expose the protected `_json()` helper and the wired handles.
 */
#[CoversClass(Tiger_Controller_Action::class)]
#[CoversClass(Tiger_Controller_Admin_Action::class)]
final class ControllerBaseTest extends IntegrationTestCase
{
    private bool $priorUnitTestMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;   // array-backed session so the FlashMessenger runs under CLI
        $_SESSION = [];

        Zend_Registry::set('Zend_View', new Zend_View());
        // The ViewRenderer resolves a module's view dir from the front controller; register the
        // default-namespace controller dir (tiger-core's core/controllers) so touching viewRenderer
        // in _json() can locate module "default" under CLI.
        Zend_Controller_Front::getInstance()->addControllerDirectory(TIGER_CORE_PATH . '/core/controllers', 'default');
        Zend_Layout::startMvc();
    }

    protected function tearDown(): void
    {
        Zend_Layout::resetMvcInstance();
        Zend_Controller_Front::getInstance()->resetInstance();
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        $reg = Zend_Registry::getInstance();
        foreach (['Zend_View', 'Zend_Config', 'Zend_Translate'] as $k) {
            if ($reg->offsetExists($k)) { $reg->offsetUnset($k); }
        }
        parent::tearDown();
    }

    /** A concrete Tiger_Controller_Action exposing the protected handles + _json. */
    private function baseController(Zend_Controller_Request_Http $req, Zend_Controller_Response_Http $res): Tiger_Controller_Action
    {
        return new class ($req, $res) extends Tiger_Controller_Action {
            public function config() { return $this->_config; }
            public function translate() { return $this->_translate; }
            public function flash() { return $this->_flash; }
            public function pubJson($data, $status = 200) { $this->_json($data, $status); }
        };
    }

    #[Test]
    public function init_wires_the_config_and_translate_handles_from_the_registry(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['app' => ['name' => 'Tiger']]));
        Zend_Registry::set('Zend_Translate', new Zend_Translate(['adapter' => 'array', 'content' => ['x' => 'y'], 'locale' => 'en']));

        $ctrl = $this->baseController(new Zend_Controller_Request_Http(), new Zend_Controller_Response_Http());

        $this->assertInstanceOf(Zend_Config::class, $ctrl->config());
        $this->assertSame('Tiger', $ctrl->config()->app->name);
        $this->assertInstanceOf(Zend_Translate::class, $ctrl->translate());
        $this->assertNotNull($ctrl->flash(), 'the flash messenger handle is wired');
    }

    #[Test]
    public function init_leaves_the_handles_null_when_nothing_is_registered(): void
    {
        // No Zend_Config / Zend_Translate in the registry — the base guards each with isRegistered().
        $ctrl = $this->baseController(new Zend_Controller_Request_Http(), new Zend_Controller_Response_Http());

        $this->assertNull($ctrl->config());
        $this->assertNull($ctrl->translate());
    }

    #[Test]
    public function json_sets_the_content_type_status_and_encoded_body(): void
    {
        $res  = new Zend_Controller_Response_Http();
        $ctrl = $this->baseController(new Zend_Controller_Request_Http(), $res);
        $ctrl->getHelper('viewRenderer');   // a real dispatch initializes this before the action

        $ctrl->pubJson(['ok' => true, 'name' => 'Thundarr'], 201);

        $this->assertSame(201, $res->getHttpResponseCode());
        $this->assertSame('{"ok":true,"name":"Thundarr"}', $res->getBody());
        $headers = $res->getHeaders();
        $ct = '';
        foreach ($headers as $h) { if (strtolower($h['name']) === 'content-type') { $ct = $h['value']; } }
        $this->assertStringContainsString('application/json', $ct);
        $this->assertFalse($ctrl->getHelper('layout')->isEnabled(), 'the layout is disabled for a JSON body');
    }

    #[Test]
    public function json_does_not_escape_unicode_or_slashes(): void
    {
        $res  = new Zend_Controller_Response_Http();
        $ctrl = $this->baseController(new Zend_Controller_Request_Http(), $res);
        $ctrl->getHelper('viewRenderer');

        $ctrl->pubJson(['path' => '/a/b', 'name' => 'café']);
        $this->assertSame('{"path":"/a/b","name":"café"}', $res->getBody());
    }

    #[Test]
    public function the_admin_base_sets_the_admin_layout_in_init(): void
    {
        $ctrl = new class (new Zend_Controller_Request_Http(), new Zend_Controller_Response_Http()) extends Tiger_Controller_Admin_Action {};
        $this->assertSame('admin', $ctrl->getHelper('layout')->getLayout());
    }
}
