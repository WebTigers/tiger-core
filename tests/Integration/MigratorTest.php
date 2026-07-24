<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Db_Migrator;

/**
 * Tiger_Db_Migrator — the tiny, dependency-free schema runner. Verified against a real MariaDB with
 * THROWAWAY fixture migrations in a temp dir, so the core-migrated schema is never disturbed.
 *
 * The invariants under test are the ones the platform's whole schema lifecycle rests on:
 *   - version ordering (ascending, string-sorted zero-padded);
 *   - idempotency (a re-run applies nothing);
 *   - the `tiger_migration` ledger records exactly the applied versions;
 *   - APPLY-ONLY-AFTER-ALL-SUCCEED — a migration whose statements don't all succeed is left UNrecorded
 *     (so the next run retries it), never half-marked done;
 *   - rollback() runs the down statements and deletes the ledger row;
 *   - status() reflects applied vs pending.
 *
 * IMPORTANT ISOLATION NOTE: MySQL/MariaDB auto-commits DDL, which ends the base class's per-test
 * transaction — so these DDL-driven tests can't rely on the tearDown rollback for cleanup. This test
 * therefore uses out-of-range fixture versions (9xxx, far above any real migration) and distinctive
 * `zzz_mig_*` table names, and cleans BOTH up explicitly (drop tables + delete ledger rows).
 */
#[CoversClass(Tiger_Db_Migrator::class)]
final class MigratorTest extends IntegrationTestCase
{
    /** Fixture versions this test may create — purged from the ISOLATED ledger in tearDown. */
    private const FIXTURE_VERSIONS = ['9001', '9002', '9003', '9101', '9102', '9201'];

    /**
     * An ISOLATED ledger table so this test's fixtures never share the real `tiger_migration` set.
     * rollback() reverses the newest applied version GLOBALLY, so if a real (or another test's)
     * timestamp-versioned migration is committed to the shared ledger it would sort above the 9xxx
     * fixtures and be picked instead — a cross-test flake. A dedicated ledger makes rollback hermetic.
     */
    private const LEDGER = 'tiger_migration_test';

    /** Throwaway tables the fixtures may create — dropped in tearDown. */
    private const THROWAWAY_TABLES = [
        'zzz_mig_a', 'zzz_mig_b', 'zzz_mig_c', 'zzz_mig_p1', 'zzz_mig_p2', 'zzz_mig_r',
    ];

    /** @var string[] temp dirs created this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        // DDL auto-committed past the test transaction, so undo the side effects by hand.
        foreach (self::THROWAWAY_TABLES as $t) {
            try { $this->db->query("DROP TABLE IF EXISTS `$t`"); } catch (\Throwable $e) {}
        }
        foreach (self::FIXTURE_VERSIONS as $v) {
            try { $this->db->delete(self::LEDGER, $this->db->quoteInto('version = ?', $v)); } catch (\Throwable $e) {}
        }
        foreach ($this->tmpDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /** Make a temp migrations dir seeded with the given [filename => php-source] fixtures. */
    private function fixtureDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/tiger_mig_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        foreach ($files as $name => $src) {
            file_put_contents($dir . '/' . $name, $src);
        }
        return $dir;
    }

    /** A migration file body that CREATEs then DROPs a throwaway table. */
    private static function createDropMigration(string $table): string
    {
        return "<?php return ['up' => [\"CREATE TABLE `$table` (id INT)\"], 'down' => [\"DROP TABLE IF EXISTS `$table`\"]];";
    }

    private function ledgerHas(string $version): bool
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::LEDGER . ' WHERE version = ?', [$version]) > 0;
    }

    #[Test]
    public function migrate_applies_pending_versions_in_ascending_order_and_records_the_ledger(): void
    {
        // Deliberately write the higher version FIRST to prove discovery sorts, not filesystem order.
        $dir = $this->fixtureDir([
            '9002_create_b.php' => self::createDropMigration('zzz_mig_b'),
            '9001_create_a.php' => self::createDropMigration('zzz_mig_a'),
        ]);
        $migrator = new Tiger_Db_Migrator($this->db, [$dir], self::LEDGER);

        $applied = $migrator->migrate();

        // PHP casts numeric-string array keys to int, so the discovered version map is keyed by int.
        $this->assertSame([9001, 9002], array_keys($applied), 'applied in ascending version order');
        $this->assertSame(['create_a', 'create_b'], array_values($applied), 'name parsed from the filename');
        $this->assertTrue($this->tableExists('zzz_mig_a'), 'the up statement ran');
        $this->assertTrue($this->tableExists('zzz_mig_b'));
        $this->assertTrue($this->ledgerHas('9001'), 'applied version is recorded in tiger_migration');
        $this->assertTrue($this->ledgerHas('9002'));
    }

    #[Test]
    public function migrate_is_idempotent_a_second_run_applies_nothing(): void
    {
        $dir = $this->fixtureDir(['9001_create_a.php' => self::createDropMigration('zzz_mig_a')]);
        $migrator = new Tiger_Db_Migrator($this->db, [$dir], self::LEDGER);

        $this->assertSame(['9001' => 'create_a'], $migrator->migrate(), 'first run applies it');
        $this->assertSame([], $migrator->migrate(), 'a second run skips the already-applied version');
    }

    #[Test]
    public function status_reports_applied_versus_pending(): void
    {
        $dir = $this->fixtureDir([
            '9001_create_a.php' => self::createDropMigration('zzz_mig_a'),
            '9002_create_b.php' => self::createDropMigration('zzz_mig_b'),
        ]);
        $migrator = new Tiger_Db_Migrator($this->db, [$dir], self::LEDGER);

        // Apply only 9001 by hand-recording it, then let status() classify both.
        $migrator->migrate();                                  // applies both
        $this->db->delete(self::LEDGER, $this->db->quoteInto('version = ?', '9002')); // pretend 9002 pending

        $status = $migrator->status();
        $this->assertTrue($status['9001']['applied'], '9001 is applied');
        $this->assertFalse($status['9002']['applied'], '9002 reads as pending');
        $this->assertSame('create_a', $status['9001']['name']);
    }

    #[Test]
    public function a_migration_that_fails_midway_is_left_unrecorded_and_stays_pending(): void
    {
        // 9101 is clean; 9102's SECOND statement is invalid SQL. The runner throws while applying 9102,
        // so 9102 must NOT be recorded — leaving it pending to be retried on the next run.
        $dir = $this->fixtureDir([
            '9101_create_p1.php' => self::createDropMigration('zzz_mig_p1'),
            '9102_partial.php'   => "<?php return ['up' => ["
                . "\"CREATE TABLE IF NOT EXISTS `zzz_mig_p2` (id INT)\", "
                . "\"THIS IS NOT VALID SQL\""
                . "], 'down' => [\"DROP TABLE IF EXISTS `zzz_mig_p2`\"]];",
        ]);
        $migrator = new Tiger_Db_Migrator($this->db, [$dir], self::LEDGER);

        $threw = false;
        try {
            $migrator->migrate();
        } catch (\Throwable $e) {
            $threw = true;                 // the invalid statement surfaces as an exception
        }
        $this->assertTrue($threw, 'an invalid statement aborts the run');

        // The clean earlier migration IS recorded; the failing one is NOT.
        $this->assertTrue($this->ledgerHas('9101'), 'the migration before the failure committed + recorded');
        $this->assertFalse($this->ledgerHas('9102'), 'apply-only-after-all-succeed: the failed migration is unrecorded');

        // A subsequent run still sees it as pending (retried, never silently marked done).
        $this->assertFalse($migrator->status()['9102']['applied'], '9102 remains pending after a failed run');
    }

    #[Test]
    public function rollback_runs_down_statements_and_deletes_the_ledger_row(): void
    {
        $dir = $this->fixtureDir(['9201_create_r.php' => self::createDropMigration('zzz_mig_r')]);
        $migrator = new Tiger_Db_Migrator($this->db, [$dir], self::LEDGER);

        $migrator->migrate();
        $this->assertTrue($this->tableExists('zzz_mig_r'), 'precondition: up created the table');
        $this->assertTrue($this->ledgerHas('9201'), 'precondition: recorded');

        $rolled = $migrator->rollback(1);

        $this->assertSame(['9201' => 'create_r'], $rolled, 'rollback reports what it reversed');
        $this->assertFalse($this->tableExists('zzz_mig_r'), 'the down statement dropped the table');
        $this->assertFalse($this->ledgerHas('9201'), 'the ledger row is removed so it can be re-applied');
    }
}
