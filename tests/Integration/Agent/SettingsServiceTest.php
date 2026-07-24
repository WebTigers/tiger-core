<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use Agent_Service_Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Agent;
use Tiger_Crypto;
use Tiger_Model_Config;
use Zend_Config;
use Zend_Registry;

/**
 * Agent_Service_Settings — the admin `/api` service behind the TigerAgent settings screen (Wave 6).
 *
 * Covers the ACL gate (admin+ only — deny-by-default for guest/user), the validate→transaction save
 * into the eager `config` tier (the on/off switch, provider, model, mode ceiling), the provider +
 * mode-ceiling fallbacks, the BYO-key convention (a NEW key is encrypted and NEVER stored in plain;
 * a BLANK field preserves the current secret by writing no key row), the "encryption not configured"
 * guard, and `models()` (the keyless static fallback + the provider-name sanitize/fallback).
 *
 * The service opens its OWN `_transaction()`; the savepoint-aware harness adapter nests it inside the
 * per-test transaction, so writes roll back cleanly with no manual cleanup. CSRF is flagged stateless
 * (no session in CLI), exactly as a token API call does.
 */
#[CoversClass(Agent_Service_Settings::class)]
final class SettingsServiceTest extends IntegrationTestCase
{
    /** A valid 32-byte base64 crypto key for the tests that need Tiger_Crypto configured. */
    private const CRYPTO_KEY = 'MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=';

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);   // CSRF-immune API path (no session in CLI)
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    /** Put a Zend_Config in the registry (crypto optional) so Tiger_Crypto/Tiger_Agent read it. */
    private function seedConfig(bool $withCrypto): void
    {
        $tiger = [];
        if ($withCrypto) { $tiger['crypto'] = ['key' => self::CRYPTO_KEY]; }
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger], true));
    }

    /** Dispatch the service with an action + payload and hand back the response object. */
    private function call(string $action, array $params = []): object
    {
        return (new Agent_Service_Settings(['action' => $action] + $params))->getResponse();
    }

    /** Read a single global config value straight from the DB (bypassing the registry cascade). */
    private function dbConfig(string $key): ?string
    {
        $v = $this->db->fetchOne(
            "SELECT config_value FROM config WHERE scope = 'global' AND scope_id = '' AND config_key = ? AND deleted = 0",
            [$key]
        );
        return $v === false ? null : (string) $v;
    }

    // ----- ACL gate (deny-by-default) -------------------------------------------------------------

    #[Test]
    public function guest_and_plain_user_are_denied_both_actions(): void
    {
        foreach (['guest', 'user', 'manager'] as $role) {
            $this->loginAs($role);
            foreach (['save', 'models'] as $action) {
                $res = $this->call($action);
                $this->assertSame(0, (int) $res->result, "{$role} denied on {$action}");
                $this->assertStringContainsString('not_allowed', json_encode($res->messages), "{$role}/{$action} ACL denial");
            }
        }
    }

    // ----- save (validate -> transaction, config tier) --------------------------------------------

    #[Test]
    public function save_writes_the_switch_provider_model_and_mode_to_the_config_tier(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);

        $res = $this->call('save', [
            'enabled'  => '1',
            'provider' => 'openai',
            'model'    => '  gpt-4o  ',
            'mode_max' => 'yolo',
        ]);

        $this->assertSame(1, (int) $res->result, 'admin save succeeds');
        $this->assertSame('1',      $this->dbConfig(Tiger_Agent::CFG_ENABLED));
        $this->assertSame('openai', $this->dbConfig(Tiger_Agent::CFG_PROVIDER));
        $this->assertSame('gpt-4o', $this->dbConfig(Tiger_Agent::CFG_MODEL), 'model is trimmed');
        $this->assertSame('yolo',   $this->dbConfig(Tiger_Agent::CFG_MODE_MAX));
        $this->assertArrayHasKey('connected', (array) $res->data);
    }

    #[Test]
    public function save_disabled_stores_zero_for_the_switch(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);
        $res = $this->call('save', ['provider' => 'anthropic']);   // no `enabled` key → off
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('0', $this->dbConfig(Tiger_Agent::CFG_ENABLED));
    }

    #[Test]
    public function save_falls_back_to_anthropic_for_an_unknown_provider(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);
        $res = $this->call('save', ['provider' => 'no-such-provider', 'enabled' => '1']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('anthropic', $this->dbConfig(Tiger_Agent::CFG_PROVIDER), 'stale/unknown provider → anthropic');
    }

    #[Test]
    public function save_falls_back_to_auto_for_an_invalid_mode_ceiling(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);
        $res = $this->call('save', ['provider' => 'anthropic', 'mode_max' => 'ludicrous']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('auto', $this->dbConfig(Tiger_Agent::CFG_MODE_MAX), 'an unknown mode → auto');
    }

    // ----- the BYO-key convention -----------------------------------------------------------------

    #[Test]
    public function a_blank_key_field_writes_no_key_row_preserving_the_stored_secret(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(true);
        $res = $this->call('save', ['provider' => 'anthropic', 'api_key' => '   ']);   // blank after trim
        $this->assertSame(1, (int) $res->result);
        $this->assertNull($this->dbConfig(Tiger_Agent::CFG_KEY_ENC), 'no key row written when the field is blank');
    }

    #[Test]
    public function a_new_key_is_encrypted_at_rest_never_stored_in_plaintext(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(true);

        $secret = 'sk-super-secret-byo-key';
        $res = $this->call('save', ['provider' => 'anthropic', 'api_key' => $secret]);
        $this->assertSame(1, (int) $res->result);

        $blob = $this->dbConfig(Tiger_Agent::CFG_KEY_ENC);
        $this->assertNotNull($blob, 'a key row was written');
        $this->assertStringNotContainsString($secret, $blob, 'the plaintext key never touches the DB');
        $this->assertSame($secret, Tiger_Crypto::decrypt($blob), 'and it round-trips back through Tiger_Crypto');
    }

    #[Test]
    public function saving_a_key_without_encryption_configured_is_a_clean_error(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);   // no tiger.crypto.key → Tiger_Crypto::isConfigured() is false
        $res = $this->call('save', ['provider' => 'anthropic', 'api_key' => 'sk-cannot-store']);

        $this->assertSame(0, (int) $res->result, 'the save refuses to store a key it cannot encrypt');
        $this->assertNull($this->dbConfig(Tiger_Agent::CFG_KEY_ENC), 'and no key row was written');
    }

    // ----- models() -------------------------------------------------------------------------------

    #[Test]
    public function models_returns_the_keyless_static_fallback_for_a_provider(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);   // no stored key → apiKey() is '' → the static fallback (no network)

        $res = $this->call('models', ['provider' => 'anthropic']);
        $this->assertSame(1, (int) $res->result);
        $this->assertFalse($res->data['live'], 'no key → not a live listing');
        $this->assertNotEmpty($res->data['models']);
        $this->assertArrayHasKey('id', $res->data['models'][0]);
        $this->assertArrayHasKey('label', $res->data['models'][0]);
    }

    #[Test]
    public function models_sanitizes_and_falls_back_for_an_unknown_provider(): void
    {
        $this->loginAs('admin');
        $this->seedConfig(false);
        // Digits/junk are stripped and an unknown key falls back to anthropic — never a hard failure.
        $res = $this->call('models', ['provider' => 'Op3nAI!!']);
        $this->assertSame(1, (int) $res->result);
        $this->assertNotEmpty($res->data['models']);
    }
}
