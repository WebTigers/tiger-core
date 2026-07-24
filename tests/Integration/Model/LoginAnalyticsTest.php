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
 * Tiger_Model_Login, part 2 — the anomaly + retention reads (LoginTest pins the windowed failure
 * counts). Here: topFailures() (the "who is being brute-forced" dashboard signal — most-targeted
 * identifiers over a window, with an `existing` flag telling a spray at REAL accounts from noise),
 * recentForUser() history, and purgeOlderThan() (GDPR retention).
 */
#[CoversClass(Tiger_Model_Login::class)]
final class LoginAnalyticsTest extends IntegrationTestCase
{
    private Tiger_Model_Login $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login = new Tiger_Model_Login();
    }

    private function attempt(array $data, int $ageSeconds = 0): string
    {
        $id = $this->login->record($data);
        if ($ageSeconds > 0) {
            $this->db->update('login', ['created_at' => date('Y-m-d H:i:s', time() - $ageSeconds)],
                $this->db->quoteInto('login_id = ?', $id));
        }
        return $id;
    }

    #[Test]
    public function top_failures_ranks_the_most_targeted_identifiers_and_flags_real_accounts(): void
    {
        $realUser = Tiger_Uuid::v7();

        // victim@ — 3 failures against a REAL account (user_id set on at least one).
        $this->attempt(['identifier' => 'victim@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE, 'user_id' => $realUser]);
        $this->attempt(['identifier' => 'victim@example.test', 'result' => Tiger_Model_Login::RESULT_LOCKED, 'user_id' => $realUser]);
        $this->attempt(['identifier' => 'victim@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE]);
        // noise@ — 1 failure, never a real account.
        $this->attempt(['identifier' => 'noise@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE]);
        // A SUCCESS must never count toward "being brute-forced".
        $this->attempt(['identifier' => 'victim@example.test', 'result' => Tiger_Model_Login::RESULT_SUCCESS, 'user_id' => $realUser]);

        $top = $this->login->topFailures(3600, 5);
        $this->assertNotEmpty($top);
        $this->assertSame('victim@example.test', $top[0]['identifier'], 'the most-failed identifier ranks first');
        $this->assertSame(3, $top[0]['attempts'], 'only the 3 non-success attempts are counted');
        $this->assertTrue($top[0]['existing'], 'a spray at a real account is flagged existing=true');

        $byId = [];
        foreach ($top as $r) { $byId[$r['identifier']] = $r; }
        $this->assertFalse($byId['noise@example.test']['existing'], 'a miss-only identifier is existing=false');
    }

    #[Test]
    public function top_failures_excludes_attempts_outside_the_window(): void
    {
        $this->attempt(['identifier' => 'old@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE], 7200);
        $top = $this->login->topFailures(3600, 5);
        $ids = array_column($top, 'identifier');
        $this->assertNotContains('old@example.test', $ids, 'an attempt older than the window is excluded');
    }

    #[Test]
    public function recent_for_user_returns_history_newest_first(): void
    {
        $user = Tiger_Uuid::v7();
        $this->attempt(['user_id' => $user, 'identifier' => 'u@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE], 60);
        $this->attempt(['user_id' => $user, 'identifier' => 'u@example.test', 'result' => Tiger_Model_Login::RESULT_SUCCESS]);

        $rows = $this->login->recentForUser($user, 20);
        $this->assertCount(2, $rows);
        $this->assertSame(Tiger_Model_Login::RESULT_SUCCESS, $rows->current()->result, 'the newest attempt is first');
    }

    #[Test]
    public function purge_older_than_removes_only_aged_rows(): void
    {
        $fresh = $this->attempt(['identifier' => 'fresh@example.test', 'result' => Tiger_Model_Login::RESULT_SUCCESS]);
        $aged  = $this->attempt(['identifier' => 'aged@example.test', 'result' => Tiger_Model_Login::RESULT_FAILURE], 40 * 86400);

        $removed = $this->login->purgeOlderThan(30);
        $this->assertGreaterThanOrEqual(1, $removed, 'the 40-day-old row is purged at a 30-day threshold');

        $freshLeft = (int) $this->db->fetchOne($this->db->quoteInto('SELECT COUNT(*) FROM login WHERE login_id = ?', $fresh));
        $agedLeft  = (int) $this->db->fetchOne($this->db->quoteInto('SELECT COUNT(*) FROM login WHERE login_id = ?', $aged));
        $this->assertSame(1, $freshLeft, 'a recent row survives');
        $this->assertSame(0, $agedLeft, 'the aged row is gone');
    }
}
