<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Backup_Service_Backup — the /api behind the Backup screen.
 *
 * `run` creates a manual backup now; `remove` deletes one (bytes + catalog row); `restore` restores
 * from a cataloged backup (guarded by a typed confirmation); `saveSettings` writes the tiger.backup.*
 * config tier (components/disk/retention/notify) that scheduled backups use. Admin+. Download and
 * upload-restore are controller actions (file streams, not JSON) — see Backup_IndexController.
 *
 * @api
 */
class Backup_Service_Backup extends Tiger_Service_Service
{
    /**
     * Create a manual backup now.
     *
     * @param  array $params components[], disk, include_secrets
     * @return void
     */
    public function run(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $components = $this->_components($params);
        if (!$components) { $this->_error('backup.bad_component', ['field' => 'components']); return; }
        $disk = $this->_disk($params['disk'] ?? 'local');
        if ($disk === null) { $this->_error('backup.bad_disk', ['field' => 'disk']); return; }

        @set_time_limit(0);
        $res = Tiger_Backup::create($components, [
            'disk'            => $disk,
            'source'          => 'manual',
            'include_secrets' => !empty($params['include_secrets']),
        ]);

        if (($res['status'] ?? '') === 'ok') {
            $this->_success(
                ['backup_id' => $res['backup_id'], 'filename' => $res['filename'], 'size' => Tiger_Backup::hsize($res['size'])],
                'backup.done'
            );
        } else {
            $this->_error(APPLICATION_ENV !== 'production' ? ('Backup failed: ' . ($res['error'] ?? '')) : 'backup.failed');
        }
    }

    /**
     * Delete a backup (archive bytes + catalog row).
     *
     * @param  array $params backup_id
     * @return void
     */
    public function remove(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = (string) ($params['backup_id'] ?? '');
        if ($id === '' || !(new Tiger_Model_Backup())->findById($id)) { $this->_error('backup.not_found'); return; }
        Tiger_Backup::delete($id);
        $this->_success([], 'backup.deleted');
    }

    /**
     * Restore from a cataloged backup. Destructive — requires confirm === 'RESTORE'.
     *
     * @param  array $params backup_id, confirm, components[] (optional subset)
     * @return void
     */
    public function restore(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        if ((string) ($params['confirm'] ?? '') !== 'RESTORE') { $this->_error('backup.restore.confirm', ['field' => 'confirm']); return; }

        $model = new Tiger_Model_Backup();
        $row   = $model->findById((string) ($params['backup_id'] ?? ''));
        if (!$row) { $this->_error('backup.not_found'); return; }
        if (($row['outcome'] ?? '') !== 'ok') { $this->_error('backup.not_found'); return; }

        $components = $this->_components($params);   // empty = all present in the archive
        @set_time_limit(0);
        $tmp = null;
        try {
            $tmp = Tiger_Backup::fetchToTemp($row);
            $res = Tiger_Backup::restore($tmp, $components);
        } catch (Throwable $e) {
            $res = ['status' => 'error', 'error' => $e->getMessage()];
        }
        // fetchToTemp returns the file in place for local disk — only unlink a streamed cloud temp.
        if ($tmp && ($row['disk'] ?? '') !== 'local' && is_file($tmp)) { @unlink($tmp); }

        if (($res['status'] ?? '') === 'ok') {
            $this->_success(['restored' => $res['restored'], 'safety_id' => $res['safety_id'] ?? null], 'backup.restore.done');
        } else {
            $this->_error(APPLICATION_ENV !== 'production' ? ('Restore failed: ' . ($res['error'] ?? '')) : 'backup.restore.failed');
        }
    }

    /**
     * Save the scheduled-backup settings (config tier — live, no deploy).
     *
     * @param  array $params components[], disk, include_secrets, retention_max, notify_enabled, notify_email
     * @return void
     */
    public function saveSettings(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $components = $this->_components($params);
        $disk = $this->_disk($params['disk'] ?? 'local');
        if ($disk === null) { $this->_error('backup.bad_disk', ['field' => 'disk']); return; }
        $email = trim((string) ($params['notify_email'] ?? ''));
        if ($email !== '') {
            foreach (array_map('trim', explode(',', $email)) as $addr) {
                if ($addr !== '' && !Zend_Validate::is($addr, 'EmailAddress')) { $this->_error('backup.bad_email', ['field' => 'notify_email']); return; }
            }
        }

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $cfg->set($g, '', 'tiger.backup.components', implode(',', $components ?: [Tiger_Backup::DATABASE]));
        $cfg->set($g, '', 'tiger.backup.disk', $disk);
        $cfg->set($g, '', 'tiger.backup.include_secrets', !empty($params['include_secrets']) ? '1' : '0');
        $cfg->set($g, '', 'tiger.backup.retention.max', (string) max(0, (int) ($params['retention_max'] ?? 7)));
        $cfg->set($g, '', 'tiger.backup.notify.enabled', !empty($params['notify_enabled']) ? '1' : '0');
        $cfg->set($g, '', 'tiger.backup.notify.email', $email);

        $this->_success([], 'backup.settings.saved');
    }

    // ----- helpers -----------------------------------------------------------

    /** Normalize + validate the components list (array or csv) against the known set. */
    protected function _components(array $params): array
    {
        $raw = $params['components'] ?? [];
        if (is_string($raw)) { $raw = explode(',', $raw); }
        $raw = array_map('trim', (array) $raw);
        return array_values(array_intersect(Tiger_Backup::COMPONENTS, $raw));
    }

    /** Validate a destination disk name against the configured destinations; null if invalid. */
    protected function _disk($name): ?string
    {
        $name = (string) $name;
        foreach (Tiger_Backup::disks() as $d) { if ($d['name'] === $name) { return $name; } }
        return null;
    }
}
