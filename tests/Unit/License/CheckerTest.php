<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto_Signature;
use Tiger_License_Checker;
use Tiger_License_Store;

/**
 * Tiger_License_Checker — the client-side gate. Driven with an in-memory store + a fake authority
 * transport, so the whole decision logic is exercised with no DB and no network. The load-bearing
 * behaviors: a signed valid verdict caches and permits updates; a signed lapsed verdict withholds the
 * update; an unreachable OR forged authority is `unknown` (assume-current, never nags); and a fresh
 * cache is served without re-asking.
 */
#[CoversClass(Tiger_License_Checker::class)]
final class CheckerTest extends UnitTestCase
{
    private ArrayLicenseStore $store;
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        Tiger_License_Checker::_reset();
        $this->store = new ArrayLicenseStore();
        Tiger_License_Checker::setStore($this->store);
        $this->keys = Tiger_Crypto_Signature::generateKeypair();
    }

    protected function tearDown(): void
    {
        Tiger_License_Checker::_reset();
        parent::tearDown();
    }

    /** A fake authority that signs `$data` with the test keypair (what the real Authority does). */
    private function respondsSigned(array $data): void
    {
        $keys = $this->keys;
        Tiger_License_Checker::setTransport(static function ($authority, $payload) use ($data, $keys) {
            $json = json_encode($data);
            return ['payload' => $json, 'signature' => Tiger_Crypto_Signature::sign($json, $keys['secret_key'])];
        });
    }

    private function license(array $over = []): array
    {
        return array_merge([
            'key'        => 'KEY-123',
            'authority'  => 'https://store.example/marketplace',
            'vendor'     => 'Acme/TigerVendor',
            'public_key' => $this->keys['public_key'],
        ], $over);
    }

    #[Test]
    public function noLicenseIsUnlicensedAndUngated(): void
    {
        $v = Tiger_License_Checker::verify('shop');
        $this->assertSame(Tiger_License_Checker::UNLICENSED, $v['state']);
        $this->assertTrue($v['can_update']);
        $this->assertTrue(Tiger_License_Checker::canUpdate('shop'));
    }

    #[Test]
    public function rememberGetForgetRoundTrip(): void
    {
        Tiger_License_Checker::remember('shop', $this->license());
        $this->assertSame('KEY-123', Tiger_License_Checker::get('shop')['key']);
        Tiger_License_Checker::forget('shop');
        $this->assertNull(Tiger_License_Checker::get('shop'));
    }

    #[Test]
    public function signedValidVerdictPermitsUpdateAndCaches(): void
    {
        $this->store->put('shop', $this->license());
        $this->respondsSigned(['valid' => true, 'ttl' => 3600, 'latest_version' => '1.2.0']);

        $v = Tiger_License_Checker::verify('shop', [], 1000);
        $this->assertSame(Tiger_License_Checker::VALID, $v['state']);
        $this->assertTrue($v['can_update']);
        $this->assertSame('1.2.0', $v['latest_version']);
        $this->assertSame(1000 + 3600, $v['expires_at']);
        // Cached back onto the record.
        $this->assertSame(Tiger_License_Checker::VALID, $this->store->get('shop')['verdict']['state']);
    }

    #[Test]
    public function signedLapsedVerdictWithholdsTheUpdate(): void
    {
        $this->store->put('shop', $this->license());
        $this->respondsSigned(['valid' => false, 'ttl' => 3600]);

        $v = Tiger_License_Checker::verify('shop', [], 1000);
        $this->assertSame(Tiger_License_Checker::LAPSED, $v['state']);
        $this->assertFalse($v['can_update']);
        $this->assertFalse(Tiger_License_Checker::canUpdate('shop'));
    }

    #[Test]
    public function unreachableAuthorityIsUnknownAndFailsOpen(): void
    {
        $this->store->put('shop', $this->license());
        Tiger_License_Checker::setTransport(static fn($a, $p) => null);   // couldn't reach home

        $v = Tiger_License_Checker::verify('shop', [], 1000);
        $this->assertSame(Tiger_License_Checker::UNKNOWN, $v['state']);
        $this->assertTrue($v['can_update'], 'an outage must never withhold updates');
    }

    #[Test]
    public function forgedSignatureIsUntrustedAndDoesNotNag(): void
    {
        $this->store->put('shop', $this->license());
        // A reply signed by a DIFFERENT key claiming lapsed — must be ignored, not treated as lapsed.
        $other = Tiger_Crypto_Signature::generateKeypair();
        Tiger_License_Checker::setTransport(static function ($a, $p) use ($other) {
            $json = json_encode(['valid' => false]);
            return ['payload' => $json, 'signature' => Tiger_Crypto_Signature::sign($json, $other['secret_key'])];
        });

        $v = Tiger_License_Checker::verify('shop', [], 1000);
        $this->assertSame(Tiger_License_Checker::UNKNOWN, $v['state']);
        $this->assertTrue($v['can_update']);
    }

    #[Test]
    public function freshCacheIsServedWithoutReAsking(): void
    {
        $this->store->put('shop', $this->license());
        $this->respondsSigned(['valid' => true, 'ttl' => 3600]);
        Tiger_License_Checker::verify('shop', [], 1000);   // populates cache, expires at 4600

        // Now make the transport blow up — a cache hit must not call it.
        Tiger_License_Checker::setTransport(static function () { throw new \RuntimeException('should not be called'); });
        $v = Tiger_License_Checker::verify('shop', [], 2000);   // still < 4600
        $this->assertSame(Tiger_License_Checker::VALID, $v['state']);
    }

    #[Test]
    public function expiredCacheReAsks(): void
    {
        $this->store->put('shop', $this->license());
        $this->respondsSigned(['valid' => true, 'ttl' => 100]);
        Tiger_License_Checker::verify('shop', [], 1000);       // expires at 1100

        $this->respondsSigned(['valid' => false, 'ttl' => 100]);   // authority now says lapsed
        $v = Tiger_License_Checker::verify('shop', [], 2000);      // past expiry → re-asks
        $this->assertSame(Tiger_License_Checker::LAPSED, $v['state']);
    }

    #[Test]
    public function statusReadsCacheWithoutNetwork(): void
    {
        $this->store->put('shop', $this->license());
        $this->respondsSigned(['valid' => true, 'ttl' => 3600]);
        Tiger_License_Checker::verify('shop', [], 1000);

        Tiger_License_Checker::setTransport(static function () { throw new \RuntimeException('status must not phone home'); });
        $this->assertSame(Tiger_License_Checker::VALID, Tiger_License_Checker::status('shop')['state']);
        $this->assertSame(Tiger_License_Checker::UNLICENSED, Tiger_License_Checker::status('never')['state']);
    }
}

/** In-memory license store for the unit tests (no DB). */
final class ArrayLicenseStore implements Tiger_License_Store
{
    /** @var array<string,array> */
    public array $data = [];

    public function get(string $slug): ?array { return $this->data[$slug] ?? null; }
    public function put(string $slug, array $record): void { $this->data[$slug] = $record; }
    public function forget(string $slug): void { unset($this->data[$slug]); }
}
