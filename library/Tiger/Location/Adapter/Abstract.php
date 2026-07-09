<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location_Adapter_Abstract — base for Location adapters.
 *
 * Every operation defaults to "unsupported" (throws), so a concrete adapter overrides ONLY
 * the ones it provides and lists them in capabilities(). Also carries the adapter's config
 * (from tiger.location.adapters.<name>) and a small JSON-over-HTTP helper the concrete
 * adapters share.
 *
 * @api
 */
abstract class Tiger_Location_Adapter_Abstract implements Tiger_Location_Adapter_Interface
{
    /** @var array adapter config (tiger.location.adapters.<name>.*) */
    protected $_config = [];

    public function __construct(array $config = [])
    {
        $this->_config = $config;
    }

    public function capabilities(): array
    {
        return [];
    }

    /** Does this adapter support a CAP_* operation? */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    // Unsupported by default — concrete adapters override what they can do.
    public function suggest(string $query, array $opts = []): array { throw $this->_unsupported(self::CAP_SUGGEST); }
    public function geocode(string $query, array $opts = []): array { throw $this->_unsupported(self::CAP_GEOCODE); }
    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place { throw $this->_unsupported(self::CAP_REVERSE); }
    public function ip(string $ip, array $opts = []): ?Tiger_Location_Place { throw $this->_unsupported(self::CAP_IP); }

    protected function _unsupported(string $cap): Tiger_Location_Exception
    {
        return new Tiger_Location_Exception(get_class($this) . " does not support '{$cap}'.");
    }

    protected function _cfg(string $key, $default = null)
    {
        return array_key_exists($key, $this->_config) ? $this->_config[$key] : $default;
    }

    /**
     * GET a URL and JSON-decode the body. Returns the decoded array, or null on any error
     * (network, non-2xx, unparseable) — adapters translate that into empty results so the
     * UI degrades gracefully.
     */
    protected function _getJson(string $url, array $headers = [], int $timeout = 8): ?array
    {
        $h = array_merge(['Accept: application/json', 'User-Agent: Tiger-Location/1.0'], $headers);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $h,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code < 200 || $code >= 300 || $body === false) {
            return null;
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }
}
