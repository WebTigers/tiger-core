<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Updates — the engine behind the one-click Updates screen.
 *
 * `check()` re-runs detection (Tiger_Update_Checker); `apply()` updates the selected items and
 * returns a per-item STEP LOG so any failure is diagnosable. Modules update the real no-shell way
 * (Tiger_Module_Installer: download → extract → migrate → publish → record). Core update in the web
 * UI is advisory for now (Composer on the CLI, or the pre-built release ZIP on the roadmap — see
 * UPDATING.md); detection still surfaces it. Every step is also written to Tiger_Log.
 */
class System_Service_Updates extends Tiger_Service_Service
{
    /**
     * Re-check what has an update (optionally forcing a fresh remote check).
     *
     * @param  array $params {refresh?: bool}
     * @return void
     */
    public function check(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $this->_success(['updates' => Tiger_Update_Checker::all(!empty($params['refresh']))]);
    }

    /**
     * Apply the selected updates. `items` is a list of slugs (`tiger-core` for the platform).
     *
     * @param  array $params {items: string[]|string}
     * @return void
     */
    public function apply(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $slugs = $params['items'] ?? [];
        if (is_string($slugs)) { $slugs = array_filter(array_map('trim', explode(',', $slugs))); }
        if (!is_array($slugs) || !$slugs) { $this->_error('system.update.none_selected'); return; }

        $index = [];
        foreach (Tiger_Update_Checker::all() as $u) { $index[$u['slug']] = $u; }

        $results = [];
        foreach ($slugs as $slug) {
            $results[] = isset($index[$slug])
                ? $this->_applyOne($index[$slug])
                : ['slug' => $slug, 'name' => $slug, 'ok' => false,
                   'log' => [['step' => 'resolve', 'ok' => false, 'detail' => 'Unknown or no-longer-pending item.']]];
        }
        $this->_recordHistory($results, $index);
        $this->_success(['results' => $results], 'system.update.done');
    }

    /**
     * The recent update-run history (durable "what ran / what broke").
     *
     * @param  array $params {limit?}
     * @return void
     */
    public function history(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $limit = (int) ($params['limit'] ?? 20);
        try {
            $this->_success(['history' => (new Tiger_Model_UpdateHistory())->recent($limit)]);
        } catch (Throwable $e) {
            $this->_success(['history' => []]);   // table not migrated yet
        }
    }

    /** Persist each applied item to update_history — never lets history-writing break an update. */
    protected function _recordHistory(array $results, array $index): void
    {
        $model = new Tiger_Model_UpdateHistory();
        foreach ($results as $res) {
            $u = $index[$res['slug']] ?? [];
            $outcome = !empty($res['advisory'])
                ? Tiger_Model_UpdateHistory::OUTCOME_ADVISORY
                : (!empty($res['ok'])
                    ? Tiger_Model_UpdateHistory::OUTCOME_SUCCESS
                    : (self::_wasRolledBack($res['log'] ?? [])
                        ? Tiger_Model_UpdateHistory::OUTCOME_ROLLBACK
                        : Tiger_Model_UpdateHistory::OUTCOME_FAILED));
            try {
                $model->record([
                    'item_type'    => $u['type'] ?? ($res['slug'] === 'tiger-core' ? 'core' : 'module'),
                    'item_slug'    => $res['slug'],
                    'item_name'    => $res['name'] ?? $res['slug'],
                    'from_version' => $u['installed'] ?? null,
                    'to_version'   => $res['version'] ?? ($u['latest'] ?? null),
                    'outcome'      => $outcome,
                    'log'          => $res['log'] ?? [],
                ]);
            } catch (Throwable $e) {
                Tiger_Log::error('update.history.write_failed', ['item' => $res['slug'], 'error' => $e->getMessage()]);
            }
        }
    }

    /** True when the step log shows a rollback step. */
    protected static function _wasRolledBack(array $log): bool
    {
        foreach ($log as $s) {
            if (($s['step'] ?? '') === 'rollback') { return true; }
        }
        return false;
    }

    /**
     * Apply a single update, accumulating a step log.
     *
     * @param  array $u an update descriptor from Tiger_Update_Checker
     * @return array {slug, name, ok, version?, advisory?, log:[{step, ok, detail}]}
     */
    protected function _applyOne(array $u): array
    {
        $log = [];
        $step = function ($step, $ok, $detail) use (&$log, $u) {
            $log[] = ['step' => $step, 'ok' => (bool) $ok, 'detail' => $detail];
            Tiger_Log::info('update.step', ['item' => $u['slug'], 'step' => $step, 'ok' => (bool) $ok, 'detail' => $detail]);
        };

        if ($u['type'] === 'core') {
            $step('resolve', true, "TigerCore {$u['installed']} → {$u['latest']}");

            // Composer host (dev / VPS / any shell host): actually RUN composer — the update APPLIES,
            // it doesn't just advise. This is the right path wherever Composer genuinely runs.
            if ($u['method'] === 'composer' && Tiger_Update_Composer::possible()) {
                $res = Tiger_Update_Composer::update(['package' => $u['repository'], 'target' => $u['latest']]);
                return ['slug' => $u['slug'], 'name' => $u['name'], 'ok' => !empty($res['ok']),
                        'version' => $res['version'] ?? null, 'log' => array_merge($log, $res['log'])];
            }

            // No-shell host (the CMS user on shared hosting): atomically swap in a pre-resolved vendored
            // release ZIP — when the host can extract+swap AND a release ZIP is published for the target.
            if (Tiger_Update_Core::possible()) {
                $rel = Tiger_Update_Core::resolveRelease($u['latest']);
                if ($rel) {
                    $res = Tiger_Update_Core::update($rel);
                    return ['slug' => $u['slug'], 'name' => $u['name'], 'ok' => !empty($res['ok']),
                            'version' => $res['version'] ?? null, 'log' => array_merge($log, $res['log'])];
                }
                $step('advise', true, 'No pre-built release ZIP is published for ' . $u['latest']
                    . ' yet — the one-click core-swap engine is ready and activates the moment a release ZIP ships.');
            } else {
                $step('advise', true, 'This host can\'t self-update the platform: Composer isn\'t runnable here '
                    . 'and there\'s no ext-zip/phar + writable vendor/ for the swap. Update via Composer on a '
                    . 'machine that can, or see UPDATING.md.');
            }
            return ['slug' => $u['slug'], 'name' => $u['name'], 'ok' => true, 'advisory' => true, 'log' => $log];
        }

        // Module — the real one-click, no-shell path.
        try {
            $step('resolve', true, "{$u['name']} {$u['installed']} → {$u['latest']}  ({$u['repository']})");
            $step('apply', true, 'Downloading the release, extracting, running migrations, republishing assets…');
            $r = Tiger_Module_Installer::installFromUrl($u['repository'], $u['ref'] ?: null, ['force' => true]);
            foreach (($r['dependencies'] ?? []) as $d) {
                $step('dependency', !empty($d['ok']), ($d['name'] ?? 'dep') . ': ' . ($d['message'] ?? ''));
            }
            $version = $r['version'] ?? $u['latest'];
            $step('done', true, "Updated to {$version}.");
            return ['slug' => $u['slug'], 'name' => $u['name'], 'ok' => true, 'version' => $version, 'log' => $log];
        } catch (Throwable $e) {
            $step('error', false, $e->getMessage());
            Tiger_Log::error('update.failed', ['item' => $u['slug'], 'error' => $e->getMessage()]);
            return ['slug' => $u['slug'], 'name' => $u['name'], 'ok' => false, 'log' => $log];
        }
    }
}
