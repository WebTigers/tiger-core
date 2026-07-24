<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Location;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto;
use Tiger_Location;
use Tiger_Location_Adapter_Interface;
use Tiger_Location_Place;
use Tiger_Test_LocationAdapter;

require_once __DIR__ . '/../../Support/Fixtures/LocationDoubles.php';

/**
 * Tiger_Location — the provider-agnostic Location facade.
 *
 * The behaviors that matter are all about ROUTING + GRACEFUL DEGRADATION, not network: the facade
 * picks the configured provider for an operation group (address vs ip), only calls an op on an adapter
 * that declares the capability, and turns any unconfigured/incapable/throwing provider into empty
 * results ([] or null) so a form quietly does nothing rather than fataling. We drive it entirely with a
 * registered test-double adapter (Tiger_Test_LocationAdapter) — no HTTP. The static adapter registry is
 * snapshotted + restored per test so registrations never leak.
 */
#[CoversClass(Tiger_Location::class)]
final class FacadeTest extends UnitTestCase
{
    /** @var array<string,string> the built-in registry, restored after each test */
    private array $registrySnapshot;

    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Test_LocationAdapter::reset();
        $prop = new ReflectionProperty(Tiger_Location::class, '_adapters');
        $this->registrySnapshot = $prop->getValue();
    }

    protected function tearDown(): void
    {
        $prop = new ReflectionProperty(Tiger_Location::class, '_adapters');
        $prop->setValue(null, $this->registrySnapshot);
        Tiger_Test_LocationAdapter::reset();
        parent::tearDown();
    }

    /** Seed config selecting the test double for both operation groups, plus its adapter config. */
    private function seedTestProvider(array $adapterCfg = []): void
    {
        Tiger_Location::register('test', Tiger_Test_LocationAdapter::class);
        $this->setConfig(['tiger' => ['location' => [
            'address'  => ['provider' => 'test'],
            'ip'       => ['provider' => 'test'],
            'adapters' => ['test' => $adapterCfg],
            'cache_ttl' => 0,   // keep the IP cache out of the way
        ]]]);
    }

    // -- registration ------------------------------------------------------------------------------

    #[Test]
    public function register_is_case_insensitive_on_the_provider_name(): void
    {
        Tiger_Location::register('MyProv', Tiger_Test_LocationAdapter::class);
        $prop = new ReflectionProperty(Tiger_Location::class, '_adapters');
        $this->assertArrayHasKey('myprov', $prop->getValue(), 'the name is lower-cased on register');
    }

    // -- suggest -----------------------------------------------------------------------------------

    #[Test]
    public function suggest_returns_empty_for_a_blank_query_without_touching_a_provider(): void
    {
        // no config at all — a blank query must short-circuit before any provider lookup.
        $this->assertSame([], (new Tiger_Location())->suggest('   '));
    }

    #[Test]
    public function suggest_returns_empty_when_no_address_provider_is_configured(): void
    {
        $this->setConfig(['tiger' => ['location' => []]]);
        $this->assertSame([], (new Tiger_Location())->suggest('1 Infinite Loop'));
    }

    #[Test]
    public function suggest_routes_to_the_configured_capable_adapter(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$suggestReturn = [new Tiger_Location_Place(['label' => 'A']), new Tiger_Location_Place(['label' => 'B'])];

        $out = (new Tiger_Location())->suggest('Main St');
        $this->assertCount(2, $out);
        $this->assertSame('A', $out[0]->label);
    }

    #[Test]
    public function suggest_returns_empty_when_the_adapter_lacks_the_capability(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$caps = ['ip'];   // does not support suggest
        Tiger_Test_LocationAdapter::$suggestReturn = [new Tiger_Location_Place(['label' => 'nope'])];

        $this->assertSame([], (new Tiger_Location())->suggest('Main St'));
    }

    #[Test]
    public function suggest_swallows_a_provider_error_into_an_empty_list(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$throw = true;
        $this->assertSame([], (new Tiger_Location())->suggest('Main St'));
    }

    // -- geocode -----------------------------------------------------------------------------------

    #[Test]
    public function geocode_routes_and_degrades_gracefully(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$geocodeReturn = [new Tiger_Location_Place(['city' => 'Reno'])];
        $this->assertSame('Reno', (new Tiger_Location())->geocode('Reno NV')[0]->city);

        Tiger_Test_LocationAdapter::$throw = true;
        $this->assertSame([], (new Tiger_Location())->geocode('Reno NV'));

        $this->assertSame([], (new Tiger_Location())->geocode('  '), 'blank short-circuits');
    }

    // -- reverse -----------------------------------------------------------------------------------

    #[Test]
    public function reverse_returns_the_place_or_null_on_error_or_unconfigured(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$reverseReturn = new Tiger_Location_Place(['city' => 'Oslo']);
        $this->assertSame('Oslo', (new Tiger_Location())->reverse(59.91, 10.75)->city);

        Tiger_Test_LocationAdapter::$throw = true;
        $this->assertNull((new Tiger_Location())->reverse(59.91, 10.75));

        $this->setConfig(['tiger' => ['location' => []]]);
        $this->assertNull((new Tiger_Location())->reverse(59.91, 10.75), 'no provider => null');
    }

    // -- ip ----------------------------------------------------------------------------------------

    #[Test]
    public function ip_rejects_a_blank_or_invalid_address_before_any_provider(): void
    {
        $svc = new Tiger_Location();
        $this->assertNull($svc->ip('   '));
        $this->assertNull($svc->ip('not-an-ip'));
        $this->assertNull($svc->ip('999.999.1.1'));
    }

    #[Test]
    public function ip_routes_a_valid_address_to_the_configured_provider(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$ipReturn = new Tiger_Location_Place(['country' => 'US', 'ip' => '8.8.8.8']);
        $place = (new Tiger_Location())->ip('8.8.8.8');
        $this->assertNotNull($place);
        $this->assertSame('US', $place->country);
    }

    #[Test]
    public function ip_returns_null_when_the_provider_throws(): void
    {
        $this->seedTestProvider();
        Tiger_Test_LocationAdapter::$throw = true;
        $this->assertNull((new Tiger_Location())->ip('8.8.8.8'));
    }

    // -- adapter resolution edge cases -------------------------------------------------------------

    #[Test]
    public function an_unknown_provider_name_resolves_to_no_adapter(): void
    {
        $this->setConfig(['tiger' => ['location' => ['address' => ['provider' => 'ghost']]]]);
        $this->assertSame([], (new Tiger_Location())->suggest('x'));
    }

    #[Test]
    public function a_registered_class_that_is_not_an_adapter_resolves_to_null(): void
    {
        Tiger_Location::register('bogus', 'Tiger_Test_NotAnAdapter');
        $this->setConfig(['tiger' => ['location' => ['address' => ['provider' => 'bogus']]]]);
        $this->assertSame([], (new Tiger_Location())->suggest('x'));
    }

    #[Test]
    public function the_adapter_instance_is_reused_within_one_facade(): void
    {
        $this->seedTestProvider();
        $svc = new Tiger_Location();
        Tiger_Test_LocationAdapter::$geocodeReturn = [new Tiger_Location_Place(['city' => 'A'])];
        $svc->geocode('q');
        // A second op on the same facade must not construct a new instance (cached in $_instances).
        $prop = new ReflectionProperty(Tiger_Location::class, '_instances');
        $this->assertArrayHasKey('test', $prop->getValue($svc));
    }

    // -- decrypt-secrets seam (adapter config) -----------------------------------------------------

    #[Test]
    public function encrypted_adapter_secrets_are_decrypted_before_reaching_the_adapter(): void
    {
        // seed a crypto key so Tiger_Crypto::isConfigured() is true, then hand the adapter an
        // encrypted `key_enc`; the facade must decrypt it to a plaintext `key` for the adapter.
        Tiger_Location::register('test', Tiger_Test_LocationAdapter::class);
        $key = base64_encode(str_repeat("\x11", 32));
        $this->setConfig(['tiger' => ['crypto' => ['key' => $key]]]);   // register the key first…
        $enc = Tiger_Crypto::encrypt('s3cr3t');                          // …so encrypt() can read it
        $this->setConfig(['tiger' => [
            'crypto'   => ['key' => $key],
            'location' => [
                'address'  => ['provider' => 'test'],
                'adapters' => ['test' => ['key_enc' => $enc]],
            ],
        ]]);

        Tiger_Test_LocationAdapter::$geocodeReturn = [];
        (new Tiger_Location())->geocode('q');   // forces construction

        $this->assertSame('s3cr3t', Tiger_Test_LocationAdapter::$seenConfig['key'] ?? null,
            'the _enc secret is decrypted to its plaintext field for the adapter');
    }

    // -- admin surface: adapters() -----------------------------------------------------------------

    #[Test]
    public function adapters_lists_the_registered_built_ins_with_their_structure(): void
    {
        $this->setConfig(['tiger' => ['location' => []]]);
        $all = Tiger_Location::adapters();

        $this->assertArrayHasKey('nominatim', $all);
        $this->assertArrayHasKey('aws', $all);
        $this->assertArrayHasKey('ipapi', $all);
        $this->assertSame('Nominatim (OpenStreetMap)', $all['nominatim']['label']);
        $this->assertContains(Tiger_Location_Adapter_Interface::CAP_SUGGEST, $all['nominatim']['caps']);
        $this->assertIsArray($all['nominatim']['fields']);
    }

    #[Test]
    public function adapters_filters_to_a_single_capability(): void
    {
        $this->setConfig(['tiger' => ['location' => []]]);
        $ipCapable = Tiger_Location::adapters(Tiger_Location_Adapter_Interface::CAP_IP);

        $this->assertArrayHasKey('ipapi', $ipCapable, 'ipapi is the IP provider');
        $this->assertArrayNotHasKey('nominatim', $ipCapable, 'nominatim does not do IP');
    }

    // -- admin surface: settings() -----------------------------------------------------------------

    #[Test]
    public function settings_reports_provider_defaults_and_masks_secret_fields(): void
    {
        $this->setConfig(['tiger' => ['location' => [
            'adapters' => ['aws' => ['region' => 'us-east-1', 'secret' => 'shh']],
        ]]]);
        $s = Tiger_Location::settings();

        $this->assertSame('ipapi', $s['ip_provider']);
        $this->assertSame('nominatim', $s['address_provider']);
        $this->assertSame(86400, $s['cache_ttl']);
        // a text field surfaces its value; a secret field surfaces only a `has` flag (never the secret).
        $this->assertSame('us-east-1', $s['values']['aws']['region']['value']);
        $this->assertTrue($s['values']['aws']['secret']['has']);
        $this->assertArrayNotHasKey('value', $s['values']['aws']['secret']);
    }

    // -- admin surface: test() ---------------------------------------------------------------------

    #[Test]
    public function test_rejects_an_invalid_ip(): void
    {
        $r = Tiger_Location::test('nope', 'ipapi');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('valid IP', $r['error']);
    }

    #[Test]
    public function test_reports_an_unknown_provider(): void
    {
        $r = Tiger_Location::test('8.8.8.8', 'does-not-exist');
        $this->assertFalse($r['ok']);
        $this->assertSame('Unknown provider.', $r['error']);
    }

    #[Test]
    public function test_reports_a_provider_that_cannot_do_ip(): void
    {
        Tiger_Location::register('test', Tiger_Test_LocationAdapter::class);
        Tiger_Test_LocationAdapter::$caps = ['suggest'];   // not CAP_IP
        $this->setConfig(['tiger' => ['location' => ['adapters' => ['test' => []]]]]);

        $r = Tiger_Location::test('8.8.8.8', 'test');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('does not support IP', $r['error']);
    }

    #[Test]
    public function test_returns_the_place_fields_on_a_successful_lookup(): void
    {
        Tiger_Location::register('test', Tiger_Test_LocationAdapter::class);
        Tiger_Test_LocationAdapter::$ipReturn = new Tiger_Location_Place(['country' => 'US', 'city' => 'Reno', 'label' => 'Reno, US']);
        $this->setConfig(['tiger' => ['location' => ['adapters' => ['test' => []]]]]);

        $r = Tiger_Location::test('8.8.8.8', 'test', ['endpoint' => 'http://example/']);
        $this->assertTrue($r['ok']);
        $this->assertSame('US', $r['country']);
        $this->assertSame('Reno', $r['city']);
        // a non-empty form override is threaded into the adapter's config.
        $this->assertSame('http://example/', Tiger_Test_LocationAdapter::$seenConfig['endpoint']);
    }

    #[Test]
    public function test_surfaces_a_provider_exception_as_an_error(): void
    {
        Tiger_Location::register('test', Tiger_Test_LocationAdapter::class);
        Tiger_Test_LocationAdapter::$throw = true;
        $this->setConfig(['tiger' => ['location' => ['adapters' => ['test' => []]]]]);

        $r = Tiger_Location::test('8.8.8.8', 'test');
        $this->assertFalse($r['ok']);
        $this->assertSame('boom', $r['error']);
    }

    #[Test]
    public function test_reports_no_result_when_the_provider_places_nothing(): void
    {
        Tiger_Location::register('test', Tiger_Test_LocationAdapter::class);
        Tiger_Test_LocationAdapter::$ipReturn = null;
        $this->setConfig(['tiger' => ['location' => ['adapters' => ['test' => []]]]]);

        $r = Tiger_Location::test('8.8.8.8', 'test');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No result', $r['error']);
    }
}
