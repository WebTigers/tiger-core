<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Location;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Location_Adapter_Aws;
use Tiger_Test_AwsAdapter;

require_once __DIR__ . '/../../Support/Fixtures/LocationDoubles.php';

/**
 * Tiger_Location_Adapter_Aws — AWS Location Service (Places), SigV4-signed.
 *
 * Two seams matter and both are network-free: the REQUEST construction (the per-op body via _textBody,
 * the country → alpha-3 FilterCountries bias, and — the sharp end — the inline SigV4 signature) and the
 * RESPONSE parse (_mapPlace: alpha-3 → alpha-2 country, Geometry.Point ordered [lng, lat], line1 =
 * "AddressNumber Street"). The signing test is deterministic without freezing the clock: it reads the
 * x-amz-date the method emitted and RE-DERIVES the signature with an independent SigV4 implementation,
 * asserting the Authorization header matches. The signed POST itself (_signedPost/_call over curl) is the
 * live boundary and is only exercised for its unprovisioned-config guard.
 */
#[CoversClass(Tiger_Location_Adapter_Aws::class)]
final class AwsTest extends UnitTestCase
{
    private const CFG = ['region' => 'us-east-1', 'place_index' => 'MyIndex', 'key' => 'AKIA_TEST', 'secret' => 'SECRET_TEST'];

    #[Test]
    public function capabilities_and_label(): void
    {
        $a = new Tiger_Location_Adapter_Aws();
        $this->assertSame(['suggest', 'geocode', 'reverse'], $a->capabilities());
        $this->assertSame('AWS Location Service', $a->label());
        $this->assertNotEmpty($a->fields());
    }

    // -- unprovisioned guard (real _call, but it bails before any network) --------------------------

    #[Test]
    public function an_unprovisioned_adapter_degrades_to_empty_without_calling_out(): void
    {
        $a = new Tiger_Location_Adapter_Aws();   // no region/index/key/secret
        $this->assertSame([], $a->geocode('anywhere'));
        $this->assertSame([], $a->suggest('anywhere'));
        $this->assertNull($a->reverse(1.0, 2.0));
    }

    #[Test]
    public function a_blank_query_short_circuits_before_a_call(): void
    {
        $a = new Tiger_Test_AwsAdapter(self::CFG);
        $a->canned = ['Results' => []];
        $this->assertSame([], $a->suggest('   '));
        $this->assertSame([], $a->geocode('   '));
        $this->assertSame([], $a->calls, 'no request built for a blank query');
    }

    // -- suggest / geocode / reverse parsing (stubbed _call) ---------------------------------------

    #[Test]
    public function suggest_maps_prediction_results_and_skips_textless_ones(): void
    {
        $a = new Tiger_Test_AwsAdapter(self::CFG);
        $a->canned = ['Results' => [
            ['Text' => '123 Main St, Reno, NV', 'PlaceId' => 'PID1'],
            ['PlaceId' => 'PID2'],   // no Text — skipped
        ]];

        $out = $a->suggest('123 Main');
        $this->assertCount(1, $out);
        $this->assertSame('123 Main St, Reno, NV', $out[0]->label);
        $this->assertSame('PID1', $out[0]->id);
        $this->assertSame('suggestion', $out[0]->type);
        $this->assertSame('search/suggestions', $a->calls[0]['op']);
    }

    #[Test]
    public function geocode_maps_a_place_result(): void
    {
        $a = new Tiger_Test_AwsAdapter(self::CFG);
        $a->canned = ['Results' => [['Place' => [
            'Label'        => '1 Infinite Loop, Cupertino, CA 95014, USA',
            'AddressNumber' => '1',
            'Street'       => 'Infinite Loop',
            'Municipality' => 'Cupertino',
            'Region'       => 'California',
            'PostalCode'   => '95014',
            'Country'      => 'USA',
            'Geometry'     => ['Point' => [-122.0312, 37.3318]],
        ]]]];

        $p = $a->geocode('1 Infinite Loop')[0];
        $this->assertSame('1 Infinite Loop', $p->line1, 'line1 = "AddressNumber Street"');
        $this->assertSame('Cupertino', $p->city);
        $this->assertSame('California', $p->region);
        $this->assertSame('95014', $p->postal);
        $this->assertSame('US', $p->country, 'alpha-3 USA mapped to alpha-2 US');
        $this->assertSame(37.3318, $p->latitude, 'Point is [lng, lat] — latitude is the 2nd element');
        $this->assertSame(-122.0312, $p->longitude);
        $this->assertSame('address', $p->type);
        $this->assertSame('search/text', $a->calls[0]['op']);
    }

    #[Test]
    public function an_unknown_alpha3_country_passes_through_unchanged(): void
    {
        $a = new Tiger_Test_AwsAdapter(self::CFG);
        $a->canned = ['Results' => [['Place' => ['Country' => 'XYZ', 'Geometry' => ['Point' => [0, 0]]]]]];
        $this->assertSame('XYZ', $a->geocode('x')[0]->country);
    }

    #[Test]
    public function reverse_sends_position_as_lng_lat_and_maps_the_first_result(): void
    {
        $a = new Tiger_Test_AwsAdapter(self::CFG);
        $a->canned = ['Results' => [['Place' => ['Municipality' => 'Oslo', 'Country' => 'NOR', 'Geometry' => ['Point' => [10.75, 59.91]]]]]];

        $p = $a->reverse(59.91, 10.75);
        $this->assertSame('Oslo', $p->city);
        $this->assertSame('NO', $p->country);
        $this->assertSame('search/position', $a->calls[0]['op']);
        $this->assertSame([10.75, 59.91], $a->calls[0]['body']['Position'], 'AWS wants [longitude, latitude]');
    }

    #[Test]
    public function reverse_returns_null_when_there_is_no_place(): void
    {
        $a = new Tiger_Test_AwsAdapter(self::CFG);
        $a->canned = ['Results' => []];
        $this->assertNull($a->reverse(1.0, 2.0));
    }

    // -- _textBody (country bias → alpha-3 FilterCountries) ----------------------------------------

    #[Test]
    public function the_text_body_adds_a_country_filter_in_alpha3(): void
    {
        $a = new Tiger_Location_Adapter_Aws(self::CFG);
        $m = new ReflectionMethod($a, '_textBody');

        $plain = $m->invoke($a, 'reno', []);
        $this->assertSame(['Text' => 'reno', 'MaxResults' => 5], $plain);
        $this->assertArrayNotHasKey('FilterCountries', $plain);

        $biased = $m->invoke($a, 'reno', ['country' => 'US']);
        $this->assertSame(['USA'], $biased['FilterCountries'], 'country biased as ISO alpha-3');
    }

    // -- SigV4 (re-derived independently, no clock freeze) -----------------------------------------

    #[Test]
    public function sigv4_headers_are_well_formed_and_the_signature_verifies(): void
    {
        $a = new Tiger_Location_Adapter_Aws(self::CFG);
        $m = new ReflectionMethod($a, '_sigV4Headers');

        $host   = 'places.geo.us-east-1.amazonaws.com';
        $region = 'us-east-1';
        $path   = '/places/v0/indexes/MyIndex/search/text';
        $body   = '{"Text":"reno","MaxResults":5}';
        $key    = 'AKIA_TEST';
        $secret = 'SECRET_TEST';

        $headers = $m->invoke($a, $host, $region, $path, $body, $key, $secret, '');
        $map     = $this->headerMap($headers);

        // structural invariants
        $this->assertSame(hash('sha256', $body), $map['x-amz-content-sha256']);
        $this->assertSame($host, $map['host']);
        $this->assertArrayNotHasKey('x-amz-security-token', $map, 'no token header when no session token');

        $auth = $map['authorization'];
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 ', $auth);
        $this->assertStringContainsString('SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date', $auth);
        $this->assertStringContainsString('Credential=' . $key . '/' . substr($map['x-amz-date'], 0, 8) . '/us-east-1/geo/aws4_request', $auth);

        // independent re-derivation using the exact timestamp the method emitted
        $expected = $this->deriveSignature($host, $region, $path, $body, $secret, $map['x-amz-date'], '');
        $this->assertStringContainsString('Signature=' . $expected, $auth, 'the SigV4 math matches an independent implementation');
    }

    #[Test]
    public function a_session_token_is_signed_in_and_sent(): void
    {
        $a = new Tiger_Location_Adapter_Aws(self::CFG);
        $m = new ReflectionMethod($a, '_sigV4Headers');

        $host = 'places.geo.us-east-1.amazonaws.com';
        $body = '{}';
        $headers = $m->invoke($a, $host, 'us-east-1', '/p', $body, 'K', 'S', 'TOKEN123');
        $map = $this->headerMap($headers);

        $this->assertSame('TOKEN123', $map['x-amz-security-token']);
        $this->assertStringContainsString('SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-security-token', $map['authorization']);

        $expected = $this->deriveSignature($host, 'us-east-1', '/p', $body, 'S', $map['x-amz-date'], 'TOKEN123');
        $this->assertStringContainsString('Signature=' . $expected, $map['authorization']);
    }

    // -- helpers -----------------------------------------------------------------------------------

    /** Split a curl "Header: value" list into a lowercased-name map. */
    private function headerMap(array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            [$name, $val] = array_pad(explode(':', $h, 2), 2, '');
            $out[strtolower(trim($name))] = trim($val);
        }
        return $out;
    }

    /** A standalone SigV4 signature for the same canonical request the adapter builds. */
    private function deriveSignature(string $host, string $region, string $path, string $body, string $secret, string $now, string $token): string
    {
        $date     = substr($now, 0, 8);
        $bodyHash = hash('sha256', $body);
        $canonicalHeaders = "content-type:application/json\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$bodyHash}\n"
            . "x-amz-date:{$now}\n";
        $signed = 'content-type;host;x-amz-content-sha256;x-amz-date';
        if ($token !== '') {
            $canonicalHeaders .= "x-amz-security-token:{$token}\n";
            $signed .= ';x-amz-security-token';
        }
        $canonicalRequest = "POST\n{$path}\n\n{$canonicalHeaders}\n{$signed}\n{$bodyHash}";
        $scope = $date . '/' . $region . '/geo/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'geo', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return hash_hmac('sha256', $stringToSign, $kSigning);
    }
}
