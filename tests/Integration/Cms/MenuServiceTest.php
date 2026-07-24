<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Cms;

use Cms_Service_Menu;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Menu;

/**
 * Cms_Service_Menu — the /api service for the CMS Menus admin (datatable / save / delete / deleteMenu
 * / reorder).
 *
 * Coverage: the ACL gate (admin+), the DataTables envelope (one row per (org, menu_key) with an item
 * count + per-row flags), the validate→write save of one item (label required; menu_key required;
 * created_by stamped; sort_order appended via nextSort), soft-deleting an item AND its subtree
 * (deleteItem), soft-deleting a whole menu (deleteMenu), and the drag-drop reorder (a batch
 * re-parent + re-sort that ONLY touches items in the given scope).
 *
 * Cms_Form_MenuItem disables CSRF (the builder saves rapidly, reload-free), so no stateless flag is
 * needed. save/delete/deleteMenu write directly (harness transaction); reorder() opens its OWN
 * transaction (can't nest), so that test commits its setup then relies on the tearDown scrub — the
 * pattern the model MenuTest uses.
 */
#[CoversClass(Cms_Service_Menu::class)]
final class MenuServiceTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // reorder() commits outside the harness txn; scrub the suite-owned menu table. For an
        // in-transaction test this runs inside that txn and is undone by the base rollback.
        try {
            $this->db->query('DELETE FROM menu');
        } catch (\Throwable $e) {
            // ignore
        }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Cms_Service_Menu(['action' => $action] + $params))->getResponse();
    }

    private function seedItem(array $overrides): string
    {
        return (new Tiger_Model_Menu())->insert(array_merge([
            'org_id'     => '',
            'menu_key'   => 'primary',
            'parent_id'  => null,
            'sort_order' => 0,
            'label'      => 'Item',
            'status'     => 'published',
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
    public function datatable_groups_by_menu_key_with_counts_and_flags(): void
    {
        $this->loginAs('admin');
        $this->seedItem(['menu_key' => 'primary', 'label' => 'Home',  'sort_order' => 0]);
        $this->seedItem(['menu_key' => 'primary', 'label' => 'About', 'sort_order' => 1]);
        $this->seedItem(['menu_key' => 'footer',  'label' => 'Legal', 'sort_order' => 0]);

        $res  = $this->call('datatable', ['draw' => 2, 'start' => 0, 'length' => 25]);
        $data = $res->data;

        $this->assertSame(2, $data['draw']);
        $this->assertSame(2, $data['recordsTotal'], 'two distinct menus (primary, footer)');

        $byKey = [];
        foreach ($data['data'] as $r) { $byKey[$r['menu_key']] = $r; }
        $this->assertSame(2, $byKey['primary']['items'], 'primary has two items');
        $this->assertSame(1, $byKey['footer']['items']);
        $this->assertSame('Global', $byKey['primary']['scope'], 'org_id "" reads as Global scope');
        $this->assertTrue($byKey['primary']['can_edit']);
    }

    #[Test]
    public function datatable_search_narrows_by_menu_key(): void
    {
        $this->loginAs('admin');
        $this->seedItem(['menu_key' => 'primary', 'label' => 'Home']);
        $this->seedItem(['menu_key' => 'sidebar-nav', 'label' => 'Widget']);

        $data = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => 'sidebar'])->data;
        $this->assertSame(1, $data['recordsFiltered']);
        $this->assertSame('sidebar-nav', $data['data'][0]['menu_key']);
    }

    // ----- save ---------------------------------------------------------------------------------

    #[Test]
    public function save_inserts_an_item_stamping_created_by_and_appending_sort_order(): void
    {
        $this->login('menu-admin', 'org-test', 'admin');
        $this->seedItem(['menu_key' => 'primary', 'label' => 'First', 'sort_order' => 0]);

        $res = $this->call('save', ['menu_key' => 'primary', 'org_id' => '', 'label' => 'Second', 'url' => '/second']);
        $this->assertSame(1, (int) $res->result);
        $id = $res->data['menu_id'];

        $row = (new Tiger_Model_Menu())->find($id)->current();
        $this->assertSame('Second', $row->label);
        $this->assertSame('/second', $row->url);
        $this->assertSame('menu-admin', $row->created_by, 'created_by stamped');
        $this->assertSame(1, (int) $row->sort_order, 'appended after the existing item (nextSort)');
        $this->assertSame('published', $row->status);
    }

    #[Test]
    public function save_requires_a_menu_key(): void
    {
        $this->loginAs('admin');
        $res = $this->call('save', ['menu_key' => '', 'label' => 'Orphan']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('key_required', json_encode($res->messages));
    }

    #[Test]
    public function save_requires_a_label_and_writes_no_row(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM menu');

        $res = $this->call('save', ['menu_key' => 'primary', 'org_id' => '', 'label' => '']);
        $this->assertSame(0, (int) $res->result, 'label is required');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('label', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM menu'));
    }

    #[Test]
    public function save_updates_an_existing_item_in_place(): void
    {
        $this->loginAs('admin');
        $id = $this->seedItem(['menu_key' => 'primary', 'label' => 'Old Label']);

        $res = $this->call('save', ['menu_id' => $id, 'menu_key' => 'primary', 'label' => 'New Label', 'url' => '/new']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame($id, $res->data['menu_id']);

        $row = (new Tiger_Model_Menu())->find($id)->current();
        $this->assertSame('New Label', $row->label);
        $this->assertSame('/new', $row->url);
    }

    // ----- delete (soft-delete a subtree) -------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_the_item_and_its_descendants(): void
    {
        $this->loginAs('admin');
        $parent = $this->seedItem(['menu_key' => 'primary', 'label' => 'Parent', 'sort_order' => 0]);
        $child  = $this->seedItem(['menu_key' => 'primary', 'label' => 'Child', 'parent_id' => $parent, 'sort_order' => 0]);

        $res = $this->call('delete', ['menu_id' => $parent]);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame(2, (int) $res->data['deleted'], 'the parent + its one child');

        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM menu WHERE menu_id = ?', [$parent]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM menu WHERE menu_id = ?', [$child]), 'subtree removed');
    }

    // ----- deleteMenu (whole menu) --------------------------------------------------------------

    #[Test]
    public function delete_menu_soft_deletes_every_item_in_the_scope(): void
    {
        $this->loginAs('admin');
        $this->seedItem(['menu_key' => 'primary', 'label' => 'A']);
        $this->seedItem(['menu_key' => 'primary', 'label' => 'B']);
        $survivor = $this->seedItem(['menu_key' => 'footer', 'label' => 'Keep']);

        $res = $this->call('deleteMenu', ['menu_key' => 'primary', 'org_id' => '']);
        $this->assertSame(1, (int) $res->result);

        $live = (int) $this->db->fetchOne("SELECT COUNT(*) FROM menu WHERE menu_key = 'primary' AND deleted = 0");
        $this->assertSame(0, $live, 'the whole primary menu is gone');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM menu WHERE menu_id = ?', [$survivor]), 'a different menu is untouched');
    }

    // ----- reorder (opens its own transaction) --------------------------------------------------

    #[Test]
    public function reorder_persists_new_parent_and_sort_order_within_the_scope(): void
    {
        $this->loginAs('admin');
        $a = $this->seedItem(['menu_key' => 'primary', 'label' => 'A', 'sort_order' => 0]);
        $b = $this->seedItem(['menu_key' => 'primary', 'label' => 'B', 'sort_order' => 1]);
        $this->db->commit();   // escape the harness txn — reorder opens its own

        $tree = json_encode([
            ['menu_id' => $b, 'parent_id' => null, 'sort_order' => 0],
            ['menu_id' => $a, 'parent_id' => $b,   'sort_order' => 0],   // A nested under B
        ]);
        $res = $this->call('reorder', ['menu_key' => 'primary', 'org_id' => '', 'tree' => $tree]);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame(2, (int) $res->data['updated'], 'both owned items updated');

        $rowA = $this->db->fetchRow('SELECT parent_id, sort_order FROM menu WHERE menu_id = ?', [$a]);
        $rowB = $this->db->fetchRow('SELECT parent_id, sort_order FROM menu WHERE menu_id = ?', [$b]);
        $this->assertSame($b, $rowA['parent_id'], 'A is re-parented under B');
        $this->assertSame(0, (int) $rowA['sort_order']);
        $this->assertNull($rowB['parent_id'], 'B is now top-level');
    }

    #[Test]
    public function reorder_ignores_items_outside_the_named_menu(): void
    {
        $this->loginAs('admin');
        $inScope = $this->seedItem(['menu_key' => 'primary', 'label' => 'In', 'sort_order' => 0]);
        $foreign = $this->seedItem(['menu_key' => 'other', 'label' => 'Foreign', 'sort_order' => 0]);
        $this->db->commit();

        $tree = json_encode([
            ['menu_id' => $inScope, 'parent_id' => null, 'sort_order' => 5],
            ['menu_id' => $foreign, 'parent_id' => null, 'sort_order' => 9],   // not in 'primary' — must be ignored
        ]);
        $res = $this->call('reorder', ['menu_key' => 'primary', 'org_id' => '', 'tree' => $tree]);

        $this->assertSame(1, (int) $res->data['updated'], 'only the in-scope item is touched');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT sort_order FROM menu WHERE menu_id = ?', [$foreign]), 'the foreign item is untouched');
    }
}
