<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use Agent_AdminController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ControllerTestCase;
use Tiger_Agent_Provider_Factory;
use Zend_Config;
use Zend_Registry;
use Zend_Session;
use Zend_Translate;

/**
 * Agent_AdminController — the TigerAgent settings screen shell (Wave 6).
 *
 * A thin admin controller: it prefills the settings form from live config and hands the view the
 * provider roster + the connected/enabled/crypto-ready flags; the actual save is the `/api` call to
 * Agent_Service_Settings (covered by SettingsServiceTest). We dispatch `indexAction` with rendering
 * off (ControllerTestCase) and assert the view vars the `.phtml` reads — the branch/assignment logic,
 * not the markup.
 */
#[CoversClass(Agent_AdminController::class)]
final class AdminControllerTest extends ControllerTestCase
{
    private bool $priorUnitTestMode;

    protected function setUp(): void
    {
        parent::setUp();
        // The admin base controller resolves the FlashMessenger helper (a session); run in ZF session
        // unit-test mode so no real session is started in CLI.
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];

        // The action titles the page via the registered translator; provide a minimal one.
        Zend_Registry::set('Zend_Translate', new Zend_Translate([
            'adapter' => 'array',
            'content' => ['agent.settings.title' => 'AI Agent'],
            'locale'  => 'en',
        ]));
        Zend_Registry::set('Zend_Config', new Zend_Config([], true));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        parent::tearDown();
    }

    #[Test]
    public function index_prefills_the_form_and_exposes_the_settings_view_model(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Agent_AdminController::class, 'index', [], 'GET');

        $view = $this->controller()->view;

        $this->assertStringContainsString('AI Agent', (string) $view->title, 'the title runs through the translator');
        $this->assertInstanceOf(\Agent_Form_Settings::class, $view->form, 'the settings form is handed to the view');

        // The provider roster the dropdown renders is the live factory options.
        $this->assertSame(Tiger_Agent_Provider_Factory::options(), $view->providers);
        $this->assertArrayHasKey('anthropic', (array) $view->providers);

        // Prefill + capability flags the view branches on.
        $this->assertSame('anthropic', $view->provider, 'defaults to anthropic with no stored provider');
        $this->assertNotSame('', (string) $view->model, 'a default model is offered');
        $this->assertIsBool($view->enabled);
        $this->assertIsBool($view->connected);
        $this->assertIsBool($view->cryptoReady);
        $this->assertContains($view->modeMax, ['ask', 'auto', 'yolo']);
    }

    #[Test]
    public function index_reflects_stored_provider_and_model_config(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['agent' => ['provider' => 'openai', 'model' => 'gpt-4o']],
        ], true));
        $this->loginAs('admin');
        $this->dispatchAction(Agent_AdminController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertSame('openai', $view->provider, 'the stored provider is surfaced');
        $this->assertSame('gpt-4o', $view->model, 'the stored model is surfaced');
    }
}
