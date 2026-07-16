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

    /**
     * Store the adapter's config (from tiger.location.adapters.<name>).
     *
     * @param  array $config the adapter's config map
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->_config = $config;
    }

    /**
     * The CAP_* operations this adapter supports (none by default).
     *
     * @return array a list of CAP_* capability constants
     */
    public function capabilities(): array
    {
        return [];
    }

    /**
     * Does this adapter support a CAP_* operation?
     *
     * @param  string $capability the CAP_* capability to check
     * @return bool               true if the operation is supported
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * A human label for the provider picker (default: the class suffix). Override for a nice name.
     *
     * @return string
     */
    public function label(): string
    {
        $p = strrpos(static::class, '_');
        return $p !== false ? substr(static::class, $p + 1) : static::class;
    }

    /**
     * The adapter's SETTABLE config fields, for the admin Location screen — so a module's new adapter
     * surfaces its own settings with zero UI code. Each field: ['key'=>, 'label'=>, 'type'=>'text'|'secret',
     * optional 'placeholder'/'help']. Values live under tiger.location.adapters.<name>.<key>; a 'secret'
     * field is stored encrypted at rest. Default: none.
     *
     * @return array<int,array>
     */
    public function fields(): array
    {
        return [];
    }

    // Unsupported by default — concrete adapters override what they can do.

    /**
     * Suggest address matches (unsupported here — override in a capable adapter).
     *
     * @param  string $query the partial address text
     * @param  array  $opts  provider options
     * @return Tiger_Location_Place[] the suggestions
     * @throws Tiger_Location_Exception always, unless the adapter overrides this
     */
    public function suggest(string $query, array $opts = []): array { throw $this->_unsupported(self::CAP_SUGGEST); }

    /**
     * Geocode free text (unsupported here — override in a capable adapter).
     *
     * @param  string $query the address text to geocode
     * @param  array  $opts  provider options
     * @return Tiger_Location_Place[] the matches
     * @throws Tiger_Location_Exception always, unless the adapter overrides this
     */
    public function geocode(string $query, array $opts = []): array { throw $this->_unsupported(self::CAP_GEOCODE); }

    /**
     * Reverse-geocode a coordinate (unsupported here — override in a capable adapter).
     *
     * @param  float $lat  the latitude
     * @param  float $lng  the longitude
     * @param  array $opts provider options
     * @return Tiger_Location_Place|null the nearest place
     * @throws Tiger_Location_Exception always, unless the adapter overrides this
     */
    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place { throw $this->_unsupported(self::CAP_REVERSE); }

    /**
     * Geolocate an IP (unsupported here — override in a capable adapter).
     *
     * @param  string $ip   the IP address to look up
     * @param  array  $opts provider options
     * @return Tiger_Location_Place|null the place
     * @throws Tiger_Location_Exception always, unless the adapter overrides this
     */
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
