<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Support;

use Zend_Db_Adapter_Pdo_Mysql;

/**
 * SavepointAdapter — a test-only PDO/MySQL adapter that makes transactions RE-ENTRANT via SAVEPOINTs.
 *
 * ZF1's adapter (like raw PDO) does not ref-count transactions: a second `beginTransaction()` while one
 * is open throws "There is already an active transaction". That collides with the harness design —
 * `IntegrationTestCase` wraps every test in one outer transaction it rolls back for isolation, but a
 * real `/api` service under test opens its OWN `_transaction()` (or a model `save()` does). Under the
 * stock adapter that inner begin blows up, so service happy-paths could only be tested by committing the
 * outer transaction first and hand-scrubbing the rows (COVERAGE-PLAN §9, finding #7).
 *
 * This adapter fixes that at the source: it counts transaction depth and maps the NESTED levels onto
 * MySQL SAVEPOINTs — the outermost begin/commit/rollBack is a real transaction, every inner one is a
 * `SAVEPOINT` / `RELEASE SAVEPOINT` / `ROLLBACK TO SAVEPOINT`. So a service's inner transaction composes
 * cleanly inside the per-test outer one, and the final outer rollBack still undoes everything for total
 * isolation. It's wired in only by the test base (`IntegrationTestCase::adapter()`); production uses the
 * stock adapter unchanged.
 *
 * @internal test infrastructure — never shipped in a request path.
 */
class SavepointAdapter extends Zend_Db_Adapter_Pdo_Mysql
{
    /** Current transaction nesting depth (0 = no open transaction). */
    private int $_txDepth = 0;

    /** Begin: a real transaction at the top level, else a named SAVEPOINT one level deeper. */
    protected function _beginTransaction()
    {
        if ($this->_txDepth === 0) {
            parent::_beginTransaction();
        } else {
            $this->_connect();
            $this->_connection->exec('SAVEPOINT ' . $this->_savepointName($this->_txDepth));
        }
        $this->_txDepth++;
    }

    /** Commit: RELEASE the inner SAVEPOINT, or really COMMIT when unwinding the outermost level. */
    protected function _commit()
    {
        if ($this->_txDepth <= 1) {
            parent::_commit();
            $this->_txDepth = 0;
            return;
        }
        $this->_txDepth--;
        $this->_connect();
        $this->_connection->exec('RELEASE SAVEPOINT ' . $this->_savepointName($this->_txDepth));
    }

    /** Roll back: ROLLBACK TO the inner SAVEPOINT, or really ROLLBACK the outermost level. */
    protected function _rollBack()
    {
        if ($this->_txDepth <= 1) {
            parent::_rollBack();
            $this->_txDepth = 0;
            return;
        }
        $this->_txDepth--;
        $this->_connect();
        $this->_connection->exec('ROLLBACK TO SAVEPOINT ' . $this->_savepointName($this->_txDepth));
    }

    /** A savepoint identifier for a given nesting level (LIFO, so the level number is unique enough). */
    private function _savepointName(int $level): string
    {
        return 'tiger_sp_' . $level;
    }
}
