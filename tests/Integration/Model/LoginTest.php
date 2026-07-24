<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Login;
use Tiger_Uuid;

/**
 * Tiger_Model_Login — the append-only sign-in audit log that feeds rate-limiting / lockout. The
 * security-critical math is the windowed failure count: `recentFailuresForIdentifier()` /
 * `recentFailuresFromIp()` must count ONLY non-success attempts (failure + locked, never success)
 * that fall INSIDE the look-back window, scoped to exactly the identifier/IP asked about. Get that
 * wrong and lockout either never trips (undercount) or locks the wrong account (over/mis-count).
 *
 * Rows are stamped `created_at = now` by the base insert(), so to exercise the window boundary the
 * tests back-date `created_at` with a direct UPDATE after recording — the only way to place a row
 * "outside the window" deterministically.
 */
#[CoversClass(Tiger_Model_Login::class)]
final class LoginTest extends IntegrationTestCase
{
    private Tiger_Model_Login $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login = new Tiger_Model_Login();
    }

    /** Record an attempt and force its created_at to `now - $ageSeconds` (0 = leave as now). */
    private function attempt(array $data, int $ageSeconds = 0): string
    {
        $id = $this->login->record($data);
        if ($ageSeconds > 0) {
            $this->db->update(
                'login',
                ['created_at' => date('Y-m-d H:i:s', time() - $ageSeconds)],
                $this->db->quoteInto('login_id = ?', $id)
            );
        }
        return $id;
    }

    #[Test]
    public function failure_count_sums_only_non_success_inside_the_window_for_that_identifier(): void
    {
        $id = 'victim@example.test';

        // Three that MUST count: two failures + one locked, all recent.
        $this->attempt(['identifier' => $id, 'result' => Tiger_Model_Login::RESULT_FAILURE]);
        $this->attempt(['identifier' => $id, 'result' => Tiger_Model_Login::RESULT_FAILURE]);
        $this->attempt(['identifier' => $id, 'result' => Tiger_Model_Login::RESULT_LOCKED]);
        // A SUCCESS must NOT count (a good login is not a brute-force signal).
        $this->attempt(['identifier' => $id, 'result' => Tiger_Model_Login::RESULT_SUCCESS]);
        // A failure for a DIFFERENT identifier must NOT bleed into this count.
        $this->attempt(['identifier' => 'someone-else@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE]);

        $this->assertSame(
            3,
            $this->login->recentFailuresForIdentifier($id, 900),
            'exactly the recent failure+locked attempts for this identifier — success and other users excluded'
        );
    }

    #[Test]
    public function failures_older_than_the_window_are_not_counted(): void
    {
        $id = 'window@example.test';

        // One fresh failure (inside), one aged 20 minutes (outside a 15-min window).
        $this->attempt(['identifier' => $id, 'result' => Tiger_Model_Login::RESULT_FAILURE]);
        $this->attempt(['identifier' => $id, 'result' => Tiger_Model_Login::RESULT_FAILURE], 1200);

        $this->assertSame(1, $this->login->recentFailuresForIdentifier($id, 900), 'only the in-window failure counts');
        // Widen the window past the aged row and both count — proves it was the window, not the row.
        $this->assertSame(2, $this->login->recentFailuresForIdentifier($id, 1800), 'a wider window re-includes the aged failure');
    }

    #[Test]
    public function ip_failure_count_windows_and_filters_the_same_way(): void
    {
        $ip = '203.0.113.7';

        $this->attempt(['ip_address' => $ip, 'identifier' => 'a@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE]);
        $this->attempt(['ip_address' => $ip, 'identifier' => 'b@example.test', 'result' => Tiger_Model_Login::RESULT_LOCKED]);
        $this->attempt(['ip_address' => $ip, 'identifier' => 'c@example.test', 'result' => Tiger_Model_Login::RESULT_SUCCESS]);
        $this->attempt(['ip_address' => $ip, 'identifier' => 'd@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE], 1200);
        // A failure from another IP must not count toward this one.
        $this->attempt(['ip_address' => '198.51.100.9', 'identifier' => 'e@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE]);

        // In-window non-success from this IP across several identifiers = 2 (the distributed signal).
        $this->assertSame(2, $this->login->recentFailuresFromIp($ip, 900), 'IP count spans identifiers but excludes success, the aged row, and other IPs');
    }

    #[Test]
    public function no_matching_attempts_counts_zero(): void
    {
        // A never-seen identifier / IP must be a clean 0, not a null or an error.
        $this->assertSame(0, $this->login->recentFailuresForIdentifier('nobody@example.test', 900));
        $this->assertSame(0, $this->login->recentFailuresFromIp('192.0.2.1', 900));
    }

    #[Test]
    public function record_persists_the_attempt_and_recent_history_is_newest_first(): void
    {
        $userId = Tiger_Uuid::v7();

        $older = $this->attempt(['user_id' => $userId, 'identifier' => 'hist@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE], 60);
        $newer = $this->attempt(['user_id' => $userId, 'identifier' => 'hist@example.test', 'result' => Tiger_Model_Login::RESULT_SUCCESS]);

        $rows = $this->login->recentForUser($userId, 10);
        $this->assertCount(2, $rows, 'both attempts are recorded against the user');

        $ordered = [];
        foreach ($rows as $r) {
            $ordered[] = $r->login_id;
        }
        $this->assertSame([$newer, $older], $ordered, 'recent history is newest-first');
    }
}
