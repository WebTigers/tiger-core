<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_AuthChallenge;
use Tiger_Model_User;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_AuthChallenge, part 2 — the query/lifecycle helpers around redeem() (AuthChallengeTest
 * pins redeem itself). Here: latestActive() (the newest still-usable challenge, used to verify a code
 * by email with no id in the URL), invalidateActive() (burn outstanding codes before issuing a fresh
 * one so only the newest is valid), countRecent() (the send-rate guard), and purgeExpired() (cron
 * cleanup). These are the guards a caller must not have to remember.
 */
#[CoversClass(Tiger_Model_AuthChallenge::class)]
final class AuthChallengeExtraTest extends IntegrationTestCase
{
    private Tiger_Model_AuthChallenge $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['security' => ['pepper' => base64_encode(str_repeat("\x41", 32))]],
        ], true));
        $this->challenge = new Tiger_Model_AuthChallenge();
    }

    private function makeUser(): string
    {
        return (new Tiger_Model_User())->insert(['email' => 'ac-' . bin2hex(random_bytes(8)) . '@example.test']);
    }

    #[Test]
    public function latest_active_returns_the_newest_usable_challenge_and_skips_dead_ones(): void
    {
        $user = $this->makeUser();

        // An older challenge, then a newer one — latestActive returns the newest.
        $old = $this->challenge->issue($user, 'email_verify', '111111', 600);
        $this->db->update('auth_challenge', ['created_at' => date('Y-m-d H:i:s', time() - 120)],
            $this->db->quoteInto('challenge_id = ?', $old));
        $new = $this->challenge->issue($user, 'email_verify', '222222', 600);

        $active = $this->challenge->latestActive($user, 'email_verify');
        $this->assertNotNull($active);
        $this->assertSame($new, $active->challenge_id, 'the newest usable challenge wins');

        // A different type has no active challenge.
        $this->assertNull($this->challenge->latestActive($user, 'password_reset'));
    }

    #[Test]
    public function latest_active_ignores_expired_and_consumed_challenges(): void
    {
        $user = $this->makeUser();

        // Expired.
        $expired = $this->challenge->issue($user, 'sms_otp', '333333', 600);
        $this->db->update('auth_challenge', ['expires_at' => date('Y-m-d H:i:s', time() - 60)],
            $this->db->quoteInto('challenge_id = ?', $expired));
        $this->assertNull($this->challenge->latestActive($user, 'sms_otp'), 'an expired challenge is not active');

        // Consumed.
        $consumed = $this->challenge->issue($user, 'sms_otp', '444444', 600);
        $this->challenge->redeem($consumed, '444444');
        $this->assertNull($this->challenge->latestActive($user, 'sms_otp'), 'a consumed challenge is not active');
    }

    #[Test]
    public function invalidate_active_burns_outstanding_challenges_of_a_type(): void
    {
        $user = $this->makeUser();
        $this->challenge->issue($user, 'password_reset', '555555', 600);
        $this->challenge->issue($user, 'password_reset', '666666', 600);
        $this->challenge->issue($user, 'email_verify',   '777777', 600);   // a different type — untouched

        $n = $this->challenge->invalidateActive($user, 'password_reset');
        $this->assertSame(2, $n, 'both outstanding reset challenges were invalidated');

        $this->assertNull($this->challenge->latestActive($user, 'password_reset'), 'no reset challenge remains active');
        $this->assertNotNull($this->challenge->latestActive($user, 'email_verify'), 'the other type is left alone');
    }

    #[Test]
    public function count_recent_windows_the_send_rate_guard(): void
    {
        $user = $this->makeUser();

        $this->challenge->issue($user, 'sms_otp', '000001', 600);
        $this->challenge->issue($user, 'sms_otp', '000002', 600);
        $old = $this->challenge->issue($user, 'sms_otp', '000003', 600);
        // Back-date one outside the window.
        $this->db->update('auth_challenge', ['created_at' => date('Y-m-d H:i:s', time() - 4000)],
            $this->db->quoteInto('challenge_id = ?', $old));

        $this->assertSame(2, $this->challenge->countRecent($user, 'sms_otp', 3600), 'only the two inside the window count');
        $this->assertSame(0, $this->challenge->countRecent($user, 'email_verify', 3600), 'a different type counts zero');
    }

    #[Test]
    public function purge_expired_hard_deletes_only_expired_rows(): void
    {
        $user = $this->makeUser();
        $live    = $this->challenge->issue($user, 'magic_link', '121212', 600);
        $expired = $this->challenge->issue($user, 'magic_link', '343434', 600);
        $this->db->update('auth_challenge', ['expires_at' => date('Y-m-d H:i:s', time() - 60)],
            $this->db->quoteInto('challenge_id = ?', $expired));

        $removed = $this->challenge->purgeExpired();
        $this->assertGreaterThanOrEqual(1, $removed, 'the expired challenge was purged');

        // The live one is still present; the expired one is gone entirely (hard delete).
        $this->assertNotNull($this->challenge->findById($live));
        $gone = (int) $this->db->fetchOne($this->db->quoteInto('SELECT COUNT(*) FROM auth_challenge WHERE challenge_id = ?', $expired));
        $this->assertSame(0, $gone, 'the expired row was hard-deleted');
    }
}
