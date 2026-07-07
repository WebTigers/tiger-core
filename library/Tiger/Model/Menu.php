<?php
/**
 * Menu — custom navigation menus (see migration 0017).
 *
 * One flat, self-referential table; a menu = the rows sharing (`org_id`, `menu_key`).
 * This model is the data gateway: it owns the queries (tenant cascade, tree assembly,
 * reorder, the admin list) and knows nothing about rendering — turning a tree into
 * HTML is Tiger_Menu's job (via Zend_Navigation).
 *
 * TENANT CASCADE is menu-level: if the current org has any rows for a `menu_key`, that
 * whole menu is used; otherwise the global ('') menu. Menus don't merge item-by-item.
 *
 * @api
 */
class Tiger_Model_Menu extends Tiger_Model_Table
{
    protected $_name    = 'menu';
    protected $_primary = 'menu_id';

    const STATUS_PUBLISHED = 'published';
    const STATUS_DRAFT     = 'draft';

    /**
     * The assembled item TREE for a menu, honoring the tenant cascade. Each node is the
     * row's columns as an array plus a `children` array (recursively). Ordered by
     * `sort_order` within each level. Front-end callers get published-only; the admin
     * editor passes $onlyPublished = false to see drafts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tree($menuKey, $orgId = '', $onlyPublished = true)
    {
        $rows = $this->flat($menuKey, $orgId, $onlyPublished);
        return $this->_assemble($rows, null);
    }

    /**
     * Flat, ordered rows for a menu after the tenant cascade (the org's menu wins whole,
     * else global). Returned as plain arrays.
     *
     * @return array<int,array<string,mixed>>
     */
    public function flat($menuKey, $orgId = '', $onlyPublished = true)
    {
        $orgId = (string) $orgId;
        $rows  = $this->_scopeRows($menuKey, $orgId, $onlyPublished);
        // Menu-level tenant override: a tenant menu replaces global entirely.
        if (!$rows && $orgId !== '') {
            $rows = $this->_scopeRows($menuKey, '', $onlyPublished);
        }
        return $rows;
    }

    /** Rows for one exact scope (no cascade), ordered for tree assembly. */
    protected function _scopeRows($menuKey, $orgId, $onlyPublished)
    {
        $select = $this->activeSelect()
            ->where('menu_key = ?', (string) $menuKey)
            ->where('org_id = ?', (string) $orgId)
            ->order(['sort_order ASC', 'created_at ASC']);
        if ($onlyPublished) {
            $select->where('status = ?', self::STATUS_PUBLISHED);
        }
        return $this->getAdapter()->fetchAll($select);
    }

    /** Build the parent/child tree from flat rows (adjacency list -> nested arrays). */
    protected function _assemble(array $rows, $parentId)
    {
        $out = [];
        foreach ($rows as $row) {
            $rowParent = ($row['parent_id'] === null || $row['parent_id'] === '') ? null : $row['parent_id'];
            if ($rowParent === $parentId) {
                $row['children'] = $this->_assemble($rows, $row['menu_id']);
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Reorder / re-parent a batch of items in one transaction (drag-drop persistence).
     * Each entry: ['menu_id' => …, 'parent_id' => …|null, 'sort_order' => int]. Only
     * items already in the given menu (scope) are touched — a guard against a client
     * moving foreign rows.
     *
     * @param array<int,array{menu_id:string,parent_id:?string,sort_order:int}> $items
     * @return int rows updated
     */
    public function reorder(array $items, $menuKey, $orgId = '')
    {
        $db = $this->getAdapter();
        $db->beginTransaction();
        try {
            $owned = $this->_ownedIds($menuKey, $orgId);
            $n = 0;
            foreach ($items as $item) {
                $id = (string) ($item['menu_id'] ?? '');
                if ($id === '' || !isset($owned[$id])) {
                    continue;
                }
                $parent = (isset($item['parent_id']) && $item['parent_id'] !== '' && $item['parent_id'] !== null)
                    ? (string) $item['parent_id'] : null;
                // A parent must be another item in the same menu (or null); ignore junk.
                if ($parent !== null && !isset($owned[$parent])) {
                    $parent = null;
                }
                $this->update(
                    ['parent_id' => $parent, 'sort_order' => (int) ($item['sort_order'] ?? 0)],
                    $db->quoteInto('menu_id = ?', $id)
                );
                $n++;
            }
            $db->commit();
            return $n;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** menu_id => true for every live item in a menu scope (reorder ownership guard). */
    protected function _ownedIds($menuKey, $orgId)
    {
        $db   = $this->getAdapter();
        $rows = $db->fetchCol(
            $db->select()->from($this->_name, ['menu_id'])
                ->where('deleted = 0')
                ->where('menu_key = ?', (string) $menuKey)
                ->where('org_id = ?', (string) $orgId)
        );
        return array_fill_keys($rows, true);
    }

    /**
     * The list of distinct menus for the admin index: one entry per (org_id, menu_key)
     * with the item count. Optional search on the key. Server-side DataTables shape.
     *
     * @return array{total:int,filtered:int,rows:array}
     */
    public function datatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 25));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $base = function () use ($db) {
            return $db->select()->from($this->_name, [])->where('deleted = 0')
                ->group(['org_id', 'menu_key']);
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $sel->where('menu_key LIKE ?', $like);
        };

        // COUNT of groups = a subquery over the grouped select.
        $countOf = function ($sel) use ($db) {
            return (int) $db->fetchOne($db->select()->from(['g' => $sel], ['c' => new Zend_Db_Expr('COUNT(*)')]));
        };

        $totalSel = $base()->columns(['org_id']);
        $total    = $countOf($totalSel);

        $filteredSel = $base()->columns(['org_id']);
        $searchFn($filteredSel);
        $filtered = $countOf($filteredSel);

        $orderCols = [0 => 'menu_key', 1 => 'items', 2 => 'org_id'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'DESC') ? 'DESC' : 'ASC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'menu_key ASC';

        $rowsSel = $base()->columns([
            'menu_key',
            'org_id',
            'items'   => new Zend_Db_Expr('COUNT(*)'),
            'updated' => new Zend_Db_Expr('MAX(COALESCE(updated_at, created_at))'),
        ])->order(new Zend_Db_Expr($orderSql))->limit($limit, $offset);
        $searchFn($rowsSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($rowsSel)];
    }

    /** Every live item for a menu scope, flat + ordered — the admin editor's working set. */
    public function itemsForEditor($menuKey, $orgId = '')
    {
        return $this->_scopeRows($menuKey, (string) $orgId, false);
    }

    /** The next sort_order to append a new item at the end of its level. */
    public function nextSort($menuKey, $orgId, $parentId)
    {
        $db  = $this->getAdapter();
        $sel = $db->select()
            ->from($this->_name, ['m' => new Zend_Db_Expr('COALESCE(MAX(sort_order), -1)')])
            ->where('menu_key = ?', (string) $menuKey)
            ->where('org_id = ?', (string) $orgId)
            ->where('deleted = 0');
        if ($parentId === null || $parentId === '') {
            $sel->where('parent_id IS NULL');
        } else {
            $sel->where('parent_id = ?', (string) $parentId);
        }
        return ((int) $db->fetchOne($sel)) + 1;
    }

    /** Soft-delete an item AND all its descendants (deleting a parent removes its subtree). */
    public function deleteItem($menuId)
    {
        $ids   = $this->_descendantIds((string) $menuId);
        $ids[] = (string) $menuId;
        $ids   = array_values(array_unique($ids));
        $this->softDelete($this->getAdapter()->quoteInto('menu_id IN (?)', $ids));
        return count($ids);
    }

    /** All live descendant ids of an item (depth-first). */
    protected function _descendantIds($menuId, array $acc = [])
    {
        $db = $this->getAdapter();
        $children = $db->fetchCol(
            $db->select()->from($this->_name, ['menu_id'])
                ->where('deleted = 0')
                ->where('parent_id = ?', (string) $menuId)
        );
        foreach ($children as $childId) {
            $acc[] = $childId;
            $acc = $this->_descendantIds($childId, $acc);
        }
        return $acc;
    }

    /** Distinct menu_keys that already have rows (feeds the "add item to menu" pickers). */
    public function keys($orgId = null)
    {
        $db  = $this->getAdapter();
        $sel = $db->select()->distinct()->from($this->_name, ['menu_key'])
            ->where('deleted = 0')->order('menu_key ASC');
        if ($orgId !== null) {
            $sel->where('org_id = ?', (string) $orgId);
        }
        return $db->fetchCol($sel);
    }
}
