<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Session;
use Tiger_Uuid;

/**
 * Tiger_Model_Session — the DB-backed session store (shared across app instances behind a load
 * balancer). Two operations carry real safety weight:
 *   - `gc()` reaps ONLY rows whose `(now - modified) > lifetime` — the expiry math. A not-yet-expired
 *     session must SURVIVE a GC pass (reaping it early = a random logout), and an expired one must be
 *     removed (leaving it = a session that outlives its TTL).
 *   - `deleteByUserId()` force-logs-out EVERY session a user holds (admin revoke / "sign out
 *     everywhere") and touches no other user's rows.
 *
 * Session extends Zend_Db_Table_Abstract directly (session_id is the PHP session id, not a UUID),
 * so tests seed rows through the adapter with explicit `modified`/`lifetime` unix ints.
 */
#[CoversClass(Tiger_Model_Session::class)]
final class SessionTest extends IntegrationTestCase
{
    private Tiger_Model_Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Tiger_Model_Session();
    }

    /** Seed a session row with a chosen age; `modified = now - $age`, `lifetime = $lifetime`. */
    private function seed(string $sid, int $age, int $lifetime, ?string $userId = null): void
    {
        $this->db->insert('session', [
            'session_id' => $sid,
            'modified'   => time() - $age,
            'lifetime'   => $lifetime,
            'data'       => 'x|s:0:"";',
            'user_id'    => $userId,
        ]);
    }

    private function exists(string $sid): bool
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM session WHERE session_id = ?', [$sid]) === 1;
    }

    #[Test]
    public function gc_reaps_only_expired_sessions_and_spares_live_ones(): void
    {
        // Expired: idle 4000s under a 3600s lifetime → (now-modified) > lifetime → reaped.
        $this->seed('sess-expired', 4000, 3600);
        // Live: just touched → 0 idle → survives comfortably.
        $this->seed('sess-fresh', 0, 3600);
        // Live near the edge: idle 100s under a 3600s lifetime → still inside TTL → survives.
        $this->seed('sess-recent', 100, 3600);

        $removed = $this->session->gc();

        $this->assertSame(1, $removed, 'gc removes exactly the one expired session');
        $this->assertFalse($this->exists('sess-expired'), 'the expired session is reaped');
        $this->assertTrue($this->exists('sess-fresh'), 'a fresh session survives GC');
        $this->assertTrue($this->exists('sess-recent'), 'a not-yet-expired session survives GC (no early logout)');
    }

    #[Test]
    public function gc_respects_each_rows_own_lifetime(): void
    {
        // Same age, different lifetimes: the short-lived one is expired, the long-lived one is not —
        // proving GC compares per-row lifetime, not a global constant.
        $this->seed('sess-short', 500, 300);   // idle 500 > 300 lifetime → expired
        $this->seed('sess-long', 500, 86400);  // idle 500 < 86400 lifetime → alive

        $removed = $this->session->gc();

        $this->assertSame(1, $removed);
        $this->assertFalse($this->exists('sess-short'), 'the short-lifetime session expired');
        $this->assertTrue($this->exists('sess-long'), 'the long-lifetime session is still alive');
    }

    #[Test]
    public function delete_by_user_id_force_logs_out_all_of_that_users_sessions_only(): void
    {
        $victim  = Tiger_Uuid::v7();
        $bystander = Tiger_Uuid::v7();

        // Two devices for the victim, one for an unrelated user.
        $this->seed('victim-a', 10, 3600, $victim);
        $this->seed('victim-b', 20, 3600, $victim);
        $this->seed('bystander-a', 30, 3600, $bystander);

        $deleted = $this->session->deleteByUserId($victim);

        $this->assertSame(2, $deleted, 'both of the victim\'s sessions are killed');
        $this->assertFalse($this->exists('victim-a'));
        $this->assertFalse($this->exists('victim-b'));
        $this->assertTrue($this->exists('bystander-a'), 'another user\'s session is untouched');
    }

    #[Test]
    public function get_by_user_id_lists_only_that_users_sessions_newest_first(): void
    {
        $user = Tiger_Uuid::v7();
        $this->seed('u-older', 300, 3600, $user);   // modified further in the past
        $this->seed('u-newer', 5, 3600, $user);     // modified most recently
        $this->seed('other', 5, 3600, Tiger_Uuid::v7());

        $ids = [];
        foreach ($this->session->getByUserId($user) as $row) {
            $ids[] = $row->session_id;
        }

        $this->assertSame(['u-newer', 'u-older'], $ids, 'exactly this user\'s sessions, ordered by modified DESC');
    }
}
