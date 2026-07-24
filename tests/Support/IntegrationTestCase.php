<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tiger_Db_Migrator;
use Tiger_Model_Table;
use Zend_Db;
use Zend_Db_Adapter_Abstract;
use Zend_Db_Table_Abstract;
use Zend_Registry;

/**
 * Base for tests that need a real MySQL/MariaDB schema.
 *
 * Design (COVERAGE-PLAN §3): a real DB, migrated ONCE per process, with every test wrapped in a
 * transaction that is rolled back in tearDown — so data tests are isolated and fast without
 * re-migrating. DDL auto-commits in MySQL, so the one-time migrate sits outside the per-test
 * transaction by construction.
 *
 * Connection comes from env so the same suite runs in CI (a MariaDB service) and against any local
 * throwaway DB — and **self-skips** when `TIGER_TEST_DB_NAME` is unset, so `composer test` never
 * fails just because a contributor has no database handy.
 *
 *   TIGER_TEST_DB_HOST (default 127.0.0.1)  TIGER_TEST_DB_PORT (default 3306)
 *   TIGER_TEST_DB_NAME (required to run)    TIGER_TEST_DB_USER (default root)
 *   TIGER_TEST_DB_PASS (default '')
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Zend_Db_Adapter_Abstract $sharedDb = null;
    private static bool $migrated = false;

    protected Zend_Db_Adapter_Abstract $db;

    protected function setUp(): void
    {
        parent::setUp();

        $name = getenv('TIGER_TEST_DB_NAME');
        if ($name === false || $name === '') {
            $this->markTestSkipped('Integration DB not configured (set TIGER_TEST_DB_NAME to run).');
        }

        $this->db = self::adapter($name);
        $this->ensureMigrated();

        // Clean per-test static context on the base model, then isolate the test in a transaction.
        Tiger_Model_Table::setActor(null);
        Tiger_Model_Table::setOrg('');
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            // Roll back everything the test wrote — cheap, total isolation.
            try {
                $this->db->rollBack();
            } catch (\Throwable $e) {
                // a test that committed/closed the txn itself is fine; ignore.
            }
        }
        Tiger_Model_Table::setActor(null);
        Tiger_Model_Table::setOrg('');
        parent::tearDown();
    }

    /** The shared, migrated adapter (built once, reused across the process). */
    private static function adapter(string $name): Zend_Db_Adapter_Abstract
    {
        if (self::$sharedDb === null) {
            self::$sharedDb = Zend_Db::factory('Pdo_Mysql', [
                'host'     => getenv('TIGER_TEST_DB_HOST') ?: '127.0.0.1',
                'port'     => (int) (getenv('TIGER_TEST_DB_PORT') ?: 3306),
                'dbname'   => $name,
                'username' => getenv('TIGER_TEST_DB_USER') ?: 'root',
                'password' => getenv('TIGER_TEST_DB_PASS') ?: '',
                'charset'  => 'utf8mb4',
            ]);
            // Wire it exactly like the app bootstrap so Tiger_Model_Table just works.
            Zend_Db_Table_Abstract::setDefaultAdapter(self::$sharedDb);
            Zend_Registry::set('Zend_Db', self::$sharedDb);
        }
        return self::$sharedDb;
    }

    /** Apply core migrations once per process (idempotent — re-runs skip applied versions). */
    private function ensureMigrated(): void
    {
        if (self::$migrated) {
            return;
        }
        $migrator = new Tiger_Db_Migrator($this->db, [dirname(__DIR__, 2) . '/migrations']);
        $migrator->migrate();
        self::$migrated = true;
    }

    /** True when a table exists in the test schema — handy for schema assertions. */
    protected function tableExists(string $table): bool
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) > 0;
    }
}
