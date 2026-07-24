<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Acl;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Registry;

// `System_Service_Acl` resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * System_Service_Acl — the read-only ACL Simulator ("would role X reach resource Y·Z, and WHY?").
 * `simulate()` runs the live Tiger_Acl_Acl::explain(); `catalog()` feeds the role/resource pickers.
 * Superadmin+ per modules/system/configs/acl.ini (ACL introspection can reveal the policy shape), so a
 * plain admin is denied. These tests characterize the gate plus the explain envelope for both an
 * ALLOW and a deny-by-default DENY, against the REAL shipped policy.
 */
#[CoversClass(System_Service_Acl::class)]
final class AclServiceTest extends IntegrationTestCase
{
    private function dispatch(array $msg): object
    {
        return (new System_Service_Acl($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- ACL: superadmin+, deny-by-default -------------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_the_simulator_to_superadmin_and_up(): void
    {
        $this->loginAs('superadmin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('System_Service_Acl'), 'the acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('superadmin', 'System_Service_Acl'));
        $this->assertTrue($acl->isAllowed('developer', 'System_Service_Acl'), 'the god developer role inherits it');
        $this->assertFalse($acl->isAllowed('admin', 'System_Service_Acl'), 'a plain admin cannot use the simulator');
        $this->assertFalse($acl->isAllowed('guest', 'System_Service_Acl'));
    }

    #[Test]
    public function a_plain_admin_is_denied_the_simulator(): void
    {
        $this->loginAs('admin');
        foreach (['simulate', 'catalog'] as $action) {
            $res = $this->dispatch(['action' => $action]);
            $this->assertSame(0, (int) $res->result, "$action is superadmin+, not admin");
            $this->assertStringContainsString('not_allowed', $this->messages($res));
        }
    }

    // ---- simulate --------------------------------------------------------------------------------

    #[Test]
    public function simulate_explains_an_allow_decision_against_the_real_policy(): void
    {
        $this->loginAs('superadmin');
        // superadmin genuinely may manage modules → an ALLOW with the explanatory envelope.
        $res = $this->dispatch(['action' => 'simulate', 'role' => 'superadmin', 'resource' => 'System_Service_Modules']);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $explain = $res->data['explain'];
        $this->assertTrue($explain['allowed']);
        $this->assertTrue($explain['roleKnown']);
        $this->assertTrue($explain['resourceKnown']);
        $this->assertContains('superadmin', $explain['roleChain']);
        $this->assertNotEmpty($explain['reason']);
    }

    #[Test]
    public function simulate_explains_a_deny_by_default_decision(): void
    {
        $this->loginAs('superadmin');
        // a plain admin may NOT manage modules → a DENY, and the reason names deny-by-default.
        $res = $this->dispatch(['action' => 'simulate', 'role' => 'admin', 'resource' => 'System_Service_Modules']);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $explain = $res->data['explain'];
        $this->assertFalse($explain['allowed']);
        $this->assertTrue($explain['roleKnown']);
        $this->assertStringContainsStringIgnoringCase('deny', $explain['reason']);
    }

    #[Test]
    public function simulate_flags_an_unknown_role(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'simulate', 'role' => 'wizard', 'resource' => 'System_Service_Modules']);
        $this->assertSame(1, (int) $res->result);

        $explain = $res->data['explain'];
        $this->assertFalse($explain['allowed']);
        $this->assertFalse($explain['roleKnown'], 'an unknown role is flagged');
    }

    // ---- catalog ---------------------------------------------------------------------------------

    #[Test]
    public function catalog_returns_the_known_roles_and_sorted_resources(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'catalog']);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $this->assertContains('superadmin', $res->data['roles']);
        $this->assertContains('guest', $res->data['roles']);
        $this->assertContains('System_Service_Modules', $res->data['resources'], 'a shipped resource is listed');

        $resources = $res->data['resources'];
        $sorted = $resources;
        sort($sorted);
        $this->assertSame($sorted, $resources, 'resources come back sorted');
    }
}
