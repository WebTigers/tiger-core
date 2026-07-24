<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_License_Checker;
use Tiger_License_Store;

/**
 * Tiger_License_Checker — the buyer-side licensing client, and the decision logic behind the
 * updater's license gate (System_Service_Updates::_applyOne → Tiger_License_Checker::gate). The
 * whole security posture of MARKETPLACE.md lives here: NAG, NEVER DISABLE. Only a definitive,
 * reached-home `lapsed` verdict withholds an UPDATE; unlicensed / unknown / valid all proceed, and
 * an authority outage or a forged reply is `unknown` (fail-OPEN), never `lapsed` — so a vendor's
 * outage can't brick a fleet and a forgery can't nag.
 *
 * Driven through the injectable store + transport seams, so this is deterministic with no network.
 */
#[CoversClass(Tiger_License_Checker::class)]
final class CheckerTest extends IntegrationTestCase
{
    private const SLUG = 'acme-widget';

    /** A minimal licensed manifest (pricing.model = licensed + authority + vendor). */
    private const LICENSED_MANIFEST = [
        'pricing' => ['model' => 'licensed', 'authority' => 'https://store.example/authority', 'vendor' => 'acme/TigerVendor'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Tiger_License_Checker::setStore($this->memoryStore());
    }

    protected function tearDown(): void
    {
        Tiger_License_Checker::_reset();   // drop injected store + transport
        parent::tearDown();
    }

    /** A tiny in-memory Tiger_License_Store so the checker needs no DB. */
    private function memoryStore(): Tiger_License_Store
    {
        return new class implements Tiger_License_Store {
            private array $rows = [];
            public function get(string $slug): ?array { return $this->rows[$slug] ?? null; }
            public function put(string $slug, array $record): void { $this->rows[$slug] = $record; }
            public function forget(string $slug): void { unset($this->rows[$slug]); }
        };
    }

    /** Make the authority reply with a fixed decoded array (unsigned/dev path). */
    private function transportReturns(?array $reply): void
    {
        Tiger_License_Checker::setTransport(static fn(string $authority, array $payload): ?array => $reply);
    }

    // ---- unlicensed: nothing to gate -------------------------------------------------------------

    #[Test]
    public function an_unlicensed_module_is_never_gated(): void
    {
        // No stored license, and a free manifest → not licensed, never blocked.
        $verdict = Tiger_License_Checker::verify(self::SLUG);
        $this->assertSame(Tiger_License_Checker::UNLICENSED, $verdict['state']);
        $this->assertTrue($verdict['can_update'], 'unlicensed always updates');

        $gate = Tiger_License_Checker::gate(['pricing' => ['model' => 'free']], self::SLUG);
        $this->assertFalse($gate['licensed']);
        $this->assertFalse($gate['blocked'], 'a free module is never blocked');
    }

    // ---- fail-open: unreachable / forged authority is UNKNOWN, never lapsed -----------------------

    #[Test]
    public function an_unreachable_authority_fails_open_as_unknown(): void
    {
        Tiger_License_Checker::remember(self::SLUG, [
            'key'       => 'LIC-123',
            'authority' => 'https://store.example/authority',
            'vendor'    => 'acme/TigerVendor',
        ]);
        $this->transportReturns(null);   // couldn't reach home

        $verdict = Tiger_License_Checker::verify(self::SLUG);
        $this->assertSame(Tiger_License_Checker::UNKNOWN, $verdict['state'], 'unreachable ≠ lapsed');
        $this->assertTrue($verdict['can_update'], 'fail-OPEN: an outage never blocks an update');

        $gate = Tiger_License_Checker::gate(self::LICENSED_MANIFEST, self::SLUG);
        $this->assertTrue($gate['licensed']);
        $this->assertFalse($gate['blocked'], 'a licensed module with an unreachable authority still updates');
    }

    #[Test]
    public function a_forged_signed_reply_is_untrusted_and_fails_open_not_lapsed(): void
    {
        // A public key is pinned, but the reply's signature doesn't verify → treated as unreachable
        // (assume-current), so a forgery can nag NOBODY.
        Tiger_License_Checker::remember(self::SLUG, [
            'key'        => 'LIC-123',
            'authority'  => 'https://store.example/authority',
            'vendor'     => 'acme/TigerVendor',
            'public_key' => str_repeat('a', 64),   // a pinned (dummy) key
        ]);
        $this->transportReturns(['payload' => '{"valid":false}', 'signature' => 'not-a-real-signature']);

        $verdict = Tiger_License_Checker::verify(self::SLUG);
        $this->assertSame(Tiger_License_Checker::UNKNOWN, $verdict['state'], 'an unverifiable reply is untrusted, not lapsed');
        $this->assertTrue($verdict['can_update'], 'a forged "lapsed" cannot withhold an update');
    }

    // ---- reached-home verdicts -------------------------------------------------------------------

    #[Test]
    public function a_reached_home_valid_verdict_permits_updates(): void
    {
        Tiger_License_Checker::remember(self::SLUG, [
            'key'       => 'LIC-123',
            'authority' => 'https://store.example/authority',
            'vendor'    => 'acme/TigerVendor',
        ]);   // no pinned key → unsigned dev reply is trusted verbatim
        $this->transportReturns(['valid' => true, 'ttl' => 3600, 'latest_version' => '2.0.0']);

        $verdict = Tiger_License_Checker::verify(self::SLUG);
        $this->assertSame(Tiger_License_Checker::VALID, $verdict['state']);
        $this->assertTrue($verdict['can_update']);
        $this->assertSame('2.0.0', $verdict['latest_version']);

        $this->assertFalse(Tiger_License_Checker::gate(self::LICENSED_MANIFEST, self::SLUG)['blocked'], 'valid is never blocked');
    }

    #[Test]
    public function only_a_definitive_lapsed_verdict_withholds_an_update(): void
    {
        Tiger_License_Checker::remember(self::SLUG, [
            'key'       => 'LIC-123',
            'authority' => 'https://store.example/authority',
            'vendor'    => 'acme/TigerVendor',
        ]);
        $this->transportReturns(['valid' => false, 'ttl' => 3600]);   // reached home, NOT entitled

        $verdict = Tiger_License_Checker::verify(self::SLUG);
        $this->assertSame(Tiger_License_Checker::LAPSED, $verdict['state']);
        $this->assertFalse($verdict['can_update'], 'a definitive lapse is the ONE state that blocks an update');

        $gate = Tiger_License_Checker::gate(self::LICENSED_MANIFEST, self::SLUG);
        $this->assertTrue($gate['licensed']);
        $this->assertTrue($gate['blocked'], 'lapsed → the update is withheld (nag) — the module still runs (never disabled)');
    }

    #[Test]
    public function can_update_mirrors_the_verdict(): void
    {
        Tiger_License_Checker::remember(self::SLUG, [
            'key'       => 'LIC-123',
            'authority' => 'https://store.example/authority',
            'vendor'    => 'acme/TigerVendor',
        ]);

        $this->transportReturns(['valid' => false]);
        $this->assertFalse(Tiger_License_Checker::canUpdate(self::SLUG), 'lapsed → cannot update');
    }
}
