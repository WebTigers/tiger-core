<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location — the Location Service facade.
 *
 * One entry point for every location lookup. Throw it a partial address (suggest/geocode),
 * a lat/lng (reverse), or an IP (ip); it routes to the configured, *capable* adapter and
 * returns the same Tiger_Location_Place payload regardless of provider. Providers are chosen
 * by config, per operation group:
 *
 *   tiger.location.address.provider = "nominatim"   ; suggest / geocode / reverse
 *   tiger.location.ip.provider      = "ipapi"       ; ip
 *   tiger.location.adapters.<name>.*                ; that adapter's own config
 *
 * Graceful by design: an unset provider, a missing/incapable adapter, or a provider error
 * yields empty results (never a fatal), so a form's autocomplete just quietly does nothing
 * when unconfigured. Register more adapters with Tiger_Location::register().
 *
 * @api
 */
class Tiger_Location
{
    /** @var array<string,string> provider name => adapter class */
    protected static $_adapters = [
        'nominatim' => 'Tiger_Location_Adapter_Nominatim',
        'aws'       => 'Tiger_Location_Adapter_Aws',
        'ipapi'     => 'Tiger_Location_Adapter_IpApi',
    ];

    /** @var array<string,Tiger_Location_Adapter_Interface> */
    protected $_instances = [];

    /** Register (or replace) a provider name -> adapter class. */
    public static function register(string $name, string $class): void
    {
        self::$_adapters[strtolower($name)] = $class;
    }

    /** @return Tiger_Location_Place[] */
    public function suggest(string $query, array $opts = []): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_SUGGEST, 'address');
        if (!$a) { return []; }
        try { return $a->suggest($query, $opts); } catch (Throwable $e) { return []; }
    }

    /** @return Tiger_Location_Place[] */
    public function geocode(string $query, array $opts = []): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_GEOCODE, 'address');
        if (!$a) { return []; }
        try { return $a->geocode($query, $opts); } catch (Throwable $e) { return []; }
    }

    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place
    {
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_REVERSE, 'address');
        if (!$a) { return null; }
        try { return $a->reverse($lat, $lng, $opts); } catch (Throwable $e) { return null; }
    }

    public function ip(string $ip, array $opts = []): ?Tiger_Location_Place
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return null; }
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_IP, 'ip');
        if (!$a) { return null; }
        try { return $a->ip($ip, $opts); } catch (Throwable $e) { return null; }
    }

    /** The configured adapter for an operation group, if it exists and supports $cap. */
    protected function _adapterFor(string $cap, string $group): ?Tiger_Location_Adapter_Interface
    {
        $provider = (string) $this->_config("location.{$group}.provider", '');
        if ($provider === '') { return null; }
        $a = $this->_adapter($provider);
        return ($a && $a->supports($cap)) ? $a : null;
    }

    protected function _adapter(string $name): ?Tiger_Location_Adapter_Interface
    {
        $name = strtolower($name);
        if (array_key_exists($name, $this->_instances)) { return $this->_instances[$name]; }
        $class = self::$_adapters[$name] ?? null;
        $inst  = ($class && class_exists($class, true)) ? new $class((array) $this->_config("location.adapters.{$name}", [])) : null;
        $this->_instances[$name] = ($inst instanceof Tiger_Location_Adapter_Interface) ? $inst : null;
        return $this->_instances[$name];
    }

    /** Read a dotted key under the `tiger` config; returns a scalar, an array (toArray), or $default. */
    protected function _config(string $dotKey, $default = null)
    {
        if (!Zend_Registry::isRegistered('Zend_Config')) { return $default; }
        $node = Zend_Registry::get('Zend_Config')->get('tiger');
        foreach (explode('.', $dotKey) as $seg) {
            if (!$node instanceof Zend_Config) { return $default; }
            $node = $node->get($seg);
            if ($node === null) { return $default; }
        }
        return ($node instanceof Zend_Config) ? $node->toArray() : $node;
    }
}
