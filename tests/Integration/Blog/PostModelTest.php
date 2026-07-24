<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Blog;

use Blog_Model_Post;
use Blog_Model_Taxonomy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Media;
use Tiger_Model_Page;
use Tiger_Model_PageVersion;
use Tiger_Model_User;

/**
 * Blog_Model_Post — an article is a `page` row (type='article') layered with the page.meta JSON
 * convention, reading-time derivation, article-scoped listing/resolution, the presentation
 * projection (author + feature media), and the admin DataTables query.
 *
 * Coverage: packMeta/unpackMeta round-trip (defaults, SEO nesting, unknown-key ignore), readingTime
 * (floor of 1), saveArticle (defers to the transactional Tiger_Model_Page::save → version snapshot),
 * resolveArticle (published-gated by slug), present() (both the bare row and the author/feature
 * branches), published() (schedule gate + pageIds scoping + empty short-circuit), and
 * articleDatatable (total/filtered/search/status/order/paging).
 */
#[CoversClass(Blog_Model_Post::class)]
final class PostModelTest extends IntegrationTestCase
{
    private Blog_Model_Post $posts;

    protected function setUp(): void
    {
        parent::setUp();
        // Stamp org so saved rows land in 'org-test' and the org-scoped reads resolve deterministically.
        $this->login('author-1', 'org-test', 'admin');
        $this->posts = new Blog_Model_Post();
    }

    /** Save an article via the model and return its page_id (nests inside the harness txn). */
    private function makeArticle(array $fields = []): string
    {
        return $this->posts->saveArticle(array_merge([
            'page_key' => 'a-' . uniqid(),
            'slug'     => 's-' . uniqid(),
            'locale'   => 'en',
            'title'    => 'An Article',
            'body'     => '<p>Body text here</p>',
            'status'   => Blog_Model_Post::STATUS_PUBLISHED,
        ], $fields));
    }

    // ----- meta packing --------------------------------------------------------------------------

    #[Test]
    public function pack_meta_maps_fields_derives_reading_time_and_nests_seo(): void
    {
        $meta = $this->posts->packMeta([
            'kicker'   => 'Kick',
            'subtitle' => 'Sub',
            'preamble' => 'Pre',
            'excerpt'  => 'Ex',
            'author_id'      => 'u-9',
            'feature_media_id' => 'm-3',
            'body'     => str_repeat('word ', 400),   // 400 words → 2 minutes
            'allow_comments'  => '1',
            'seo_title'       => 'SEO T',
            'seo_description' => 'SEO D',
            'og_image_id'     => 'og-1',
            'canonical'       => 'https://x/y',
            'ignored_key'     => 'nope',
        ]);

        $this->assertSame('Kick', $meta['kicker']);
        $this->assertSame('u-9', $meta['author_id']);
        $this->assertSame(2, $meta['reading_time'], '400 words / 200 wpm = 2');
        $this->assertTrue($meta['allow_comments']);
        $this->assertSame('SEO T', $meta['seo']['title']);
        $this->assertSame('og-1', $meta['seo']['og_image_id']);
        $this->assertArrayNotHasKey('ignored_key', $meta, 'unknown editor keys are dropped');
    }

    #[Test]
    public function pack_meta_defaults_when_fields_absent(): void
    {
        $meta = $this->posts->packMeta([]);
        $this->assertSame('', $meta['kicker']);
        $this->assertFalse($meta['allow_comments'], 'absent checkbox → false');
        $this->assertSame(1, $meta['reading_time'], 'empty body still floors at 1');
        $this->assertSame(['title' => '', 'description' => '', 'og_image_id' => '', 'canonical' => ''], $meta['seo']);
    }

    #[Test]
    public function unpack_meta_accepts_json_or_array_and_fills_defaults(): void
    {
        $json = json_encode(['kicker' => 'K', 'seo' => ['title' => 'T']]);
        $fromJson  = $this->posts->unpackMeta($json);
        $fromArray = $this->posts->unpackMeta(['kicker' => 'K', 'seo' => ['title' => 'T']]);

        $this->assertSame('K', $fromJson['kicker']);
        $this->assertSame('T', $fromJson['seo']['title']);
        $this->assertSame('', $fromJson['seo']['description'], 'missing seo keys keep defaults');
        $this->assertSame($fromJson, $fromArray, 'string and array inputs decode identically');
    }

    #[Test]
    public function reading_time_floors_at_one_minute(): void
    {
        $this->assertSame(1, $this->posts->readingTime(''));
        $this->assertSame(1, $this->posts->readingTime('<p>just a few words</p>'));
        $this->assertSame(3, $this->posts->readingTime(str_repeat('w ', 500)), '500 words → ceil(2.5) = 3');
    }

    // ----- saveArticle ---------------------------------------------------------------------------

    #[Test]
    public function save_article_writes_a_page_row_typed_article_and_snapshots_a_version(): void
    {
        $id = $this->makeArticle(['title' => 'Saved One', 'body' => 'hello world words']);

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame(Blog_Model_Post::TYPE_ARTICLE, $row->type, 'stored as an article page');
        $this->assertSame('Saved One', $row->title);
        $this->assertSame(Blog_Model_Post::FORMAT_HTML, $row->format);
        $this->assertSame('org-test', $row->org_id, 'org-scoped from the acting tenant');

        $meta = json_decode($row->meta, true);
        $this->assertArrayHasKey('reading_time', $meta, 'meta packed onto the row');

        $this->assertCount(1, (new Tiger_Model_PageVersion())->recentForPage($id, 10), 'one version snapshot');
    }

    #[Test]
    public function save_article_updates_in_place_and_adds_a_version(): void
    {
        $id = $this->makeArticle(['slug' => 'stable-slug', 'page_key' => 'stable-slug', 'title' => 'V1']);
        $again = $this->posts->saveArticle([
            'page_key' => 'stable-slug', 'slug' => 'stable-slug', 'locale' => 'en',
            'title' => 'V2', 'body' => 'more', 'status' => 'published',
        ], $id);

        $this->assertSame($id, $again, 'same page_id → an update');
        $this->assertSame('V2', (new Tiger_Model_Page())->findById($id)->title);
        $this->assertCount(2, (new Tiger_Model_PageVersion())->recentForPage($id, 10));
    }

    #[Test]
    public function save_article_defaults_status_and_null_published_at(): void
    {
        $id = $this->posts->saveArticle([
            'page_key' => 'defaulted', 'slug' => 'defaulted', 'locale' => 'en',
            'title' => 'Defaulted', 'body' => 'x',   // no status, no published_at
        ]);
        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame(Blog_Model_Post::STATUS_DRAFT, $row->status, 'status defaults to draft');
        $this->assertNull($row->published_at, 'empty published_at stored as NULL');
    }

    // ----- resolveArticle ------------------------------------------------------------------------

    #[Test]
    public function resolve_article_returns_a_published_article_by_slug(): void
    {
        $this->makeArticle(['slug' => 'find-me', 'page_key' => 'find-me', 'status' => 'published']);
        $found = $this->posts->resolveArticle('find-me', 'en', 'org-test');
        $this->assertNotNull($found);
        $this->assertSame('find-me', $found->slug);
    }

    #[Test]
    public function resolve_article_does_not_surface_a_draft(): void
    {
        $this->makeArticle(['slug' => 'hidden', 'page_key' => 'hidden', 'status' => 'draft']);
        $this->assertNull($this->posts->resolveArticle('hidden', 'en', 'org-test'), 'drafts are not resolvable');
    }

    // ----- present -------------------------------------------------------------------------------

    #[Test]
    public function present_projects_a_bare_row_with_no_author_or_feature(): void
    {
        $id  = $this->makeArticle(['title' => 'Bare', 'slug' => 'bare', 'page_key' => 'bare', 'kicker' => 'Kick', 'subtitle' => 'Sub']);
        $row = (new Tiger_Model_Page())->findById($id);

        $data = $this->posts->present($row);
        $this->assertSame('Bare', $data['title']);
        $this->assertSame('/blog/bare', $data['url']);
        $this->assertSame('Kick', $data['kicker']);
        $this->assertSame('Sub', $data['excerpt'], 'excerpt falls back to subtitle when unset');
        $this->assertSame('', $data['author']['name'], 'no author id → empty name');
        $this->assertNull($data['feature'], 'no feature id → null feature');
    }

    #[Test]
    public function present_resolves_the_author_name_and_feature_media(): void
    {
        $uid = (new Tiger_Model_User())->insert(['email' => 'byline@example.com', 'username' => 'byline']);
        $mid = (new Tiger_Model_Media())->insert([
            'org_id' => 'org-test', 'filename' => 'hero.jpg', 'mime_type' => 'image/jpeg',
            'storage_key' => 'k/hero.jpg', 'disk' => 'local', 'kind' => 'image',
        ]);

        $id  = $this->makeArticle(['title' => 'Rich', 'slug' => 'rich', 'page_key' => 'rich', 'author_id' => $uid, 'feature_media_id' => $mid]);
        $row = (new Tiger_Model_Page())->findById($id);

        $data = $this->posts->present($row);
        $this->assertSame($uid, $data['author']['id']);
        $this->assertSame('byline', $data['author']['name'], 'author name resolved from the user row');
        $this->assertIsArray($data['feature']);
        $this->assertSame($mid, $data['feature']['id']);
        $this->assertArrayHasKey('url', $data['feature']);
    }

    // ----- published listing ---------------------------------------------------------------------

    #[Test]
    public function published_lists_live_articles_newest_first_and_skips_future_and_drafts(): void
    {
        $this->makeArticle(['title' => 'Old', 'slug' => 'old', 'page_key' => 'old', 'status' => 'published', 'published_at' => '2020-01-01 00:00:00']);
        $this->makeArticle(['title' => 'New', 'slug' => 'new', 'page_key' => 'new', 'status' => 'published', 'published_at' => '2024-01-01 00:00:00']);
        $this->makeArticle(['title' => 'Draft', 'slug' => 'draft', 'page_key' => 'draft', 'status' => 'draft']);
        $this->makeArticle(['title' => 'Future', 'slug' => 'future', 'page_key' => 'future', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s', time() + 86400)]);

        $rows = [];
        foreach ($this->posts->published(['locale' => 'en', 'orgId' => 'org-test', 'limit' => 20]) as $r) { $rows[] = $r->title; }

        $this->assertContains('New', $rows);
        $this->assertContains('Old', $rows);
        $this->assertNotContains('Draft', $rows, 'drafts excluded');
        $this->assertNotContains('Future', $rows, 'scheduled-future excluded');
        $this->assertSame('New', $rows[0], 'newest published_at first');
    }

    #[Test]
    public function published_scopes_to_page_ids_and_short_circuits_on_empty(): void
    {
        $keep = $this->makeArticle(['title' => 'Keep', 'slug' => 'keep', 'page_key' => 'keep', 'status' => 'published', 'published_at' => '2023-01-01 00:00:00']);
        $this->makeArticle(['title' => 'Skip', 'slug' => 'skip', 'page_key' => 'skip', 'status' => 'published', 'published_at' => '2023-01-01 00:00:00']);

        $scoped = [];
        foreach ($this->posts->published(['locale' => 'en', 'orgId' => 'org-test', 'pageIds' => [$keep]]) as $r) { $scoped[] = $r->title; }
        $this->assertSame(['Keep'], $scoped, 'only the linked page id');

        $empty = $this->posts->published(['locale' => 'en', 'orgId' => 'org-test', 'pageIds' => []]);
        $this->assertSame([], $empty, 'no page ids → empty result, no query');
    }

    // ----- articleDatatable ----------------------------------------------------------------------

    #[Test]
    public function article_datatable_counts_searches_filters_and_paginates(): void
    {
        $this->makeArticle(['title' => 'Alpha Report', 'slug' => 'alpha', 'page_key' => 'alpha', 'status' => 'published']);
        $this->makeArticle(['title' => 'Beta Report', 'slug' => 'beta', 'page_key' => 'beta', 'status' => 'draft']);

        $all = $this->posts->articleDatatable(['limit' => 25, 'offset' => 0]);
        $this->assertSame(2, $all['total']);
        $this->assertSame(2, $all['filtered']);

        $searched = $this->posts->articleDatatable(['search' => 'Alpha', 'limit' => 25]);
        $this->assertSame(2, $searched['total'], 'total ignores search');
        $this->assertSame(1, $searched['filtered'], 'filtered honors search');
        $this->assertSame('Alpha Report', $searched['rows'][0]['title']);

        $draftsOnly = $this->posts->articleDatatable(['status' => 'draft', 'limit' => 25]);
        $this->assertSame(1, $draftsOnly['total'], 'status scopes the working set');

        $ordered = $this->posts->articleDatatable(['orderCol' => 0, 'orderDir' => 'ASC', 'limit' => 25]);
        $this->assertSame('Alpha Report', $ordered['rows'][0]['title'], 'title ASC order applied');

        $paged = $this->posts->articleDatatable(['limit' => 1, 'offset' => 0, 'orderCol' => 0, 'orderDir' => 'ASC']);
        $this->assertCount(1, $paged['rows'], 'limit paginates');
    }
}
