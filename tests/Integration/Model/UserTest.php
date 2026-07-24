<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Tiger_Uuid;

/**
 * Tiger_Model_User — the thin identity row and, security-critically, `findByIdentifier()`: the ONE
 * entry point every login flow uses to turn a claimed identifier into a user. The load-bearing rule
 * is that only a VERIFIED factor resolves identity — an unconfirmed phone/oauth/passkey identifier
 * must never log anyone in — alongside email/username uniqueness (`isTaken`), the guard a signup or
 * profile edit leans on.
 */
#[CoversClass(Tiger_Model_User::class)]
final class UserTest extends IntegrationTestCase
{
    private Tiger_Model_User $user;
    private Tiger_Model_UserCredential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new Tiger_Model_User();
        $this->cred = new Tiger_Model_UserCredential();
    }

    private function makeUser(string $email, ?string $username = null): string
    {
        $data = ['email' => $email];
        if ($username !== null) {
            $data['username'] = $username;
        }
        return $this->user->insert($data);
    }

    #[Test]
    public function email_and_username_resolve_identity_directly(): void
    {
        $tag  = substr(Tiger_Uuid::v7(), 0, 12);
        $id   = $this->makeUser("who-$tag@example.test", "who-$tag");

        $this->assertSame($id, $this->user->findByIdentifier("who-$tag@example.test")->user_id, 'email resolves');
        $this->assertSame($id, $this->user->findByIdentifier("who-$tag")->user_id, 'username resolves');
        $this->assertNull($this->user->findByIdentifier('nobody-here@example.test'), 'an unknown identifier resolves to nobody');
        $this->assertNull($this->user->findByIdentifier('   '), 'a blank identifier resolves to nobody');
    }

    #[Test]
    public function only_a_verified_factor_identifier_resolves_to_the_user(): void
    {
        $id    = $this->makeUser('phoneuser-' . substr(Tiger_Uuid::v7(), 0, 12) . '@example.test');
        $phone = '+1555' . substr((string) Tiger_Uuid::v7(), 0, 7);

        // An UNVERIFIED sms factor (verified_at NULL) must not resolve identity.
        $credId = $this->cred->addSms($id, $phone);
        $this->assertNull($this->user->findByIdentifier($phone), 'a pending/unverified factor does NOT log you in');

        // Once confirmed, the same identifier resolves to its owner (login-by-phone).
        $this->cred->markVerified($credId);
        $this->assertSame($id, $this->user->findByIdentifier($phone)->user_id, 'a verified factor identifier resolves');
    }

    #[Test]
    public function is_taken_enforces_email_and_username_uniqueness(): void
    {
        $tag = substr(Tiger_Uuid::v7(), 0, 12);
        $id  = $this->makeUser("taken-$tag@example.test", "taken-$tag");

        $this->assertTrue($this->user->isTaken('email', "taken-$tag@example.test"), 'the email is taken');
        $this->assertTrue($this->user->isTaken('username', "taken-$tag"), 'the username is taken');
        $this->assertFalse($this->user->isTaken('email', "free-$tag@example.test"), 'a fresh email is free');

        // The owner excluding itself is NOT a collision (the profile-edit case).
        $this->assertFalse($this->user->isTaken('email', "taken-$tag@example.test", $id), 'excluding the owner clears the collision');
        $this->assertFalse($this->user->isTaken('nonsense', 'x'), 'an unknown column is never "taken"');
    }
}
