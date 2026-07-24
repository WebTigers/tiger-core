<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_PasswordHistory;
use Tiger_Model_User;

/**
 * Tiger_Model_PasswordHistory — the retired-hash archive (migration 0012) that feeds reuse
 * prevention. Two behaviors matter: `recentForUser()` returns hashes NEWEST-FIRST (the policy walks
 * the most recent first) and `prune()` bounds the archive to the newest N (so it can't grow
 * unbounded). Because rows created in one test tick share a `created_at` second, the ordering tests
 * insert explicit, spaced timestamps so "newest first" is deterministic rather than tie-broken.
 */
#[CoversClass(Tiger_Model_PasswordHistory::class)]
final class PasswordHistoryTest extends IntegrationTestCase
{
    private Tiger_Model_PasswordHistory $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->history = new Tiger_Model_PasswordHistory();
    }

    private function makeUser(): string
    {
        return (new Tiger_Model_User())->insert(['email' => 'hist-' . bin2hex(random_bytes(8)) . '@example.test']);
    }

    /** Archive a row with an explicit created_at so ordering is unambiguous (secret doubles as a marker). */
    private function archiveAt(string $userId, string $marker, int $ageSeconds): void
    {
        $this->history->insert([
            'user_id'    => $userId,
            'secret'     => $marker,
            'created_at' => date('Y-m-d H:i:s', time() - $ageSeconds),
        ]);
    }

    #[Test]
    public function recent_for_user_returns_newest_first(): void
    {
        $user = $this->makeUser();
        $this->archiveAt($user, 'oldest', 300);
        $this->archiveAt($user, 'middle', 200);
        $this->archiveAt($user, 'newest', 100);

        $markers = [];
        foreach ($this->history->recentForUser($user, 5) as $row) {
            $markers[] = (string) $row->secret;
        }
        $this->assertSame(['newest', 'middle', 'oldest'], $markers, 'newest retired hash comes first');
    }

    #[Test]
    public function recent_for_user_scopes_to_the_user(): void
    {
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $this->archiveAt($user, 'u-new', 100);
        $this->archiveAt($user, 'u-old', 200);
        $this->archiveAt($other, 'other', 150);

        $secrets = [];
        foreach ($this->history->recentForUser($user, 50) as $row) {
            $this->assertSame($user, $row->user_id, 'only this user\'s history is returned');
            $secrets[] = (string) $row->secret;
        }
        $this->assertSame(['u-new', 'u-old'], $secrets, 'newest-first, and never another user\'s row');
    }

    /**
     * recentForUser() honors its $limit. (Regression guard for a fixed bug: the limit used to be a
     * silent no-op — passed as fetchAll()'s $count alongside a Select, which Zend_Db_Table_Abstract
     * ignores when the first arg is already a Select, so it returned ALL retained rows regardless.
     * Now the limit lives ON the Select — see Model/PasswordHistory.php. This pins that it takes.)
     */
    #[Test]
    public function recent_for_user_honors_its_limit(): void
    {
        $user = $this->makeUser();
        $this->archiveAt($user, 'r1', 300);
        $this->archiveAt($user, 'r2', 200);
        $this->archiveAt($user, 'r3', 100);

        // Fixed: the $limit now lives on the Select, so a limit of 1 returns exactly the newest row,
        // and 2 returns the newest two — the configured `history` count is honored (see method docblock).
        $this->assertCount(1, $this->history->recentForUser($user, 1), 'limit=1 → newest only');
        $this->assertSame('r3', $this->history->recentForUser($user, 1)->current()->secret);
        $this->assertCount(2, $this->history->recentForUser($user, 2), 'limit=2 → newest two');
        $this->assertCount(3, $this->history->recentForUser($user, 5), 'limit > rows → all');
    }

    #[Test]
    public function prune_keeps_only_the_newest_n(): void
    {
        $user = $this->makeUser();
        // Seven rows, oldest→newest, spaced so age order is stable.
        foreach (['h1' => 700, 'h2' => 600, 'h3' => 500, 'h4' => 400, 'h5' => 300, 'h6' => 200, 'h7' => 100] as $marker => $age) {
            $this->archiveAt($user, $marker, $age);
        }

        $this->history->prune($user, 3);

        $kept = [];
        foreach ($this->history->recentForUser($user, 50) as $row) {
            $kept[] = (string) $row->secret;
        }
        $this->assertSame(['h7', 'h6', 'h5'], $kept, 'prune retains exactly the newest 3, dropping the rest');
    }
}
