<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Location;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Location_Adapter_IpApi;
use Tiger_Test_IpApiAdapter;

require_once __DIR__ . '/../../Support/Fixtures/LocationDoubles.php';

/**
 * Tiger_Location_Adapter_IpApi — IP geolocation (CAP_IP only) via ip-api.com.
 *
 * Network stubbed by a subclass; what's tested is the URL the adapter builds (the fixed `fields` set +
 * an optional Pro `key`) and the field-map from an ip-api `success` body into a normalized Place
 * (countryCode is already alpha-2, the one-line label is assembled from city/region/country, `ip` comes
 * from the echoed `query`). Non-success, blank IP, and a blank endpoint all degrade to null. No HTTP.
 */
#[CoversClass(Tiger_Location_Adapter_IpApi::class)]
final class IpApiTest extends UnitTestCase
{
    private function successBody(array $overrides = []): array
    {
        return array_replace([
            'status'      => 'success',
            'country'     => 'United States',
            'countryCode' => 'US',
            'region'      => 'NV',
            'regionName'  => 'Nevada',
            'city'        => 'Reno',
            'zip'         => '89501',
            'lat'         => 39.5296,
            'lon'         => -119.8138,
            'query'       => '8.8.8.8',
        ], $overrides);
    }

    #[Test]
    public function capabilities_and_label(): void
    {
        $a = new Tiger_Location_Adapter_IpApi();
        $this->assertSame(['ip'], $a->capabilities());
        $this->assertSame('ip-api.com', $a->label());
        $this->assertNotEmpty($a->fields());
    }

    #[Test]
    public function ip_maps_a_success_body_into_a_normalized_place(): void
    {
        $a = new Tiger_Test_IpApiAdapter();
        $a->canned = $this->successBody();

        $p = $a->ip('8.8.8.8');
        $this->assertNotNull($p);
        $this->assertSame('Reno', $p->city);
        $this->assertSame('Nevada', $p->region);
        $this->assertSame('89501', $p->postal);
        $this->assertSame('US', $p->country);
        $this->assertSame(39.5296, $p->latitude);
        $this->assertSame(-119.8138, $p->longitude);
        $this->assertSame('8.8.8.8', $p->ip);
        $this->assertSame('Reno, Nevada US', $p->label);
        $this->assertSame('ip', $p->type);
        $this->assertSame('ipapi', $p->source);
    }

    #[Test]
    public function the_request_url_carries_the_fixed_field_set(): void
    {
        $a = new Tiger_Test_IpApiAdapter();
        $a->canned = $this->successBody();
        $a->ip('8.8.8.8');

        $url = $a->urls[0];
        $this->assertStringContainsString('http://ip-api.com/json/8.8.8.8?fields=', $url);
        $this->assertStringContainsString('countryCode', $url);
        $this->assertStringNotContainsString('&key=', $url, 'no key appended when none configured');
    }

    #[Test]
    public function a_pro_key_is_appended_to_the_url(): void
    {
        $a = new Tiger_Test_IpApiAdapter(['key' => 'PRO-123']);
        $a->canned = $this->successBody();
        $a->ip('8.8.8.8');

        $this->assertStringContainsString('&key=PRO-123', $a->urls[0]);
    }

    #[Test]
    public function a_non_success_body_returns_null(): void
    {
        $a = new Tiger_Test_IpApiAdapter();
        $a->canned = ['status' => 'fail', 'message' => 'private range'];
        $this->assertNull($a->ip('10.0.0.1'));
    }

    #[Test]
    public function a_null_transport_result_returns_null(): void
    {
        $a = new Tiger_Test_IpApiAdapter();
        $a->canned = null;
        $this->assertNull($a->ip('8.8.8.8'));
    }

    #[Test]
    public function a_blank_ip_or_blank_endpoint_short_circuits_to_null(): void
    {
        $a = new Tiger_Test_IpApiAdapter();
        $a->canned = $this->successBody();
        $this->assertNull($a->ip(''), 'blank IP => null');
        $this->assertSame([], $a->urls, 'no request was built for a blank IP');

        $b = new Tiger_Test_IpApiAdapter(['endpoint' => '']);
        $b->canned = $this->successBody();
        $this->assertNull($b->ip('8.8.8.8'), 'blank endpoint => null');
    }

    #[Test]
    public function the_ip_falls_back_to_the_argument_when_the_body_omits_query(): void
    {
        $a = new Tiger_Test_IpApiAdapter();
        $a->canned = $this->successBody(['query' => null]);
        $this->assertSame('8.8.8.8', $a->ip('8.8.8.8')->ip);
    }
}
