<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Uuid;

/**
 * Tiger_Uuid — client-generated UUID PKs. v7 (time-ordered, the default) must be sortable by
 * creation, and isValid() is the gate a lot of lookups lean on, so both get real coverage.
 */
#[CoversClass(Tiger_Uuid::class)]
final class UuidTest extends UnitTestCase
{
    private const RE_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const RE_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    #[Test]
    public function v4_has_the_right_version_and_variant(): void
    {
        $u = Tiger_Uuid::v4();
        $this->assertMatchesRegularExpression(self::RE_V4, $u);
        $this->assertTrue(Tiger_Uuid::isValid($u));
    }

    #[Test]
    public function v7_has_the_right_version_and_variant(): void
    {
        $u = Tiger_Uuid::v7();
        $this->assertMatchesRegularExpression(self::RE_V7, $u);
        $this->assertTrue(Tiger_Uuid::isValid($u));
    }

    #[Test]
    public function generate_defaults_to_v7(): void
    {
        $this->assertMatchesRegularExpression(self::RE_V7, Tiger_Uuid::generate());
    }

    #[Test]
    public function v4_values_are_unique(): void
    {
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[Tiger_Uuid::v4()] = true;
        }
        $this->assertCount(1000, $seen, 'v4 collided within 1000 draws');
    }

    #[Test]
    public function v7_is_time_ordered_at_millisecond_granularity(): void
    {
        // v7's guarantee is ms-granularity ordering: the embedded timestamp never goes backwards.
        // (Within a single ms the low 10 bytes are random, so full-string order is NOT guaranteed —
        // that's spec-correct, and exactly what this asserts instead of over-claiming.)
        $prevTs = 0.0;
        for ($i = 0; $i < 5000; $i++) {
            $ts = Tiger_Uuid::timeOf(Tiger_Uuid::v7());
            $this->assertGreaterThanOrEqual($prevTs, $ts, "v7 timestamp went backwards at draw $i");
            $prevTs = $ts;
        }
    }

    #[Test]
    public function v7_generated_across_a_millisecond_boundary_sort_in_order(): void
    {
        $first = Tiger_Uuid::v7();
        usleep(2000); // cross a ms boundary
        $second = Tiger_Uuid::v7();
        $this->assertLessThan($second, $first, 'v7 across a ms boundary must sort by creation');
    }

    #[Test]
    public function timeof_v7_is_close_to_now(): void
    {
        $before = microtime(true);
        $ts = Tiger_Uuid::timeOf(Tiger_Uuid::v7());   // float unix seconds, ms precision
        $after = microtime(true);

        $this->assertIsFloat($ts);
        $this->assertGreaterThanOrEqual($before - 1.0, $ts);
        $this->assertLessThanOrEqual($after + 1.0, $ts);
    }

    #[Test]
    public function isvalid_rejects_junk(): void
    {
        $this->assertFalse(Tiger_Uuid::isValid(''));
        $this->assertFalse(Tiger_Uuid::isValid('not-a-uuid'));
        $this->assertFalse(Tiger_Uuid::isValid('zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz'));
        // right shape, one char too long
        $this->assertFalse(Tiger_Uuid::isValid('00000000-0000-7000-8000-0000000000000'));
    }

    #[Test]
    public function isvalid_accepts_a_canonical_lowercase_uuid(): void
    {
        $this->assertTrue(Tiger_Uuid::isValid('00000000-0000-7000-8000-000000000000'));
    }
}
