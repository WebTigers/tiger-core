<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto;
use Tiger_Recaptcha;

/**
 * Tiger_Recaptcha — the config + verify hub shared by the form control and the validator.
 *
 * All the config accessors are pure reads over the cascade (enabled / version / min_score / fail_open /
 * hide_badge / site + secret key), and verify() short-circuits WITHOUT any HTTP whenever the secret or
 * the token is empty — that's the whole network-free surface, tested here. The secret is returned
 * plaintext from config, or decrypted from `secret_key_enc` when Tiger_Crypto is configured, and is
 * NEVER echoed by settings() (only a has_secret flag). The live siteverify POST (verify() with a real
 * secret + token) is the HTTP boundary and is not unit-tested.
 */
#[CoversClass(Tiger_Recaptcha::class)]
final class RecaptchaHubTest extends UnitTestCase
{
    #[Test]
    public function config_accessors_report_their_defaults_when_unset(): void
    {
        $this->setConfig(['tiger' => []]);
        $this->assertFalse(Tiger_Recaptcha::isEnabled());
        $this->assertSame('', Tiger_Recaptcha::siteKey());
        $this->assertSame('v2', Tiger_Recaptcha::version());
        $this->assertSame(0.5, Tiger_Recaptcha::minScore());
        $this->assertTrue(Tiger_Recaptcha::failOpen(), 'fail-open defaults on (availability)');
        $this->assertFalse(Tiger_Recaptcha::hideBadge());
    }

    #[Test]
    public function config_accessors_reflect_the_cascade(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => [
            'enabled'   => 1,
            'version'   => 'v3',
            'site_key'  => 'SITE',
            'min_score' => 0.7,
            'fail_open' => 0,
            'hide_badge' => 1,
        ]]]);

        $this->assertTrue(Tiger_Recaptcha::isEnabled());
        $this->assertSame('v3', Tiger_Recaptcha::version());
        $this->assertSame('SITE', Tiger_Recaptcha::siteKey());
        $this->assertSame(0.7, Tiger_Recaptcha::minScore());
        $this->assertFalse(Tiger_Recaptcha::failOpen());
        $this->assertTrue(Tiger_Recaptcha::hideBadge());
    }

    #[Test]
    public function an_unknown_version_normalizes_to_v2(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => ['version' => 'v9']]]);
        $this->assertSame('v2', Tiger_Recaptcha::version());
    }

    #[Test]
    public function the_secret_key_is_read_plaintext_from_config(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => ['secret_key' => 'PLAIN-SECRET']]]);
        $this->assertSame('PLAIN-SECRET', Tiger_Recaptcha::secretKey());
    }

    #[Test]
    public function the_secret_key_is_decrypted_from_an_encrypted_config_value(): void
    {
        $key = base64_encode(str_repeat("\x11", 32));
        $this->setConfig(['tiger' => ['crypto' => ['key' => $key]]]);   // register the key first…
        $enc = Tiger_Crypto::encrypt('ENC-SECRET');                      // …then encrypt
        $this->setConfig(['tiger' => [
            'crypto'    => ['key' => $key],
            'recaptcha' => ['secret_key_enc' => $enc],
        ]]);

        $this->assertSame('ENC-SECRET', Tiger_Recaptcha::secretKey());
    }

    #[Test]
    public function the_secret_key_is_empty_when_nothing_is_configured(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => []]]);
        $this->assertSame('', Tiger_Recaptcha::secretKey());
    }

    #[Test]
    public function verify_short_circuits_without_http_when_secret_or_token_is_empty(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => []]]);   // no secret
        $this->assertSame(['success' => false], Tiger_Recaptcha::verify('any-token'));

        $this->setConfig(['tiger' => ['recaptcha' => ['secret_key' => 'S']]]);
        $this->assertSame(['success' => false], Tiger_Recaptcha::verify(''), 'empty token => no HTTP');
    }

    #[Test]
    public function the_badge_css_and_legal_notice_only_render_for_v3_with_hide_badge(): void
    {
        // off by default
        $this->setConfig(['tiger' => ['recaptcha' => ['version' => 'v3']]]);
        $this->assertSame('', Tiger_Recaptcha::badgeCss(), 'no hide_badge => nothing');
        $this->assertSame('', Tiger_Recaptcha::legalNotice());

        // hide_badge but v2 => nothing (v2 has no floating badge)
        $this->setConfig(['tiger' => ['recaptcha' => ['version' => 'v2', 'hide_badge' => 1]]]);
        $this->assertSame('', Tiger_Recaptcha::badgeCss());

        // v3 + hide_badge => both render
        $this->setConfig(['tiger' => ['recaptcha' => ['version' => 'v3', 'hide_badge' => 1]]]);
        $this->assertStringContainsString('grecaptcha-badge', Tiger_Recaptcha::badgeCss());
        $this->assertStringContainsString('grecaptcha-terms', Tiger_Recaptcha::legalNotice());
    }

    #[Test]
    public function settings_exposes_a_has_secret_flag_but_never_the_secret(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => [
            'enabled' => 1, 'version' => 'v2', 'site_key' => 'SITE', 'secret_key' => 'SUPER-SECRET',
        ]]]);
        $s = Tiger_Recaptcha::settings();

        $this->assertTrue($s['has_secret']);
        $this->assertSame('SITE', $s['site_key']);
        $this->assertNotContains('SUPER-SECRET', $s, 'the secret is never returned');
    }
}
