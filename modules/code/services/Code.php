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
 *
 * @api
 */
class Code_Service_Code extends Tiger_Service_Service
{
    /**
     * DataTables source for the code list.
     *
     * @param  array $params the DataTables request (draw/start/length/search/order) plus filters
     * @return void
     */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt     = $this->_dtParams($params);
        $search = strtolower(trim((string) $dt['search']));
        $langf  = (string) ($params['language'] ?? '');

        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'delete');

        // LOCAL snippets (the `code` table). Pull the whole matching set — the table is small
        // (dozens) — so it can be merged with module snippets and paginated together below.
        $data = (new Tiger_Model_Code())->datatable([
            'search'   => $dt['search'],
            'language' => $langf,
            'orderCol' => -1,
            'orderDir' => '',
            'offset'   => 0,
            'limit'    => 100000,
        ]);

        $items = [];
        foreach ($data['rows'] as $r) {
            $stamp = (string) ($r['updated_at'] ?: $r['created_at']);
            $items[] = [
                'code_id'    => $r['code_id'],
                'name'       => ($r['name'] !== '' ? $r['name'] : '(untitled)'),
                'language'   => $r['language'],
                'location'   => $r['run_location'],
                'priority'   => (int) $r['priority'],
                'active'     => (int) $r['active'] === 1,
                'errored'    => $r['status'] === Tiger_Model_Code::STATUS_ERROR,
                'last_error' => (string) $r['last_error'],
                'updated'    => substr($stamp, 0, 16),
                'source'     => 'local',
                'module'     => '',
                'can_edit'   => $canEdit,
                'can_delete' => $canDelete,
                'can_view'   => false,
                '_sort'      => strtolower((string) $r['name']),
            ];
        }

        // MODULE snippets — discovered live from installed `code` modules (files, never copied in).
        // Read-only rows: no edit/delete, but a View-source + an activate toggle. All PHP, so they
        // drop out when a `language` filter other than php is applied.
        if ($langf === '' || $langf === Tiger_Model_Code::LANG_PHP) {
            $activeKeys = array_flip(Tiger_Code_Modules::activeKeys());
            foreach (Tiger_Code_Modules::all() as $key => $s) {
                if ($search !== '') {
                    $hay = strtolower($s['label'] . ' ' . $s['description'] . ' ' . $s['module'] . ' ' . $s['category']);
                    if (strpos($hay, $search) === false) { continue; }
                }
                $items[] = [
                    'code_id'    => 'module:' . $key,
                    'name'       => ($s['label'] !== '' ? $s['label'] : $key),
                    'language'   => Tiger_Model_Code::LANG_PHP,
                    'location'   => ($s['scope'] !== '' ? $s['scope'] : Tiger_Model_Code::LOC_GLOBAL),
                    'priority'   => null,
                    'active'     => isset($activeKeys[$key]),
                    'errored'    => false,
                    'last_error' => '',
                    'updated'    => '',
                    'source'     => 'module',
                    'module'     => $s['module'],
                    'can_edit'   => false,
                    'can_delete' => false,
                    'can_view'   => true,
                    '_sort'      => strtolower((string) $s['label']),
                ];
            }
        }

        // Merge + sort by name + paginate in PHP (mixed origins, no shared DB order).
        usort($items, static fn($a, $b) => strcmp($a['_sort'], $b['_sort']));
        $total = count($items);
        $len   = ($dt['length'] > 0) ? $dt['length'] : 25;
        $page  = array_slice($items, (int) $dt['start'], $len);
        foreach ($page as &$row) { unset($row['_sort']); }
        unset($row);

        $this->_dtResponse($dt['draw'], $total, $total, $page);
    }

    /**
     * Return a module snippet's read-only source (for "View source" in the Code Area). The body
     * is never copied into the DB — it's read live from the installed module file.
     *
     * @param  array $params must carry `code_id` in the `module:<key>` form
     * @return void
     */
    public function moduleSource(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = (string) ($params['code_id'] ?? '');
        $key = (strncmp($id, 'module:', 7) === 0) ? substr($id, 7) : '';
        $s   = $key !== '' ? Tiger_Code_Modules::get($key) : null;
        if (!$s) { $this->_error('core.api.error.general'); return; }

        $this->_success([
            'key'         => $key,
            'name'        => $s['label'],
            'module'      => $s['module'],
            'category'    => $s['category'],
            'scope'       => $s['scope'],
            'description' => $s['description'],
            'active'      => Tiger_Code_Modules::isActive($key),
            'source'      => Tiger_Code_Modules::source($key),
        ]);
    }

    /**
     * Create or update a snippet (insert when code_id is empty). Lints PHP before storing.
     *
     * @param  array $params the posted snippet form values
     * @return void
     */
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

    /**
     * Activate a snippet (lints first).
     *
     * @param  array $params must carry `code_id`
     * @return void
     */
    public function activate(array $params): void { $this->_toggle($params, true); }

    /**
     * Deactivate a snippet.
     *
     * @param  array $params must carry `code_id`
     * @return void
     */
    public function deactivate(array $params): void { $this->_toggle($params, false); }

    protected function _toggle(array $params, $on): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['code_id']) ? (string) $params['code_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }

        // A module snippet (file-based, from an installed `code` module) — toggle its config flag,
        // never a `code` row.
        if (strncmp($id, 'module:', 7) === 0) {
            $this->_toggleModule(substr($id, 7), $on);
            return;
        }

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

    /**
     * Activate/deactivate a MODULE snippet by key. Flips the config active-set (no `code` row, no
     * copied body), rebuilds the bundle, and rolls the flag back if the new set won't compile
     * (e.g. it redeclares a function another active snippet defines) — so the site stays healthy.
     *
     * @param  string $key the `<module>/<file>` snippet key
     * @param  bool   $on  activate (true) or deactivate (false)
     * @return void
     */
    protected function _toggleModule($key, $on): void
    {
        $s = Tiger_Code_Modules::get($key);
        if (!$s) { $this->_error('That snippet is no longer available — the module may have been removed.'); return; }

        if ($on) {
            $lint = (new Tiger_Model_Code())->lint(Tiger_Code_Modules::body($key));
            if (!$lint['ok']) { $this->_error('Cannot activate — ' . $lint['error']); return; }
        }

        Tiger_Code_Modules::setActive($key, $on);
        try {
            Tiger_Code_Runtime::rebuild();
        } catch (Throwable $e) {
            Tiger_Code_Modules::setActive($key, !$on);                    // roll back the flag
            try { Tiger_Code_Runtime::rebuild(); } catch (Throwable $e2) { /* last-good stays live */ }
            if ($on) {
                $this->_error('Cannot activate — it conflicts with the running set: ' . $e->getMessage());
                return;
            }
        }
        $this->_success(['code_id' => 'module:' . $key], $on ? 'code.activated' : 'code.deactivated', '/code');
    }

    /**
     * Soft-delete a snippet (recoverable).
     *
     * @param  array $params must carry `code_id`
     * @return void
     */
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

    /**
     * Restore a prior version (does not auto-reactivate).
     *
     * @param  array $params must carry `code_id` and `version`
     * @return void
     */
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
