<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_AclResource;
use Tiger_Model_AclRole;
use Tiger_Model_AclRule;

/**
 * The DB tier that feeds authorization — `acl_resource` / `acl_role` / `acl_rule`. These three
 * loaders (`getResourceList` / `getRoleList` / `getRuleList`) are what `Tiger_Acl_Acl` reads on top
 * of the code-shipped ini policy, and the DB tier loads LAST so it *wins* on conflict. That makes
 * the soft-delete contract security-critical: a **dropped** rule must not keep flipping a decision
 * and a **soft-deleted** allow row must not keep granting. Each loader builds on `activeSelect()`,
 * so the invariant under test is "deleted rows are NOT in the active set" — exercised directly by
 * seeding `acl_*` rows and asserting exactly what comes back.
 */
#[CoversClass(Tiger_Model_AclResource::class)]
#[CoversClass(Tiger_Model_AclRole::class)]
#[CoversClass(Tiger_Model_AclRule::class)]
final class AclModelsTest extends IntegrationTestCase
{
    private Tiger_Model_AclResource $resources;
    private Tiger_Model_AclRole $roles;
    private Tiger_Model_AclRule $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resources = new Tiger_Model_AclResource();
        $this->roles     = new Tiger_Model_AclRole();
        $this->rules     = new Tiger_Model_AclRule();
    }

    /** Collect one column out of a loader's rowset into a plain sorted array. */
    private function col(iterable $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row->$key;
        }
        sort($out);
        return $out;
    }

    // ----- acl_resource -------------------------------------------------------------------------

    #[Test]
    public function resource_loader_returns_active_rows_and_excludes_deleted(): void
    {
        $this->resources->insert(['resource' => 'Billing_Service_Invoice']);
        $this->resources->insert(['resource' => 'Reports_Service_Export']);
        $doomed = $this->resources->insert(['resource' => 'Legacy_Service_Gone']);

        // Baseline: the tables ship empty (migrations seed no acl_* rows), so the loader returns
        // exactly what we seeded — a precise assertion, not a "contains".
        $this->assertSame(
            ['Billing_Service_Invoice', 'Legacy_Service_Gone', 'Reports_Service_Export'],
            $this->col($this->resources->getResourceList(), 'resource'),
            'the active set is exactly the seeded resources'
        );

        // Soft-delete one — it must drop out of the active set (an extra resource must not linger).
        $this->resources->softDelete($this->db->quoteInto('acl_resource_id = ?', $doomed));
        $this->assertSame(
            ['Billing_Service_Invoice', 'Reports_Service_Export'],
            $this->col($this->resources->getResourceList(), 'resource'),
            'a soft-deleted resource is NOT returned by the loader'
        );
    }

    // ----- acl_role -----------------------------------------------------------------------------

    #[Test]
    public function role_loader_returns_active_rows_and_excludes_deleted(): void
    {
        $this->roles->insert(['role' => 'editor', 'parent_role' => 'user']);
        $doomed = $this->roles->insert(['role' => 'ghost', 'parent_role' => 'user']);

        $this->assertSame(['editor', 'ghost'], $this->col($this->roles->getRoleList(), 'role'));

        // A soft-deleted role must vanish from the graph the ACL builds — a stale role in the graph
        // could carry inherited grants it shouldn't.
        $this->roles->softDelete($this->db->quoteInto('acl_role_id = ?', $doomed));
        $this->assertSame(
            ['editor'],
            $this->col($this->roles->getRoleList(), 'role'),
            'a soft-deleted role is NOT returned by the loader'
        );
    }

    // ----- acl_rule (the load-bearing one) ------------------------------------------------------

    #[Test]
    public function rule_loader_returns_active_rows_and_excludes_deleted(): void
    {
        // An allow grant and a deny grant — both must be visible while live…
        $allow  = $this->rules->insert(['role' => 'editor', 'resource' => 'Billing_Service_Invoice', 'privilege' => 'view',   'permission' => 'allow']);
        $deny   = $this->rules->insert(['role' => 'editor', 'resource' => 'Billing_Service_Invoice', 'privilege' => 'delete', 'permission' => 'deny']);

        $loaded = [];
        foreach ($this->rules->getRuleList() as $r) {
            $loaded[$r->acl_rule_id] = $r->permission;
        }
        $this->assertCount(2, $loaded, 'both live rules load');
        $this->assertSame('allow', $loaded[$allow]);
        $this->assertSame('deny',  $loaded[$deny]);
    }

    #[Test]
    public function a_soft_deleted_allow_rule_stops_granting(): void
    {
        // The security invariant stated as a scenario: revoke an allow by soft-deleting it, and it
        // must no longer be in the set the ACL loads — otherwise a revoked grant keeps granting.
        $grant = $this->rules->insert(['role' => 'editor', 'resource' => 'Billing_Service_Invoice', 'privilege' => 'view', 'permission' => 'allow']);
        $this->assertCount(1, iterator_to_array($this->rules->getRuleList()), 'the grant is live');

        $this->rules->softDelete($this->db->quoteInto('acl_rule_id = ?', $grant));

        $this->assertCount(
            0,
            iterator_to_array($this->rules->getRuleList()),
            'a soft-deleted allow rule is NOT loaded — a revoked grant cannot keep granting'
        );
    }

    #[Test]
    public function a_soft_deleted_deny_rule_is_dropped_so_it_cannot_keep_blocking(): void
    {
        // Symmetry: the DB tier wins last, so a stray deny would override an ini allow. Once
        // soft-deleted it must fall out of the set, or it keeps flipping decisions to deny.
        $deny = $this->rules->insert(['role' => 'editor', 'resource' => 'Reports_Service_Export', 'privilege' => 'run', 'permission' => 'deny']);
        $this->rules->softDelete($this->db->quoteInto('acl_rule_id = ?', $deny));

        $this->assertSame([], iterator_to_array($this->rules->getRuleList()), 'a dropped deny rule is gone from the loader');
    }
}
