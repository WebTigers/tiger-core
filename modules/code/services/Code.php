<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Code_Service_Code — the /api service for Tiger Code authoring.
 *
 * Thin + gated to `superadmin`+ (configs/acl.ini — the `code.execute` privilege): writing
 * server-executed PHP is the top privilege in the system. Every write LINTS the PHP before
 * it can enter (a parse error is refused, never stored active), defers to Tiger_Model_Code
 * (transactional save + version snapshot), then rebuilds the compiled bundle
 * (Tiger_Code_Runtime::rebuild) so the change is live next request.
 *
 * v1 = the PHP tier: language `php`, run location `global` (runs on every request), platform
 * scope (`org_id = ''`). The client CSS/JS/HTML tier + admin/frontend/page scoping come next.
 */
class Code_Service_Code extends Tiger_Service_Service
{
    /** DataTables source for the code list. */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt   = $this->_dtParams($params);
        $data = (new Tiger_Model_Code())->datatable([
            'search'   => $dt['search'],
            'language' => (string) ($params['language'] ?? ''),
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'delete');

        $rows = [];
        foreach ($data['rows'] as $r) {
            $stamp = (string) ($r['updated_at'] ?: $r['created_at']);
            $rows[] = [
                'code_id'    => $r['code_id'],
                'name'       => ($r['name'] !== '' ? $r['name'] : '(untitled)'),
                'language'   => $r['language'],
                'location'   => $r['run_location'],
                'priority'   => (int) $r['priority'],
                'active'     => (int) $r['active'] === 1,
                'errored'    => $r['status'] === Tiger_Model_Code::STATUS_ERROR,
                'last_error' => (string) $r['last_error'],
                'updated'    => substr($stamp, 0, 16),
                'can_edit'   => $canEdit,
                'can_delete' => $canDelete,
            ];
        }
        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /** Create or update a snippet (insert when code_id is empty). Lints PHP before storing. */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Code_Form_Code();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        $model  = new Tiger_Model_Code();
        $active = !empty($v['active']);

        $allowed  = [Tiger_Model_Code::LANG_PHP, Tiger_Model_Code::LANG_PHTML, Tiger_Model_Code::LANG_HTML, Tiger_Model_Code::LANG_CSS, Tiger_Model_Code::LANG_JS];
        $language = in_array($v['language'], $allowed, true) ? $v['language'] : Tiger_Model_Code::LANG_PHP;

        // Server-executed languages (php, phtml) must parse — a syntax error is refused.
        if (in_array($language, Tiger_Model_Code::SERVER_LANGS, true)) {
            $lint = $model->lint($v['code']);
            if (!$lint['ok']) { $this->_error('Not saved — ' . $lint['error']); return; }
        }

        // auto_insert applies to INJECTED languages; css is always head; php has no location.
        $autoInsert = null;
        if ($language !== Tiger_Model_Code::LANG_PHP) {
            $autoInsert = ($language === Tiger_Model_Code::LANG_CSS) ? Tiger_Model_Code::AUTO_HEAD
                : (($v['auto_insert'] === Tiger_Model_Code::AUTO_FOOTER) ? Tiger_Model_Code::AUTO_FOOTER : Tiger_Model_Code::AUTO_HEAD);
        }

        $data = [
            'org_id'       => '',                                  // v1: platform scope (tenant scoping later)
            'name'         => (string) $v['name'],
            'description'  => (string) $v['description'],
            'language'     => $language,
            'code'         => (string) $v['code'],
            'run_location' => Tiger_Model_Code::LOC_GLOBAL,        // v1: global
            'auto_insert'  => $autoInsert,
            'priority'     => (int) ($v['priority'] ?: 100),
            'active'       => $active ? 1 : 0,
            'status'       => $active ? Tiger_Model_Code::STATUS_ACTIVE : Tiger_Model_Code::STATUS_DRAFT,
        ];

        try {
            $id  = $model->save($data, !empty($params['code_id']) ? (string) $params['code_id'] : null);
            $err = $this->_safeRebuild($model, $id);
            if ($err !== null) {
                $this->_error('Saved, but not activated — it conflicts with the running set: ' . $err);
                return;
            }
            $this->_success(['code_id' => $id], 'code.saved', '/code');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Rebuild the bundle; if the new active set won't compile (e.g. a redeclare with another
     * active snippet), deactivate the offending row + rebuild again so the site stays healthy,
     * and return the compiler error. Returns null on success.
     */
    protected function _safeRebuild(Tiger_Model_Code $model, $id): ?string
    {
        try {
            Tiger_Code_Runtime::rebuild();
            return null;
        } catch (Throwable $e) {
            $model->markError($id, $e->getMessage());
            try { Tiger_Code_Runtime::rebuild(); } catch (Throwable $e2) { /* last-good stays live */ }
            return $e->getMessage();
        }
    }

    /** Activate a snippet (lints first). */
    public function activate(array $params): void { $this->_toggle($params, true); }

    /** Deactivate a snippet. */
    public function deactivate(array $params): void { $this->_toggle($params, false); }

    protected function _toggle(array $params, $on): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['code_id']) ? (string) $params['code_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }

        $model = new Tiger_Model_Code();
        $row   = $model->findById($id);
        if (!$row) { $this->_error('core.api.error.general'); return; }

        if ($on && in_array($row->language, Tiger_Model_Code::SERVER_LANGS, true)) {
            $lint = $model->lint($row->code);
            if (!$lint['ok']) { $this->_error('Cannot activate — ' . $lint['error']); return; }
        }
        try {
            $model->setActive($id, $on);
            $err = $this->_safeRebuild($model, $id);
            if ($on && $err !== null) {
                $this->_error('Cannot activate — it conflicts with the running set: ' . $err);
                return;
            }
            $this->_success(['code_id' => $id], $on ? 'code.activated' : 'code.deactivated', '/code');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete a snippet (recoverable). */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['code_id']) ? (string) $params['code_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }
        try {
            (new Tiger_Model_Code())->softDelete(['code_id = ?' => $id]);
            Tiger_Code_Runtime::rebuild();   // drop it from the bundle
            $this->_success(['code_id' => $id], 'code.deleted', '/code');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Restore a prior version (does not auto-reactivate). */
    public function restore(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id      = !empty($params['code_id']) ? (string) $params['code_id'] : '';
        $version = isset($params['version']) ? (int) $params['version'] : 0;
        if ($id === '' || $version < 1) { $this->_error('core.api.error.general'); return; }
        try {
            $model = new Tiger_Model_Code();
            $model->restoreVersion($id, $version);
            $this->_safeRebuild($model, $id);
            $this->_success(['code_id' => $id], 'code.restored', '/code/index/edit/id/' . $id);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }
}
