<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_License_Checker;
use Tiger_License_Store;

/**
 * Tiger_License_Checker — the no-network status/verdict branches the Wave-4 CheckerTest didn't reach: a
 * held license that has never been verified reports UNKNOWN (assume-current, quiet) rather than lapsed;
 * verify() short-circuits to UNLICENSED when the stored record is missing its key or authority.
 */
#[CoversClass(Tiger_License_Checker::class)]
final class CheckerStatusTest extends UnitTestCase
{
    private W5LicenseStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Tiger_License_Checker::_reset();
        $this->store = new W5LicenseStore();
        Tiger_License_Checker::setStore($this->store);
    }

    protected function tearDown(): void
    {
        Tiger_License_Checker::_reset();
        parent::tearDown();
    }

    #[Test]
    public function status_of_a_held_but_never_verified_license_is_unknown(): void
    {
        // A key is on file but no verdict has ever been cached — the badge reads UNKNOWN, and it still
        // permits updates (nag-never-disable: unknown is assume-current, not lapsed).
        $this->store->put('shop', ['key' => 'K-1', 'authority' => 'https://a', 'public_key' => '']);

        $v = Tiger_License_Checker::status('shop');
        $this->assertSame(Tiger_License_Checker::UNKNOWN, $v['state']);
        $this->assertTrue($v['can_update']);
    }

    #[Test]
    public function verify_of_a_record_missing_its_key_is_unlicensed(): void
    {
        // A malformed record (authority but no key) is treated as nothing to gate — UNLICENSED, ungated.
        $this->store->put('shop', ['authority' => 'https://a']);
        $v = Tiger_License_Checker::verify('shop', [], 1000);
        $this->assertSame(Tiger_License_Checker::UNLICENSED, $v['state']);
        $this->assertTrue($v['can_update']);
    }
}

/** In-memory license store (no DB). */
final class W5LicenseStore implements Tiger_License_Store
{
    /** @var array<string,array> */
    public array $data = [];

    public function get(string $slug): ?array { return $this->data[$slug] ?? null; }
    public function put(string $slug, array $record): void { $this->data[$slug] = $record; }
    public function forget(string $slug): void { unset($this->data[$slug]); }
}
