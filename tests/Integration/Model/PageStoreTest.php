<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Model_PageRedirect;
use Tiger_Model_PageVersion;
use Tiger_Uuid;

/**
 * Tiger_Model_Page, part 2 — the WRITE + admin surface (PageTest covers resolveBySlug's dispatch
 * gate). Here: the transactional save() that snapshots to page_version and drops a 301 behind a slug
 * change, restoreVersion() (which copies an old version back AND re-snapshots), children() for nav,
 * search()'s visibility gate, and the CMS admin datatable().
 *
 * save() opens its own transaction; the harness's SavepointAdapter nests it inside the per-test
 * transaction, so these run and roll back cleanly.
 */
#[CoversClass(Tiger_Model_Page::class)]
final class PageStoreTest extends IntegrationTestCase
{
    private Tiger_Model_Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = new Tiger_Model_Page();
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'org_id' => '',
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
        ], $overrides);
    }

    #[Test]
    public function save_inserts_and_snapshots_version_one(): void
    {
        $id = $this->page->save($this->baseRow(['slug' => 'welcome', 'title' => 'Welcome', 'body' => 'hi']));
        $this->assertNotSame('', $id);

        $row = $this->page->findById($id);
        $this->assertSame('Welcome', $row->title);

        $versions = (new Tiger_Model_PageVersion())->recentForPage($id);
        $this->assertCount(1, $versions, 'the insert snapshots version 1');
    }

    #[Test]
    public function save_update_snapshots_a_new_version(): void
    {
        $id = $this->page->save($this->baseRow(['slug' => 'about', 'title' => 'About', 'body' => 'v1']));
        $this->page->save(['title' => 'About Us', 'body' => 'v2', 'status' => Tiger_Model_Page::STATUS_PUBLISHED, 'format' => Tiger_Model_Page::FORMAT_HTML], $id);

        $this->assertSame('About Us', $this->page->findById($id)->title);
        $this->assertCount(2, (new Tiger_Model_PageVersion())->recentForPage($id), 'each save adds a version');
    }

    #[Test]
    public function changing_a_pages_slug_leaves_a_301_behind(): void
    {
        $org = Tiger_Uuid::v7();
        $id  = $this->page->save($this->baseRow(['org_id' => $org, 'slug' => 'old-url', 'title' => 'T', 'body' => 'b']));

        $this->page->save(['slug' => 'new-url'], $id);
        $this->assertSame('new-url', $this->page->findById($id)->slug, 'the slug changed');

        $redirect = (new Tiger_Model_PageRedirect())->findFrom('old-url', 'en', $org);
        $this->assertNotNull($redirect, 'a redirect from the retired slug was recorded');
        $this->assertSame('new-url', $redirect->to_slug);
        $this->assertSame(301, (int) $redirect->code);
    }

    #[Test]
    public function reclaiming_a_slug_clears_a_stale_redirect_from_it(): void
    {
        $org = Tiger_Uuid::v7();
        $redirects = new Tiger_Model_PageRedirect();
        // A pre-existing redirect FROM 'fresh' (would otherwise loop when a page reclaims that slug).
        $redirects->add('fresh', 'somewhere', 'en', $org);

        $id = $this->page->save($this->baseRow(['org_id' => $org, 'slug' => 'temp', 'title' => 'T', 'body' => 'b']));
        $this->page->save(['slug' => 'fresh'], $id);   // rename onto the slug that had a redirect

        $this->assertNull($redirects->findFrom('fresh', 'en', $org), 'the stale redirect FROM the reclaimed slug is cleared');
    }

    #[Test]
    public function restore_version_copies_an_old_body_back_and_resnapshots(): void
    {
        $id = $this->page->save($this->baseRow(['slug' => 'r', 'title' => 'V1', 'body' => 'body one']));
        $this->page->save(['title' => 'V2', 'body' => 'body two', 'status' => Tiger_Model_Page::STATUS_PUBLISHED, 'format' => Tiger_Model_Page::FORMAT_HTML], $id);

        $this->page->restoreVersion($id, 1);
        $this->assertSame('body one', $this->page->findById($id)->body, 'the version-1 body is restored');
        $this->assertCount(3, (new Tiger_Model_PageVersion())->recentForPage($id), 'the restore itself snapshots a new version');
    }

    #[Test]
    public function restore_version_throws_for_a_missing_version(): void
    {
        $id = $this->page->save($this->baseRow(['slug' => 'x', 'title' => 'X', 'body' => 'b']));
        $this->expectException(\RuntimeException::class);
        $this->page->restoreVersion($id, 99);
    }

    #[Test]
    public function children_returns_published_pages_under_a_parent_in_order(): void
    {
        $org    = Tiger_Uuid::v7();
        $parent = $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'parent', 'title' => 'Parent']));

        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'c2', 'title' => 'Zeta',  'parent_id' => $parent, 'sort_order' => 2]));
        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'c1', 'title' => 'Alpha', 'parent_id' => $parent, 'sort_order' => 1]));
        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'd',  'title' => 'Draft', 'parent_id' => $parent, 'sort_order' => 0, 'status' => Tiger_Model_Page::STATUS_DRAFT]));

        $kids = $this->page->children($parent, 'en', $org);
        $titles = [];
        foreach ($kids as $k) { $titles[] = $k->title; }
        $this->assertSame(['Alpha', 'Zeta'], $titles, 'published children in sort order; the draft is excluded');
    }

    #[Test]
    public function search_honors_the_publish_and_org_gate(): void
    {
        $org = Tiger_Uuid::v7();
        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'p1', 'title' => 'Photography Basics', 'body' => 'about photography']));
        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'p2', 'title' => 'Photography Draft',  'body' => 'photography secrets', 'status' => Tiger_Model_Page::STATUS_DRAFT]));
        $this->page->insert($this->baseRow(['org_id' => Tiger_Uuid::v7(), 'slug' => 'p3', 'title' => 'Photography Elsewhere', 'body' => 'photography elsewhere']));

        $hits = $this->page->search('photography', 'en', $org, 20);
        $titles = array_column($hits, 'title');
        $this->assertContains('Photography Basics', $titles, 'a published in-scope page is found');
        $this->assertNotContains('Photography Draft', $titles, 'a draft is never surfaced');
        $this->assertNotContains('Photography Elsewhere', $titles, 'another tenant\'s page is not surfaced');
    }

    #[Test]
    public function search_returns_empty_for_a_blank_term(): void
    {
        $this->assertSame([], $this->page->search('  ', 'en', ''));
    }

    #[Test]
    public function datatable_counts_filters_and_searches(): void
    {
        $org = Tiger_Uuid::v7();
        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'a', 'title' => 'Alpha Page', 'status' => Tiger_Model_Page::STATUS_PUBLISHED]));
        $this->page->insert($this->baseRow(['org_id' => $org, 'slug' => 'b', 'title' => 'Beta Page',  'status' => Tiger_Model_Page::STATUS_DRAFT]));
        $this->page->insert($this->baseRow(['org_id' => $org, 'page_key' => 'lay', 'slug' => null, 'title' => 'Layout', 'type' => Tiger_Model_Page::TYPE_LAYOUT]));

        $all = $this->page->datatable(['limit' => 25]);
        $this->assertGreaterThanOrEqual(3, $all['total']);

        $published = $this->page->datatable(['status' => Tiger_Model_Page::STATUS_PUBLISHED, 'limit' => 25]);
        foreach ($published['rows'] as $r) { $this->assertSame(Tiger_Model_Page::STATUS_PUBLISHED, $r['status']); }

        $layouts = $this->page->datatable(['type' => Tiger_Model_Page::TYPE_LAYOUT, 'limit' => 25]);
        foreach ($layouts['rows'] as $r) { $this->assertSame(Tiger_Model_Page::TYPE_LAYOUT, $r['type']); }

        $search = $this->page->datatable(['search' => 'Alpha', 'limit' => 25]);
        $this->assertSame(1, $search['filtered']);
        $this->assertSame('Alpha Page', $search['rows'][0]['title']);
    }
}
