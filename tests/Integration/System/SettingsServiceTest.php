<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Settings;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Config;
use Zend_Registry;

// `System_Service_Settings` (+ its Form) resolve via the harness module autoloader (tests/bootstrap.php).

/**
 * System_Service_Settings — the /api behind the core Settings screen (session lifetime + auto-logout +
 * reCAPTCHA), admin+ per modules/system/configs/acl.ini. The happy path validates System_Form_Settings
 * then writes to the `config` DB tier (scope=global) — the live-override pattern, no deploy. These
 * tests characterize: the ACL gate (guest denied), a form-validation refusal that writes NOTHING, and a
 * valid save landing every value in the config table (session TTL floored, auto-logout toggle/window/
 * action, reCAPTCHA fields; a blank secret is left untouched).
 *
 * `/api` calls carry a CSRF token a CLI test doesn't have; we set the documented `tiger.auth.stateless`
 * flag (Tiger_Form skips CSRF for stateless bearer callers) so the form's REAL field validators run —
 * exactly as the Signup service tests do.
 */
#[CoversClass(System_Service_Settings::class)]
final class SettingsServiceTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Zend_Registry::set('tiger.auth.stateless', false);
        parent::tearDown();
    }

    private function dispatch(array $msg): object
    {
        Zend_Registry::set('tiger.auth.stateless', true);
        return (new System_Service_Settings($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    /** A complete, valid settings payload; $over replaces any field. */
    private function validParams(array $over = []): array
    {
        return array_merge([
            'action'             => 'save',
            'session_ttl'        => '3600',
            'autologout_enabled' => '1',
            'autologout_seconds' => '900',
            'autologout_action'  => 'lock',
        ], $over);
    }

    // ---- ACL -------------------------------------------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_settings_to_admin_and_up(): void
    {
        $this->loginAs('admin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('System_Service_Settings'), 'the acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('admin', 'System_Service_Settings'));
        $this->assertFalse($acl->isAllowed('user', 'System_Service_Settings'), 'a plain user is denied');
        $this->assertFalse($acl->isAllowed('guest', 'System_Service_Settings'));
    }

    #[Test]
    public function a_guest_is_denied_saving_settings(): void
    {
        $this->login('anon', 'o-1', 'guest');
        $res = $this->dispatch($this->validParams());

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired');
    }

    // ---- validation refuses + writes nothing -----------------------------------------------------

    #[Test]
    public function an_invalid_form_returns_field_errors_and_writes_no_config(): void
    {
        $this->loginAs('admin');
        // session_ttl below the floor (>59) and a non-numeric autologout window both fail validation.
        $res = $this->dispatch($this->validParams(['session_ttl' => '10', 'autologout_seconds' => 'abc']));

        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form, 'field errors are returned');
        $this->assertStringContainsString('core.api.error.form', $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $this->assertNull($cfg->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.session.ttl.authed'), 'nothing was written');
    }

    // ---- the happy path lands every value in the config tier -------------------------------------

    #[Test]
    public function a_valid_save_writes_session_and_autologout_values_to_the_config_table(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch($this->validParams());

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertSame('/system/settings', $res->redirect, 'the success redirect is returned');

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $this->assertSame('3600', $cfg->get($g, '', 'tiger.session.ttl.authed'));
        $this->assertSame('1', $cfg->get($g, '', 'tiger.session.autologout.enabled'));
        $this->assertSame('900', $cfg->get($g, '', 'tiger.session.autologout.seconds'));
        $this->assertSame('lock', $cfg->get($g, '', 'tiger.session.autologout.action'));
    }

    #[Test]
    public function the_session_ttl_is_floored_and_the_autologout_action_normalizes(): void
    {
        $this->loginAs('admin');
        // session_ttl '60' is the minimum the form accepts (>59); the service floors at max(60, ttl).
        // An unrecognized action string normalizes to 'logout' (only 'lock' is special-cased).
        $res = $this->dispatch($this->validParams([
            'session_ttl' => '60', 'autologout_enabled' => '0', 'autologout_action' => 'logout',
        ]));
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $this->assertSame('60', $cfg->get($g, '', 'tiger.session.ttl.authed'));
        $this->assertSame('0', $cfg->get($g, '', 'tiger.session.autologout.enabled'), 'the toggle stored off');
        $this->assertSame('logout', $cfg->get($g, '', 'tiger.session.autologout.action'));
    }

    #[Test]
    public function a_valid_save_also_writes_the_recaptcha_settings(): void
    {
        $this->loginAs('admin');
        // A blank secret leaves the stored secret untouched (no crypto needed) — the documented behavior.
        $res = $this->dispatch($this->validParams([
            'recaptcha_enabled'    => '1',
            'recaptcha_version'    => 'v3',
            'recaptcha_site_key'   => 'test-site-key',
            'recaptcha_secret_key' => '',
            'recaptcha_min_score'  => '0.7',
            'recaptcha_fail_open'  => '1',
        ]));
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $this->assertSame('1', $cfg->get($g, '', 'tiger.recaptcha.enabled'));
        $this->assertSame('v3', $cfg->get($g, '', 'tiger.recaptcha.version'));
        $this->assertSame('test-site-key', $cfg->get($g, '', 'tiger.recaptcha.site_key'));
        $this->assertSame('0.7', $cfg->get($g, '', 'tiger.recaptcha.min_score'));
        $this->assertNull($cfg->get($g, '', 'tiger.recaptcha.secret_key'), 'a blank secret writes nothing');
    }

    #[Test]
    public function a_valid_save_also_writes_the_consent_location_and_signup_tabs(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch($this->validParams([
            // Cookies (GDPR consent) tab — rides on $params via the Tiger_Consent shared writer.
            'consent_mode'         => 'auto',
            'consent_message'      => 'We use cookies.',
            'consent_accept_label' => 'OK',
            // Location tab — provider selection (the adapter fields ride on $params too).
            'location_ip_provider'      => 'nominatim',
            'location_address_provider' => 'nominatim',
            'location_cache_ttl'        => '3600',
            // Signup tab — the public-signup kill switch (lands in the lazy `option` tier).
            'signup_settings' => '1',
            'signup_disabled' => '1',
        ]));
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        $this->assertSame('auto', $cfg->get($g, '', 'tiger.consent.mode'));
        $this->assertSame('nominatim', $cfg->get($g, '', 'tiger.location.ip.provider'));

        $opt = new \Tiger_Model_Option();
        $this->assertSame('1', $opt->get(\Tiger_Model_Option::SCOPE_GLOBAL, '', 'signup.public_disabled'), 'the signup kill switch persisted');
    }

    // ---- locationTest ----------------------------------------------------------------------------

    #[Test]
    public function location_test_is_denied_to_a_guest(): void
    {
        $this->login('anon', 'o-1', 'guest');
        $res = $this->dispatch(['action' => 'locationTest', 'ip' => '8.8.8.8', 'provider' => 'nominatim']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }

    #[Test]
    public function location_test_returns_a_result_for_an_admin(): void
    {
        $this->loginAs('admin');
        // An invalid IP short-circuits inside Tiger_Location::test (no network) → a clean ok=false result,
        // exercising the admin path + the /api envelope without any live provider call.
        $res = $this->dispatch(['action' => 'locationTest', 'ip' => 'not-an-ip', 'provider' => 'nominatim']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertFalse($res->data['ok'], 'an invalid IP is reported, not looked up');
        $this->assertArrayHasKey('error', $res->data);
    }
}
