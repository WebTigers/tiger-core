<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Cms;

use Cms_Service_Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Model_PageVersion;
use Zend_Registry;

/**
 * Cms_Service_Page — the /api CRUD service for CMS authoring (datatable / save / delete / restore).
 *
 * Coverage: the ACL gate (admin+, deny-by-default), the DataTables envelope with server-computed
 * per-row flags plus the status/type filters and the computed `scheduled` flag, and the domain
 * specifics: save() is version-on-write (each save snapshots a page_version; a slug change leaves a
 * 301 page_redirect behind), org-scoped + created_by stamped, validated (a blank title → form error,
 * no row), soft-delete (reads exclude deleted), and restore-to-a-prior-version.
 *
 * Cms_Form_Page carries CSRF, so the request is flagged STATELESS (Tiger_Form's own token-request
 * skip). Tiger_Model_Page::save() opens its OWN transaction (write + version snapshot), which can't
 * nest inside the harness txn — save/restore tests commit the harness txn first (escapeTxn) and rely
 * on the tearDown scrub; the suite owns these tables (migrations seed no rows).
 */
#[CoversClass(Cms_Service_Page::class)]
final class PageServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->query('DELETE FROM page_version');
            $this->db->query('DELETE FROM page_redirect');
            $this->db->query('DELETE FROM page');
        } catch (\Throwable $e) {
            // ignore
        }
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Cms_Service_Page(['action' => $action] + $params))->getResponse();
    }

    /** Commit + leave the harness txn so Tiger_Model_Page::save can open its own. */
    private function escapeTxn(): void
    {
        $this->db->commit();
    }

    /** Seed a page row directly (stays in the harness txn — for read/datatable tests). */
    private function seedPage(array $overrides): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'title'  => 'Seed',
            'body'   => '',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_DRAFT,
        ], $overrides));
    }

    // ----- ACL gate -----------------------------------------------------------------------------

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

    // ----- datatable ----------------------------------------------------------------------------

    #[Test]
    public function datatable_returns_the_envelope_with_flags(): void
    {
        $this->loginAs('admin');
        $this->seedPage(['title' => 'Grid Page', 'slug' => 'grid-page', 'page_key' => 'grid-page']);

        $data = $this->call('datatable', ['draw' => 4, 'start' => 0, 'length' => 25, 'search' => 'Grid Page'])->data;
        $this->assertSame(4, $data['draw']);
        $this->assertSame(1, $data['recordsFiltered']);
        $row = $data['data'][0];
        $this->assertSame('Grid Page', $row['title']);
        $this->assertSame('/grid-page', $row['handle'], 'handle is the slug when set');
        $this->assertTrue($row['can_edit']);
        $this->assertArrayHasKey('can_delete', $row);
    }

    #[Test]
    public function datatable_type_filter_scopes_total_and_rows(): void
    {
        $this->loginAs('admin');
        $this->seedPage(['type' => Tiger_Model_Page::TYPE_PAGE, 'title' => 'A Page', 'slug' => 'a-page', 'page_key' => 'a-page']);
        $this->seedPage(['type' => Tiger_Model_Page::TYPE_LAYOUT, 'title' => 'A Layout', 'page_key' => 'a-layout']);

        $data = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'type' => 'layout'])->data;
        $this->assertSame(1, $data['recordsTotal'], 'the type filter scopes the working set');
        foreach ($data['data'] as $r) { $this->assertSame('layout', $r['type']); }
    }

    #[Test]
    public function datatable_flags_a_future_published_page_as_scheduled(): void
    {
        $this->loginAs('admin');
        $future = date('Y-m-d H:i:s', time() + 86400);
        $this->seedPage([
            'title' => 'Scheduled Page', 'slug' => 'sched', 'page_key' => 'sched',
            'status' => Tiger_Model_Page::STATUS_PUBLISHED, 'published_at' => $future,
        ]);

        $data = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => 'Scheduled Page'])->data;
        $this->assertTrue($data['data'][0]['scheduled'], 'published + future published_at = scheduled');
    }

    // ----- save (version-on-write) --------------------------------------------------------------

    #[Test]
    public function save_creates_a_page_org_scoped_stamped_and_versioned(): void
    {
        $this->login('page-admin', 'org-test', 'admin');
        $this->escapeTxn();

        $res = $this->call('save', [
            'title' => 'Hello World', 'slug' => 'hello-world', 'type' => 'page',
            'format' => 'html', 'status' => 'draft', 'locale' => 'en', 'body' => '<p>Hi</p>',
        ]);
        $this->assertSame(1, (int) $res->result);
        $id = $res->data['page_id'];

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame('Hello World', $row->title);
        $this->assertSame('hello-world', $row->slug);
        $this->assertSame('page-admin', $row->created_by, 'created_by stamped from the acting admin');
        $this->assertSame('org-test', $row->org_id, 'org-scoped from the acting tenant');

        $versions = (new Tiger_Model_PageVersion())->recentForPage($id, 10);
        $this->assertCount(1, $versions, 'the save snapshotted exactly one version');
        $this->assertSame('Hello World', $versions[0]->title, 'the snapshot carries the saved content');
    }

    #[Test]
    public function save_derives_a_slug_and_key_from_the_title(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $res = $this->call('save', ['title' => 'Auto Slug Page', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(1, (int) $res->result);
        $row = (new Tiger_Model_Page())->findById($res->data['page_id']);
        $this->assertSame('auto-slug-page', $row->slug, 'slug derived from title for a page');
        $this->assertSame('auto-slug-page', $row->page_key, 'page_key always set');
    }

    #[Test]
    public function a_blank_title_returns_a_form_error_and_writes_no_row(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM page');

        $res = $this->call('save', ['title' => '', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame(0, (int) $res->result, 'title is required');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('title', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM page'), 'nothing inserted');
    }

    #[Test]
    public function save_updates_in_place_and_adds_a_version(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $create = $this->call('save', ['title' => 'V1 Title', 'slug' => 'v-page', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en', 'body' => 'one']);
        $id = $create->data['page_id'];

        $update = $this->call('save', ['page_id' => $id, 'title' => 'V2 Title', 'slug' => 'v-page', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en', 'body' => 'two']);
        $this->assertSame(1, (int) $update->result);
        $this->assertSame($id, $update->data['page_id'], 'same id — an update');

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame('V2 Title', $row->title);
        $this->assertCount(2, (new Tiger_Model_PageVersion())->recentForPage($id, 10), 'each save snapshots a version');
    }

    #[Test]
    public function changing_the_slug_leaves_a_301_redirect_behind(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $create = $this->call('save', ['title' => 'Movable', 'slug' => 'old-path', 'type' => 'page', 'format' => 'html', 'status' => 'published', 'locale' => 'en']);
        $id = $create->data['page_id'];

        $this->call('save', ['page_id' => $id, 'title' => 'Movable', 'slug' => 'new-path', 'type' => 'page', 'format' => 'html', 'status' => 'published', 'locale' => 'en']);

        $redirect = $this->db->fetchRow('SELECT from_slug, to_slug FROM page_redirect WHERE from_slug = ?', ['old-path']);
        $this->assertNotFalse($redirect, 'a redirect row was recorded from the old slug');
        $this->assertSame('new-path', $redirect['to_slug'], 'it points at the new slug');
    }

    // ----- delete (soft-delete) -----------------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_and_reads_exclude_it(): void
    {
        $this->loginAs('admin');
        $model = new Tiger_Model_Page();
        $id = $this->seedPage(['title' => 'Doomed Page', 'slug' => 'doomed', 'page_key' => 'doomed']);

        $this->assertSame(1, (int) $this->call('delete', ['page_id' => $id])->result);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM page WHERE page_id = ?', [$id]));
        $this->assertNull($model->findById($id), 'a deleted page is excluded from reads');
    }

    #[Test]
    public function delete_with_no_id_is_a_clean_error(): void
    {
        $this->loginAs('admin');
        $res = $this->call('delete', ['page_id' => '']);
        $this->assertSame(0, (int) $res->result, 'a missing id is refused, not a fatal');
    }

    // ----- restore (to a prior version) ---------------------------------------------------------

    #[Test]
    public function restore_reverts_the_page_to_a_prior_version(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $create = $this->call('save', ['title' => 'Original', 'slug' => 'restorable', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en', 'body' => 'first body']);
        $id = $create->data['page_id'];   // version 1 = "Original"
        $this->call('save', ['page_id' => $id, 'title' => 'Edited', 'slug' => 'restorable', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en', 'body' => 'second body']);   // version 2

        $res = $this->call('restore', ['page_id' => $id, 'version' => 1]);
        $this->assertSame(1, (int) $res->result);

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame('Original', $row->title, 'the page reverted to version 1');
        $this->assertSame('first body', $row->body);
        $this->assertCount(3, (new Tiger_Model_PageVersion())->recentForPage($id, 10), 'the restore itself snapshots a new version');
    }

    #[Test]
    public function restore_with_a_bad_version_is_a_clean_error(): void
    {
        $this->loginAs('admin');
        $res = $this->call('restore', ['page_id' => 'whatever', 'version' => 0]);
        $this->assertSame(0, (int) $res->result, 'version < 1 is refused up front');
    }
}
