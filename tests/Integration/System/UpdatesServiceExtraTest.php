<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Updates;
use Tiger\Tests\Support\IntegrationTestCase;

// System_Service_Updates resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * System_Service_Updates — the branches Wave 4's UpdatesServiceTest left uncovered: the `check()`
 * detection read, and the `apply()` orchestration itself (build the pending index → dispatch each
 * selected slug → record the run to `update_history`). We drive `apply()` with a slug that ISN'T
 * pending, which exercises the whole apply body — the "unknown/no-longer-pending" resolution result,
 * `_recordHistory` (the OUTCOME_FAILED path), and `_wasRolledBack` — WITHOUT running the real
 * `_applyOne` installer/composer engine (which needs a live release + a writable vendor/). The core
 * version check is seeded into its file cache so no dispatch reaches Packagist.
 *
 * `_applyOne`'s core-update and module-update branches (network + real installer) are characterized
 * only at the ACL/guard level here — see WAVE6-FINDINGS-system.md.
 */
#[CoversClass(System_Service_Updates::class)]
final class UpdatesServiceExtraTest extends IntegrationTestCase
{
    /** Core version-check cache path + original contents for restore. */
    private string $coreCacheFile = '';
    private ?string $coreCacheOrig = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed a low "latest" so Tiger_Update_Checker::all() resolves offline with nothing pending.
        $this->coreCacheFile = dirname(APPLICATION_PATH) . '/var/cache/updates/core.json';
        $this->coreCacheOrig = is_file($this->coreCacheFile) ? (string) file_get_contents($this->coreCacheFile) : null;
        @mkdir(dirname($this->coreCacheFile), 0775, true);
        @file_put_contents($this->coreCacheFile, json_encode(['v' => '0.0.1']));
    }

    protected function tearDown(): void
    {
        if ($this->coreCacheFile !== '') {
            if ($this->coreCacheOrig !== null) { @file_put_contents($this->coreCacheFile, $this->coreCacheOrig); }
            else { @unlink($this->coreCacheFile); }
        }
        parent::tearDown();
    }

    private function dispatch(array $msg): object
    {
        return (new System_Service_Updates($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- check() ---------------------------------------------------------------------------------

    #[Test]
    public function check_returns_the_detection_list_for_a_superadmin(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'check']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertIsArray($res->data['updates'], 'the checker result is returned');
        // The seeded core descriptor is present and, with a low latest, is not flagged for update.
        $core = null;
        foreach ($res->data['updates'] as $u) { if (($u['slug'] ?? '') === 'tiger-core') { $core = $u; } }
        $this->assertNotNull($core, 'the platform (tiger-core) is always a checkable item');
        $this->assertFalse((bool) $core['update'], 'the seeded low latest means no pending core update');
    }

    #[Test]
    public function a_plain_admin_is_denied_checking_for_updates(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'check']);

        $this->assertSame(0, (int) $res->result, 'checking is superadmin+');
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }

    // ---- apply() orchestration (unknown item → full body, no installer) --------------------------

    #[Test]
    public function apply_resolves_an_item_that_is_not_pending_and_records_the_run(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'apply', 'items' => 'no-such-module']);

        // The run "succeeds" as an operation (it dispatched); the item itself failed to resolve.
        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertStringContainsString('system.update.done', $this->messages($res));

        $this->assertCount(1, $res->data['results']);
        $item = $res->data['results'][0];
        $this->assertSame('no-such-module', $item['slug']);
        $this->assertFalse((bool) $item['ok'], 'an unknown/no-longer-pending item does not succeed');
        $this->assertSame('resolve', $item['log'][0]['step'], 'the resolution step is logged');
    }

    #[Test]
    public function apply_accepts_a_comma_separated_string_of_items(): void
    {
        // `items` may arrive as a CSV string (not just an array) — it's split + trimmed.
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'apply', 'items' => 'ghost-a, ghost-b']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $slugs = array_map(static fn ($r) => $r['slug'], $res->data['results']);
        $this->assertSame(['ghost-a', 'ghost-b'], $slugs, 'both CSV items were dispatched');
    }

    // ---- history() -------------------------------------------------------------------------------

    #[Test]
    public function history_defaults_its_limit_and_returns_a_list(): void
    {
        // No `limit` param → the default (20) branch; the read is fail-soft and always a success.
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'history']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertIsArray($res->data['history']);
    }
}
