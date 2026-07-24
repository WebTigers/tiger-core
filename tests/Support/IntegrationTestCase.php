<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tiger_Acl_Acl;
use Tiger_Db_Migrator;
use Tiger_Model_Table;
use Zend_Auth;
use Zend_Auth_Storage_NonPersistent;
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
        Zend_Auth::getInstance()->clearIdentity();   // Zend_Auth is a process singleton — never leak an identity
        parent::tearDown();
    }

    /**
     * Sign a caller in for the duration of a test (Wave 3 `/api`-service scaffolding).
     *
     * Sets a non-persistent `Zend_Auth` identity ({user_id, org_id, role}) so a service's
     * `_isAdmin()`/ACL gate and `$this->_user_id`/`_org_id` see an authenticated actor, stamps the
     * model actor + org so writes carry `created_by`/`org_id`, and registers the **real** shipped ACL
     * policy (`Tiger_Acl_Acl` — core + every module's `acl.ini`) so `isAllowed()` reflects the rules
     * that actually ship, not a fixture. Cleared in tearDown. Call with role `guest` (or don't call it)
     * to test the deny path. Returns the identity object.
     *
     * @param  string $userId the acting user id
     * @param  string $orgId  the acting org (tenant) id
     * @param  string $role   the ACL role (guest|user|manager|supermanager|admin|superadmin|developer)
     * @return object         the identity written to Zend_Auth
     */
    protected function login(string $userId, string $orgId = 'org-test', string $role = 'user'): object
    {
        $identity = (object) ['user_id' => $userId, 'org_id' => $orgId, 'role' => $role];

        $auth = Zend_Auth::getInstance();
        $auth->setStorage(new Zend_Auth_Storage_NonPersistent());   // in-memory: no $_SESSION in CLI
        $auth->getStorage()->write($identity);

        Tiger_Model_Table::setActor($userId);
        if ($orgId !== '') { Tiger_Model_Table::setOrg($orgId); }

        // The real policy (ini + DB tiers). Rebuilt per login so a test that seeds DB rules first sees them.
        Zend_Registry::set('Zend_Acl', new Tiger_Acl_Acl());

        return $identity;
    }

    /** Shorthand: sign in a synthetic user carrying $role, in the shared test org. */
    protected function loginAs(string $role): object
    {
        return $this->login('user-' . $role, 'org-test', $role);
    }

    /** Drop the signed-in identity (revert to guest) mid-test. */
    protected function logout(): void
    {
        Zend_Auth::getInstance()->clearIdentity();
        Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_NonPersistent());
    }

    /** The shared, migrated adapter (built once, reused across the process). */
    private static function adapter(string $name): Zend_Db_Adapter_Abstract
    {
        if (self::$sharedDb === null) {
            // The savepoint-aware adapter (not the stock one) so a service's own `_transaction()` nests
            // inside the per-test outer transaction instead of throwing — see SavepointAdapter.
            self::$sharedDb = new SavepointAdapter([
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
