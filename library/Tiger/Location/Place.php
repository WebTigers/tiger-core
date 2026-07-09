<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location_Place — the ONE normalized payload every Location adapter returns.
 *
 * Whether you fed the service a partial address, a lat/lng, or an IP, you get back this same
 * shape, so callers (and the JSON that reaches the browser) never care which provider
 * answered. Any field may be null when a provider can't supply it.
 *
 * @api
 */
class Tiger_Location_Place
{
    /** @var string|null provider place id (feeds a follow-up retrieve/geocode) */
    public $id;
    /** @var string|null human-readable one-line address / label */
    public $label;

    public $line1;
    public $line2;
    public $city;
    /** @var string|null state / province / region */
    public $region;
    public $postal;
    /** @var string|null ISO-3166-1 alpha-2 country code */
    public $country;

    /** @var float|null */
    public $latitude;
    /** @var float|null */
    public $longitude;

    /** @var string|null result kind: address | street | city | poi | ip | … */
    public $type;
    /** @var string|null which adapter/provider produced this */
    public $source;
    /** @var string|null the IP, for ip() lookups */
    public $ip;
    /** @var array raw provider payload (debug / provider-specific extras) */
    public $raw = [];

    public function __construct(array $data = [])
    {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) { $this->$k = $v; }
        }
    }

    /** The stable public payload (no raw provider blob). */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'label'     => $this->label,
            'line1'     => $this->line1,
            'line2'     => $this->line2,
            'city'      => $this->city,
            'region'    => $this->region,
            'postal'    => $this->postal,
            'country'   => $this->country,
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'type'      => $this->type,
            'source'    => $this->source,
            'ip'        => $this->ip,
        ];
    }
}
