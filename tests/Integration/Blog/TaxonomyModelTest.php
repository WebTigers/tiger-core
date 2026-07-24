<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Blog;

use Blog_Model_Taxonomy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;

/**
 * Blog_Model_Taxonomy — categories, tags, and the page↔term join (`page_taxonomy`).
 *
 * Coverage: findOrCreate (mint-on-first-use, slug-collapse match, empty→null), listVocabulary
 * (vocab/locale/org scope + ordering), resolveTermBySlug (tenant-over-global), the join maintenance
 * (syncPage full-rewrite ignoring blanks, forPage in author order + vocab filter, pageIdsForTerm),
 * counts (published-only per-term counts), and slugify.
 */
#[CoversClass(Blog_Model_Taxonomy::class)]
final class TaxonomyModelTest extends IntegrationTestCase
{
    private Blog_Model_Taxonomy $tax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login('editor-1', 'org-test', 'admin');
        $this->tax = new Blog_Model_Taxonomy();
    }

    // ----- findOrCreate --------------------------------------------------------------------------

    #[Test]
    public function find_or_create_mints_a_term_then_reuses_it_by_slug(): void
    {
        $first = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Web Dev', 'en', 'org-test');
        $this->assertNotNull($first);

        // "web-dev" collapses to the same slug → same term id, no duplicate.
        $again = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'web-dev', 'en', 'org-test');
        $this->assertSame($first, $again, 'a matching slug resolves the existing term');

        $row = $this->tax->findById($first);
        $this->assertSame('web-dev', $row->slug);
        $this->assertSame('Web Dev', $row->name, 'the first-seen display name is kept');
        $this->assertSame('active', $row->status);
    }

    #[Test]
    public function find_or_create_returns_null_for_a_blank_name(): void
    {
        $this->assertNull($this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, '   ', 'en', 'org-test'));
    }

    #[Test]
    public function find_or_create_separates_vocabularies(): void
    {
        $cat = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'News', 'en', 'org-test');
        $tag = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'News', 'en', 'org-test');
        $this->assertNotSame($cat, $tag, 'the same name in two vocabularies is two terms');
    }

    // ----- listVocabulary ------------------------------------------------------------------------

    #[Test]
    public function list_vocabulary_returns_only_the_matching_vocab_ordered(): void
    {
        $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Zeta', 'en', 'org-test');
        $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Alpha', 'en', 'org-test');
        $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'ignored-tag', 'en', 'org-test');

        $names = [];
        foreach ($this->tax->listVocabulary(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'en', 'org-test') as $t) { $names[] = $t->name; }

        $this->assertContains('Alpha', $names);
        $this->assertContains('Zeta', $names);
        $this->assertNotContains('ignored-tag', $names, 'tags excluded from the category list');
        // equal sort_order → name ASC
        $this->assertLessThan(array_search('Zeta', $names, true), array_search('Alpha', $names, true), 'name-ASC tiebreak');
    }

    // ----- resolveTermBySlug ---------------------------------------------------------------------

    #[Test]
    public function resolve_term_by_slug_finds_the_term(): void
    {
        $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'PHP', 'en', 'org-test');
        $row = $this->tax->resolveTermBySlug(Blog_Model_Taxonomy::VOCAB_TAG, 'php', 'en', 'org-test');
        $this->assertNotNull($row);
        $this->assertSame('PHP', $row->name);

        $this->assertNull($this->tax->resolveTermBySlug(Blog_Model_Taxonomy::VOCAB_TAG, 'nope', 'en', 'org-test'));
    }

    #[Test]
    public function resolve_term_by_slug_prefers_a_tenant_term_over_global(): void
    {
        // Global term (org '') and a tenant term share the slug; tenant should win (org_id DESC).
        $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Shared', 'en', '');
        $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Shared', 'en', 'org-test');

        $row = $this->tax->resolveTermBySlug(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'shared', 'en', 'org-test');
        $this->assertSame('org-test', $row->org_id, 'the tenant row shadows the global one');
    }

    // ----- syncPage / forPage / pageIdsForTerm ---------------------------------------------------

    #[Test]
    public function sync_page_rewrites_links_preserving_order_and_ignoring_blanks(): void
    {
        $a = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'First', 'en', 'org-test');
        $b = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Second', 'en', 'org-test');
        $c = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'Third', 'en', 'org-test');
        $pageId = 'page-xyz';

        // include a blank id — it must be skipped, not linked.
        $this->tax->syncPage($pageId, [$a, '', $b]);

        $ids = $this->tax->pageIdsForTerm($a);
        $this->assertSame([$pageId], $ids, 'the page is linked to term A');

        $names = array_map(fn($r) => $r['name'], $this->tax->forPage($pageId));
        $this->assertSame(['First', 'Second'], $names, 'links returned in author (sort) order, blank skipped');

        // Re-sync replaces the whole set (full rewrite): now only C.
        $this->tax->syncPage($pageId, [$c]);
        $after = array_map(fn($r) => $r['name'], $this->tax->forPage($pageId));
        $this->assertSame(['Third'], $after, 'a re-sync replaces the previous link set');
        $this->assertSame([], $this->tax->pageIdsForTerm($a), 'the old link is gone');
    }

    #[Test]
    public function for_page_can_filter_to_one_vocabulary(): void
    {
        $cat = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'CatOnly', 'en', 'org-test');
        $tag = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'TagOnly', 'en', 'org-test');
        $pageId = 'page-filter';
        $this->tax->syncPage($pageId, [$cat, $tag]);

        $cats = array_map(fn($r) => $r['name'], $this->tax->forPage($pageId, Blog_Model_Taxonomy::VOCAB_CATEGORY));
        $this->assertSame(['CatOnly'], $cats, 'vocabulary filter narrows to categories');
    }

    // ----- counts --------------------------------------------------------------------------------

    #[Test]
    public function counts_returns_terms_with_a_link_count(): void
    {
        // NOTE: characterizes CURRENT behavior. The docblock says "published only", but the query
        // sums COUNT(pt.page_id) (the LINK), so a draft-linked page still counts — see the LEFT-join
        // finding in WAVE4-FINDINGS-blog.md. A term with two links (one published, one draft) counts 2.
        $tid   = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Counted', 'en', 'org-test');
        $empty = $this->tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Empty', 'en', 'org-test');

        $pub   = (new \Tiger_Model_Page())->insert(['type' => 'article', 'org_id' => 'org-test', 'locale' => 'en', 'title' => 'P', 'body' => '', 'format' => 'html', 'status' => 'published', 'page_key' => 'p', 'slug' => 'p']);
        $draft = (new \Tiger_Model_Page())->insert(['type' => 'article', 'org_id' => 'org-test', 'locale' => 'en', 'title' => 'D', 'body' => '', 'format' => 'html', 'status' => 'draft', 'page_key' => 'd', 'slug' => 'd']);
        $this->tax->syncPage($pub, [$tid]);
        $this->tax->syncPage($draft, [$tid]);

        $counts = [];
        foreach ($this->tax->counts(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'en', 'org-test') as $r) { $counts[$r['name']] = (int) $r['n']; }

        $this->assertSame(2, $counts['Counted'], 'CURRENT behavior: counts links, not just published posts (bug)');
        $this->assertSame(0, $counts['Empty'], 'a term with no links counts zero');
        // Ordering: the busier term sorts ahead of the empty one (n DESC).
        $this->assertSame('Counted', array_key_first($counts), 'ordered by count DESC');
    }

    // ----- slugify -------------------------------------------------------------------------------

    #[Test]
    public function slugify_lowercases_and_hyphenates(): void
    {
        $this->assertSame('hello-world', $this->tax->slugify('  Hello, World!  '));
        $this->assertSame('a-b-c', $this->tax->slugify('a__b--c'));
        $this->assertSame('', $this->tax->slugify('!!!'));
    }
}
