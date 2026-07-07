<?php
/**
 * Cms_Service_Page — the /api service for CMS authoring (save / delete / restore).
 *
 * The only write path for the CMS admin: the editor POSTs here (module=cms,
 * service=page, method=save|delete|restore). Each action gates on admin+ via the
 * ACL, validates where a form applies, then defers the actual write to the
 * transactional Tiger_Model_Page — save() snapshots a version and leaves a 301 on
 * a slug change; restoreVersion() writes a prior version back as a new one.
 *
 * ACL resource = this class name (Cms_Service_Page), granted admin+ in
 * modules/cms/configs/acl.ini; the gateway also privilege-checks the method name.
 */
class Cms_Service_Page extends Tiger_Service_Service
{
    /**
     * DataTables server-side source for the content list. Reads the DT params
     * (search/sort/paginate), counts total + filtered, and returns one page of rows
     * as STRUCTURED DATA (no HTML). Each row carries the caller's control permissions
     * (`can_edit`/`can_delete`, privilege-checked) so the client renders ACL-correct
     * action buttons — authorization stays server-side. See AGENTS.md (client/server).
     */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt = $this->_dtParams($params);

        // The query lives in the model; the service validates the toolbar filters and
        // formats + ACL-gates the rows. Toolbar filters define the working set (both
        // counts); the search box narrows recordsFiltered.
        $data = (new Tiger_Model_Page())->datatable([
            'search'   => $dt['search'],
            'status'   => in_array(($params['status'] ?? ''), [Tiger_Model_Page::STATUS_DRAFT, Tiger_Model_Page::STATUS_PUBLISHED, Tiger_Model_Page::STATUS_ARCHIVED], true) ? (string) $params['status'] : '',
            'type'     => in_array(($params['type']   ?? ''), [Tiger_Model_Page::TYPE_PAGE, Tiger_Model_Page::TYPE_LAYOUT, Tiger_Model_Page::TYPE_PARTIAL], true) ? (string) $params['type'] : '',
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        // Server-authoritative control permissions (per-caller, privilege-checked so
        // tightening save/delete in the ACL flows straight through to the buttons).
        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'delete');

        $rows = [];
        foreach ($data['rows'] as $r) {
            $stamp = (string) ($r['updated_at'] ?: $r['created_at']);
            $rows[] = [
                'page_id'    => $r['page_id'],
                'title'      => ($r['title'] !== null && $r['title'] !== '') ? $r['title'] : '(untitled)',
                'type'       => $r['type'],
                'handle'     => ($r['slug'] !== null && $r['slug'] !== '') ? '/' . $r['slug'] : '#' . $r['page_key'],
                'locale'     => $r['locale'],
                'status'     => $r['status'],
                'scheduled'  => ($r['status'] === 'published' && $r['published_at'] && strtotime((string) $r['published_at']) > time()),
                'updated'    => substr($stamp, 0, 16),
                'can_edit'   => $canEdit,
                'can_delete' => $canDelete,
            ];
        }

        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /** Create or update a page (insert when page_id is empty). */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Cms_Form_Page();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        $pageId = !empty($params['page_id']) ? (string) $params['page_id'] : null;

        // Pages route by slug; layouts/partials have none — NULL (not '') keeps the
        // UNIQUE(org_id, slug, locale) index happy for many keyless rows. A page_key
        // is always set so every row has a stable handle: from the slug, else a
        // slugified title.
        $isPage = ($v['type'] === 'page');
        $slugIn = trim((string) $v['slug']);
        $slug   = $slugIn !== '' ? $this->_slugify($slugIn) : ($isPage ? $this->_slugify($v['title']) : null);

        $key = trim((string) $v['page_key']);
        if ($key === '') {
            $key = $this->_slugify(($slug !== null && $slug !== '') ? $slug : $v['title']);
        }

        $publishedAt = trim((string) $v['published_at']);

        $data = [
            'type'         => $v['type'],
            'page_key'     => $key,
            'slug'         => $slug,
            'locale'       => $v['locale'],
            'title'        => $v['title'],
            'body'         => (string) $v['body'],
            'format'       => $v['format'],
            'layout_key'   => trim((string) $v['layout_key']) !== '' ? $v['layout_key'] : null,
            'status'       => $v['status'],
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
        ];

        try {
            // Tiger_Model_Page::save() is itself transactional (write + version snapshot).
            $id = (new Tiger_Model_Page())->save($data, $pageId);
            $this->_success(['page_id' => $id], 'cms.page.saved', '/cms/page');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete a page (recoverable — the row is flagged, not dropped). */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['page_id']) ? (string) $params['page_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }

        try {
            (new Tiger_Model_Page())->softDelete(['page_id = ?' => $id]);
            $this->_success(['page_id' => $id], 'cms.page.deleted', '/cms/page');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Restore a page to a prior version (current content is snapshotted first). */
    public function restore(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id      = !empty($params['page_id']) ? (string) $params['page_id'] : '';
        $version = isset($params['version']) ? (int) $params['version'] : 0;
        if ($id === '' || $version < 1) { $this->_error('core.api.error.general'); return; }

        try {
            (new Tiger_Model_Page())->restoreVersion($id, $version);
            $this->_success(['page_id' => $id], 'cms.page.restored', '/cms/page/edit/id/' . $id);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** lowercase, hyphen-joined, ascii slug (shared by slug + key derivation). */
    protected function _slugify($text): string
    {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }
}
