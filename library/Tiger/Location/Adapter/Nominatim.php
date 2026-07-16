<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location_Adapter_Nominatim — OpenStreetMap geocoding (the free/zero-config default).
 *
 * Backed by Nominatim (search + reverse). No API key, so signup autocomplete works out of the
 * box for dev/demo. Two caveats to respect in production: the public instance is rate-limited
 * (~1 req/s) and requires a descriptive User-Agent (both handled here) — for real traffic,
 * self-host Nominatim or point `endpoint` at a hosted OSM provider (LocationIQ, Geoapify), or
 * switch the address provider to AWS/Google/Mapbox.
 *
 * Config: 'endpoint' (default https://nominatim.openstreetmap.org), 'email' (optional, added
 * to requests per OSM policy for higher volume).
 *
 * @api
 */
class Tiger_Location_Adapter_Nominatim extends Tiger_Location_Adapter_Abstract
{
    /**
     * The CAP_* operations this adapter supports (suggest, geocode, reverse).
     *
     * @return array a list of CAP_* capability constants
     */
    public function capabilities(): array
    {
        return [self::CAP_SUGGEST, self::CAP_GEOCODE, self::CAP_REVERSE];
    }

    public function label(): string
    {
        return 'Nominatim (OpenStreetMap)';
    }

    public function fields(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint', 'type' => 'text', 'placeholder' => 'https://nominatim.openstreetmap.org'],
            ['key' => 'email', 'label' => 'Contact email', 'type' => 'text',
             'help' => 'Optional — OSM asks for a contact email at higher request volumes.'],
        ];
    }

    /**
     * Autocomplete a partial address via Nominatim search.
     *
     * @param  string $query the partial address text
     * @param  array  $opts  options (e.g. 'limit', 'country' to bias results)
     * @return Tiger_Location_Place[] the suggestions
     */
    public function suggest(string $query, array $opts = []): array
    {
        return $this->_search($query, (int) ($opts['limit'] ?? 5), $opts);
    }

    /**
     * Geocode free text to structured place(s) via Nominatim search.
     *
     * @param  string $query the address text to geocode
     * @param  array  $opts  options (e.g. 'limit', 'country' to bias results)
     * @return Tiger_Location_Place[] the matches
     */
    public function geocode(string $query, array $opts = []): array
    {
        return $this->_search($query, (int) ($opts['limit'] ?? 5), $opts);
    }

    /**
     * Reverse-geocode a coordinate to the nearest address via Nominatim.
     *
     * @param  float $lat  the latitude
     * @param  float $lng  the longitude
     * @param  array $opts provider options
     * @return Tiger_Location_Place|null the nearest place, or null on no match
     */
    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place
    {
        $url = $this->_base() . '/reverse?' . http_build_query(array_filter([
            'lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'addressdetails' => 1,
            'email' => $this->_cfg('email'),
        ]));
        $r = $this->_getJson($url);
        return (is_array($r) && !empty($r['address'])) ? $this->_place($r) : null;
    }

    /** @return Tiger_Location_Place[] */
    protected function _search(string $query, int $limit, array $opts = []): array
    {
        $url = $this->_base() . '/search?' . http_build_query(array_filter([
            'q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => max(1, min(10, $limit)),
            // Country bias: restrict suggestions to the chosen country (faster + relevant).
            'countrycodes' => !empty($opts['country']) ? strtolower((string) $opts['country']) : null,
            'email' => $this->_cfg('email'),
        ]));
        $rows = $this->_getJson($url) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) { $out[] = $this->_place($r); }
        }
        return $out;
    }

    protected function _base(): string
    {
        return rtrim((string) $this->_cfg('endpoint', 'https://nominatim.openstreetmap.org'), '/');
    }

    /** Map a Nominatim result to the normalized Place. */
    protected function _place(array $r): Tiger_Location_Place
    {
        $a = $r['address'] ?? [];
        $line1 = trim((string) (($a['house_number'] ?? '') . ' ' . ($a['road'] ?? '')));
        $city  = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet'] ?? $a['municipality'] ?? null;

        return new Tiger_Location_Place([
            'id'        => isset($r['place_id']) ? (string) $r['place_id'] : null,
            'label'     => $r['display_name'] ?? null,
            'line1'     => $line1 !== '' ? $line1 : null,
            'city'      => $city,
            'region'    => $a['state'] ?? $a['region'] ?? null,
            'postal'    => $a['postcode'] ?? null,
            'country'   => isset($a['country_code']) ? strtoupper((string) $a['country_code']) : null,
            'latitude'  => isset($r['lat']) ? (float) $r['lat'] : null,
            'longitude' => isset($r['lon']) ? (float) $r['lon'] : null,
            'type'      => $r['addresstype'] ?? $r['type'] ?? 'address',
            'source'    => 'nominatim',
            'raw'       => $r,
        ]);
    }
}
