<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\Test;
use System_Service_Updates;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Registry;

// System_Service_Updates is a first-party module class resolved by the harness module autoloader.

/**
 * System_Service_Updates — the one-click self-updater. Applying platform/module updates is
 * `superadmin`+ ONLY (modules/system/configs/acl.ini), so guest and plain admin are denied. The
 * network-driven paths (check/apply of a real release) aren't exercised here; instead we prove the
 * ACL gate and the input guard (`apply` with no selection is a clean "none selected", not a crash),
 * and the license gate itself — the nag-never-disable rule — is characterized against
 * Tiger_License_Checker directly in the License\CheckerTest (that's the decision logic this service
 * consumes at Updates::_applyOne).
 */
final class UpdatesServiceTest extends IntegrationTestCase
{
    private function dispatch(array $msg): object
    {
        return (new System_Service_Updates($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    #[Test]
    public function the_shipped_acl_gates_the_updater_to_superadmin_and_up(): void
    {
        $this->loginAs('superadmin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('System_Service_Updates'), 'the updates acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('superadmin', 'System_Service_Updates'), 'superadmin may self-update');
        $this->assertTrue($acl->isAllowed('developer', 'System_Service_Updates'), 'the god developer role inherits it');
        $this->assertFalse($acl->isAllowed('admin', 'System_Service_Updates'), 'a plain admin cannot apply updates');
        $this->assertFalse($acl->isAllowed('guest', 'System_Service_Updates'), 'a guest is denied');
    }

    #[Test]
    public function a_guest_is_denied_applying_updates(): void
    {
        $this->login('anon', 'o-1', 'guest');
        $res = $this->dispatch(['action' => 'apply', 'items' => 'tiger-core']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired');
    }

    #[Test]
    public function a_plain_admin_is_denied_applying_updates(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'apply', 'items' => 'tiger-core']);

        $this->assertSame(0, (int) $res->result, 'applying updates is superadmin+, not admin');
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired for admin');
    }

    #[Test]
    public function apply_with_no_selection_is_a_clean_error_not_a_crash(): void
    {
        // Superadmin clears the gate; the empty-selection guard fires BEFORE any update engine runs
        // (so this needs no network).
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'apply', 'items' => []]);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('none_selected', $this->messages($res), 'the empty-selection guard fired');
        $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'superadmin cleared the ACL gate first');
    }

    #[Test]
    public function history_returns_a_list_for_a_superadmin(): void
    {
        // history() is fail-soft: it returns [] even if the table isn't migrated — never an error.
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'history', 'limit' => 5]);

        $this->assertSame(1, (int) $res->result, 'history is a read that always succeeds');
        $this->assertIsArray($res->data['history'], 'the payload carries a history list');
    }
}
