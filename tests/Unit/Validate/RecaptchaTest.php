<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Validate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Validate_Recaptcha;
use Zend_Config;

/**
 * Tiger_Validate_Recaptcha — the server-side reCAPTCHA validator attachable to any element.
 *
 * The deterministic, network-free contract: when reCAPTCHA is DISABLED it passes through (so dev forms
 * validate with no keys); when ENABLED with a missing token it fails with the "missing" message; and
 * when a token is present but the install has no secret configured, Tiger_Recaptcha::verify() returns a
 * definitive `success:false` WITHOUT any HTTP (the empty-secret short-circuit), so the validator fails
 * closed. The token is read from the fixed `g-recaptcha-response` context field, falling back to the
 * element value. The v3 score/action gate and the fail-open-on-outage policy depend on a live Google
 * siteverify response and are the HTTP boundary — not unit-tested here (see WAVE5-FINDINGS-geo.md).
 */
#[CoversClass(Tiger_Validate_Recaptcha::class)]
final class RecaptchaTest extends UnitTestCase
{
    /** Turn reCAPTCHA on, with a public site key but no secret (so verify() short-circuits, no HTTP). */
    private function enableKeyless(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => [
            'enabled'  => 1,
            'version'  => 'v2',
            'site_key' => 'PUBLIC-SITE-KEY',
            // no secret_key — verify() returns success:false without reaching Google
        ]]]);
    }

    #[Test]
    public function it_passes_through_when_recaptcha_is_disabled(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => ['enabled' => 0]]]);
        $v = new Tiger_Validate_Recaptcha();
        $this->assertTrue($v->isValid('', []), 'feature off => always valid');
        $this->assertSame([], $v->getMessages());
    }

    #[Test]
    public function it_passes_through_when_config_is_entirely_absent(): void
    {
        $this->setConfig(['tiger' => []]);   // no recaptcha node at all
        $this->assertTrue((new Tiger_Validate_Recaptcha())->isValid('', []));
    }

    #[Test]
    public function an_enabled_but_tokenless_submission_fails_missing(): void
    {
        $this->enableKeyless();
        $v = new Tiger_Validate_Recaptcha();

        $this->assertFalse($v->isValid('', []), 'no token anywhere => invalid');
        $this->assertArrayHasKey(Tiger_Validate_Recaptcha::MISSING, $v->getMessages());
    }

    #[Test]
    public function a_token_with_no_configured_secret_fails_closed(): void
    {
        // Token present, but the install has no secret — Tiger_Recaptcha::verify short-circuits to
        // success:false (no HTTP), so the validator fails with the "failed" message.
        $this->enableKeyless();
        $v = new Tiger_Validate_Recaptcha();

        $this->assertFalse($v->isValid('', ['g-recaptcha-response' => 'tok-from-widget']));
        $this->assertArrayHasKey(Tiger_Validate_Recaptcha::FAILED, $v->getMessages());
    }

    #[Test]
    public function the_token_is_read_from_the_element_value_when_the_context_lacks_it(): void
    {
        $this->enableKeyless();
        $v = new Tiger_Validate_Recaptcha();

        // context carries no g-recaptcha-response, so the element value is used as the token — which
        // means we reach verify() and fail CLOSED (FAILED), rather than the tokenless MISSING path.
        $this->assertFalse($v->isValid('value-token', null));
        $this->assertArrayHasKey(Tiger_Validate_Recaptcha::FAILED, $v->getMessages());
        $this->assertArrayNotHasKey(Tiger_Validate_Recaptcha::MISSING, $v->getMessages());
    }

    #[Test]
    public function the_constructor_accepts_an_expected_v3_action_as_an_array_or_config(): void
    {
        // Constructing with an action must not error and must not change the keyless behavior below.
        $fromArray  = new Tiger_Validate_Recaptcha(['action' => 'login']);
        $fromConfig = new Tiger_Validate_Recaptcha(new Zend_Config(['action' => 'signup']));
        $none       = new Tiger_Validate_Recaptcha(null);

        $this->setConfig(['tiger' => ['recaptcha' => ['enabled' => 0]]]);
        $this->assertTrue($fromArray->isValid('', []));
        $this->assertTrue($fromConfig->isValid('', []));
        $this->assertTrue($none->isValid('', []));
    }
}
