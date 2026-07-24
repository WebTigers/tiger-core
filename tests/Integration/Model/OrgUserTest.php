<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Org;
use Tiger_Model_OrgUser;
use Tiger_Model_User;
use Tiger_Uuid;

/**
 * Tiger_Model_OrgUser — the membership row that is BOTH the tenancy boundary and the role carrier
 * (ARCHITECTURE §7/§8). The security-critical contract: role lives on the membership (a user can be
 * admin in one org, viewer in another), and the ABSENCE of a membership row *is* the cross-tenant
 * denial — `roleOf()` returns null, never a stale or cross-org role.
 */
#[CoversClass(Tiger_Model_OrgUser::class)]
final class OrgUserTest extends IntegrationTestCase
{
    private Tiger_Model_OrgUser $orgUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgUser = new Tiger_Model_OrgUser();
    }

    private function makeUser(string $tag): string
    {
        return (new Tiger_Model_User())->insert(['email' => "$tag-" . substr(Tiger_Uuid::v7(), 0, 12) . '@example.test']);
    }

    private function makeOrg(string $name): string
    {
        return (new Tiger_Model_Org())->insert(['name' => $name, 'slug' => strtolower($name) . '-' . substr(Tiger_Uuid::v7(), 0, 8)]);
    }

    #[Test]
    public function role_lives_on_the_membership_so_it_differs_per_org(): void
    {
        $user = $this->makeUser('u');
        $orgA = $this->makeOrg('Acme');
        $orgB = $this->makeOrg('Beta');

        $this->orgUser->insert(['org_id' => $orgA, 'user_id' => $user, 'role' => 'admin']);
        $this->orgUser->insert(['org_id' => $orgB, 'user_id' => $user, 'role' => 'viewer']);

        $this->assertSame('admin', $this->orgUser->roleOf($orgA, $user), 'same user is admin in org A');
        $this->assertSame('viewer', $this->orgUser->roleOf($orgB, $user), '…and viewer in org B');
    }

    #[Test]
    public function absence_of_a_membership_is_the_cross_tenant_denial(): void
    {
        $member = $this->makeUser('member');
        $stranger = $this->makeUser('stranger');
        $orgA = $this->makeOrg('Acme');
        $orgB = $this->makeOrg('Beta');

        $this->orgUser->insert(['org_id' => $orgA, 'user_id' => $member, 'role' => 'admin']);

        // a user with no row in this org => null (never a leaked role)
        $this->assertNull($this->orgUser->roleOf($orgA, $stranger), 'non-member gets null');
        // the member has no standing in a DIFFERENT org
        $this->assertNull($this->orgUser->roleOf($orgB, $member), 'membership does not cross tenants');
        $this->assertNull($this->orgUser->membership($orgB, $member));
        $this->assertNotNull($this->orgUser->membership($orgA, $member));
    }

    #[Test]
    public function a_soft_deleted_membership_revokes_the_role(): void
    {
        $user = $this->makeUser('u');
        $orgA = $this->makeOrg('Acme');
        $this->orgUser->insert(['org_id' => $orgA, 'user_id' => $user, 'role' => 'admin']);
        $this->assertSame('admin', $this->orgUser->roleOf($orgA, $user));

        // revoke by soft-delete — activeSelect() excludes it, so the role is gone next read.
        $this->orgUser->softDelete(['org_id = ?' => $orgA, 'user_id = ?' => $user]);
        $this->assertNull($this->orgUser->roleOf($orgA, $user), 'a revoked membership grants nothing');
    }

    #[Test]
    public function orgsForUser_lists_only_that_users_active_memberships(): void
    {
        $user = $this->makeUser('u');
        $other = $this->makeUser('other');
        $orgA = $this->makeOrg('Acme');
        $orgB = $this->makeOrg('Beta');

        $this->orgUser->insert(['org_id' => $orgA, 'user_id' => $user, 'role' => 'admin']);
        $this->orgUser->insert(['org_id' => $orgB, 'user_id' => $user, 'role' => 'viewer']);
        $this->orgUser->insert(['org_id' => $orgA, 'user_id' => $other, 'role' => 'admin']);

        $orgIds = [];
        foreach ($this->orgUser->orgsForUser($user) as $row) {
            $orgIds[] = $row->org_id;
        }
        sort($orgIds);
        $expected = [$orgA, $orgB];
        sort($expected);
        $this->assertSame($expected, $orgIds, 'exactly this user\'s two orgs, no one else\'s');
    }
}
