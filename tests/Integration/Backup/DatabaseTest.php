<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Backup;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Backup_Database;
use Zend_Db_Table_Abstract;

/**
 * Tiger_Backup_Database — the portable, shell-free SQL dump + restore over the live Zend_Db adapter.
 *
 * There is no `mysqldump` — schema (`SHOW CREATE TABLE`) and rows are read through the app adapter and
 * emitted as standard SQL, so it runs on locked-down cPanel hosting. These tests characterize:
 *   - `dump()` over the REAL migrated test schema (read-only) — the token header, the SET-guards, a
 *     DROP+CREATE per table, chunked INSERTs, and the returned {tables, rows, token} tally;
 *   - the restore GUARD — a file that isn't a TigerBackup dump (no statement token) is refused before
 *     a single statement runs;
 *   - a real `import()` round trip confined to a THROWAWAY table (`zzz_w5_selftest`) so nothing real is
 *     ever clobbered — the destructive DROP/CREATE only ever names the throwaway table;
 *   - the mid-restore failure path — a broken statement aborts with a "restore failed at statement N"
 *     RuntimeException (FK-checks restored on the way out).
 *
 * `import()` runs raw PDO `exec` (DDL implicit-commits, escaping the per-test transaction), so the
 * throwaway tables are dropped explicitly in tearDown. `dump()` writes to a temp file, also cleaned up.
 */
#[CoversClass(Tiger_Backup_Database::class)]
final class DatabaseTest extends IntegrationTestCase
{
    /** @var string[] temp .sql files to unlink */
    private array $tmpFiles = [];
    /** @var string[] throwaway tables to hard-drop (they escape the per-test transaction via DDL) */
    private array $throwaway = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) { @unlink($f); }
        // Drop any throwaway tables an import() test created (raw — outside the rolled-back txn).
        if (isset($this->db)) {
            $pdo = $this->db->getConnection();
            foreach ($this->throwaway as $t) {
                try { $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`'); } catch (\Throwable $e) {}
            }
        }
        $this->tmpFiles = [];
        $this->throwaway = [];
        parent::tearDown();
    }

    private function tmpFile(): string
    {
        $p = tempnam(sys_get_temp_dir(), 'tw5dump') . '.sql';
        $this->tmpFiles[] = $p;
        return $p;
    }

    /** Assemble a dump body in the exact statement-token framing dump()/import() speak. */
    private function craftDump(string $token, array $statements): string
    {
        $sep = "\n-- @" . $token . "@\n";
        $sql = "-- TigerBackup SQL dump (test)\n-- TIGER_STMT_TOKEN: {$token}\n";
        $sql .= 'SET NAMES utf8mb4;' . $sep;
        foreach ($statements as $s) {
            $sql .= $s . $sep;
        }
        return $sql;
    }

    // ---- dump() over the real schema (read-only) -----------------------------------------------

    #[Test]
    public function dump_writes_a_tokened_sql_file_over_the_live_schema(): void
    {
        $path = $this->tmpFile();
        $meta = Tiger_Backup_Database::dump($path);

        // The tally is sane against the migrated test DB (many tables, the migrations table has rows).
        $this->assertArrayHasKey('tables', $meta);
        $this->assertArrayHasKey('rows', $meta);
        $this->assertArrayHasKey('token', $meta);
        $this->assertGreaterThan(0, $meta['tables'], 'the migrated schema has tables to dump');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $meta['token']);

        $sql = file_get_contents($path);
        // The self-describing header the restore keys off + the portability guards.
        $this->assertStringContainsString('-- TIGER_STMT_TOKEN: ' . $meta['token'], $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1', $sql);
        // Every dumped table is DROP-guarded then recreated.
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        // The per-dump statement separator carries the random token (collision-proof against row data).
        $this->assertStringContainsString('-- @' . $meta['token'] . '@', $sql);
    }

    #[Test]
    public function dump_emits_rows_as_chunked_inserts_for_a_populated_table(): void
    {
        // The migrations ledger is always populated after ensureMigrated(), so its rows must dump.
        $path = $this->tmpFile();
        $meta = Tiger_Backup_Database::dump($path);
        $sql  = file_get_contents($path);

        $this->assertGreaterThan(0, $meta['rows'], 'a migrated DB has at least the migration ledger rows');
        $this->assertStringContainsString('INSERT INTO', $sql, 'row data is emitted as INSERT statements');
    }

    // ---- the restore guard ---------------------------------------------------------------------

    #[Test]
    public function import_refuses_a_file_that_is_not_a_tigerbackup_dump(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "-- just some SQL\nSELECT 1;\n");   // no TIGER_STMT_TOKEN header

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a TigerBackup SQL dump/i');
        Tiger_Backup_Database::import($path);
    }

    #[Test]
    public function import_throws_when_the_file_cannot_be_read(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot read/i');
        Tiger_Backup_Database::import(sys_get_temp_dir() . '/w5-no-such-dump-' . bin2hex(random_bytes(4)) . '.sql');
    }

    // ---- a real import() round trip, confined to a throwaway table ------------------------------

    #[Test]
    public function import_executes_a_valid_dump_against_a_throwaway_table(): void
    {
        $table = 'zzz_w5_selftest';
        $this->throwaway[] = $table;

        $token = bin2hex(random_bytes(6));
        $sql = $this->craftDump($token, [
            'DROP TABLE IF EXISTS `' . $table . '`',
            'CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, name VARCHAR(50))',
            "INSERT INTO `{$table}` (id, name) VALUES (1, 'alpha'), (2, 'bravo')",
        ]);
        $path = $this->tmpFile();
        file_put_contents($path, $sql);

        $n = Tiger_Backup_Database::import($path);
        $this->assertGreaterThanOrEqual(4, $n, 'header/SET + drop + create + insert all executed');

        // The throwaway table now exists with the inserted rows (raw read — it was implicit-committed).
        $pdo = Zend_Db_Table_Abstract::getDefaultAdapter()->getConnection();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $this->assertSame(2, $count, 'the restored rows landed');
    }

    #[Test]
    public function import_aborts_on_a_broken_statement_and_restores_fk_checks(): void
    {
        $token = bin2hex(random_bytes(6));
        // A valid statement, then deliberate garbage that PDO can't parse.
        $sql = $this->craftDump($token, [
            'SET @w5 := 1',
            'THIS IS NOT VALID SQL AT ALL',
        ]);
        $path = $this->tmpFile();
        file_put_contents($path, $sql);

        try {
            Tiger_Backup_Database::import($path);
            $this->fail('a broken statement must abort the restore');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('restore failed at statement', $e->getMessage());
        }

        // FK checks were re-enabled on the failure path (the catch runs SET FOREIGN_KEY_CHECKS=1).
        $pdo = Zend_Db_Table_Abstract::getDefaultAdapter()->getConnection();
        $fk  = $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $this->assertSame(1, (int) $fk, 'FK checks are restored even when a restore fails');
    }
}
