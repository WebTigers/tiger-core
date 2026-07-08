<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms_Service_Menu — the /api service for the CMS Menus admin.
 *
 * Thin, ACL-gated (admin+, modules/cms/configs/acl.ini), validate-then-write. Powers the
 * menus list (datatable) and the item editor: save/delete an item, delete a whole menu,
 * and reorder (the drag-drop persistence — a batch re-parent + re-sort in one txn). All
 * queries live in Tiger_Model_Menu; this service validates + shapes.
 */
class Cms_Service_Menu extends Tiger_Service_Service
{
    /** DataTables source for the menus list: one row per (org, menu_key) with an item count. */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt   = $this->_dtParams($params);
        $data = (new Tiger_Model_Menu())->datatable([
            'search'   => $dt['search'],
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'deleteMenu');

        $rows = [];
        foreach ($data['rows'] as $r) {
            $global = ((string) $r['org_id'] === '');
            $rows[] = [
                'menu_key'   => $r['menu_key'],
                'org_id'     => (string) $r['org_id'],
                'scope'      => $global ? 'Global' : 'Org',
                'items'      => (int) $r['items'],
                'updated'    => substr((string) $r['updated'], 0, 16),
                'can_edit'   => $canEdit,
                'can_delete' => $canDelete,
            ];
        }
        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /** Create or update one menu item (insert when menu_id is empty). */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $menuKey = trim((string) ($params['menu_key'] ?? ''));
        if ($menuKey === '') { $this->_error('cms.menu.key_required'); return; }

        $form = new Cms_Form_MenuItem();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        $data = [
            'label'       => $v['label'],
            'page_key'    => $this->_nn($v['page_key']),
            'url'         => $this->_nn($v['url']),
            'icon'        => $this->_nn($v['icon']),
            'css_class'   => $this->_nn($v['css_class']),
            'dom_id'      => $this->_nn($v['dom_id']),
            'link_target' => $this->_nn($v['link_target']),
            'link_rel'    => $this->_nn($v['link_rel']),
            'resource'    => $this->_nn($v['resource']),
            'privilege'   => $this->_nn($v['privilege']),
            'status'      => ($v['status'] === 'draft') ? 'draft' : 'published',
        ];

        $model  = new Tiger_Model_Menu();
        $menuId = !empty($params['menu_id']) ? (string) $params['menu_id'] : null;

        try {
            if ($menuId) {
                $model->update($data, $model->getAdapter()->quoteInto('menu_id = ?', $menuId));
                $id = $menuId;
            } else {
                $orgId    = (string) ($params['org_id'] ?? '');
                $parentId = !empty($params['parent_id']) ? (string) $params['parent_id'] : null;
                $data['menu_key']   = $menuKey;
                $data['org_id']     = $orgId;
                $data['parent_id']  = $parentId;
                $data['sort_order'] = $model->nextSort($menuKey, $orgId, $parentId);
                $id = $model->insert($data);
            }
            $this->_success(['menu_id' => $id], 'cms.menu.item_saved',
                '/cms/menu/edit/key/' . rawurlencode($menuKey));
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete one item and its subtree. */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = (string) ($params['menu_id'] ?? '');
        if ($id === '') { $this->_error('core.api.error.general'); return; }
        try {
            $n = (new Tiger_Model_Menu())->deleteItem($id);
            $this->_success(['deleted' => $n], 'cms.menu.item_deleted');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete an entire menu (every item in the org+key scope). */
    public function deleteMenu(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $menuKey = (string) ($params['menu_key'] ?? '');
        $orgId   = (string) ($params['org_id'] ?? '');
        if ($menuKey === '') { $this->_error('core.api.error.general'); return; }
        try {
            $model = new Tiger_Model_Menu();
            $db    = $model->getAdapter();
            $model->softDelete($db->quoteInto('menu_key = ?', $menuKey) . ' AND ' . $db->quoteInto('org_id = ?', $orgId));
            $this->_success(['menu_key' => $menuKey], 'cms.menu.deleted', '/cms/menu');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Persist a drag-drop reorder: `tree` is a JSON array of
     * [{menu_id, parent_id, sort_order}, …]. Only items in the given menu are touched.
     */
    public function reorder(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $menuKey = (string) ($params['menu_key'] ?? '');
        $orgId   = (string) ($params['org_id'] ?? '');
        $items   = json_decode((string) ($params['tree'] ?? ''), true);
        if ($menuKey === '' || !is_array($items)) { $this->_error('core.api.error.general'); return; }
        try {
            $n = (new Tiger_Model_Menu())->reorder($items, $menuKey, $orgId);
            $this->_success(['updated' => $n], 'cms.menu.reordered');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Trim to a value, or null when empty — keeps NULLs clean for optional columns. */
    protected function _nn($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
