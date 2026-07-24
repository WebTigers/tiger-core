<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Location;
use Tiger_Location_Adapter_Interface;
use Tiger_Location_Place;
use Tiger_Service_Location;

/**
 * Tiger_Service_Location — the public guest-facing Location endpoint over /api. Exercised with NO network:
 * with no provider configured, Tiger_Location returns empty results by design, so the service's shape
 * (suggest → {results:[]}, reverse/ip → {place:null}) is provable offline. A registered fake adapter then
 * proves the happy path — the service maps a Place through toArray() into the envelope — still with no I/O.
 */
#[CoversClass(Tiger_Service_Location::class)]
final class LocationServiceTest extends UnitTestCase
{
    private function response(array $message): object
    {
        return (new Tiger_Service_Location($message))->getResponse();
    }

    // ----- unconfigured: graceful empties, no network ------------------------

    #[Test]
    public function suggest_returns_empty_results_when_no_provider_is_configured(): void
    {
        $res = $this->response(['action' => 'suggest', 'q' => '221B Baker Street', 'country' => 'gb']);
        $this->assertSame(1, $res->result);
        $this->assertSame([], $res->data['results'], 'no provider => no suggestions, no fatal');
    }

    #[Test]
    public function reverse_returns_a_null_place_when_unconfigured(): void
    {
        $res = $this->response(['action' => 'reverse', 'lat' => '51.5', 'lng' => '-0.12']);
        $this->assertSame(1, $res->result);
        $this->assertNull($res->data['place']);
    }

    #[Test]
    public function ip_returns_a_null_place_when_unconfigured(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $res = $this->response(['action' => 'ip']);
        $this->assertSame(1, $res->result);
        $this->assertNull($res->data['place']);
    }

    // ----- configured: a fake adapter proves the mapping ---------------------

    #[Test]
    public function suggest_maps_configured_provider_places_into_the_envelope(): void
    {
        Tiger_Location::register('faketest', FakeLocationAdapter::class);
        $this->setConfig(['tiger' => ['location' => ['address' => ['provider' => 'faketest']]]]);

        $res = $this->response(['action' => 'suggest', 'q' => 'Somewhere']);
        $this->assertSame(1, $res->result);
        $this->assertCount(1, $res->data['results']);
        $this->assertSame('Testville', $res->data['results'][0]['city'], 'the Place is normalized via toArray()');
    }

    #[Test]
    public function reverse_maps_a_configured_place(): void
    {
        Tiger_Location::register('faketest', FakeLocationAdapter::class);
        $this->setConfig(['tiger' => ['location' => ['address' => ['provider' => 'faketest']]]]);

        $res = $this->response(['action' => 'reverse', 'lat' => '1.0', 'lng' => '2.0']);
        $this->assertSame(1, $res->result);
        $this->assertIsArray($res->data['place']);
        $this->assertSame('Testville', $res->data['place']['city']);
    }
}

/** An offline Location adapter: capable of suggest/reverse, answers with a canned Place. */
class FakeLocationAdapter implements Tiger_Location_Adapter_Interface
{
    public function __construct($config = []) {}

    public function supports(string $cap): bool
    {
        return in_array($cap, [self::CAP_SUGGEST, self::CAP_REVERSE], true);
    }

    public function capabilities(): array
    {
        return [self::CAP_SUGGEST, self::CAP_REVERSE];
    }

    public function suggest(string $query, array $opts = []): array
    {
        return [$this->place()];
    }

    public function geocode(string $query, array $opts = []): array
    {
        return [$this->place()];
    }

    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place
    {
        return $this->place();
    }

    public function ip(string $ip, array $opts = []): ?Tiger_Location_Place
    {
        return null;
    }

    private function place(): Tiger_Location_Place
    {
        $p = new Tiger_Location_Place();
        $p->city    = 'Testville';
        $p->country = 'US';
        return $p;
    }
}
