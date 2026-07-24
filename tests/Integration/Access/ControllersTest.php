<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Access;

use Access_OrgController;
use Access_UserController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ModuleControllerTestCase;
use Tiger_Model_Org;
use Tiger_Model_User;

/**
 * The two Access admin controllers — Users and Organizations. Each is thin: index renders the
 * DataTables shell (useDataTables flag), edit renders the identity/org form (create when no id,
 * prefilled when an id resolves to a row). The harness dispatches each action rendering-off and asserts
 * the view model — covering both the new-record and the edit-prefill branch.
 */
#[CoversClass(Access_UserController::class)]
#[CoversClass(Access_OrgController::class)]
final class ControllersTest extends ModuleControllerTestCase
{
    #[Test]
    public function user_index_renders_the_datatables_shell(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Access_UserController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Users', (string) $view->title);
        $this->assertTrue((bool) $view->useDataTables);
    }

    #[Test]
    public function user_edit_renders_a_blank_form_for_a_new_user(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Access_UserController::class, 'edit', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('New User', (string) $view->title);
        $this->assertInstanceOf(\Access_Form_User::class, $view->form);
        $this->assertNull($view->user);
    }

    #[Test]
    public function user_edit_prefills_the_form_for_an_existing_user(): void
    {
        $id = (new Tiger_Model_User())->insert([
            'email'    => 'edituser@example.test',
            'username' => 'edituser',
            'status'   => 'active',
        ]);
        $this->loginAs('admin');
        $this->dispatchAction(Access_UserController::class, 'edit', ['id' => $id], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Edit User', (string) $view->title);
        $this->assertNotNull($view->user);
        $this->assertSame('edituser@example.test', $view->form->getValues()['email']);
    }

    #[Test]
    public function org_index_renders_the_datatables_shell(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Access_OrgController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Organizations', (string) $view->title);
        $this->assertTrue((bool) $view->useDataTables);
    }

    #[Test]
    public function org_edit_renders_a_blank_form_for_a_new_org(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Access_OrgController::class, 'edit', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('New Organization', (string) $view->title);
        $this->assertInstanceOf(\Access_Form_Org::class, $view->form);
        $this->assertNull($view->org);
    }

    #[Test]
    public function org_edit_prefills_the_form_for_an_existing_org(): void
    {
        $id = (new Tiger_Model_Org())->insert([
            'name'   => 'Edit Org',
            'slug'   => 'edit-org-' . substr(md5((string) mt_rand()), 0, 8),
            'status' => 'active',
        ]);
        $this->loginAs('admin');
        $this->dispatchAction(Access_OrgController::class, 'edit', ['id' => $id], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Edit Organization', (string) $view->title);
        $this->assertNotNull($view->org);
        $this->assertSame('Edit Org', $view->form->getValues()['name']);
    }
}
