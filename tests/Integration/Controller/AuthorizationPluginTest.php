<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Controller_Plugin_Authorization;
use Tiger_Model_Org;
use Tiger_Model_OrgUser;
use Tiger_Model_Table;
use Tiger_Model_User;
use Zend_Auth;
use Zend_Controller_Request_Simple;
use Zend_Session;

/**
 * Tiger_Controller_Plugin_Authorization — the unbypassable, deny-by-default authorization gate.
 *
 * This front-controller plugin (not a base-controller preDispatch, so it can't be bypassed by
 * forgetting to extend a base) resolves the caller's LIVE role every request and authorizes the
 * target controller resource. The two security-load-bearing pieces are exercised here directly:
 *
 *   - `_resolveRole()` — the "live role" guarantee: the session carries only who+which-org; the role
 *     is read FRESH from `org_user` every request, so a revoked/changed membership takes effect on the
 *     very next request. Guests resolve to `guest`; a LOCKED (suspended) session resolves to `guest`
 *     everywhere until re-verified. It also stamps the model actor/org.
 *   - `_resourceFor()` — the ACL resource for a dispatch = the controller class name (ZF1 convention),
 *     StudlyCased, module-prefixed for non-default modules.
 *
 * The full preDispatch → deny → redirect/403 cycle needs the front controller + an exiting redirector,
 * so it's covered at the functional/smoke level; here we lock the decision logic those paths hang off.
 *
 * Uses Zend's documented unit-test session mode (array-backed) so `isLocked()` (a session read) runs
 * for real under CLI.
 */
#[CoversClass(Tiger_Controller_Plugin_Authorization::class)]
final class AuthorizationPluginTest extends IntegrationTestCase
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

    /** A subclass that exposes the two protected decision methods for direct testing. */
    private function plugin(): Tiger_Controller_Plugin_Authorization
    {
        return new class extends Tiger_Controller_Plugin_Authorization {
            public function pubResolveRole() { return $this->_resolveRole(); }
            public function pubResourceFor($request) { return $this->_resourceFor($request); }
        };
    }

    /** Seed a user + org + an active membership carrying $role; sign the user in. Returns [userId, orgId]. */
    private function signInWithMembership(string $role): array
    {
        $userId = (new Tiger_Model_User())->insert(['email' => $role . '@authz.test', 'status' => 'active']);
        $orgId  = (new Tiger_Model_Org())->insert(['name' => 'Authz ' . $role, 'slug' => 'authz-' . $role]);
        (new Tiger_Model_OrgUser())->insert(['org_id' => $orgId, 'user_id' => $userId, 'role' => $role]);
        // The identity deliberately claims a DIFFERENT role, to prove resolveRole reads the LIVE one.
        $this->login($userId, $orgId, 'user');
        return [$userId, $orgId];
    }

    private function req(string $controller, string $module = '', string $action = 'index'): Zend_Controller_Request_Simple
    {
        $r = new Zend_Controller_Request_Simple();
        $r->setControllerName($controller)->setActionName($action);
        if ($module !== '') { $r->setModuleName($module); }
        return $r;
    }

    // ----- _resolveRole (the live-role guarantee) ----------------------------------------------

    #[Test]
    public function a_request_with_no_identity_resolves_to_guest(): void
    {
        $this->logout();
        $this->assertSame('guest', $this->plugin()->pubResolveRole());
    }

    #[Test]
    public function the_role_is_read_live_from_the_membership_not_the_session(): void
    {
        $this->signInWithMembership('manager');   // identity says 'user', membership says 'manager'
        $this->assertSame('manager', $this->plugin()->pubResolveRole(), 'the LIVE org_user role wins over the session role');
    }

    #[Test]
    public function revoking_the_membership_drops_to_the_base_authenticated_role_next_request(): void
    {
        [$userId, $orgId] = $this->signInWithMembership('admin');
        $this->assertSame('admin', $this->plugin()->pubResolveRole());

        // Revoke the membership (soft-delete) — the very next resolve must fall back to the base role.
        (new Tiger_Model_OrgUser())->softDelete(['org_id = ?' => $orgId, 'user_id = ?' => $userId]);
        $this->assertSame('user', $this->plugin()->pubResolveRole(), 'a revoked membership takes effect immediately');
    }

    #[Test]
    public function a_locked_session_resolves_to_guest(): void
    {
        $this->signInWithMembership('admin');
        // Suspend the session (lock screen) — authorize as guest everywhere until re-verified.
        (new \Tiger_Service_Authentication())->lock();
        $this->assertSame('guest', $this->plugin()->pubResolveRole(), 'a locked session is treated as guest');
    }

    #[Test]
    public function resolving_the_role_stamps_the_model_actor_and_org(): void
    {
        Tiger_Model_Table::setActor(null);
        Tiger_Model_Table::setOrg('');
        [$userId, $orgId] = $this->signInWithMembership('manager');

        $this->plugin()->pubResolveRole();

        $this->assertSame($userId, Tiger_Model_Table::actor(), 'the acting user is stamped for created_by/updated_by');
        $this->assertSame($orgId, Tiger_Model_Table::org(), 'the active org (tenant) is stamped');
    }

    // ----- _resourceFor (controller → ACL resource) --------------------------------------------

    #[Test]
    public function a_default_namespace_controller_maps_to_its_controller_class(): void
    {
        $this->assertSame('IndexController', $this->plugin()->pubResourceFor($this->req('index')));
    }

    #[Test]
    public function a_module_controller_is_prefixed_with_the_studly_module(): void
    {
        $this->assertSame('Cms_AdminController', $this->plugin()->pubResourceFor($this->req('admin', 'cms')));
    }

    #[Test]
    public function hyphenated_controller_and_module_names_studly_case(): void
    {
        $this->assertSame('MyMod_UserAdminController', $this->plugin()->pubResourceFor($this->req('user-admin', 'my-mod')));
    }

    #[Test]
    public function an_empty_controller_name_has_no_resource(): void
    {
        $this->assertNull($this->plugin()->pubResourceFor($this->req('')));
    }
}
