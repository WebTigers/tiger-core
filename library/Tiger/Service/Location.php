<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Service_Location — the Location Service over /api.
 *
 * A public core service (reached as module=tiger & service=location, ACL-allowed to guest in
 * core acl.ini) so public forms — e.g. the signup address autocomplete — can look up
 * addresses. A thin wrapper over Tiger_Location that returns normalized Place payloads.
 *
 *   method=suggest  q=<partial>        -> { results: [Place, …] }   (autocomplete)
 *   method=reverse  lat=<>  lng=<>     -> { place: Place|null }      (coords -> address)
 *   method=ip                          -> { place: Place|null }      (the VISITOR's own IP)
 *
 * @api
 */
class Tiger_Service_Location extends Tiger_Service_Service
{
    public function suggest(array $params): void
    {
        $opts = [];
        $cc = preg_replace('/[^A-Za-z]/', '', (string) ($params['country'] ?? ''));
        if ($cc !== '') { $opts['country'] = strtoupper($cc); }   // bias to the chosen country
        $places = (new Tiger_Location())->suggest((string) ($params['q'] ?? ''), $opts);
        $this->_success(['results' => array_map(static function ($p) { return $p->toArray(); }, $places)]);
    }

    public function reverse(array $params): void
    {
        $place = (new Tiger_Location())->reverse((float) ($params['lat'] ?? 0), (float) ($params['lng'] ?? 0));
        $this->_success(['place' => $place ? $place->toArray() : null]);
    }

    public function ip(array $params): void
    {
        // Always the requester's own IP (normalized from X-Forwarded-For behind the ALB) —
        // guests can't probe arbitrary addresses through this endpoint.
        $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $place = (new Tiger_Location())->ip($ip);
        $this->_success(['place' => $place ? $place->toArray() : null]);
    }
}
