<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Nav;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Acl_Acl;
use Tiger_Model_Config;
use Zend_Auth;
use Zend_Auth_Storage_NonPersistent;
use Zend_Registry;

// `System_Service_Nav` resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * System_Service_Nav — persists a user's admin-menu sort order. A drag posts the new order of a
 * group's item keys; the service writes one `tiger.nav.<group>.<key>.sort` config row per item at
 * USER scope (inside a real `_transaction`, which nests via the harness savepoint adapter). Admin+,
 * and deliberately FAIL-SOFT: a malformed/oversized payload is a no-op success, never an error.
 *
 * These tests characterize the ACL gate, the config rows written (per-item index order at user
 * scope), the key/group sanitization, and every fail-soft branch.
 */
#[CoversClass(System_Service_Nav::class)]
final class NavServiceTest extends IntegrationTestCase
{
    private function dispatch(array $msg): object
    {
        return (new System_Service_Nav($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- ACL -------------------------------------------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_nav_sort_to_admin_and_up(): void
    {
        $this->loginAs('admin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('System_Service_Nav'), 'the acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('admin', 'System_Service_Nav'));
        $this->assertFalse($acl->isAllowed('user', 'System_Service_Nav'), 'a plain user is denied');
        $this->assertFalse($acl->isAllowed('guest', 'System_Service_Nav'));
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $this->login('anon', 'o-1', 'guest');
        $res = $this->dispatch(['action' => 'sort', 'group' => 'root', 'keys' => '["a","b"]']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }

    // ---- the write path --------------------------------------------------------------------------

    #[Test]
    public function sort_writes_one_index_ordered_config_row_per_item_at_user_scope(): void
    {
        $this->login('u-nav', 'org-test', 'admin');
        $res = $this->dispatch(['action' => 'sort', 'group' => 'root', 'keys' => '["dashboard","content","users"]']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $u   = Tiger_Model_Config::SCOPE_USER;
        $this->assertSame('0', $cfg->get($u, 'u-nav', 'tiger.nav.root.dashboard.sort'));
        $this->assertSame('1', $cfg->get($u, 'u-nav', 'tiger.nav.root.content.sort'));
        $this->assertSame('2', $cfg->get($u, 'u-nav', 'tiger.nav.root.users.sort'));
        // Global scope is untouched — this is a private per-user override.
        $this->assertNull($cfg->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.nav.root.dashboard.sort'));
    }

    #[Test]
    public function sort_sanitizes_the_group_and_item_keys_and_skips_empty_ones(): void
    {
        $this->login('u-nav', 'org-test', 'admin');
        // Group with junk chars → stripped to 'settings'; a key with junk → stripped; an all-junk key skipped.
        $res = $this->dispatch(['action' => 'sort', 'group' => 'set/tings!', 'keys' => '["me!!nu","@@@","tools"]']);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $u   = Tiger_Model_Config::SCOPE_USER;
        // 'me!!nu' → 'menu' at index 0; '@@@' stripped to '' → skipped (does NOT consume an index);
        // 'tools' → index 1.
        $this->assertSame('0', $cfg->get($u, 'u-nav', 'tiger.nav.settings.menu.sort'));
        $this->assertSame('1', $cfg->get($u, 'u-nav', 'tiger.nav.settings.tools.sort'));
    }

    // ---- fail-soft branches ----------------------------------------------------------------------

    #[Test]
    public function a_malformed_keys_payload_is_a_no_op_success(): void
    {
        $this->login('u-nav', 'org-test', 'admin');
        // `keys` isn't a JSON array → fail-soft: success with nothing written.
        $res = $this->dispatch(['action' => 'sort', 'group' => 'root', 'keys' => 'not-json']);

        $this->assertSame(1, (int) $res->result, 'fail-soft returns success');
        $this->assertStringNotContainsString('error', strtolower($this->messages($res)));
        $this->assertNull((new Tiger_Model_Config())->get(Tiger_Model_Config::SCOPE_USER, 'u-nav', 'tiger.nav.root.x.sort'));
    }

    #[Test]
    public function an_empty_group_is_a_no_op_success(): void
    {
        $this->login('u-nav', 'org-test', 'admin');
        $res = $this->dispatch(['action' => 'sort', 'group' => '', 'keys' => '["a"]']);
        $this->assertSame(1, (int) $res->result, 'an empty group after sanitization is fail-soft');
    }

    #[Test]
    public function an_admin_identity_with_no_user_id_is_told_login_is_required(): void
    {
        // An authenticated admin whose identity carries no user_id clears the ACL gate but can't own a
        // per-user override → the login_required branch (there's no user to scope the rows to).
        $auth = Zend_Auth::getInstance();
        $auth->setStorage(new Zend_Auth_Storage_NonPersistent());
        $auth->getStorage()->write((object) ['user_id' => '', 'org_id' => 'org-test', 'role' => 'admin']);
        Zend_Registry::set('Zend_Acl', new Tiger_Acl_Acl());

        $res = $this->dispatch(['action' => 'sort', 'group' => 'root', 'keys' => '["a"]']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('login_required', $this->messages($res));
    }

    #[Test]
    public function an_oversized_key_list_is_a_no_op_success(): void
    {
        $this->login('u-nav', 'org-test', 'admin');
        // More than MAX_KEYS (60) items → fail-soft no-op.
        $keys = json_encode(array_map(fn($i) => 'k' . $i, range(1, System_Service_Nav::MAX_KEYS + 5)));
        $res  = $this->dispatch(['action' => 'sort', 'group' => 'root', 'keys' => $keys]);

        $this->assertSame(1, (int) $res->result);
        $this->assertNull((new Tiger_Model_Config())->get(Tiger_Model_Config::SCOPE_USER, 'u-nav', 'tiger.nav.root.k1.sort'));
    }
}
