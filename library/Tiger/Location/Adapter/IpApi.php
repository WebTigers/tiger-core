<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location_Adapter_IpApi — approximate location for an IP address (CAP_IP only).
 *
 * Backed by ip-api.com, which is **free for non-commercial / development use** (no key, but
 * rate-limited and http-only on the free tier). It is deliberately the *dev default* so a fresh
 * install has working IP geolocation with zero setup — but it should NOT be pointed at in
 * production. For production, swap in a paid vendor (the endpoint + field mapping are the only
 * things that change): ipinfo.io, ipgeolocation.io, ipstack, or ip2location are all drop-in
 * alternatives, and none carry MaxMind's licensing baggage. (AskLevi runs a paid, non-MaxMind
 * vendor in prod for exactly this reason.) Because the whole thing is just an endpoint + a small
 * field map, retargeting it is a config change, not a rewrite.
 *
 * Config (tiger.location.adapters.ipapi.*):
 *   - endpoint : base URL (default 'http://ip-api.com/json')
 *   - key      : optional API key (appended as &key=… for paid/keyed endpoints)
 *
 * Every failure degrades to null so the caller can carry on without location.
 *
 * @api
 */
class Tiger_Location_Adapter_IpApi extends Tiger_Location_Adapter_Abstract
{
    const DEFAULT_ENDPOINT = 'http://ip-api.com/json';

    /** The ip-api fields we request (keeps the payload tight + the mapping stable). */
    const FIELDS = 'status,country,countryCode,region,regionName,city,zip,lat,lon,query';

    public function capabilities(): array
    {
        return [self::CAP_IP];
    }

    /**
     * Resolve an IP to an approximate Tiger_Location_Place, or null when the provider can't
     * place it (private/invalid IP, rate-limit, network error, …).
     */
    public function ip(string $ip, array $opts = []): ?Tiger_Location_Place
    {
        $endpoint = rtrim((string) $this->_cfg('endpoint', self::DEFAULT_ENDPOINT), '/');
        if ($endpoint === '' || $ip === '') {
            return null;
        }

        $url = $endpoint . '/' . rawurlencode($ip) . '?fields=' . self::FIELDS;
        $key = $this->_cfg('key');
        if (!empty($key)) {
            $url .= '&key=' . rawurlencode((string) $key);
        }

        $data = $this->_getJson($url);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        $city       = isset($data['city']) ? (string) $data['city'] : null;
        $regionName = isset($data['regionName']) ? (string) $data['regionName'] : null;
        $country    = isset($data['countryCode']) ? (string) $data['countryCode'] : null;   // already alpha-2

        // Assemble a friendly one-line label from whatever pieces we got.
        $label = trim(implode(', ', array_filter([$city, $regionName])) . ' ' . (string) $country);
        $label = trim($label, ', ');

        return new Tiger_Location_Place([
            'city'      => $city,
            'region'    => $regionName,
            'postal'    => isset($data['zip']) ? (string) $data['zip'] : null,
            'country'   => $country,
            'latitude'  => isset($data['lat']) ? (float) $data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float) $data['lon'] : null,
            'ip'        => isset($data['query']) ? (string) $data['query'] : $ip,
            'label'     => $label !== '' ? $label : null,
            'type'      => 'ip',
            'source'    => 'ipapi',
            'raw'       => $data,
        ]);
    }
}
