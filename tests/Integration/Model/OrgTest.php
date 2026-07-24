<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Org;
use Tiger_Uuid;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_Org — the tenant row: the siteOrgId() founding-org heuristic (+ its per-request memo),
 * slug uniqueness (slugTaken), and the parent_org_id hierarchy.
 *
 * siteOrgId() is process-static (a per-request cache in production). Every test here resets it via
 * reflection in setUp/tearDown so the tests are order-independent, and neutralizes the Zend_Config
 * `tiger.site.org_id` short-circuit so the FOUNDING-org branch (oldest by created_at) is what's exercised.
 */
#[CoversClass(Tiger_Model_Org::class)]
final class OrgTest extends IntegrationTestCase
{
    private Tiger_Model_Org $org;
    private ?Zend_Config $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = new Tiger_Model_Org();

        // Force the founding-org branch: no configured tiger.site.org_id.
        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        Zend_Registry::set('Zend_Config', new Zend_Config([]));
        $this->resetSiteOrgMemo();
    }

    protected function tearDown(): void
    {
        $this->resetSiteOrgMemo();
        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } else {
            Zend_Registry::set('Zend_Config', new Zend_Config([]));
        }
        parent::tearDown();
    }

    /** Clear the process-static site-org cache so a test starts from a clean memo. */
    private function resetSiteOrgMemo(): void
    {
        // ReflectionProperty is accessible without setAccessible() on PHP 8.1+.
        (new ReflectionProperty(Tiger_Model_Org::class, '_siteOrgId'))->setValue(null, null);
    }

    private function makeOrg(string $name, ?string $createdAt = null, ?string $parent = null): string
    {
        $data = [
            'name'          => $name,
            'slug'          => strtolower($name) . '-' . substr(Tiger_Uuid::v7(), 0, 8),
            'parent_org_id' => $parent,
        ];
        if ($createdAt !== null) {
            $data['created_at'] = $createdAt;   // base only stamps created_at when it's empty
        }
        return $this->org->insert($data);
    }

    #[Test]
    public function site_org_id_resolves_to_the_founding_oldest_org(): void
    {
        // Insert out of chronological order; the FOUNDING org is the oldest by created_at, not by insert order.
        $newer   = $this->makeOrg('Newer', '2026-06-01 00:00:00');
        $founding = $this->makeOrg('Founder', '2026-01-01 00:00:00');
        $middle  = $this->makeOrg('Middle', '2026-03-01 00:00:00');

        $this->assertSame($founding, Tiger_Model_Org::siteOrgId(), 'the oldest org is the site/founding org');
    }

    #[Test]
    public function site_org_id_is_memoized_across_calls(): void
    {
        $first = $this->makeOrg('First', '2026-01-01 00:00:00');
        $this->assertSame($first, Tiger_Model_Org::siteOrgId(), 'resolves the founding org');

        // A later insert of an EVEN OLDER org must not change the already-cached answer this request.
        $this->makeOrg('EvenOlder', '2020-01-01 00:00:00');
        $this->assertSame($first, Tiger_Model_Org::siteOrgId(), 'the memo holds — no re-query mid-request');

        // …and after a reset it re-resolves and now picks up the older founding org.
        $this->resetSiteOrgMemo();
        $olderRows = $this->org->fetchAll($this->org->activeSelect()->where('name = ?', 'EvenOlder'));
        $this->assertSame($olderRows->current()->org_id, Tiger_Model_Org::siteOrgId(), 'a fresh request re-resolves the oldest');
    }

    #[Test]
    public function site_org_id_is_blank_when_no_org_exists(): void
    {
        // Nothing inserted in this transaction → no founding org → ''.
        $this->assertSame('', Tiger_Model_Org::siteOrgId(), 'a pre-install install has no site org');
    }

    #[Test]
    public function slug_taken_reflects_live_uniqueness(): void
    {
        $id = $this->makeOrgWithSlug('acme');

        $this->assertTrue($this->org->slugTaken('acme'), 'an in-use slug is taken');
        $this->assertFalse($this->org->slugTaken('vacant'), 'an unused slug is free');
        $this->assertFalse($this->org->slugTaken('acme', $id), 'excluding the owner itself, the slug is free (edit case)');

        // A soft-deleted org frees its slug for the resolver (slugTaken builds on activeSelect).
        $this->org->softDelete(['org_id = ?' => $id]);
        $this->assertFalse($this->org->slugTaken('acme'), 'a soft-deleted org no longer holds its slug');
    }

    private function makeOrgWithSlug(string $slug): string
    {
        return $this->org->insert(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    #[Test]
    public function parent_org_id_models_a_hierarchy(): void
    {
        $parent = $this->makeOrg('Enterprise');
        $childA = $this->makeOrg('DeptA', null, $parent);
        $childB = $this->makeOrg('DeptB', null, $parent);
        $unrelated = $this->makeOrg('Other');

        // A child resolves its parent off its own row.
        $this->assertSame($parent, $this->org->findById($childA)->parent_org_id);
        $this->assertNull($this->org->findById($parent)->parent_org_id, 'a root org has no parent');

        // children() lists exactly the direct children, no one else.
        $childIds = [];
        foreach ($this->org->children($parent) as $row) {
            $childIds[] = $row->org_id;
        }
        sort($childIds);
        $expected = [$childA, $childB];
        sort($expected);
        $this->assertSame($expected, $childIds, 'exactly the two departments are children of the enterprise');
    }

    #[Test]
    public function find_by_slug_resolves_the_route_facing_identifier(): void
    {
        $id = $this->org->insert(['name' => 'Routed', 'slug' => 'routed-co']);
        $this->assertSame($id, $this->org->findBySlug('routed-co')->org_id);
        $this->assertNull($this->org->findBySlug('no-such-slug'));
    }
}
