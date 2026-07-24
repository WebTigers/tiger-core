<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Acl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Acl_Acl;
use ReflectionMethod;
use Zend_Acl_Resource;

/**
 * Tiger_Acl_Acl — the protected policy-loading internals AclTest doesn't reach through the public API
 * (which is driven only by the shipped ini + a DB tier). Reflection exercises the rule applier's four
 * permission verbs + wildcard normalization + its named-but-unregistered guards, the topological role
 * adder's cycle/missing-parent fallback, the resource de-dup, and the role-chain edge cases — the
 * branches a data-only policy rarely hits at runtime but which must behave when an app's data does.
 */
#[CoversClass(Tiger_Acl_Acl::class)]
final class AclInternalsTest extends UnitTestCase
{
    private Tiger_Acl_Acl $acl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acl = new Tiger_Acl_Acl();   // the real shipped role graph (ini; DB tier is a no-op with no adapter)
    }

    private function call(string $method, array $args = [])
    {
        return (new ReflectionMethod(Tiger_Acl_Acl::class, $method))->invokeArgs($this->acl, $args);
    }

    #[Test]
    public function apply_rule_walks_the_four_permission_verbs(): void
    {
        $this->acl->addResource(new Zend_Acl_Resource('Internals_Res'));

        // allow (privilege wildcard) → a blanket grant on the resource
        $this->call('_applyRule', ['user', 'Internals_Res', '*', 'allow']);
        $this->assertTrue($this->acl->isAllowed('user', 'Internals_Res', 'act'));

        // deny (specific privilege) → the more-specific deny overrides the blanket allow for `act`
        $this->call('_applyRule', ['user', 'Internals_Res', 'act', 'deny']);
        $this->assertFalse($this->acl->isAllowed('user', 'Internals_Res', 'act'));

        // removeDeny → drop the specific deny → the blanket allow shows through again
        $this->call('_applyRule', ['user', 'Internals_Res', 'act', 'removeDeny']);
        $this->assertTrue($this->acl->isAllowed('user', 'Internals_Res', 'act'));

        // removeAllow (wildcard) → drop the blanket grant → deny-by-default
        $this->call('_applyRule', ['user', 'Internals_Res', '*', 'removeAllow']);
        $this->assertFalse($this->acl->isAllowed('user', 'Internals_Res', 'act'));
    }

    #[Test]
    public function apply_rule_ignores_a_named_but_unregistered_role_or_resource(): void
    {
        // Unregistered resource → the rule is skipped (never registers it, never throws).
        $this->call('_applyRule', ['user', 'Never_Registered', 'act', 'allow']);
        $this->assertFalse($this->acl->has('Never_Registered'));

        // Unregistered role → skipped.
        $this->acl->addResource(new Zend_Acl_Resource('Internals_Res2'));
        $this->call('_applyRule', ['ghost-role', 'Internals_Res2', 'act', 'allow']);
        $this->assertFalse($this->acl->isAllowed('user', 'Internals_Res2', 'act'), 'the skipped rule granted nothing');
    }

    #[Test]
    public function apply_rule_normalizes_the_wildcard_tokens(): void
    {
        $this->acl->addResource(new Zend_Acl_Resource('Wild_Res'));
        // '*'/'all'/'' all mean the Zend_Acl wildcard (null). A wildcard allow on the resource for any role.
        $this->call('_applyRule', ['all', 'Wild_Res', '*', 'allow']);
        $this->assertTrue($this->acl->isAllowed('guest', 'Wild_Res', 'anything'), 'a wildcard-role allow reaches guest');
    }

    #[Test]
    public function register_resource_dedups_and_ignores_empties(): void
    {
        $this->call('_registerResource', ['']);            // empty → no-op
        $this->assertFalse($this->acl->has(''));

        $this->call('_registerResource', ['Dedup_Res']);
        $this->call('_registerResource', ['Dedup_Res']);   // second call short-circuits on has()
        $this->assertTrue($this->acl->has('Dedup_Res'));
    }

    #[Test]
    public function topological_role_add_survives_a_cycle_and_a_missing_parent(): void
    {
        // A 2-node cycle plus a child whose declared parent doesn't exist — all must still be added
        // (parentless as a fallback), and the loop must terminate.
        $this->call('_addRolesTopologically', [[
            'cyc_a'   => ['cyc_b'],
            'cyc_b'   => ['cyc_a'],
            'orphan'  => ['no_such_parent'],
        ]]);

        $this->assertTrue($this->acl->hasRole('cyc_a'));
        $this->assertTrue($this->acl->hasRole('cyc_b'));
        $this->assertTrue($this->acl->hasRole('orphan'));
    }

    #[Test]
    public function topological_role_add_respects_declared_parents_when_acyclic(): void
    {
        $this->call('_addRolesTopologically', [['newparent' => [], 'newchild' => ['newparent']]]);

        $this->assertTrue($this->acl->hasRole('newparent'));
        $this->assertTrue($this->acl->hasRole('newchild'));
        // the chain reflects the declared parent
        $chain = $this->call('_roleChain', ['newchild']);
        $this->assertContains('newparent', $chain);
    }

    #[Test]
    public function role_chain_handles_null_and_unknown_roles(): void
    {
        $this->assertSame([], $this->call('_roleChain', [null]), 'null role → empty chain');
        $this->assertSame(['zzz-unknown'], $this->call('_roleChain', ['zzz-unknown']), 'unknown role → itself only');

        // A known role includes itself first, then its parents (admin descends from the lower tiers).
        $chain = $this->call('_roleChain', ['admin']);
        $this->assertSame('admin', $chain[0]);
        $this->assertContains('user', $chain);
    }
}
