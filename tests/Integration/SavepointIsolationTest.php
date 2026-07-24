<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration;

use Access_Service_User;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Media;
use Zend_Config;
use Zend_Registry;

/**
 * Proves the harness's re-entrant transaction isolation (Tiger\Tests\Support\SavepointAdapter).
 *
 * The base wraps every test in one outer transaction it rolls back for isolation. Stock ZF1/PDO can't
 * open a second transaction inside that one, so a real `/api` service's own `_transaction()` (or a model
 * `save()`) used to throw "already an active transaction" — the reason the Wave-3 service tests had to
 * commit-and-scrub (COVERAGE-PLAN §9, finding #7). The SavepointAdapter maps nested begin/commit/rollBack
 * onto MySQL SAVEPOINTs, so inner transactions compose AND the final outer rollback still discards
 * everything. This test locks both halves: nesting works, and isolation survives an inner COMMIT.
 */
final class SavepointIsolationTest extends IntegrationTestCase
{
    /** A throwaway row keyed by `filename` (media has the simplest required column set). */
    private function insertMarker(string $key): void
    {
        (new Tiger_Model_Media())->insert([
            'org_id' => 'sp-org', 'filename' => $key, 'mime_type' => 'text/plain',
            'storage_key' => $key, 'disk' => 'local', 'kind' => 'file',
        ]);
    }

    private function markerCount(string $key): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM media WHERE filename = ?', [$key]);
    }

    #[Test]
    public function a_nested_begin_commit_does_not_throw_and_persists_within_the_outer_txn(): void
    {
        // Under the stock adapter this beginTransaction() (inside the base outer txn) would throw.
        $this->db->beginTransaction();          // depth 1 → SAVEPOINT tiger_sp_1
        $this->insertMarker('sp-nest-commit');
        $this->db->commit();                    // → RELEASE SAVEPOINT tiger_sp_1 (no error)

        $this->assertSame(1, $this->markerCount('sp-nest-commit'), 'the inner-committed row is visible');
    }

    #[Test]
    public function a_nested_rollback_undoes_only_its_own_work(): void
    {
        $this->insertMarker('sp-outer');        // outer-txn work
        $this->db->beginTransaction();          // SAVEPOINT
        $this->insertMarker('sp-inner');
        $this->db->rollBack();                  // → ROLLBACK TO SAVEPOINT (inner only)

        $this->assertSame(1, $this->markerCount('sp-outer'), 'outer work survives the inner rollback');
        $this->assertSame(0, $this->markerCount('sp-inner'), 'inner work is undone');
    }

    #[Test]
    public function three_levels_of_nesting_compose(): void
    {
        $this->db->beginTransaction();          // depth 1
        $this->db->beginTransaction();          // depth 2
        $this->insertMarker('sp-deep');
        $this->db->commit();                    // release depth 2
        $this->db->commit();                    // release depth 1

        $this->assertSame(1, $this->markerCount('sp-deep'), 'a two-deep nest resolves cleanly');
    }

    // The two probes below are identical on purpose: each asserts a clean start THEN inner-commits the
    // SAME marker. If the outer per-test rollback failed to discard an inner-committed row, whichever
    // runs second would see count>0 at the top and fail — so both passing proves isolation holds across
    // tests despite an inner COMMIT, in any order.

    #[Test]
    public function isolation_survives_an_inner_commit_probe_a(): void
    {
        $this->isolationProbe();
    }

    #[Test]
    public function isolation_survives_an_inner_commit_probe_b(): void
    {
        $this->isolationProbe();
    }

    private function isolationProbe(): void
    {
        $this->assertSame(0, $this->markerCount('sp-iso'), 'each test starts clean — the prior outer rollback discarded even inner-committed savepoint work');
        $this->db->beginTransaction();
        $this->insertMarker('sp-iso');
        $this->db->commit();
        $this->assertSame(1, $this->markerCount('sp-iso'));
    }

    #[Test]
    public function a_real_service_transaction_now_nests_inside_the_per_test_txn(): void
    {
        // The payoff: dispatch a real /api service whose save() opens its OWN _transaction(), WITHOUT
        // escaping the base txn first (the Wave-3 workaround). It must commit cleanly and the row is then
        // discarded by the base rollback — service happy-paths test with true rollback isolation.
        $this->loginAs('admin');
        Zend_Registry::set('tiger.auth.stateless', true);                                    // no CSRF session in CLI
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['i18n' => ['locales' => 'en,es']]], true));

        $res = (new Access_Service_User([
            'action' => 'save', 'email' => 'savepoint@w3ctest.com', 'username' => 'spuser', 'status' => 'active',
        ]))->getResponse();

        $this->assertSame(1, (int) $res->result, 'the service save committed its own nested transaction');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user WHERE email = ?', ['savepoint@w3ctest.com']), 'the row landed and is visible in-test');

        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
    }
}
