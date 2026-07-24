<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Menu;
use Tiger_Uuid;

/**
 * Tiger_Model_Menu — the flat, self-referential, tenant-cascading nav tree (one menu = the rows sharing
 * (`org_id`, `menu_key`); ARCHITECTURE §Content menus).
 *
 * The invariants under test:
 *   - GROUPING: flat()/tree() return only the rows of the asked-for menu_key (menus don't bleed together).
 *   - TENANT CASCADE is menu-LEVEL: a tenant that has any rows for a key REPLACES the global menu whole
 *     (no item-by-item merge); a tenant with none falls back to global.
 *   - reorder() is an ownership/scope GUARD: it only touches items already in the given (menu_key, org)
 *     scope — a client can't pull a foreign menu's or another org's rows into the update, and a parent_id
 *     that isn't a sibling in the same menu is refused (nulled), so the tree can't be corrupted.
 *   - PARENT/CHILD via self-reference assembles into a nested tree.
 */
#[CoversClass(Tiger_Model_Menu::class)]
final class MenuTest extends IntegrationTestCase
{
    private Tiger_Model_Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->menu = new Tiger_Model_Menu();
    }

    /**
     * reorder() opens its OWN transaction (correct in production), which can't nest inside the harness's
     * per-test transaction (Zend_Db/PDO has no nesting). So a reorder test commits its setup rows to
     * leave the harness transaction, lets reorder manage its own, and relies on tearDown() to scrub the
     * table (this isolated test DB's `menu` is owned entirely by this suite).
     */
    private function commitSetup(): void
    {
        $this->db->commit();
    }

    protected function tearDown(): void
    {
        // Remove any rows a reorder test committed outside the harness transaction. For a normal
        // (still-in-transaction) test this runs inside that transaction and is undone by the rollback.
        try {
            $this->db->query('DELETE FROM menu');
        } catch (\Throwable $e) {
            // ignore
        }
        parent::tearDown();
    }

    private function insertItem(array $overrides): string
    {
        return $this->menu->insert(array_merge([
            'org_id'     => '',
            'menu_key'   => 'primary',
            'parent_id'  => null,
            'sort_order' => 0,
            'label'      => 'Item',
            'status'     => Tiger_Model_Menu::STATUS_PUBLISHED,
        ], $overrides));
    }

    #[Test]
    public function items_are_grouped_by_menu_key(): void
    {
        $this->insertItem(['menu_key' => 'primary', 'label' => 'Home',   'sort_order' => 0]);
        $this->insertItem(['menu_key' => 'primary', 'label' => 'About',  'sort_order' => 1]);
        $this->insertItem(['menu_key' => 'footer',  'label' => 'Legal',  'sort_order' => 0]);

        $primary = $this->menu->flat('primary', '');
        $footer  = $this->menu->flat('footer', '');

        $this->assertCount(2, $primary, 'the primary menu has exactly its two items');
        $this->assertCount(1, $footer, 'the footer menu is a separate group');
        $labels = array_map(static fn ($r) => $r['label'], $primary);
        $this->assertSame(['Home', 'About'], $labels, 'ordered by sort_order within the menu');
    }

    #[Test]
    public function a_tenant_menu_replaces_the_global_menu_whole(): void
    {
        $orgA = Tiger_Uuid::v7();
        $orgB = Tiger_Uuid::v7();

        // Global 'primary' with two items…
        $this->insertItem(['menu_key' => 'primary', 'org_id' => '', 'label' => 'G-Home', 'sort_order' => 0]);
        $this->insertItem(['menu_key' => 'primary', 'org_id' => '', 'label' => 'G-About', 'sort_order' => 1]);
        // …and a tenant-A override with a SINGLE, different item.
        $this->insertItem(['menu_key' => 'primary', 'org_id' => $orgA, 'label' => 'A-Dashboard', 'sort_order' => 0]);

        // Tenant A sees ONLY its own menu — not a merge of global + tenant.
        $seenByA = $this->menu->flat('primary', $orgA);
        $this->assertCount(1, $seenByA, 'the tenant menu replaces global whole (no item merge)');
        $this->assertSame('A-Dashboard', $seenByA[0]['label']);

        // Tenant B has no rows for the key → falls back to the global menu.
        $seenByB = $this->menu->flat('primary', $orgB);
        $this->assertCount(2, $seenByB, 'a tenant with no own menu gets global');
        $this->assertSame(['G-Home', 'G-About'], array_map(static fn ($r) => $r['label'], $seenByB));
    }

    #[Test]
    public function reorder_only_touches_items_owned_by_the_given_menu_scope(): void
    {
        // Two menus in the global scope; 'other' is foreign to the reorder call below.
        $home  = $this->insertItem(['menu_key' => 'primary', 'label' => 'Home',  'sort_order' => 0]);
        $about = $this->insertItem(['menu_key' => 'primary', 'label' => 'About', 'sort_order' => 1]);
        $alien = $this->insertItem(['menu_key' => 'other',   'label' => 'Alien', 'sort_order' => 7]);
        $this->commitSetup();

        // The client tries to sneak the foreign 'other' item into a 'primary' reorder.
        $n = $this->menu->reorder([
            ['menu_id' => $home,  'parent_id' => null, 'sort_order' => 5],
            ['menu_id' => $alien, 'parent_id' => null, 'sort_order' => 0],   // must be ignored (not owned)
        ], 'primary', '');

        $this->assertSame(1, $n, 'only the one owned item was updated; the foreign item was skipped');
        $this->assertSame(5, (int) $this->menu->findById($home)->sort_order, 'the owned item moved');
        $this->assertSame(7, (int) $this->menu->findById($alien)->sort_order, 'the foreign menu\'s item is untouched');
        $this->assertSame(1, (int) $this->menu->findById($about)->sort_order, 'an unmentioned item keeps its order');
    }

    #[Test]
    public function reorder_cannot_pull_in_another_orgs_item(): void
    {
        $orgA = Tiger_Uuid::v7();
        $mine    = $this->insertItem(['menu_key' => 'primary', 'org_id' => '',   'label' => 'Mine',    'sort_order' => 0]);
        $foreign = $this->insertItem(['menu_key' => 'primary', 'org_id' => $orgA, 'label' => 'Foreign', 'sort_order' => 3]);
        $this->commitSetup();

        // Reordering the GLOBAL 'primary' must not be able to move org A's same-keyed item.
        $n = $this->menu->reorder([
            ['menu_id' => $mine,    'parent_id' => null, 'sort_order' => 9],
            ['menu_id' => $foreign, 'parent_id' => null, 'sort_order' => 0],
        ], 'primary', '');

        $this->assertSame(1, $n);
        $this->assertSame(3, (int) $this->menu->findById($foreign)->sort_order, 'a cross-org item is never touched');
    }

    #[Test]
    public function reorder_refuses_a_parent_that_is_not_a_sibling_in_the_menu(): void
    {
        $home  = $this->insertItem(['menu_key' => 'primary', 'label' => 'Home',  'sort_order' => 0]);
        $child = $this->insertItem(['menu_key' => 'primary', 'label' => 'Child', 'sort_order' => 1]);
        $alien = $this->insertItem(['menu_key' => 'other',   'label' => 'Alien', 'sort_order' => 0]);
        $this->commitSetup();

        // Try to re-parent an owned item under a FOREIGN item — the guard must null the bogus parent.
        $this->menu->reorder([
            ['menu_id' => $child, 'parent_id' => $alien, 'sort_order' => 0],
        ], 'primary', '');
        $this->assertNull($this->menu->findById($child)->parent_id, 'a parent outside the menu is refused (nulled)');

        // A legitimate re-parent under a real sibling sticks.
        $this->menu->reorder([
            ['menu_id' => $child, 'parent_id' => $home, 'sort_order' => 0],
        ], 'primary', '');
        $this->assertSame($home, $this->menu->findById($child)->parent_id, 'a real in-menu parent is accepted');
    }

    #[Test]
    public function the_tree_assembles_parent_child_via_self_reference(): void
    {
        $parent = $this->insertItem(['menu_key' => 'primary', 'label' => 'Products', 'sort_order' => 0]);
        $childA = $this->insertItem(['menu_key' => 'primary', 'label' => 'Widgets',  'parent_id' => $parent, 'sort_order' => 0]);
        $childB = $this->insertItem(['menu_key' => 'primary', 'label' => 'Gadgets',  'parent_id' => $parent, 'sort_order' => 1]);
        $this->insertItem(['menu_key' => 'primary', 'label' => 'Contact', 'sort_order' => 1]);   // a second top-level

        $tree = $this->menu->tree('primary');

        $this->assertCount(2, $tree, 'two top-level nodes (Products, Contact)');
        $this->assertSame('Products', $tree[0]['label']);
        $this->assertCount(2, $tree[0]['children'], 'Products has its two children nested under it');
        $this->assertSame(['Widgets', 'Gadgets'], array_map(static fn ($r) => $r['label'], $tree[0]['children']));
        $this->assertSame($parent, $tree[0]['children'][0]['parent_id'], 'the child self-references its parent');
        $this->assertSame([], $tree[1]['children'], 'the sibling top-level has no children');
    }
}
