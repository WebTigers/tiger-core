<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Access;

use Access_Service_Org;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Org;
use Zend_Registry;

/**
 * Access_Service_Org — the /api CRUD service behind the Organizations admin (datatable / save /
 * delete).
 *
 * An org is a tenant with a self-referential parent and a URL-safe unique slug. Coverage: the ACL
 * gate (admin+, deny-by-default), the DataTables envelope with server-computed per-row flags, the
 * validate→write save (name required; slug slugified + uniqueness-guarded; created_by stamped; the
 * new org gets its OWN generated org_id, not the actor's tenant; the parent-is-self guard), and the
 * soft-delete with its "not the org you're acting in" guard.
 *
 * Note: Access_Service_Org::save() writes directly (no service-level _transaction), so these tests
 * live entirely inside the harness's per-test transaction — no commit/scrub dance needed.
 */
#[CoversClass(Access_Service_Org::class)]
final class OrgServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);   // CSRF-immune API path (no session in CLI)
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Access_Service_Org(['action' => $action] + $params))->getResponse();
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
    public function datatable_returns_the_envelope_and_flags(): void
    {
        $this->loginAs('admin');
        (new Tiger_Model_Org())->insert(['name' => 'Acme Grid Co', 'slug' => 'acme-grid', 'status' => 'active']);

        $res  = $this->call('datatable', ['draw' => 3, 'start' => 0, 'length' => 25, 'search' => 'acme-grid']);
        $data = $res->data;

        $this->assertSame(3, $data['draw']);
        $this->assertSame(1, $data['recordsFiltered'], 'search narrows to the one match');
        $row = $data['data'][0];
        $this->assertSame('Acme Grid Co', $row['name']);
        $this->assertSame('acme-grid', $row['slug']);
        $this->assertTrue($row['can_edit']);
        $this->assertArrayHasKey('member_count', $row);
    }

    #[Test]
    public function datatable_marks_the_current_org_and_blocks_deleting_it(): void
    {
        $orgId = (new Tiger_Model_Org())->insert(['name' => 'Current Tenant', 'slug' => 'current-tenant', 'status' => 'active']);
        $this->login('actor', $orgId, 'admin');   // act IN this org

        $res  = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'search' => 'current-tenant']);
        $mine = null;
        foreach ($res->data['data'] as $r) { if ($r['org_id'] === $orgId) { $mine = $r; break; } }

        $this->assertNotNull($mine);
        $this->assertFalse($mine['can_delete'], 'you cannot delete the org you are acting in');
    }

    // ----- save ---------------------------------------------------------------------------------

    #[Test]
    public function save_persists_a_new_org_with_a_fresh_id_and_stamps_created_by(): void
    {
        $this->login('org-admin', 'org-test', 'admin');
        $res = $this->call('save', ['name' => 'New Studio', 'slug' => 'New Studio!', 'status' => 'active']);

        $this->assertSame(1, (int) $res->result);
        $id = $res->data['org_id'];
        $this->assertNotEmpty($id);
        $this->assertNotSame('org-test', $id, 'the new org gets its OWN id, not the actor tenant');

        $row = (new Tiger_Model_Org())->findById($id);
        $this->assertSame('New Studio', $row->name);
        $this->assertSame('new-studio', $row->slug, 'slug is slugified (lowercased, non-alnum -> hyphen)');
        $this->assertSame('org-admin', $row->created_by, 'created_by stamped from the acting admin');
        $this->assertSame(0, (int) $row->deleted);
    }

    #[Test]
    public function save_derives_the_slug_from_the_name_when_slug_is_blank(): void
    {
        $this->loginAs('admin');
        $res = $this->call('save', ['name' => 'Derived Name Org', 'slug' => '', 'status' => 'active']);
        $this->assertSame(1, (int) $res->result);
        $row = (new Tiger_Model_Org())->findById($res->data['org_id']);
        $this->assertSame('derived-name-org', $row->slug);
    }

    #[Test]
    public function an_invalid_payload_returns_form_errors_and_writes_no_row(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM org');

        $res = $this->call('save', ['name' => '', 'slug' => 'x', 'status' => 'active']);

        $this->assertSame(0, (int) $res->result, 'name is required');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('name', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM org'), 'no row written');
    }

    #[Test]
    public function save_rejects_a_duplicate_slug(): void
    {
        $this->loginAs('admin');
        (new Tiger_Model_Org())->insert(['name' => 'First', 'slug' => 'dup-slug', 'status' => 'active']);
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM org');

        $res = $this->call('save', ['name' => 'Second', 'slug' => 'dup-slug', 'status' => 'active']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('slug_taken', json_encode($res->messages));
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM org'));
    }

    #[Test]
    public function save_refuses_an_org_that_is_its_own_parent(): void
    {
        $this->loginAs('admin');
        $id = (new Tiger_Model_Org())->insert(['name' => 'Selfy', 'slug' => 'selfy', 'status' => 'active']);

        $res = $this->call('save', ['org_id' => $id, 'name' => 'Selfy', 'slug' => 'selfy', 'parent_org_id' => $id, 'status' => 'active']);
        $this->assertSame(0, (int) $res->result, 'an org cannot parent itself');
        $this->assertStringContainsString('parent_self', json_encode($res->messages));
    }

    #[Test]
    public function save_updates_an_existing_org_in_place(): void
    {
        $this->loginAs('admin');
        $id = (new Tiger_Model_Org())->insert(['name' => 'Old Name', 'slug' => 'old-slug', 'status' => 'active']);

        $res = $this->call('save', ['org_id' => $id, 'name' => 'New Name', 'slug' => 'old-slug', 'status' => 'suspended']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame($id, $res->data['org_id']);

        $row = (new Tiger_Model_Org())->findById($id);
        $this->assertSame('New Name', $row->name);
        $this->assertSame('suspended', $row->status);
    }

    // ----- delete (soft-delete) -----------------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_and_reads_exclude_it(): void
    {
        $this->loginAs('admin');
        $model = new Tiger_Model_Org();
        $id = $model->insert(['name' => 'Doomed Org', 'slug' => 'doomed-org', 'status' => 'active']);

        $this->assertSame(1, (int) $this->call('delete', ['org_id' => $id])->result);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM org WHERE org_id = ?', [$id]));
        $this->assertNull($model->findById($id), 'a deleted org is excluded from reads');
    }

    #[Test]
    public function delete_refuses_the_org_you_are_acting_in(): void
    {
        $model = new Tiger_Model_Org();
        $id = $model->insert(['name' => 'My Tenant', 'slug' => 'my-tenant', 'status' => 'active']);
        $this->login('actor', $id, 'admin');

        $res = $this->call('delete', ['org_id' => $id]);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('no_self_delete', json_encode($res->messages));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM org WHERE org_id = ?', [$id]), 'still live');
    }
}
