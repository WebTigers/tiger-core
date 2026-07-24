<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Code;
use Tiger_Model_CodeVersion;

/**
 * Tiger_Model_Code — the Tiger Code store (server-executed PHP + client CSS/JS/HTML).
 *
 * The behaviours pinned here are the ones a bug would turn into an outage or an RCE-safety hole:
 *   - save() is transactional and snapshots every write to code_version (the runtime never runs this
 *     table per request; it compiles active rows into a cached bundle),
 *   - normalize() strips a leading `<?php`/`<?=`/`<?`/BOM and a trailing `?>` so the stored body is
 *     clean (a `<?=` opener becomes an `echo`),
 *   - lint() runs `php -l` OUT of process (never executes the code) and reports ok/error,
 *   - setActive()/markError() drive the active flag + status (the auto-deactivate rail),
 *   - the compiler's load queries (activeForLoad / activeClient) return only active rows for the
 *     language + run location + org scope, in priority order.
 *
 * save() opens its own transaction; the harness's SavepointAdapter lets that nest inside the per-test
 * transaction, so these run normally and roll back at teardown.
 */
#[CoversClass(Tiger_Model_Code::class)]
final class CodeTest extends IntegrationTestCase
{
    private Tiger_Model_Code $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->code = new Tiger_Model_Code();
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'org_id'       => '',
            'name'         => 'Snippet',
            'language'     => Tiger_Model_Code::LANG_PHP,
            'code'         => 'return 1;',
            'run_location' => Tiger_Model_Code::LOC_GLOBAL,
            'priority'     => 10,
            'active'       => 0,
            'status'       => Tiger_Model_Code::STATUS_DRAFT,
        ], $overrides);
    }

    #[Test]
    public function save_inserts_and_snapshots_a_first_version(): void
    {
        $id = $this->code->save($this->baseRow(['name' => 'Hello', 'code' => 'return 42;']));
        $this->assertNotSame('', $id);

        $row = $this->code->findById($id);
        $this->assertSame('Hello', $row->name);
        $this->assertSame('return 42;', $row->code);

        $versions = (new Tiger_Model_CodeVersion())->recentFor($id);
        $this->assertCount(1, $versions, 'the insert snapshots version 1');
        $this->assertSame(1, (int) $versions->current()->version);
        $this->assertSame('return 42;', $versions->current()->code);
    }

    #[Test]
    public function save_update_snapshots_a_new_version_each_time(): void
    {
        $id = $this->code->save($this->baseRow(['code' => 'return 1;']));
        $this->code->save(['name' => 'Snippet', 'language' => Tiger_Model_Code::LANG_PHP, 'code' => 'return 2;',
            'run_location' => Tiger_Model_Code::LOC_GLOBAL, 'priority' => 10, 'active' => 0, 'status' => Tiger_Model_Code::STATUS_DRAFT], $id);

        $this->assertSame('return 2;', $this->code->findById($id)->code, 'the row reflects the latest save');
        $this->assertCount(2, (new Tiger_Model_CodeVersion())->recentFor($id), 'each save adds a version');
    }

    #[Test]
    public function normalize_strips_php_openers_bom_and_a_trailing_close_tag(): void
    {
        $this->assertSame("\nreturn 1;", $this->code->normalize("<?php\nreturn 1;"));
        $this->assertSame(' return 1;', $this->code->normalize('<? return 1;'));
        $this->assertSame('echo  $x;', $this->code->normalize('<?= $x;'), 'a <?= opener becomes echo (with the trailing chars preserved)');
        $this->assertSame('return 1;', $this->code->normalize("return 1;?>\n"));
        $this->assertSame(' return 1;', $this->code->normalize("\xEF\xBB\xBF<?php return 1;"), 'a UTF-8 BOM is stripped too');
    }

    #[Test]
    public function lint_passes_valid_php_and_reports_a_syntax_error_without_leaking_the_temp_path(): void
    {
        $ok = $this->code->lint('return 1 + 2;');
        $this->assertTrue($ok['ok'], 'valid PHP lints clean');
        $this->assertNull($ok['error']);

        $bad = $this->code->lint('return 1 +;');   // deliberate syntax error
        $this->assertFalse($bad['ok'], 'a syntax error is caught');
        $this->assertNotNull($bad['error']);
        $this->assertStringNotContainsString('tigercode', $bad['error'], 'the temp path is not leaked');
        $this->assertStringNotContainsString(sys_get_temp_dir(), $bad['error']);
    }

    #[Test]
    public function set_active_toggles_the_flag_and_status_and_clears_last_error_on_activate(): void
    {
        $id = $this->code->save($this->baseRow());
        $this->code->markError($id, 'boom');
        $errored = $this->code->findById($id);
        $this->assertSame(0, (int) $errored->active, 'markError deactivates');
        $this->assertSame(Tiger_Model_Code::STATUS_ERROR, $errored->status);
        $this->assertSame('boom', $errored->last_error);

        $this->code->setActive($id, true);
        $on = $this->code->findById($id);
        $this->assertSame(1, (int) $on->active);
        $this->assertSame(Tiger_Model_Code::STATUS_ACTIVE, $on->status);
        $this->assertNull($on->last_error, 'activating clears the stored error');

        $this->code->setActive($id, false);
        $off = $this->code->findById($id);
        $this->assertSame(0, (int) $off->active);
        $this->assertSame(Tiger_Model_Code::STATUS_DRAFT, $off->status);
    }

    #[Test]
    public function mark_error_truncates_a_long_message(): void
    {
        $id = $this->code->save($this->baseRow());
        $this->code->markError($id, str_repeat('E', 5000));
        $this->assertSame(2000, strlen($this->code->findById($id)->last_error), 'the error is clamped to 2000 chars');
    }

    #[Test]
    public function active_for_load_returns_only_active_rows_for_the_lang_location_and_scope_in_priority_order(): void
    {
        // Two active PHP/global rows (priority order) + noise that must be excluded.
        $this->code->save($this->baseRow(['name' => 'B', 'priority' => 20, 'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE]));
        $this->code->save($this->baseRow(['name' => 'A', 'priority' => 5,  'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE]));
        $this->code->save($this->baseRow(['name' => 'Inactive', 'active' => 0]));
        $this->code->save($this->baseRow(['name' => 'Admin', 'run_location' => Tiger_Model_Code::LOC_ADMIN, 'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE]));
        $this->code->save($this->baseRow(['name' => 'JS', 'language' => Tiger_Model_Code::LANG_JS, 'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE]));

        $rows   = $this->code->activeForLoad(Tiger_Model_Code::LANG_PHP, Tiger_Model_Code::LOC_GLOBAL, '');
        $names  = [];
        foreach ($rows as $r) { $names[] = $r->name; }
        $this->assertSame(['A', 'B'], $names, 'only active php/global rows, in priority order');
    }

    #[Test]
    public function active_client_returns_client_injected_languages_only(): void
    {
        $this->code->save($this->baseRow(['name' => 'CSS', 'language' => Tiger_Model_Code::LANG_CSS, 'run_location' => Tiger_Model_Code::LOC_FRONTEND, 'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE]));
        $this->code->save($this->baseRow(['name' => 'PHP', 'language' => Tiger_Model_Code::LANG_PHP, 'run_location' => Tiger_Model_Code::LOC_FRONTEND, 'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE]));

        $rows  = $this->code->activeClient(Tiger_Model_Code::LOC_FRONTEND, '');
        $names = [];
        foreach ($rows as $r) { $names[] = $r->name; }
        $this->assertSame(['CSS'], $names, 'server-executed php is excluded from the client manifest');
    }

    #[Test]
    public function restore_version_copies_an_old_body_back_without_reactivating(): void
    {
        $id = $this->code->save($this->baseRow(['code' => 'return "v1";']));
        $this->code->save(['name' => 'Snippet', 'language' => Tiger_Model_Code::LANG_PHP, 'code' => 'return "v2";',
            'run_location' => Tiger_Model_Code::LOC_GLOBAL, 'priority' => 10, 'active' => 1, 'status' => Tiger_Model_Code::STATUS_ACTIVE], $id);

        $this->code->restoreVersion($id, 1);
        $restored = $this->code->findById($id);
        $this->assertSame('return "v1";', $restored->code, 'the version-1 body is restored');
        // restoreVersion copies the snapshotted status but does NOT re-flip active on the row.
        $this->assertSame(1, (int) $restored->active, 'restore does not touch the active flag (executable-code safety)');
    }

    #[Test]
    public function restore_version_throws_for_a_missing_version(): void
    {
        $id = $this->code->save($this->baseRow());
        $this->expectException(\RuntimeException::class);
        $this->code->restoreVersion($id, 99);
    }

    #[Test]
    public function datatable_filters_by_language_and_search(): void
    {
        $this->code->save($this->baseRow(['name' => 'Alpha Helper', 'description' => 'does alpha', 'language' => Tiger_Model_Code::LANG_PHP]));
        $this->code->save($this->baseRow(['name' => 'Beta Style', 'description' => 'css thing', 'language' => Tiger_Model_Code::LANG_CSS]));

        $all = $this->code->datatable(['limit' => 25]);
        $this->assertGreaterThanOrEqual(2, $all['total']);

        $php = $this->code->datatable(['language' => Tiger_Model_Code::LANG_PHP, 'limit' => 25]);
        foreach ($php['rows'] as $r) { $this->assertSame(Tiger_Model_Code::LANG_PHP, $r['language']); }

        $search = $this->code->datatable(['search' => 'Alpha', 'limit' => 25]);
        $this->assertSame(1, $search['filtered']);
        $this->assertSame('Alpha Helper', $search['rows'][0]['name']);
    }
}
