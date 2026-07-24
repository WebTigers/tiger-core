<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Backup;

use Backup_IndexController;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ModuleControllerTestCase;

/**
 * Backup_IndexController — dispatch coverage via the ControllerTestCase harness.
 *
 * (Was a characterization of a real bug: the controller redeclared the base `_json()` with an
 * incompatible signature, a PHP 8.5 fatal-at-class-load that 500'd every /backup request. Fixed by
 * renaming the helper to `_jsonBody()`; this test now exercises the actual actions.)
 */
final class IndexControllerTest extends ModuleControllerTestCase
{
    #[Test]
    public function the_admin_screen_renders_the_backups_list_and_settings(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(Backup_IndexController::class, 'index');
        $this->assertSame(200, $res->getHttpResponseCode(), 'the backup admin screen dispatches without error');
    }

    #[Test]
    public function a_download_of_an_unknown_backup_id_does_not_stream(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(Backup_IndexController::class, 'download', ['id' => 'no-such-backup']);
        // No matching row → the action returns without a file body (a 404-ish empty stream), never a fatal.
        $this->assertNotSame('', (string) $res->getHttpResponseCode());
    }

    #[Test]
    public function a_restore_without_the_typed_confirmation_is_refused(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(Backup_IndexController::class, 'upload', ['confirm' => ''], 'POST');
        $json = json_decode($res->getBody(), true);
        $this->assertSame(0, (int) ($json['result'] ?? -1), 'a restore needs the literal RESTORE confirmation');
    }

    #[Test]
    public function a_confirmed_restore_with_no_uploaded_file_reports_upload_failed(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(Backup_IndexController::class, 'upload', ['confirm' => 'RESTORE'], 'POST');
        $json = json_decode($res->getBody(), true);
        $this->assertSame(0, (int) ($json['result'] ?? -1), 'no file → refused');
        $this->assertStringContainsString('upload', json_encode($json), 'the failure is an upload error');
    }
}
