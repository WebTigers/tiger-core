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
 * Tiger_Model_Menu, part 2 — the admin editor surface (MenuTest covers the front-end tree + reorder
 * guard). Here: the distinct-menus datatable, itemsForEditor (drafts included), nextSort (append at
 * the end of a level), keys() (the "add item to menu" picker source), tree() showing drafts for the
 * editor, and deleteItem() soft-deleting a subtree (a parent takes its descendants with it).
 */
#[CoversClass(Tiger_Model_Menu::class)]
final class MenuAdminTest extends IntegrationTestCase
{
    private Tiger_Model_Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->menu = new Tiger_Model_Menu();
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
    public function items_for_editor_includes_drafts_but_tree_front_end_excludes_them(): void
    {
        $org = Tiger_Uuid::v7();
        $this->insertItem(['org_id' => $org, 'label' => 'Live',  'status' => Tiger_Model_Menu::STATUS_PUBLISHED, 'sort_order' => 0]);
        $this->insertItem(['org_id' => $org, 'label' => 'Draft', 'status' => Tiger_Model_Menu::STATUS_DRAFT, 'sort_order' => 1]);

        $editor = $this->menu->itemsForEditor('primary', $org);
        $this->assertCount(2, $editor, 'the editor working set includes drafts');

        $publishedTree = $this->menu->tree('primary', $org, true);
        $this->assertCount(1, $publishedTree, 'the front-end tree excludes drafts');
        $this->assertSame('Live', $publishedTree[0]['label']);

        $draftTree = $this->menu->tree('primary', $org, false);
        $this->assertCount(2, $draftTree, 'the admin tree ($onlyPublished=false) shows drafts');
    }

    #[Test]
    public function next_sort_appends_at_the_end_of_a_level(): void
    {
        $org = Tiger_Uuid::v7();
        $this->assertSame(0, $this->menu->nextSort('primary', $org, null), 'an empty top level starts at 0');

        $this->insertItem(['org_id' => $org, 'label' => 'A', 'sort_order' => 0]);
        $this->insertItem(['org_id' => $org, 'label' => 'B', 'sort_order' => 4]);
        $this->assertSame(5, $this->menu->nextSort('primary', $org, null), 'next top-level sort is max+1');

        $parent = $this->insertItem(['org_id' => $org, 'label' => 'P', 'sort_order' => 1]);
        $this->insertItem(['org_id' => $org, 'label' => 'child', 'parent_id' => $parent, 'sort_order' => 2]);
        $this->assertSame(3, $this->menu->nextSort('primary', $org, $parent), 'next child sort is scoped to the parent');
    }

    #[Test]
    public function keys_lists_distinct_menu_keys_optionally_scoped_by_org(): void
    {
        $org = Tiger_Uuid::v7();
        $this->insertItem(['org_id' => '',   'menu_key' => 'primary']);
        $this->insertItem(['org_id' => '',   'menu_key' => 'footer']);
        $this->insertItem(['org_id' => $org, 'menu_key' => 'sidebar']);

        $all = $this->menu->keys();
        $this->assertContains('primary', $all);
        $this->assertContains('footer', $all);
        $this->assertContains('sidebar', $all);

        $scoped = $this->menu->keys($org);
        $this->assertSame(['sidebar'], $scoped, 'scoping by org lists only that org\'s keys');
    }

    #[Test]
    public function delete_item_soft_deletes_the_whole_subtree(): void
    {
        $org    = Tiger_Uuid::v7();
        $parent = $this->insertItem(['org_id' => $org, 'label' => 'Parent', 'sort_order' => 0]);
        $child  = $this->insertItem(['org_id' => $org, 'label' => 'Child', 'parent_id' => $parent, 'sort_order' => 0]);
        $grand  = $this->insertItem(['org_id' => $org, 'label' => 'Grandchild', 'parent_id' => $child, 'sort_order' => 0]);
        $sibling = $this->insertItem(['org_id' => $org, 'label' => 'Sibling', 'sort_order' => 1]);

        $n = $this->menu->deleteItem($parent);
        $this->assertSame(3, $n, 'the parent plus its two descendants were soft-deleted');

        // The subtree is gone from the editor set; the sibling survives.
        $remaining = array_column($this->menu->itemsForEditor('primary', $org), 'label');
        $this->assertSame(['Sibling'], $remaining, 'only the untouched sibling remains');
        // findById excludes deleted rows, so read the flag directly to prove the grandchild was soft-deleted.
        $grandDeleted = (int) $this->db->fetchOne($this->db->quoteInto('SELECT deleted FROM menu WHERE menu_id = ?', $grand));
        $this->assertSame(1, $grandDeleted, 'the grandchild is soft-deleted, not orphaned');
    }

    #[Test]
    public function datatable_lists_one_row_per_menu_with_an_item_count_and_search(): void
    {
        $this->insertItem(['menu_key' => 'primary', 'label' => 'Home']);
        $this->insertItem(['menu_key' => 'primary', 'label' => 'About']);
        $this->insertItem(['menu_key' => 'footer',  'label' => 'Legal']);

        $all = $this->menu->datatable(['limit' => 25]);
        $this->assertSame(2, $all['total'], 'two distinct menus (primary, footer)');
        $byKey = [];
        foreach ($all['rows'] as $r) { $byKey[$r['menu_key']] = (int) $r['items']; }
        $this->assertSame(2, $byKey['primary'], 'the primary menu counts its two items');
        $this->assertSame(1, $byKey['footer']);

        $search = $this->menu->datatable(['search' => 'foot', 'limit' => 25]);
        $this->assertSame(1, $search['filtered']);
        $this->assertSame('footer', $search['rows'][0]['menu_key']);
    }
}
