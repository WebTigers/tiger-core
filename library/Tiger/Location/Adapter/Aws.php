<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location_Adapter_Aws — AWS Location Service (Places) adapter.
 *
 * Supports autocomplete (CAP_SUGGEST), forward geocoding (CAP_GEOCODE), and reverse geocoding
 * (CAP_REVERSE) via the AWS Location Service "Places" APIs. The underlying place data is
 * **Esri/HERE-backed** (whichever provider your Place Index is configured for) — so before this
 * adapter does anything useful you must **provision a Place Index in the AWS console** (or via
 * IaC) and name it in config (`place_index`). Without an index — or without credentials/region —
 * every operation degrades gracefully to empty results ([]) / null rather than throwing.
 *
 * Requests are POST JSON to `places.geo.{region}.amazonaws.com`, **SigV4-signed** for the `geo`
 * service. The sibling S3 adapter (Tiger_Media_Storage_S3) leans on the aws-sdk-php client to
 * sign for it; we don't pull the SDK in here (Location is a much smaller surface), so the SigV4
 * canonical-request → string-to-sign → signing-key → Authorization steps are replicated inline
 * below (see _signedPost / _sigV4Headers). Credentials come from config (`key`/`secret`/`token`)
 * and, when absent, fall back to the standard environment variables (AWS_ACCESS_KEY_ID /
 * AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN) — the same env fallback the S3 adapter relies on.
 *
 * Config (tiger.location.adapters.aws.*):
 *   - region      : AWS region (e.g. 'us-east-1')
 *   - place_index : the AWS Location Service Place Index name
 *   - key         : AWS access key id     (else env AWS_ACCESS_KEY_ID)
 *   - secret      : AWS secret access key  (else env AWS_SECRET_ACCESS_KEY)
 *   - token       : AWS session token      (else env AWS_SESSION_TOKEN) — optional (STS creds)
 *
 * @api
 */
class Tiger_Location_Adapter_Aws extends Tiger_Location_Adapter_Abstract
{
    const SERVICE = 'geo';
    const MAX_RESULTS = 5;

    /**
     * AWS Location returns country as ISO-3166-1 **alpha-3**; Tiger_Location_Place wants alpha-2.
     * Small common-set map — anything not here falls through as the raw code.
     */
    protected static $_alpha3to2 = [
        'USA' => 'US', 'US' => 'US', 'CAN' => 'CA', 'GBR' => 'GB', 'IRL' => 'IE',
        'AUS' => 'AU', 'NZL' => 'NZ', 'DEU' => 'DE', 'FRA' => 'FR', 'ESP' => 'ES',
        'ITA' => 'IT', 'NLD' => 'NL', 'SWE' => 'SE', 'NOR' => 'NO', 'JPN' => 'JP',
        'MEX' => 'MX', 'BRA' => 'BR',
    ];

    public function capabilities(): array
    {
        return [self::CAP_SUGGEST, self::CAP_GEOCODE, self::CAP_REVERSE];
    }

    /**
     * Autocomplete a partial address. Returns Tiger_Location_Place[] of lightweight suggestions
     * (label + optional PlaceId to feed a follow-up geocode). Empty on any error.
     *
     * @return Tiger_Location_Place[]
     */
    public function suggest(string $query, array $opts = []): array
    {
        if (trim($query) === '') {
            return [];
        }
        $data = $this->_call('search/suggestions', ['Text' => $query, 'MaxResults' => self::MAX_RESULTS]);
        if (!is_array($data) || empty($data['Results'])) {
            return [];
        }
        $places = [];
        foreach ($data['Results'] as $result) {
            $text = $result['Text'] ?? null;
            if ($text === null) {
                continue;
            }
            $places[] = new Tiger_Location_Place([
                'label'  => (string) $text,
                'id'     => isset($result['PlaceId']) ? (string) $result['PlaceId'] : null,
                'type'   => 'suggestion',
                'source' => 'aws',
                'raw'    => $result,
            ]);
        }
        return $places;
    }

    /**
     * Geocode free text to structured address(es) with coordinates. Empty on any error.
     *
     * @return Tiger_Location_Place[]
     */
    public function geocode(string $query, array $opts = []): array
    {
        if (trim($query) === '') {
            return [];
        }
        $data = $this->_call('search/text', ['Text' => $query, 'MaxResults' => self::MAX_RESULTS]);
        if (!is_array($data) || empty($data['Results'])) {
            return [];
        }
        $places = [];
        foreach ($data['Results'] as $result) {
            if (!empty($result['Place'])) {
                $places[] = $this->_mapPlace($result['Place']);
            }
        }
        return $places;
    }

    /**
     * Reverse-geocode a coordinate to the nearest address. Null on any error / no match.
     * Note AWS takes position as [longitude, latitude].
     */
    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place
    {
        $data = $this->_call('search/position', ['Position' => [$lng, $lat], 'MaxResults' => 1]);
        if (!is_array($data) || empty($data['Results'][0]['Place'])) {
            return null;
        }
        return $this->_mapPlace($data['Results'][0]['Place']);
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Build + sign + POST a Places request to /places/v0/indexes/{index}/{op}. Returns the decoded
     * JSON array, or null on any missing config / network / non-2xx error (callers turn that into
     * [] or null — never a thrown exception to the UI).
     */
    protected function _call(string $op, array $body): ?array
    {
        $region = (string) $this->_cfg('region', '');
        $index  = (string) $this->_cfg('place_index', '');
        $key    = (string) ($this->_cfg('key') ?: getenv('AWS_ACCESS_KEY_ID'));
        $secret = (string) ($this->_cfg('secret') ?: getenv('AWS_SECRET_ACCESS_KEY'));
        $token  = (string) ($this->_cfg('token') ?: getenv('AWS_SESSION_TOKEN'));

        if ($region === '' || $index === '' || $key === '' || $secret === '') {
            return null;   // not provisioned — degrade quietly
        }

        $host = 'places.geo.' . $region . '.amazonaws.com';
        $path = '/places/v0/indexes/' . rawurlencode($index) . '/' . $op;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);

        return $this->_signedPost($host, $region, $path, $json, $key, $secret, $token);
    }

    /**
     * Perform a SigV4-signed POST of a JSON body and JSON-decode the response. Returns the decoded
     * array or null on any error. Signing is done inline (canonical request → string-to-sign →
     * signing key → Authorization header) since we don't load aws-sdk-php here.
     */
    protected function _signedPost(
        string $host,
        string $region,
        string $path,
        string $jsonBody,
        string $key,
        string $secret,
        string $token,
        int $timeout = 8
    ): ?array {
        $headers = $this->_sigV4Headers($host, $region, $path, $jsonBody, $key, $secret, $token);

        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $respBody = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300 || $respBody === false) {
            return null;
        }
        $data = json_decode((string) $respBody, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Compute the AWS SigV4 request headers for a POST to the given host/path with a JSON body.
     * Returns the full curl header list (Authorization + x-amz-date + host + content-type + the
     * optional x-amz-security-token). This is the standard four-step SigV4:
     *   1. canonical request  (method, path, query, canonical headers, signed headers, body hash)
     *   2. string to sign     (algorithm, timestamp, credential scope, hash of canonical request)
     *   3. signing key         (HMAC chain: date → region → service → 'aws4_request')
     *   4. signature + Authorization header
     *
     * @return string[]
     */
    protected function _sigV4Headers(
        string $host,
        string $region,
        string $path,
        string $jsonBody,
        string $key,
        string $secret,
        string $token
    ): array {
        $algorithm = 'AWS4-HMAC-SHA256';
        $now       = gmdate('Ymd\THis\Z');
        $date      = gmdate('Ymd');
        $scope     = $date . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        $bodyHash  = hash('sha256', $jsonBody);
        $contentType = 'application/json';

        // 1. Canonical request. Sign host, content-type, x-amz-content-sha256, x-amz-date and
        //    (when present) x-amz-security-token — headers must be sorted by lowercased name.
        $canonicalHeaders = "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$bodyHash}\n"
            . "x-amz-date:{$now}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        if ($token !== '') {
            $canonicalHeaders .= "x-amz-security-token:{$token}\n";
            $signedHeaders    .= ';x-amz-security-token';
        }
        // Path segments are already URL-safe (rawurlencode'd index); no query string on these POSTs.
        $canonicalRequest = "POST\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$bodyHash}";

        // 2. String to sign.
        $stringToSign = $algorithm . "\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        // 3. Signing key (HMAC chain).
        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        // 4. Signature + Authorization header.
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = $algorithm
            . ' Credential=' . $key . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: ' . $contentType,
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $bodyHash,
            'x-amz-date: ' . $now,
            'Accept: application/json',
        ];
        if ($token !== '') {
            $headers[] = 'x-amz-security-token: ' . $token;
        }
        return $headers;
    }

    /**
     * Map an AWS Location "Place" object to a normalized Tiger_Location_Place.
     * (Geometry.Point is [longitude, latitude]; Country is alpha-3 → mapped to alpha-2.)
     */
    protected function _mapPlace(array $place): Tiger_Location_Place
    {
        // line1 = "AddressNumber Street" (either piece may be missing).
        $line1 = trim(
            (isset($place['AddressNumber']) ? (string) $place['AddressNumber'] . ' ' : '')
            . (isset($place['Street']) ? (string) $place['Street'] : '')
        );

        $point = $place['Geometry']['Point'] ?? null;
        $lng   = (is_array($point) && isset($point[0])) ? (float) $point[0] : null;
        $lat   = (is_array($point) && isset($point[1])) ? (float) $point[1] : null;

        return new Tiger_Location_Place([
            'label'     => isset($place['Label']) ? (string) $place['Label'] : null,
            'line1'     => $line1 !== '' ? $line1 : null,
            'city'      => isset($place['Municipality']) ? (string) $place['Municipality'] : null,
            'region'    => isset($place['Region']) ? (string) $place['Region'] : null,
            'postal'    => isset($place['PostalCode']) ? (string) $place['PostalCode'] : null,
            'country'   => isset($place['Country']) ? $this->_alpha2((string) $place['Country']) : null,
            'latitude'  => $lat,
            'longitude' => $lng,
            'type'      => 'address',
            'source'    => 'aws',
            'raw'       => $place,
        ]);
    }

    /** Map an ISO-3166 alpha-3 country code to alpha-2, falling back to the raw code. */
    protected function _alpha2(string $code): string
    {
        $upper = strtoupper($code);
        return self::$_alpha3to2[$upper] ?? $code;
    }
}
