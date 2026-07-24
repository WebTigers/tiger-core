<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Backup;

use Backup_Service_Backup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Backup;
use Tiger_Log;
use Tiger_Model_Backup;
use Tiger_Model_Config;
use Zend_Config;
use Zend_Registry;

// `Backup_Service_Backup` resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * Backup_Service_Backup — the /api behind the Backup screen. Admin+ per modules/backup/configs/acl.ini.
 * `run` creates a backup now; `remove` deletes one; `restore` restores (guarded by a typed confirm);
 * `saveSettings` writes the tiger.backup.* config tier.
 *
 * These tests characterize:
 *   - the ACL gate + input validation guards (bad component / bad disk / not-found / restore confirm),
 *     which never touch the destructive path;
 *   - a REAL end-to-end database-only backup (SQL dump → zip → local disk → catalog row) and its
 *     removal, confined to the DATABASE component so no app files are collected;
 *   - the destructive-restore GUARD logic (confirm !== 'RESTORE', a missing/failed backup) WITHOUT ever
 *     running a real restore (which would overwrite the live DB + files).
 *
 * The catalog row rides the per-test transaction (rolled back for isolation); the physical zip on the
 * local disk is cleaned up explicitly in tearDown.
 */
#[CoversClass(Backup_Service_Backup::class)]
#[CoversClass(Tiger_Backup::class)]
final class BackupServiceTest extends IntegrationTestCase
{
    /** Absolute paths of any archive bytes a real backup wrote — removed in tearDown. */
    private array $artifacts = [];
    private ?Zend_Config $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        // A null log sink — Tiger_Backup logs on some paths, which the strict output check would flag.
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['log' => ['writer' => 'null']]]));
        Tiger_Log::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->artifacts as $f) { @unlink($f); }
        $this->artifacts = [];
        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif (Zend_Registry::isRegistered('Zend_Config')) {
            Zend_Registry::set('Zend_Config', new Zend_Config([]));
        }
        Tiger_Log::reset();
        parent::tearDown();
    }

    private function dispatch(array $msg): object
    {
        return (new Backup_Service_Backup($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    /** The local backups dir where Tiger_Backup stores archives (under APPLICATION_ROOT). */
    private function localBackupDir(): string
    {
        return APPLICATION_ROOT . '/storage/backups';
    }

    // ---- ACL -------------------------------------------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_backup_to_admin_and_up(): void
    {
        $this->loginAs('admin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('Backup_Service_Backup'), 'the acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('admin', 'Backup_Service_Backup'));
        $this->assertFalse($acl->isAllowed('user', 'Backup_Service_Backup'), 'a plain user is denied');
        $this->assertFalse($acl->isAllowed('guest', 'Backup_Service_Backup'));
    }

    #[Test]
    public function a_guest_is_denied_every_backup_verb(): void
    {
        $this->login('anon', 'o-1', 'guest');
        foreach (['run', 'remove', 'restore', 'saveSettings'] as $action) {
            $res = $this->dispatch(['action' => $action]);
            $this->assertSame(0, (int) $res->result, "$action denied to a guest");
            $this->assertStringContainsString('not_allowed', $this->messages($res));
        }
    }

    // ---- run: input guards -----------------------------------------------------------------------

    #[Test]
    public function run_rejects_an_empty_or_unknown_component_set(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'run', 'components' => 'bogus,alsobad', 'disk' => 'local']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('bad_component', $this->messages($res));
    }

    #[Test]
    public function run_rejects_an_unknown_disk(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'run', 'components' => 'database', 'disk' => 's3-nowhere']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('bad_disk', $this->messages($res));
    }

    // ---- run + remove: the real, database-only round trip ----------------------------------------

    #[Test]
    public function run_creates_a_database_backup_and_remove_deletes_it(): void
    {
        $this->loginAs('admin');

        $res = $this->dispatch(['action' => 'run', 'components' => 'database', 'disk' => 'local', 'include_secrets' => '0']);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $backupId = $res->data['backup_id'];
        $filename = $res->data['filename'];
        $this->assertNotEmpty($backupId);
        $this->assertStringStartsWith('TigerBackup-', $filename);

        // The archive bytes exist on the local disk...
        $archive = $this->localBackupDir() . '/' . $filename;
        $this->artifacts[] = $archive;   // ensure cleanup even if an assertion fails
        $this->assertFileExists($archive, 'the zip was written to the local disk');

        // ...and the catalog row is present + marked ok (it rides the per-test transaction).
        $row = (new Tiger_Model_Backup())->findById($backupId);
        $this->assertNotNull($row);
        $this->assertSame('ok', $row['outcome']);
        $this->assertSame('manual', $row['source']);

        // remove() deletes the bytes and soft-deletes the row.
        $del = $this->dispatch(['action' => 'remove', 'backup_id' => $backupId]);
        $this->assertSame(1, (int) $del->result, $this->messages($del));
        $this->assertFileDoesNotExist($archive, 'remove() deleted the archive bytes');
    }

    // ---- remove: not-found -----------------------------------------------------------------------

    #[Test]
    public function remove_reports_not_found_for_a_missing_backup(): void
    {
        $this->loginAs('admin');

        $empty = $this->dispatch(['action' => 'remove', 'backup_id' => '']);
        $this->assertSame(0, (int) $empty->result);
        $this->assertStringContainsString('not_found', $this->messages($empty));

        $unknown = $this->dispatch(['action' => 'remove', 'backup_id' => 'no-such-id']);
        $this->assertSame(0, (int) $unknown->result);
        $this->assertStringContainsString('not_found', $this->messages($unknown));
    }

    // ---- restore: the destructive guard (no real restore ever runs) ------------------------------

    #[Test]
    public function restore_requires_the_typed_confirmation(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'restore', 'backup_id' => 'anything', 'confirm' => 'yes please']);

        $this->assertSame(0, (int) $res->result, 'a wrong confirm string is refused BEFORE any restore work');
        $this->assertStringContainsString('restore.confirm', $this->messages($res));
    }

    #[Test]
    public function restore_reports_not_found_for_an_unknown_backup_even_when_confirmed(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'restore', 'backup_id' => 'no-such-id', 'confirm' => 'RESTORE']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_found', $this->messages($res));
    }

    #[Test]
    public function restore_refuses_a_backup_that_did_not_finish_ok(): void
    {
        $this->loginAs('admin');
        // A catalog row still 'running' (never finished) must not be restorable — guarded before any
        // destructive action. We insert the row directly (rides the per-test transaction).
        $id = (new Tiger_Model_Backup())->begin('TigerBackup-unfinished.zip', 'local', ['database'], 'manual');

        $res = $this->dispatch(['action' => 'restore', 'backup_id' => $id, 'confirm' => 'RESTORE']);
        $this->assertSame(0, (int) $res->result, 'a non-ok backup is refused');
        $this->assertStringContainsString('not_found', $this->messages($res));
    }

    // ---- saveSettings ----------------------------------------------------------------------------

    #[Test]
    public function save_settings_writes_the_backup_config_tier(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch([
            'action'          => 'saveSettings',
            'components'      => 'database,media',
            'disk'           => 'local',
            'include_secrets' => '1',
            'retention_max'  => '5',
            'notify_enabled' => '1',
            'notify_email'   => 'ops@example.com',
        ]);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $this->assertSame('database,media', $cfg->get($g, '', 'tiger.backup.components'));
        $this->assertSame('local', $cfg->get($g, '', 'tiger.backup.disk'));
        $this->assertSame('1', $cfg->get($g, '', 'tiger.backup.include_secrets'));
        $this->assertSame('5', $cfg->get($g, '', 'tiger.backup.retention.max'));
        $this->assertSame('1', $cfg->get($g, '', 'tiger.backup.notify.enabled'));
        $this->assertSame('ops@example.com', $cfg->get($g, '', 'tiger.backup.notify.email'));
    }

    #[Test]
    public function save_settings_rejects_a_bad_disk_and_a_bad_email(): void
    {
        $this->loginAs('admin');

        $badDisk = $this->dispatch(['action' => 'saveSettings', 'components' => 'database', 'disk' => 'nope']);
        $this->assertSame(0, (int) $badDisk->result);
        $this->assertStringContainsString('bad_disk', $this->messages($badDisk));

        $badEmail = $this->dispatch(['action' => 'saveSettings', 'components' => 'database', 'disk' => 'local', 'notify_email' => 'not-an-email']);
        $this->assertSame(0, (int) $badEmail->result);
        $this->assertStringContainsString('bad_email', $this->messages($badEmail));
    }
}
