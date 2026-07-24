<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

/**
 * Global-namespace test doubles for the Location facade + adapter tests.
 *
 * Location adapters are global `Tiger_*` classes (PSR-0), so their doubles must be too. These are
 * `require_once`'d by the tests that register them with Tiger_Location — they let the facade be driven
 * end-to-end with canned Places and forced failures, never touching the network.
 */

/**
 * A fully-driveable fake adapter: capabilities, per-op return values, a throw switch, and it captures
 * the config the facade constructed it with. Everything is static so the facade (which does its own
 * `new $class($cfg)`) can be steered from a test; call reset() in setUp.
 */
class Tiger_Test_LocationAdapter extends Tiger_Location_Adapter_Abstract
{
    /** @var array<int,string> */
    public static $caps = ['suggest', 'geocode', 'reverse', 'ip'];
    /** @var bool when true every operation throws (to exercise the facade's graceful-empty catch) */
    public static $throw = false;
    /** @var Tiger_Location_Place[] */
    public static $suggestReturn = [];
    /** @var Tiger_Location_Place[] */
    public static $geocodeReturn = [];
    public static $reverseReturn;   // ?Tiger_Location_Place
    public static $ipReturn;        // ?Tiger_Location_Place
    /** @var array the config the facade passed the last instance */
    public static $seenConfig = [];

    public static function reset(): void
    {
        self::$caps          = ['suggest', 'geocode', 'reverse', 'ip'];
        self::$throw         = false;
        self::$suggestReturn = [];
        self::$geocodeReturn = [];
        self::$reverseReturn = null;
        self::$ipReturn      = null;
        self::$seenConfig    = [];
    }

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        self::$seenConfig = $config;
    }

    public function capabilities(): array
    {
        return self::$caps;
    }

    public function suggest(string $query, array $opts = []): array
    {
        if (self::$throw) { throw new Tiger_Location_Exception('boom'); }
        return self::$suggestReturn;
    }

    public function geocode(string $query, array $opts = []): array
    {
        if (self::$throw) { throw new Tiger_Location_Exception('boom'); }
        return self::$geocodeReturn;
    }

    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place
    {
        if (self::$throw) { throw new Tiger_Location_Exception('boom'); }
        return self::$reverseReturn;
    }

    public function ip(string $ip, array $opts = []): ?Tiger_Location_Place
    {
        if (self::$throw) { throw new Tiger_Location_Exception('boom'); }
        return self::$ipReturn;
    }
}

/**
 * A do-nothing concrete adapter: overrides nothing, so it exercises Tiger_Location_Adapter_Abstract's
 * defaults (empty capabilities, the class-suffix label, and every operation throwing "unsupported").
 */
class Tiger_Test_BareAdapter extends Tiger_Location_Adapter_Abstract
{
}

/**
 * A class that is NOT a Tiger_Location_Adapter_Interface but constructs cleanly with the adapter
 * signature — so the facade's "resolved instance isn't an adapter → null" branch can be exercised
 * without a constructor error escaping.
 */
class Tiger_Test_NotAnAdapter
{
    public function __construct(array $config = []) {}
}

/**
 * A Nominatim subclass whose network hop (_getJson) is replaced by a canned payload, so the real
 * parse path (_search / _place / reverse mapping) runs deterministically. It also records every URL
 * the adapter built, so a test can assert URL construction.
 */
class Tiger_Test_NominatimAdapter extends Tiger_Location_Adapter_Nominatim
{
    /** @var mixed the value the next _getJson returns */
    public $canned;
    /** @var string[] URLs the adapter asked for */
    public $urls = [];

    protected function _getJson(string $url, array $headers = [], int $timeout = 8): ?array
    {
        $this->urls[] = $url;
        return $this->canned;
    }
}

/**
 * An ip-api subclass with the network hop stubbed. Records the URL so the fields/key construction can
 * be asserted, and returns a canned decoded body.
 */
class Tiger_Test_IpApiAdapter extends Tiger_Location_Adapter_IpApi
{
    /** @var mixed the value the next _getJson returns */
    public $canned;
    /** @var string[] URLs the adapter asked for */
    public $urls = [];

    protected function _getJson(string $url, array $headers = [], int $timeout = 8): ?array
    {
        $this->urls[] = $url;
        return $this->canned;
    }
}

/**
 * An AWS-Location subclass with the signed-POST hop (_call) stubbed to a canned decoded body, so the
 * request-shape (_textBody) and response-parse (_mapPlace) paths run without AWS. Records the op + body.
 */
class Tiger_Test_AwsAdapter extends Tiger_Location_Adapter_Aws
{
    /** @var mixed the value the next _call returns */
    public $canned;
    /** @var array<int,array{op:string,body:array}> */
    public $calls = [];

    protected function _call(string $op, array $body): ?array
    {
        $this->calls[] = ['op' => $op, 'body' => $body];
        return $this->canned;
    }
}
