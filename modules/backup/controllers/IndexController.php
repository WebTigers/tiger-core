<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Backup_IndexController — the Backup admin screen (/backup). Thin: assembles the history + settings +
 * schedule state for the initial render; mutations (run/restore/delete/settings) go through
 * Backup_Service_Backup over /api. Two non-JSON actions live here: `download` streams an archive and
 * `upload` receives an uploaded archive to restore. Admin+. See ADMIN.md.
 */
class Backup_IndexController extends Tiger_Controller_Admin_Action
{
    public function init()
    {
        parent::init();
    }

    public function indexAction()
    {
        $model = new Tiger_Model_Backup();

        // Current schedule of the backup.run job (for the embedded schedule control).
        $sched = ['every' => 'daily', 'at' => '02:00', 'dow' => 1, 'dom' => 1, 'enabled' => false];
        if (Tiger_Schedule::get('backup.run')) {
            $job = Tiger_Schedule::effective(Tiger_Schedule::get('backup.run'));
            $sched = [
                'every'   => (string) $job['every'],
                'at'      => (string) $job['at'],
                'dow'     => (int) $job['dow'],
                'dom'     => (int) $job['dom'],
                'enabled' => Tiger_Schedule::enabled('backup.run'),
            ];
        }

        $this->view->title       = 'Backup & Restore — Tiger Admin';
        $this->view->backups     = $model->recent(50);
        $this->view->disks       = Tiger_Backup::disks();
        $this->view->components  = Tiger_Backup::COMPONENTS;
        $this->view->settings    = $this->_settings();
        $this->view->schedule    = $sched;
        $this->view->cronCommand = class_exists('Schedule_Service_Schedule') ? Schedule_Service_Schedule::cronCommand() : '';
    }

    /** Stream a stored backup archive to the browser (admin-gated by ACL). */
    public function downloadAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $response = $this->getResponse();

        $row = (new Tiger_Model_Backup())->findById((string) $this->getParam('id', ''));
        if (!$row || $row['outcome'] !== 'ok') { $response->setHttpResponseCode(404); return; }
        $meta = $row->toArray();

        try {
            $path    = Tiger_Backup::fetchToTemp($meta);
            $isTemp  = ($meta['disk'] !== 'local');
        } catch (Throwable $e) {
            $response->setHttpResponseCode(404); return;
        }

        $response->setHeader('Content-Type', 'application/zip', true)
                 ->setHeader('Content-Length', (string) filesize($path), true)
                 ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($meta['filename']) . '"', true)
                 ->setHeader('Cache-Control', 'private, no-store', true)
                 ->sendHeaders();
        $fh = fopen($path, 'rb');
        fpassthru($fh);
        fclose($fh);
        if ($isTemp) { @unlink($path); }
        exit;
    }

    /** Receive an uploaded archive and restore from it (returns JSON). */
    public function uploadAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $response = $this->getResponse();
        $response->setHeader('Content-Type', 'application/json', true);

        if (($this->getParam('confirm', '')) !== 'RESTORE') { $response->setBody($this->_json(0, 'backup.restore.confirm')); return; }
        $file = $_FILES['archive'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $response->setBody($this->_json(0, 'backup.upload.failed')); return; }

        $dir = APPLICATION_ROOT . '/var/backup';
        @is_dir($dir) || @mkdir($dir, 0775, true);
        $tmp = $dir . '/upload-' . bin2hex(random_bytes(4)) . '.zip';
        if (!@move_uploaded_file($file['tmp_name'], $tmp)) { $response->setBody($this->_json(0, 'backup.upload.failed')); return; }

        // Validate it's a TigerBackup archive before doing anything destructive.
        if (Tiger_Backup_Archive::read($tmp, 'manifest.json') === false) {
            @unlink($tmp);
            $response->setBody($this->_json(0, 'backup.upload.invalid'));
            return;
        }

        @set_time_limit(0);
        try {
            $res = Tiger_Backup::restore($tmp);
        } catch (Throwable $e) {
            $res = ['status' => 'error', 'error' => $e->getMessage()];
        }
        @unlink($tmp);

        if (($res['status'] ?? '') === 'ok') {
            $response->setBody($this->_json(1, 'backup.restore.done', ['restored' => $res['restored']]));
        } else {
            $response->setBody($this->_json(0, APPLICATION_ENV !== 'production' ? ('Restore failed: ' . ($res['error'] ?? '')) : 'backup.restore.failed'));
        }
    }

    /** Read the tiger.backup.* settings (config tier) for the settings form. */
    protected function _settings(): array
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $get = function ($key, $default) use ($cfg) {
            if (!$cfg) { return $default; }
            $node = $cfg;
            foreach (explode('.', $key) as $seg) {
                $node = $node instanceof Zend_Config ? $node->get($seg) : null;
                if ($node === null) { return $default; }
            }
            return is_scalar($node) ? (string) $node : $default;
        };
        return [
            'components'      => array_filter(array_map('trim', explode(',', $get('tiger.backup.components', 'database,media')))),
            'disk'            => $get('tiger.backup.disk', 'local'),
            'include_secrets' => $get('tiger.backup.include_secrets', '1') === '1',
            'retention_max'   => (int) $get('tiger.backup.retention.max', '7'),
            'notify_enabled'  => $get('tiger.backup.notify.enabled', '0') === '1',
            'notify_email'    => $get('tiger.backup.notify.email', ''),
        ];
    }

    /** Minimal JSON envelope for the two non-service actions (message key is translated client-side-agnostic). */
    protected function _json(int $result, string $messageKey, array $data = []): string
    {
        $t = Zend_Registry::isRegistered('Zend_Translate') ? Zend_Registry::get('Zend_Translate') : null;
        $msg = $t ? $t->translate($messageKey) : $messageKey;
        return json_encode([
            'result'   => $result,
            'data'     => $data,
            'messages' => [['message' => $msg, 'class' => $result ? 'success' : 'error', 'field' => null]],
        ], JSON_UNESCAPED_SLASHES);
    }
}
