<?php
/**
 * Access_Service_Org — /api service for the Organizations admin (datatable / save /
 * delete).
 *
 * An org is a tenant with a self-referential parent (hierarchies) and a URL-safe
 * unique slug. The list shows the parent name + a live member count (via org_user).
 * Rows carry per-row ACL flags; you can't delete the org you're currently acting in.
 * ACL: admin+ (modules/access/configs/acl.ini).
 *
 * Note (v1): soft-delete flags the row; it does NOT reparent children or purge
 * memberships (the FK ON DELETE actions only fire on a hard delete). Member/child
 * management is a later screen.
 */
class Access_Service_Org extends Tiger_Service_Service
{
    private const STATUSES = ['active', 'suspended'];

    /** DataTables server-side source: org + parent name + member count. */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt = $this->_dtParams($params);

        $data = (new Tiger_Model_Org())->datatable([
            'search'   => $dt['search'],
            'status'   => in_array(($params['status'] ?? ''), self::STATUSES, true) ? (string) $params['status'] : '',
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'delete');

        $rows = [];
        foreach ($data['rows'] as $r) {
            $isCurrent = ($r['org_id'] === $this->_org_id);
            $rows[] = [
                'org_id'       => $r['org_id'],
                'name'         => $r['name'],
                'slug'         => $r['slug'],
                'parent'       => ($r['parent_name'] !== null && $r['parent_name'] !== '') ? $r['parent_name'] : '',
                'member_count' => (int) $r['member_count'],
                'status'       => $r['status'],
                'created'      => substr((string) $r['created_at'], 0, 10),
                'can_edit'     => $canEdit,
                'can_delete'   => $canDelete && !$isCurrent,   // not the org you're acting in
            ];
        }

        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /** Create or update an org (insert when org_id is empty). */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Access_Form_Org();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        $orgId  = !empty($params['org_id']) ? (string) $params['org_id'] : null;
        $name   = trim((string) $v['name']);
        $slugIn = trim((string) $v['slug']);
        $slug   = $this->_slugify($slugIn !== '' ? $slugIn : $name);
        $parent = trim((string) $v['parent_org_id']);
        $parent = $parent !== '' ? $parent : null;

        if ($slug === '')                          { $this->_error('access.org.slug_required'); return; }
        if ($orgId !== null && $parent === $orgId) { $this->_error('access.org.parent_self');   return; }

        $org = new Tiger_Model_Org();
        if ($org->slugTaken($slug, $orgId)) { $this->_error('access.org.slug_taken'); return; }

        $data = ['name' => $name, 'slug' => $slug, 'parent_org_id' => $parent, 'status' => $v['status']];

        try {
            if ($orgId) {
                $org->update($data, ['org_id = ?' => $orgId]);
                $id = $orgId;
            } else {
                $id = $org->insert($data);
            }
            $this->_success(['org_id' => $id], 'access.org.saved', '/access/org');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete an org (never the one you're acting in). */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['org_id']) ? (string) $params['org_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }
        if ($id === $this->_org_id) { $this->_error('access.org.no_self_delete'); return; }

        try {
            (new Tiger_Model_Org())->softDelete(['org_id = ?' => $id]);
            $this->_success(['org_id' => $id], 'access.org.deleted', '/access/org');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** lowercase, hyphen-joined, ascii slug. */
    private function _slugify($text): string
    {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }
}
