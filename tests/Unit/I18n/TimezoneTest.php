<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\I18n;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_I18n_Timezone;

/**
 * Tiger_I18n_Timezone — the IANA timezone list, enriched for a searchable picker.
 *
 * Pure computation over PHP's own DateTimeZone data (no config, no DB). The label folds three search
 * axes into one string — `id (ABBR, UTC±HH:MM)` — and deliberately DROPS the abbreviation when a zone
 * has only a numeric one (so the offset isn't printed twice). options() returns id → label ordered by
 * current UTC offset. The abbreviation/offset are computed for "now" (DST-aware), so where an assertion
 * could straddle a DST change it is matched by pattern rather than a frozen string.
 */
#[CoversClass(Tiger_I18n_Timezone::class)]
final class TimezoneTest extends UnitTestCase
{
    #[Test]
    public function label_folds_a_named_abbreviation_and_offset(): void
    {
        // New York is UTC-05:00 (EST) or UTC-04:00 (EDT) depending on the season — match either.
        $this->assertMatchesRegularExpression(
            '#^America/New_York \(E[SD]T, UTC-0[45]:00\)$#',
            Tiger_I18n_Timezone::label('America/New_York')
        );
    }

    #[Test]
    public function label_drops_a_numeric_abbreviation_to_avoid_a_doubled_offset(): void
    {
        // Kathmandu (+05:45) has no named abbreviation — PHP formats 'T' as "+0545", which is dropped so
        // the label carries the offset exactly once.
        $this->assertSame('Asia/Kathmandu (UTC+05:45)', Tiger_I18n_Timezone::label('Asia/Kathmandu'));
    }

    #[Test]
    public function label_for_utc_shows_a_zero_offset(): void
    {
        $this->assertSame('UTC (UTC, UTC+00:00)', Tiger_I18n_Timezone::label('UTC'));
    }

    #[Test]
    public function an_invalid_id_returns_the_bare_id(): void
    {
        $this->assertSame('Not/AZone', Tiger_I18n_Timezone::label('Not/AZone'));
    }

    #[Test]
    public function options_is_a_non_empty_id_to_label_map_of_valid_zones(): void
    {
        $opts = Tiger_I18n_Timezone::options();
        $this->assertGreaterThan(400, count($opts));
        $this->assertArrayHasKey('UTC', $opts);
        $this->assertArrayHasKey('America/New_York', $opts);
        // every key is a real IANA id, and its value is the composed label.
        $this->assertStringContainsString('America/New_York (', $opts['America/New_York']);
    }

    #[Test]
    public function options_are_ordered_by_current_utc_offset(): void
    {
        $keys  = array_keys(Tiger_I18n_Timezone::options());
        $first = new DateTimeZone($keys[0]);
        $last  = new DateTimeZone($keys[count($keys) - 1]);
        $now   = new \DateTime('now');
        $this->assertLessThanOrEqual(
            $last->getOffset($now),
            $first->getOffset($now),
            'the list runs from the most-negative offset to the most-positive'
        );
    }
}
