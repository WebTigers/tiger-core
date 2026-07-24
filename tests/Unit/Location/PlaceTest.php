<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Location;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Location_Place;

/**
 * Tiger_Location_Place — the one normalized payload every Location adapter returns.
 *
 * Two invariants carry the value: the constructor is a WHITELIST hydrator (only declared properties
 * are set, unknown keys are silently ignored — an adapter can't smuggle arbitrary fields onto the
 * object), and toArray() is the STABLE public contract that deliberately drops the `raw` provider
 * blob (so the debug payload never leaks into the JSON that reaches the browser). Pure value object,
 * no DB.
 */
#[CoversClass(Tiger_Location_Place::class)]
final class PlaceTest extends UnitTestCase
{
    #[Test]
    public function the_constructor_hydrates_declared_fields(): void
    {
        $place = new Tiger_Location_Place([
            'id'        => 'osm:123',
            'label'     => '1 Infinite Loop, Cupertino, CA',
            'line1'     => '1 Infinite Loop',
            'city'      => 'Cupertino',
            'region'    => 'CA',
            'postal'    => '95014',
            'country'   => 'US',
            'latitude'  => 37.3318,
            'longitude' => -122.0312,
            'type'      => 'address',
            'source'    => 'nominatim',
        ]);

        $this->assertSame('osm:123', $place->id);
        $this->assertSame('Cupertino', $place->city);
        $this->assertSame('US', $place->country);
        $this->assertSame(37.3318, $place->latitude);
        $this->assertSame('nominatim', $place->source);
    }

    #[Test]
    public function the_constructor_ignores_unknown_keys(): void
    {
        // A provider dumping extra keys can't create dynamic properties on the value object — only
        // the declared schema is populated.
        $place = new Tiger_Location_Place([
            'city'      => 'Paris',
            'bogus'     => 'x',
            'admin_sql' => 'DROP TABLE',
        ]);

        $this->assertSame('Paris', $place->city);
        $this->assertArrayNotHasKey('bogus', $place->toArray());
        $this->assertArrayNotHasKey('admin_sql', $place->toArray());
    }

    #[Test]
    public function an_empty_construction_leaves_nulls_and_an_empty_raw(): void
    {
        $place = new Tiger_Location_Place();
        $this->assertNull($place->city);
        $this->assertNull($place->country);
        $this->assertSame([], $place->raw, 'raw defaults to an empty array, never null');
    }

    #[Test]
    public function raw_is_stored_on_the_object_but_dropped_from_the_public_payload(): void
    {
        // `raw` is the provider-specific debug blob — kept on the object for server-side use, but the
        // public contract (toArray) must NOT expose it.
        $place = new Tiger_Location_Place([
            'city' => 'Berlin',
            'raw'  => ['provider' => 'nominatim', 'osm_id' => 999, 'place_rank' => 30],
        ]);

        $this->assertSame(['provider' => 'nominatim', 'osm_id' => 999, 'place_rank' => 30], $place->raw);
        $this->assertArrayNotHasKey('raw', $place->toArray());
    }

    #[Test]
    public function to_array_exposes_exactly_the_stable_field_set(): void
    {
        $place = new Tiger_Location_Place(['city' => 'Tokyo', 'raw' => ['x' => 1]]);

        $this->assertSame(
            ['id', 'label', 'line1', 'line2', 'city', 'region', 'postal', 'country',
             'latitude', 'longitude', 'type', 'source', 'ip'],
            array_keys($place->toArray())
        );
    }

    #[Test]
    public function to_array_round_trips_normalized_field_values(): void
    {
        $data = [
            'id'        => 'p1',
            'label'     => 'Somewhere',
            'line1'     => '10 Downing St',
            'line2'     => 'Flat 2',
            'city'      => 'London',
            'region'    => 'England',
            'postal'    => 'SW1A 2AA',
            'country'   => 'GB',
            'latitude'  => 51.5033,
            'longitude' => -0.1276,
            'type'      => 'address',
            'source'    => 'nominatim',
            'ip'        => null,
        ];

        $this->assertSame($data, (new Tiger_Location_Place($data))->toArray());
    }

    #[Test]
    public function an_ip_lookup_place_carries_its_ip(): void
    {
        // The IP geolocation path populates `ip`, which IS part of the public payload (unlike raw).
        $place = new Tiger_Location_Place(['type' => 'ip', 'country' => 'US', 'ip' => '203.0.113.7']);
        $out   = $place->toArray();
        $this->assertSame('203.0.113.7', $out['ip']);
        $this->assertSame('ip', $out['type']);
    }
}
