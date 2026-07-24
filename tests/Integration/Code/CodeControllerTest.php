<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Code;

use Code_IndexController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ControllerTestCase;
use Tiger_Model_Code;
use Zend_Session;

/**
 * Code_IndexController — the Code Area admin screens, dispatched through the harness (rendering off).
 *
 * The controller is thin READ+RENDER (every mutation is a Code_Service_Code /api call, covered by the
 * service tests): index is the DataTables shell, edit prefills the CodeMirror editor from the requested
 * `code` row. So the assertion is the shell — the action dispatches, sets its title + view-vars, and the
 * editor form is prefilled from the row (with its version history). Zend_Session unit-test mode is on for
 * the FlashMessenger the base wires in init(). (ACL is the unbypassable Authorization plugin, exercised
 * elsewhere; the harness dispatches the action directly — the service tests own the superadmin gate.)
 */
#[CoversClass(Code_IndexController::class)]
final class CodeControllerTest extends ControllerTestCase
{
    private bool $priorUnitTestMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        parent::tearDown();
    }

    #[Test]
    public function index_renders_the_code_list_shell(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(Code_IndexController::class, 'index', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertSame('Code — Tiger Admin', $view->title);
        $this->assertTrue($view->useDataTables);
    }

    #[Test]
    public function edit_with_no_id_renders_a_blank_new_snippet_form(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(Code_IndexController::class, 'edit', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertStringContainsString('New', $view->title);
        $this->assertNull($view->row, 'no row to edit');
        $this->assertSame([], $view->versions);
        $this->assertNotNull($view->form);
    }

    #[Test]
    public function edit_with_an_id_prefills_the_editor_from_the_row(): void
    {
        $this->loginAs('superadmin');
        $id = (new Tiger_Model_Code())->insert([
            'org_id'       => '',
            'name'         => 'Greeter',
            'description'  => 'a helper',
            'language'     => Tiger_Model_Code::LANG_PHP,
            'code'         => "if (!function_exists('w6_greet')) { function w6_greet() {} }",
            'run_location' => Tiger_Model_Code::LOC_GLOBAL,
            'priority'     => 100,
            'active'       => 1,
            'status'       => Tiger_Model_Code::STATUS_ACTIVE,
        ]);

        $res = $this->dispatchAction(Code_IndexController::class, 'edit', ['id' => $id], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertStringContainsString('Edit', $view->title);
        $this->assertNotNull($view->row, 'the snippet row loaded');
        $this->assertSame('Greeter', $view->row->name);
        $this->assertSame('Greeter', $view->form->getValue('name'), 'the editor form is prefilled from the row');
        $this->assertSame(Tiger_Model_Code::LANG_PHP, $view->form->getValue('language'));
        $this->assertNotNull($view->versions, 'the version history is passed to the editor');
    }
}
