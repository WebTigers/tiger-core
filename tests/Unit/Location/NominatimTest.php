<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Location;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Location_Adapter_Nominatim;
use Tiger_Test_NominatimAdapter;

require_once __DIR__ . '/../../Support/Fixtures/LocationDoubles.php';

/**
 * Tiger_Location_Adapter_Nominatim — the free/key-less OSM default.
 *
 * The network hop (_getJson) is stubbed by a subclass, so the two things that matter run
 * deterministically: the URL the adapter BUILDS (endpoint, jsonv2/addressdetails, limit clamp,
 * country bias, contact email) and how a Nominatim result is PARSED into a normalized Place (line1
 * = "house_number road", the city fallback chain, country upper-cased to alpha-2, lat/lon → float).
 * No real HTTP.
 */
#[CoversClass(Tiger_Location_Adapter_Nominatim::class)]
final class NominatimTest extends UnitTestCase
{
    private function row(array $overrides = []): array
    {
        return array_replace([
            'place_id'     => 12345,
            'display_name' => '1 Infinite Loop, Cupertino, CA, USA',
            'lat'          => '37.3318',
            'lon'          => '-122.0312',
            'addresstype'  => 'house',
            'type'         => 'house',
            'address'      => [
                'house_number' => '1',
                'road'         => 'Infinite Loop',
                'city'         => 'Cupertino',
                'state'        => 'California',
                'postcode'     => '95014',
                'country_code' => 'us',
            ],
        ], $overrides);
    }

    #[Test]
    public function capabilities_and_label(): void
    {
        $a = new Tiger_Location_Adapter_Nominatim();
        $this->assertSame(['suggest', 'geocode', 'reverse'], $a->capabilities());
        $this->assertSame('Nominatim (OpenStreetMap)', $a->label());
        $this->assertNotEmpty($a->fields());
    }

    #[Test]
    public function suggest_parses_a_search_result_into_a_normalized_place(): void
    {
        $a = new Tiger_Test_NominatimAdapter();
        $a->canned = [$this->row()];

        $out = $a->suggest('1 Infinite');
        $this->assertCount(1, $out);
        $p = $out[0];
        $this->assertSame('12345', $p->id, 'place_id stringified');
        $this->assertSame('1 Infinite Loop', $p->line1);
        $this->assertSame('Cupertino', $p->city);
        $this->assertSame('California', $p->region);
        $this->assertSame('95014', $p->postal);
        $this->assertSame('US', $p->country, 'country_code upper-cased to alpha-2');
        $this->assertSame(37.3318, $p->latitude);
        $this->assertSame(-122.0312, $p->longitude);
        $this->assertSame('house', $p->type);
        $this->assertSame('nominatim', $p->source);
        $this->assertSame($this->row(), $p->raw);
    }

    #[Test]
    public function the_city_falls_back_through_town_village_hamlet_municipality(): void
    {
        $a = new Tiger_Test_NominatimAdapter();
        $a->canned = [$this->row(['address' => ['town' => 'Smallville', 'road' => 'Main']])];
        $this->assertSame('Smallville', $a->geocode('x')[0]->city);

        $a->canned = [$this->row(['address' => ['municipality' => 'Metroville']])];
        $this->assertSame('Metroville', $a->geocode('x')[0]->city);
    }

    #[Test]
    public function a_missing_street_leaves_line1_null(): void
    {
        $a = new Tiger_Test_NominatimAdapter();
        $a->canned = [$this->row(['address' => ['city' => 'Nowhere']])];
        $this->assertNull($a->geocode('x')[0]->line1, 'no house_number/road => null, not an empty string');
    }

    #[Test]
    public function the_search_url_carries_the_query_country_bias_and_limit(): void
    {
        $a = new Tiger_Test_NominatimAdapter();
        $a->canned = [];
        $a->suggest('main st', ['country' => 'US', 'limit' => 99]);

        $url = $a->urls[0];
        $this->assertStringContainsString('https://nominatim.openstreetmap.org/search?', $url);
        $this->assertStringContainsString('format=jsonv2', $url);
        $this->assertStringContainsString('addressdetails=1', $url);
        $this->assertStringContainsString('countrycodes=us', $url, 'country lower-cased for the bias');
        $this->assertStringContainsString('limit=10', $url, 'limit clamps to 10');
    }

    #[Test]
    public function a_configured_endpoint_and_email_shape_the_url(): void
    {
        $a = new Tiger_Test_NominatimAdapter(['endpoint' => 'https://osm.example/nominatim/', 'email' => 'ops@example.com']);
        $a->canned = [];
        $a->geocode('paris');

        $url = $a->urls[0];
        $this->assertStringContainsString('https://osm.example/nominatim/search?', $url, 'trailing slash trimmed then /search appended');
        $this->assertStringContainsString('email=ops%40example.com', $url);
    }

    #[Test]
    public function reverse_maps_a_single_result_and_returns_null_when_unplaceable(): void
    {
        $a = new Tiger_Test_NominatimAdapter();
        $a->canned = $this->row();
        $place = $a->reverse(37.33, -122.03);
        $this->assertNotNull($place);
        $this->assertSame('Cupertino', $place->city);
        $this->assertStringContainsString('/reverse?', $a->urls[0]);

        $a2 = new Tiger_Test_NominatimAdapter();
        $a2->canned = ['no' => 'address key here'];
        $this->assertNull($a2->reverse(0.0, 0.0), 'a result without an address => null');
    }

    #[Test]
    public function a_null_search_response_yields_an_empty_list(): void
    {
        $a = new Tiger_Test_NominatimAdapter();
        $a->canned = null;   // transport/parse failure upstream
        $this->assertSame([], $a->suggest('x'));
    }
}
