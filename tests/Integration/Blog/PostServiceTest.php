<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Blog;

use Blog_Form_Post;
use Blog_Model_Post;
use Blog_Model_Taxonomy;
use Blog_Service_Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Model_PageVersion;
use Zend_Registry;

/**
 * Blog_Service_Post — the /api authoring service for articles (datatable / save / delete / restore).
 *
 * Coverage: the ACL gate (admin+, deny-by-default), the DataTables envelope with server-computed
 * per-row flags + status filter + the computed `scheduled` flag, save() (slug derivation from
 * title, reserved-slug + blank-slug refusal, form-validation, taxonomy sync creating + linking
 * terms, update-in-place), soft-delete + its clean-error edge, and restore (+ its bad-version edge).
 *
 * Blog_Form_Post carries CSRF, so the request is flagged STATELESS (Tiger_Form's token-request skip).
 * Blog_Model_Post::saveArticle opens its own transaction, which nests under the harness txn via the
 * SavepointAdapter — happy paths dispatch inline and the per-test rollback isolates.
 */
#[CoversClass(Blog_Service_Post::class)]
#[CoversClass(Blog_Form_Post::class)]
final class PostServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    /** Dispatch the service (constructor runs the action) and return the response object. */
    private function call(string $action, array $params = []): object
    {
        return (new Blog_Service_Post(['action' => $action] + $params))->getResponse();
    }

    /** Seed an article row directly (stays in the harness txn — for read/datatable tests). */
    private function seedArticle(array $overrides): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'type'     => Blog_Model_Post::TYPE_ARTICLE,
            'org_id'   => 'org-test',
            'locale'   => 'en',
            'title'    => 'Seed Article',
            'body'     => '',
            'format'   => Tiger_Model_Page::FORMAT_HTML,
            'status'   => Blog_Model_Post::STATUS_DRAFT,
            'meta'     => json_encode(Blog_Model_Post::META_DEFAULTS),
        ], $overrides));
    }

    // ----- ACL gate ------------------------------------------------------------------------------

    #[Test]
    public function guest_and_plain_user_are_denied_admin_clears(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $this->assertStringContainsString('not_allowed', json_encode($this->call('datatable')->messages), 'guest denied');

        $this->loginAs('user');
        $this->assertSame(0, (int) $this->call('datatable')->result, 'plain user denied');

        $this->loginAs('admin');
        $this->assertSame(1, (int) $this->call('datatable', ['draw' => 1])->result, 'admin allowed');
    }

    // ----- datatable -----------------------------------------------------------------------------

    #[Test]
    public function datatable_returns_the_envelope_with_flags_and_handle(): void
    {
        $this->loginAs('admin');
        $this->seedArticle(['title' => 'Grid Article', 'slug' => 'grid-article', 'page_key' => 'grid-article']);

        $data = $this->call('datatable', ['draw' => 7, 'start' => 0, 'length' => 25, 'search' => 'Grid Article'])->data;
        $this->assertSame(7, $data['draw']);
        $this->assertSame(1, $data['recordsFiltered']);
        $row = $data['data'][0];
        $this->assertSame('Grid Article', $row['title']);
        $this->assertSame('/blog/grid-article', $row['handle'], 'handle is /blog/<slug>');
        $this->assertTrue($row['can_edit']);
        $this->assertArrayHasKey('can_delete', $row);
    }

    #[Test]
    public function datatable_status_filter_scopes_and_untitled_falls_back(): void
    {
        $this->loginAs('admin');
        $this->seedArticle(['title' => 'A Draft', 'slug' => 'a-draft', 'page_key' => 'a-draft', 'status' => 'draft']);
        $this->seedArticle(['title' => '', 'slug' => '', 'page_key' => 'pub-untitled', 'status' => 'published']);

        $drafts = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'status' => 'draft'])->data;
        $this->assertSame(1, $drafts['recordsTotal'], 'status filter scopes the working set');

        $all = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25])->data;
        $titles = array_column($all['data'], 'title');
        $this->assertContains('(untitled)', $titles, 'a blank title renders as (untitled)');
        // the untitled published row has an empty handle (no slug).
        foreach ($all['data'] as $r) {
            if ($r['title'] === '(untitled)') { $this->assertSame('', $r['handle'], 'no slug → empty handle'); }
        }
    }

    #[Test]
    public function datatable_flags_a_future_published_article_as_scheduled(): void
    {
        $this->loginAs('admin');
        $future = date('Y-m-d H:i:s', time() + 86400);
        $this->seedArticle(['title' => 'Sched Article', 'slug' => 'sched-article', 'page_key' => 'sched-article', 'status' => 'published', 'published_at' => $future]);

        $data = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => 'Sched Article'])->data;
        $this->assertTrue($data['data'][0]['scheduled'], 'published + future published_at = scheduled');
    }

    // ----- save ----------------------------------------------------------------------------------

    #[Test]
    public function save_creates_an_article_derives_slug_and_syncs_taxonomy(): void
    {
        $this->login('blog-admin', 'org-test', 'admin');

        $res = $this->call('save', [
            'title'      => 'My First Post',   // slug derived from this
            'body'       => '<p>Content body words here</p>',
            'status'     => 'published',
            'locale'     => 'en',
            'categories' => 'Tech, News',
            'tags'       => 'php, tiger',
        ]);
        $this->assertSame(1, (int) $res->result, json_encode($res->messages));
        $this->assertSame('/blog/post', $res->redirect, 'redirects to the article list');
        $id = $res->data['page_id'];

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame('My First Post', $row->title);
        $this->assertSame('my-first-post', $row->slug, 'slug derived from the title');
        $this->assertSame('org-test', $row->org_id, 'org-scoped');

        // taxonomy: two categories + two tags were minted and linked (4 terms).
        $tax   = new Blog_Model_Taxonomy();
        $names = array_map(fn($r) => $r['name'], $tax->forPage($id));
        $this->assertContains('Tech', $names);
        $this->assertContains('php', $names);
        $this->assertCount(4, $names, 'all four typed terms linked');

        $this->assertCount(1, (new Tiger_Model_PageVersion())->recentForPage($id, 10), 'one version snapshot');
    }

    #[Test]
    public function save_honors_an_explicit_slug(): void
    {
        $this->loginAs('admin');
        $res = $this->call('save', ['title' => 'Title Here', 'slug' => 'Custom Slug!', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('custom-slug', (new Tiger_Model_Page())->findById($res->data['page_id'])->slug, 'explicit slug is slugified');
    }

    #[Test]
    public function save_refuses_a_reserved_slug(): void
    {
        $this->loginAs('admin');
        $res = $this->call('save', ['title' => 'Feed', 'slug' => 'feed', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(0, (int) $res->result, 'a reserved path is refused');
        $this->assertStringContainsString('slug_reserved', json_encode($res->messages));
    }

    #[Test]
    public function save_refuses_when_the_slug_resolves_empty(): void
    {
        $this->loginAs('admin');
        // A title of only punctuation slugifies to '' (and no explicit slug given).
        $res = $this->call('save', ['title' => '!!!', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(0, (int) $res->result, 'an empty derived slug is refused');
        $this->assertStringContainsString('blog.error.slug', json_encode($res->messages));
    }

    #[Test]
    public function save_returns_a_form_error_for_a_blank_title(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM page');

        $res = $this->call('save', ['title' => '', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(0, (int) $res->result, 'title is required');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('title', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM page'), 'nothing inserted');
    }

    #[Test]
    public function save_updates_in_place_and_rewrites_taxonomy(): void
    {
        $this->login('blog-admin', 'org-test', 'admin');
        $create = $this->call('save', ['title' => 'Editable', 'slug' => 'editable', 'status' => 'draft', 'locale' => 'en', 'categories' => 'One']);
        $id = $create->data['page_id'];

        $update = $this->call('save', ['post_id' => $id, 'title' => 'Editable v2', 'slug' => 'editable', 'status' => 'published', 'locale' => 'en', 'categories' => 'Two']);
        $this->assertSame(1, (int) $update->result);
        $this->assertSame($id, $update->data['page_id'], 'same id — an update');

        $this->assertSame('Editable v2', (new Tiger_Model_Page())->findById($id)->title);
        $names = array_map(fn($r) => $r['name'], (new Blog_Model_Taxonomy())->forPage($id));
        $this->assertSame(['Two'], $names, 'taxonomy rewritten to the new set');
        $this->assertCount(2, (new Tiger_Model_PageVersion())->recentForPage($id, 10), 'each save snapshots a version');
    }

    #[Test]
    public function save_turns_a_duplicate_slug_into_a_clean_error(): void
    {
        $this->login('blog-admin', 'org-test', 'admin');
        $first = $this->call('save', ['title' => 'Unique One', 'slug' => 'dupe-slug', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(1, (int) $first->result);

        // A second NEW article with the same slug violates the (org_id, slug, locale) unique key →
        // saveArticle throws → the service catch turns it into result=0, not a fatal.
        $dup = $this->call('save', ['title' => 'Unique Two', 'slug' => 'dupe-slug', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(0, (int) $dup->result, 'a duplicate slug is a clean error');
        $this->assertNotEmpty($dup->messages);
    }

    // ----- delete --------------------------------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_and_reads_exclude_it(): void
    {
        $this->loginAs('admin');
        $model = new Blog_Model_Post();
        $id = $this->seedArticle(['title' => 'Doomed', 'slug' => 'doomed', 'page_key' => 'doomed']);

        $res = $this->call('delete', ['post_id' => $id]);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('/blog/post', $res->redirect);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM page WHERE page_id = ?', [$id]));
        $this->assertNull($model->findById($id), 'a deleted article is excluded from reads');
    }

    #[Test]
    public function delete_with_no_id_is_a_clean_error(): void
    {
        $this->loginAs('admin');
        $this->assertSame(0, (int) $this->call('delete', ['post_id' => ''])->result, 'a missing id is refused, not fatal');
    }

    // ----- restore -------------------------------------------------------------------------------

    #[Test]
    public function restore_reverts_the_article_to_a_prior_version(): void
    {
        $this->login('blog-admin', 'org-test', 'admin');
        $create = $this->call('save', ['title' => 'Original Title', 'slug' => 'restorable', 'status' => 'draft', 'locale' => 'en', 'body' => 'first']);
        $id = $create->data['page_id'];   // version 1
        $this->call('save', ['post_id' => $id, 'title' => 'Edited Title', 'slug' => 'restorable', 'status' => 'draft', 'locale' => 'en', 'body' => 'second']);   // version 2

        $res = $this->call('restore', ['post_id' => $id, 'version' => 1]);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('/blog/post/edit/id/' . $id, $res->redirect);

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame('Original Title', $row->title, 'reverted to version 1');
        $this->assertSame('first', $row->body);
        $this->assertCount(3, (new Tiger_Model_PageVersion())->recentForPage($id, 10), 'the restore itself snapshots a version');
    }

    #[Test]
    public function restore_with_a_bad_version_is_a_clean_error(): void
    {
        $this->loginAs('admin');
        $this->assertSame(0, (int) $this->call('restore', ['post_id' => 'x', 'version' => 0])->result, 'version < 1 refused up front');
        $this->assertSame(0, (int) $this->call('restore', ['post_id' => '', 'version' => 2])->result, 'a missing id is refused');
    }

    #[Test]
    public function restore_of_a_nonexistent_version_is_a_clean_error(): void
    {
        $this->login('blog-admin', 'org-test', 'admin');
        $create = $this->call('save', ['title' => 'Only V1', 'slug' => 'only-v1', 'status' => 'draft', 'locale' => 'en']);
        $id = $create->data['page_id'];

        // Version 99 doesn't exist → restoreVersion throws → the service catch → result=0 (not fatal).
        $res = $this->call('restore', ['post_id' => $id, 'version' => 99]);
        $this->assertSame(0, (int) $res->result, 'a missing version is a clean error');
        $this->assertNotEmpty($res->messages);
    }
}
