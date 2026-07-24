<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Module;
use Tiger_Model_Org;
use Tiger_Model_OrgUser;
use Tiger_Model_User;
use Tiger_Uuid;

/**
 * The admin-grid + finder methods that OrgTest / UserTest / ModuleTest didn't reach: the Org and User
 * server-side DataTables queries (which JOIN org_user for a live member/role summary), Org::children()
 * + setSiteOrgId(), User::preferredLocale(), and Module::bySlug()/bySlugMap(). Each is a real read the
 * admin back office depends on, exercised against the migrated schema.
 */
#[CoversClass(Tiger_Model_Org::class)]
#[CoversClass(Tiger_Model_User::class)]
#[CoversClass(Tiger_Model_Module::class)]
final class AdminFindersTest extends IntegrationTestCase
{
    // ---- Org ---------------------------------------------------------------

    #[Test]
    public function org_children_returns_direct_descendants(): void
    {
        $org  = new Tiger_Model_Org();
        $root = $org->insert(['name' => 'Root', 'slug' => 'root-' . bin2hex(random_bytes(4))]);
        $a    = $org->insert(['name' => 'Dept A', 'slug' => 'a-' . bin2hex(random_bytes(4)), 'parent_org_id' => $root]);
        $b    = $org->insert(['name' => 'Dept B', 'slug' => 'b-' . bin2hex(random_bytes(4)), 'parent_org_id' => $root]);
        $org->insert(['name' => 'Unrelated', 'slug' => 'u-' . bin2hex(random_bytes(4))]);

        $kids = $org->children($root);
        $ids  = [];
        foreach ($kids as $k) { $ids[] = $k->org_id; }
        sort($ids);
        $expected = [$a, $b]; sort($expected);
        $this->assertSame($expected, $ids, 'only the direct children of the root are returned');
    }

    #[Test]
    public function org_datatable_reports_a_live_member_count_and_parent_name(): void
    {
        $org      = new Tiger_Model_Org();
        $orgUser  = new Tiger_Model_OrgUser();
        $user     = new Tiger_Model_User();

        $parent = $org->insert(['name' => 'Acme Parent', 'slug' => 'acme-' . bin2hex(random_bytes(4))]);
        $child  = $org->insert(['name' => 'Acme Child', 'slug' => 'acmec-' . bin2hex(random_bytes(4)), 'parent_org_id' => $parent]);

        // Two members on the child org.
        $u1 = $user->insert(['email' => 'm1-' . bin2hex(random_bytes(6)) . '@example.test']);
        $u2 = $user->insert(['email' => 'm2-' . bin2hex(random_bytes(6)) . '@example.test']);
        $orgUser->insert(['org_id' => $child, 'user_id' => $u1, 'role' => 'admin']);
        $orgUser->insert(['org_id' => $child, 'user_id' => $u2, 'role' => 'member']);

        $result = $org->datatable(['search' => 'Acme Child', 'limit' => 25]);
        $this->assertSame(1, $result['filtered']);
        $row = $result['rows'][0];
        $this->assertSame('Acme Child', $row['name']);
        $this->assertSame('Acme Parent', $row['parent_name'], 'the parent org name is joined in');
        $this->assertSame(2, (int) $row['member_count'], 'the live member count comes from org_user');
    }

    #[Test]
    public function org_datatable_filters_by_status(): void
    {
        $org = new Tiger_Model_Org();
        $org->insert(['name' => 'Zeta Active', 'slug' => 'za-' . bin2hex(random_bytes(4)), 'status' => 'active']);
        $org->insert(['name' => 'Zeta Suspended', 'slug' => 'zs-' . bin2hex(random_bytes(4)), 'status' => 'suspended']);

        $suspended = $org->datatable(['search' => 'Zeta', 'status' => 'suspended', 'limit' => 25]);
        $this->assertSame(1, $suspended['filtered']);
        $this->assertSame('Zeta Suspended', $suspended['rows'][0]['name']);
    }

    #[Test]
    public function set_site_org_id_overrides_the_cached_resolution(): void
    {
        // Snapshot the private static so we can restore it EXACTLY (setSiteOrgId('') would cache ''
        // as a resolved value, poisoning the lazy founding-org resolution for sibling test files).
        $ref  = new \ReflectionProperty(Tiger_Model_Org::class, '_siteOrgId');
        $prior = $ref->getValue();
        try {
            $forced = Tiger_Uuid::v7();
            Tiger_Model_Org::setSiteOrgId($forced);
            $this->assertSame($forced, Tiger_Model_Org::siteOrgId(), 'a multi-site override wins over the founding-org heuristic');
        } finally {
            $ref->setValue(null, $prior);   // restore the exact prior state (usually null = unresolved)
        }
    }

    // ---- User --------------------------------------------------------------

    #[Test]
    public function preferred_locale_returns_a_supported_locale_or_null(): void
    {
        $user = new Tiger_Model_User();
        $es   = $user->insert(['email' => 'loc-' . bin2hex(random_bytes(6)) . '@example.test', 'locale' => 'es']);
        $none = $user->insert(['email' => 'loc-' . bin2hex(random_bytes(6)) . '@example.test']);
        $xx   = $user->insert(['email' => 'loc-' . bin2hex(random_bytes(6)) . '@example.test', 'locale' => 'xx']);

        $this->assertSame('es', $user->preferredLocale($es, ['en', 'es']), 'a supported stored locale resolves');
        $this->assertNull($user->preferredLocale($none, ['en', 'es']), 'no stored locale → null');
        $this->assertNull($user->preferredLocale($xx, ['en', 'es']), 'an unsupported locale → null');
        $this->assertNull($user->preferredLocale(Tiger_Uuid::v7(), ['en']), 'an unknown user → null');
    }

    #[Test]
    public function user_datatable_summarizes_membership_and_searches(): void
    {
        $user    = new Tiger_Model_User();
        $orgUser = new Tiger_Model_OrgUser();
        $org     = new Tiger_Model_Org();

        // org_user carries an FK to org, so the memberships need real org rows.
        $orgA = $org->insert(['name' => 'GridOrg A', 'slug' => 'ga-' . bin2hex(random_bytes(4))]);
        $orgB = $org->insert(['name' => 'GridOrg B', 'slug' => 'gb-' . bin2hex(random_bytes(4))]);
        $u = $user->insert(['email' => 'grid-' . bin2hex(random_bytes(6)) . '@example.test', 'username' => 'griduser' . bin2hex(random_bytes(3))]);
        $orgUser->insert(['org_id' => $orgA, 'user_id' => $u, 'role' => 'admin']);
        $orgUser->insert(['org_id' => $orgB, 'user_id' => $u, 'role' => 'member']);

        $result = $user->datatable(['search' => $user->findById($u)->email, 'limit' => 25]);
        $this->assertSame(1, $result['filtered']);
        $row = $result['rows'][0];
        $this->assertSame(2, (int) $row['org_count'], 'the user belongs to two orgs');
        $this->assertStringContainsString('admin', (string) $row['role_names'], 'the distinct roles are concatenated');
        $this->assertStringContainsString('member', (string) $row['role_names']);
    }

    // ---- Module ------------------------------------------------------------

    #[Test]
    public function module_by_slug_and_by_slug_map(): void
    {
        $module = new Tiger_Model_Module();
        $module->install('blog-' . bin2hex(random_bytes(4)), ['name' => 'Blog', 'version' => '1.0.0']);
        $slug = 'shop-' . bin2hex(random_bytes(4));
        $module->install($slug, ['name' => 'Shop', 'version' => '2.0.0']);   // install() returns the id, not the slug

        $row = $module->bySlug($slug);
        $this->assertNotNull($row, 'bySlug finds the row');
        $this->assertSame('Shop', $row->name);
        $this->assertNull($module->bySlug('does-not-exist'), 'a missing slug returns null');

        $map = $module->bySlugMap();
        $this->assertArrayHasKey($slug, $map, 'bySlugMap is keyed by slug');
        $this->assertSame('Shop', $map[$slug]->name);
    }
}
