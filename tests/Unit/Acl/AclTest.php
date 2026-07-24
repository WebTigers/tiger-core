<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Acl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Acl_Acl;
use Zend_Acl_Resource;

/**
 * Tiger_Acl_Acl — the authorization engine, tested against the REAL shipped policy in
 * core/configs/acl.ini (the base loads it via the include paths the bootstrap defines). Covers the
 * spine every access decision rides: the role graph + inheritance, the deny-by-default baseline, the
 * developer god-rule, the unknown-role guard, and explain()'s trace.
 *
 * No DB needed — the DB rule loaders are try/caught, so with no adapter the engine is exactly the
 * ini policy, which is what we assert.
 */
#[CoversClass(Tiger_Acl_Acl::class)]
final class AclTest extends UnitTestCase
{
    private Tiger_Acl_Acl $acl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acl = new Tiger_Acl_Acl();
    }

    #[Test]
    public function the_default_role_graph_is_loaded(): void
    {
        foreach (['guest', 'user', 'manager', 'supermanager', 'admin', 'superadmin', 'developer'] as $role) {
            $this->assertTrue($this->acl->hasRole($role), "role `$role` must exist");
        }
    }

    #[Test]
    public function deny_by_default_refuses_a_registered_resource_with_no_matching_allow(): void
    {
        // A caller registers the resource, then asks — the baseline `deny * * *` refuses it.
        $this->acl->addResource(new Zend_Acl_Resource('Some_Service_Unruled'));
        $this->assertFalse($this->acl->isAllowed('guest', 'Some_Service_Unruled', 'anything'));
        $this->assertFalse($this->acl->isAllowed('user', 'Some_Service_Unruled', 'anything'));
    }

    #[Test]
    public function isAllowed_throws_on_an_unregistered_resource_by_design(): void
    {
        // BY DESIGN: asking about a resource the ACL has never heard of is a CALLER bug, and the
        // engine fails loud rather than silently denying (which would mask the mistake). Legit callers
        // register their resource first (Tiger_Ajax_ServiceFactory / the Authorization plugin do).
        $this->expectException(\Zend_Acl_Exception::class);
        $this->acl->isAllowed('guest', 'Never_Registered_Resource', 'go');
    }

    #[Test]
    public function the_developer_role_is_god_mode(): void
    {
        $this->acl->addResource(new Zend_Acl_Resource('Some_Service_Unruled'));
        $this->assertTrue($this->acl->isAllowed('developer', 'Some_Service_Unruled', 'anything'),
            'developer has allow * * in the shipped policy');
    }

    #[Test]
    public function a_scoped_allow_is_honored_and_inherited_up_the_graph(): void
    {
        // acl.ini: user may reach Tiger_Service_Token; guest may not; admin inherits user's grant.
        $this->assertTrue($this->acl->isAllowed('user', 'Tiger_Service_Token', 'mint'));
        $this->assertFalse($this->acl->isAllowed('guest', 'Tiger_Service_Token', 'mint'));
        $this->assertTrue($this->acl->isAllowed('admin', 'Tiger_Service_Token', 'mint'),
            'admin descends from user, so it inherits the token allow');
    }

    #[Test]
    public function an_unknown_role_is_denied_not_fatal(): void
    {
        // Tiger_Acl_Acl guards this (plain Zend_Acl would throw) — a typo'd role locks OUT, never in.
        $this->assertFalse($this->acl->isAllowed('nonesuch-role', 'Tiger_Service_Token', 'mint'));
    }

    #[Test]
    public function explain_returns_a_trace_that_matches_the_decision(): void
    {
        $allow = $this->acl->explain('user', 'Tiger_Service_Token', 'mint');
        $this->assertTrue($allow['allowed']);
        $this->assertTrue($allow['roleKnown']);
        $this->assertContains('user', $allow['roleChain']);
        $this->assertNotSame('', (string) $allow['reason'], 'an allow must carry a reason');

        $deny = $this->acl->explain('guest', 'Tiger_Service_Token', 'mint');
        $this->assertFalse($deny['allowed']);
        // the trace agrees with the authoritative decision
        $this->assertSame($this->acl->isAllowed('guest', 'Tiger_Service_Token', 'mint'), $deny['allowed']);
    }

    #[Test]
    public function explain_flags_an_unknown_role(): void
    {
        $ex = $this->acl->explain('nonesuch-role', 'Tiger_Service_Token', 'mint');
        $this->assertFalse($ex['allowed']);
        $this->assertFalse($ex['roleKnown'], 'explain must mark an unknown role');
    }
}
