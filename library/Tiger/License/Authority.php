<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_License_Authority — the client for a license authority's DOWNLOAD endpoint.
 *
 * When a buyer installs (or updates) a licensed module, the module's declared authority is the only party
 * that can hand out the code: it verifies the license server-side, then mints a SHORT-LIVED, signed
 * download and returns just its location — so the authority never has to proxy the bytes and the seller's
 * repo token never leaves the seller. This client makes that one call and normalizes the reply to a
 * download descriptor `{url, signature, sha256, version}`; the caller (Tiger_Module_Installer) downloads
 * from `url` and verifies the signature before extracting.
 *
 * Vendor-neutral: the authority URL comes from the module's `pricing.authority`; this client knows nothing
 * about who runs it. The transport is an injectable seam so the flow is testable with no network.
 *
 * @api
 * @see Tiger_Module_Installer::installFromAuthority()
 * @see Tiger_License_Checker
 */
class Tiger_License_Authority
{
    /** @var callable|null injected transport: fn(string $url, array $payload): ?array */
    protected static $transport = null;

    /**
     * Swap the transport (tests inject a fake authority). Pass null to reset to real HTTP.
     *
     * @param  callable|null $transport the transport, or null to reset
     * @return void
     */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Reset the injected transport — for test isolation.
     *
     * @return void
     */
    public static function _reset(): void
    {
        self::$transport = null;
    }

    /**
     * Ask an authority to authorize + locate a licensed download. Returns a normalized descriptor, or null
     * when the authority is unreachable, refuses (invalid/lapsed license), or replies without a usable,
     * signed download. A licensed download MUST carry both an http(s) `url` and a `signature`.
     *
     * @param  string $authority the authority base URL (module.json `pricing.authority`)
     * @param  string $key       the buyer's license key
     * @param  string $product   the product/module slug being installed
     * @param  string $domain    the install host (for the authority's domain-binding check)
     * @return array{url:string,signature:string,sha256:?string,version:?string}|null the download descriptor, or null
     */
    public static function download(string $authority, string $key, string $product, string $domain = ''): ?array
    {
        $resp = self::_post(rtrim($authority, '/') . '/download', [
            'key'     => $key,
            'product' => $product,
            'domain'  => $domain,
        ]);
        if (!is_array($resp)) {
            return null;
        }

        $url = (string) ($resp['url'] ?? '');
        $sig = (string) ($resp['signature'] ?? '');
        if ($url === '' || $sig === '' || !preg_match('#^https?://#i', $url)) {
            return null;   // no usable, signed download → treat as a refusal
        }

        return [
            'url'       => $url,
            'signature' => $sig,
            'sha256'    => isset($resp['sha256']) ? (string) $resp['sha256'] : null,
            'version'   => isset($resp['version']) ? (string) $resp['version'] : null,
        ];
    }

    /** POST a JSON payload to the authority and decode the JSON reply, or null on any failure. */
    protected static function _post(string $url, array $payload): ?array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $payload);
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => (string) json_encode($payload),
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
