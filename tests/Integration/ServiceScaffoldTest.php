<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration;

use Access_Service_User;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Auth;
use Zend_Registry;

// A module `/api` service isn't on the test autoload path (no module resource loader in the harness),
// but it only extends the autoloadable Tiger_Service_Service — so require the file and dispatch it.
require_once TIGER_CORE_PATH . '/modules/access/services/User.php';

/**
 * Proves the Wave-3 `/api`-service scaffolding on IntegrationTestCase (`login()`/`loginAs()`/`logout()`)
 * works against a REAL admin-gated service (`Access_Service_User`) and the REAL shipped ACL policy —
 * so the service-test wave can rely on it: an authenticated identity reaches the service, the real
 * `acl.ini` rule decides, and the deny path (guest) is genuinely denied. This is the harness gate that
 * unblocks Signup/System_Modules/Code service tests.
 */
final class ServiceScaffoldTest extends IntegrationTestCase
{
    #[Test]
    public function login_sets_the_zend_auth_identity_and_the_model_actor(): void
    {
        $this->login('u-42', 'o-7', 'admin');

        $identity = Zend_Auth::getInstance()->getIdentity();
        $this->assertIsObject($identity);
        $this->assertSame('u-42', $identity->user_id);
        $this->assertSame('o-7', $identity->org_id);
        $this->assertSame('admin', $identity->role);

        // The model actor/org are stamped too — an insert now carries created_by/org_id.
        $media = new \Tiger_Model_Media();
        $id = $media->insert(['org_id' => 'o-7', 'filename' => 's.txt', 'mime_type' => 'text/plain', 'storage_key' => 'k', 'disk' => 'local', 'kind' => 'file']);
        $row = $media->find($id)->current();
        $this->assertSame('u-42', $row->created_by, 'actor stamped from the logged-in identity');
    }

    #[Test]
    public function the_real_acl_policy_is_registered_and_decides(): void
    {
        $this->loginAs('admin');
        $acl = Zend_Registry::get('Zend_Acl');

        // The shipped modules/access/configs/acl.ini allows admin on the service, denies everyone below.
        $this->assertTrue($acl->has('Access_Service_User'), 'the module resource loaded from acl.ini');
        $this->assertTrue($acl->isAllowed('admin', 'Access_Service_User'), 'admin allowed per shipped rule');
        $this->assertFalse($acl->isAllowed('guest', 'Access_Service_User'), 'guest denied per shipped rule');
        $this->assertFalse($acl->isAllowed('user', 'Access_Service_User'), 'a plain user is denied too');
    }

    #[Test]
    public function guest_is_denied_dispatching_a_real_admin_service(): void
    {
        // No login → guest. The service's _isAdmin() gate must refuse before doing any work.
        $this->login('anon', 'o-1', 'guest');
        $res = (new Access_Service_User(['action' => 'datatable']))->getResponse();

        $this->assertSame(0, (int) $res->result, 'guest is denied');
        $messages = json_encode($res->messages ?? []);
        $this->assertStringContainsString('not_allowed', $messages, 'the ACL denial fired');
    }

    #[Test]
    public function an_admin_passes_the_gate_on_the_same_service(): void
    {
        $this->loginAs('admin');
        $res = (new Access_Service_User(['action' => 'datatable', 'draw' => 1, 'start' => 0, 'length' => 10]))->getResponse();

        // We don't assert the payload shape here (that's the service's own test) — only that the ACL
        // gate did NOT deny an admin: the response is not the not_allowed refusal.
        $this->assertStringNotContainsString('not_allowed', json_encode($res->messages ?? []), 'admin cleared the gate');
    }

    #[Test]
    public function logout_reverts_to_guest(): void
    {
        $this->loginAs('admin');
        $this->assertIsObject(Zend_Auth::getInstance()->getIdentity());
        $this->logout();
        $this->assertNull(Zend_Auth::getInstance()->getIdentity(), 'identity cleared → guest');
    }
}
