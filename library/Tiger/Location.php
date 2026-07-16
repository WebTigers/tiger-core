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

    /**
     * Register (or replace) a provider name -> adapter class.
     *
     * @param  string $name  the provider name (case-insensitive)
     * @param  string $class the adapter class to instantiate for it
     * @return void
     */
    public static function register(string $name, string $class): void
    {
        self::$_adapters[strtolower($name)] = $class;
    }

    /**
     * Suggest address matches for a partial query via the configured address provider.
     *
     * @param  string $query the partial address text
     * @param  array  $opts  provider options (e.g. country bias)
     * @return Tiger_Location_Place[] the suggestions, or [] when unconfigured or on error
     */
    public function suggest(string $query, array $opts = []): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_SUGGEST, 'address');
        if (!$a) { return []; }
        try { return $a->suggest($query, $opts); } catch (Throwable $e) { return []; }
    }

    /**
     * Geocode a query to structured place(s) via the configured address provider.
     *
     * @param  string $query the address text to geocode
     * @param  array  $opts  provider options (e.g. country bias)
     * @return Tiger_Location_Place[] the matches, or [] when unconfigured or on error
     */
    public function geocode(string $query, array $opts = []): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_GEOCODE, 'address');
        if (!$a) { return []; }
        try { return $a->geocode($query, $opts); } catch (Throwable $e) { return []; }
    }

    /**
     * Reverse-geocode a coordinate to the nearest place via the configured address provider.
     *
     * @param  float $lat  the latitude
     * @param  float $lng  the longitude
     * @param  array $opts provider options
     * @return Tiger_Location_Place|null the place, or null when unconfigured or on error
     */
    public function reverse(float $lat, float $lng, array $opts = []): ?Tiger_Location_Place
    {
        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_REVERSE, 'address');
        if (!$a) { return null; }
        try { return $a->reverse($lat, $lng, $opts); } catch (Throwable $e) { return null; }
    }

    /**
     * Geolocate an IP address via the configured IP provider.
     *
     * @param  string $ip   the IP address to look up
     * @param  array  $opts provider options
     * @return Tiger_Location_Place|null the place, or null on an invalid IP, unconfigured, or error
     */
    public function ip(string $ip, array $opts = []): ?Tiger_Location_Place
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return null; }

        // Cache first — an IP's country doesn't change, so no consumer ever hammers the provider on the
        // request path (a positive result is cached for cache_ttl, a negative for a shorter window).
        $provider = (string) self::_config('location.ip.provider', '');
        [$found, $place] = self::_cacheGet($provider, $ip);
        if ($found) { return $place; }

        $a = $this->_adapterFor(Tiger_Location_Adapter_Interface::CAP_IP, 'ip');
        $place = null;
        if ($a) { try { $place = $a->ip($ip, $opts); } catch (Throwable $e) { $place = null; } }
        self::_cacheSet($provider, $ip, $place);
        return $place;
    }

    /** The configured adapter for an operation group, if it exists and supports $cap. */
    protected function _adapterFor(string $cap, string $group): ?Tiger_Location_Adapter_Interface
    {
        $provider = (string) self::_config("location.{$group}.provider", '');
        if ($provider === '') { return null; }
        $a = $this->_adapter($provider);
        return ($a && $a->supports($cap)) ? $a : null;
    }

    protected function _adapter(string $name): ?Tiger_Location_Adapter_Interface
    {
        $name = strtolower($name);
        if (array_key_exists($name, $this->_instances)) { return $this->_instances[$name]; }
        $class = self::$_adapters[$name] ?? null;
        $cfg   = self::_decryptSecrets((array) self::_config("location.adapters.{$name}", []));
        $inst  = ($class && class_exists($class, true)) ? new $class($cfg) : null;
        $this->_instances[$name] = ($inst instanceof Tiger_Location_Adapter_Interface) ? $inst : null;
        return $this->_instances[$name];
    }

    // -- admin settings surface (the System > Location tab) ------------------------------------------

    /**
     * Registered adapters, optionally filtered to those supporting a capability. Each entry:
     * ['name','label','caps','fields'] — so the admin screen can render a provider picker + each
     * provider's own config fields (declared by the adapter) with zero per-adapter UI code.
     *
     * @param  string|null $cap a CAP_* constant to filter by (e.g. CAP_IP), or null for all
     * @return array<string,array>
     */
    public static function adapters(?string $cap = null): array
    {
        $out = [];
        foreach (self::$_adapters as $name => $class) {
            if (!class_exists($class, true)) { continue; }
            try { $inst = new $class(self::_decryptSecrets((array) self::_config("location.adapters.{$name}", []))); }
            catch (Throwable $e) { continue; }
            if (!($inst instanceof Tiger_Location_Adapter_Interface)) { continue; }
            if ($cap !== null && !$inst->supports($cap)) { continue; }
            $out[$name] = [
                'name'   => $name,
                'label'  => method_exists($inst, 'label') ? $inst->label() : ucfirst($name),
                'caps'   => $inst->capabilities(),
                'fields' => method_exists($inst, 'fields') ? $inst->fields() : [],
            ];
        }
        return $out;
    }

    /**
     * Current settings for the admin form: the selected providers, the cache TTL, the registered
     * adapters (structure), and each adapter's current field values (secret fields masked to a `has` flag).
     *
     * @return array
     */
    public static function settings(): array
    {
        $adapters = self::adapters();
        $values   = [];
        foreach ($adapters as $name => $a) {
            $cfg = (array) self::_config("location.adapters.{$name}", []);
            $fv  = [];
            foreach ($a['fields'] as $f) {
                $k = $f['key'];
                if (($f['type'] ?? 'text') === 'secret') {
                    $fv[$k] = ['has' => (!empty($cfg[$k]) || !empty($cfg[$k . '_enc']))];
                } else {
                    $fv[$k] = ['value' => (isset($cfg[$k]) && is_scalar($cfg[$k])) ? (string) $cfg[$k] : ''];
                }
            }
            $values[$name] = $fv;
        }
        return [
            'ip_provider'      => (string) self::_config('location.ip.provider', 'ipapi'),
            'address_provider' => (string) self::_config('location.address.provider', 'nominatim'),
            'cache_ttl'        => (int) self::_config('location.cache_ttl', 86400),
            'adapters'         => $adapters,
            'values'           => $values,
        ];
    }

    /**
     * Persist location settings to the config tier (global, live-override). Provider selections + cache
     * TTL + each adapter's fields; a `secret` field is encrypted at rest (Tiger_Crypto) and a BLANK
     * secret keeps the current one. Shared by the core System screen (and any module that surfaces it).
     *
     * @param  array $values ip_provider, address_provider, cache_ttl, adapters[<name>][<field>]
     * @return void
     */
    public static function saveSettings(array $values): void
    {
        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;

        if (array_key_exists('ip_provider', $values))      { $cfg->set($g, '', 'tiger.location.ip.provider', preg_replace('/[^a-z0-9_-]/i', '', (string) $values['ip_provider'])); }
        if (array_key_exists('address_provider', $values)) { $cfg->set($g, '', 'tiger.location.address.provider', preg_replace('/[^a-z0-9_-]/i', '', (string) $values['address_provider'])); }
        if (array_key_exists('cache_ttl', $values))        { $cfg->set($g, '', 'tiger.location.cache_ttl', (string) max(0, (int) $values['cache_ttl'])); }

        $submitted = (isset($values['adapters']) && is_array($values['adapters'])) ? $values['adapters'] : [];
        foreach (self::adapters() as $name => $a) {
            if (!isset($submitted[$name]) || !is_array($submitted[$name])) { continue; }
            foreach ($a['fields'] as $f) {
                $k = $f['key'];
                if (!array_key_exists($k, $submitted[$name])) { continue; }
                $val  = (string) $submitted[$name][$k];
                $base = "tiger.location.adapters.{$name}.{$k}";
                if (($f['type'] ?? 'text') === 'secret') {
                    if ($val === '') { continue; }   // blank = keep the existing secret
                    if (class_exists('Tiger_Crypto') && Tiger_Crypto::isConfigured()) {
                        $cfg->set($g, '', $base . '_enc', Tiger_Crypto::encrypt($val));
                    } else {
                        $cfg->set($g, '', $base, $val);
                    }
                } else {
                    $cfg->set($g, '', $base, trim($val));
                }
            }
        }
    }

    /**
     * Live IP lookup for the admin "Test" button. Builds the given provider from its saved config with
     * any non-empty form overrides (so you can test a key you just typed, or the saved one if blank).
     * Bypasses the cache. Returns ['ok'=>bool, 'country','city','label'|'error'].
     *
     * @param  string $ip
     * @param  string $provider  the adapter name to test
     * @param  array  $formConfig field overrides from the form (endpoint, key, …)
     * @return array
     */
    public static function test(string $ip, string $provider, array $formConfig = []): array
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return ['ok' => false, 'error' => 'Enter a valid IP address.']; }
        $provider = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $provider));
        $class    = self::$_adapters[$provider] ?? null;
        if (!$class || !class_exists($class, true)) { return ['ok' => false, 'error' => 'Unknown provider.']; }

        $cfg = self::_decryptSecrets((array) self::_config("location.adapters.{$provider}", []));
        foreach ($formConfig as $k => $v) { if ($v !== '' && $v !== null) { $cfg[$k] = (string) $v; } }

        $inst = new $class($cfg);
        if (!($inst instanceof Tiger_Location_Adapter_Interface) || !$inst->supports(Tiger_Location_Adapter_Interface::CAP_IP)) {
            return ['ok' => false, 'error' => 'That provider does not support IP lookups.'];
        }
        try { $place = $inst->ip($ip); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (!$place) { return ['ok' => false, 'error' => 'No result — check the endpoint/key, or the IP may be private/unknown.']; }
        return ['ok' => true, 'country' => (string) $place->country, 'city' => (string) ($place->city ?? ''), 'label' => (string) ($place->label ?? '')];
    }

    // -- helpers -------------------------------------------------------------------------------------

    /** Turn any '<field>_enc' (a secret encrypted at rest) into its plaintext '<field>' for the adapter. */
    protected static function _decryptSecrets(array $cfg): array
    {
        if (!class_exists('Tiger_Crypto') || !Tiger_Crypto::isConfigured()) { return $cfg; }
        foreach ($cfg as $k => $v) {
            if (is_string($k) && substr($k, -4) === '_enc' && is_string($v) && $v !== '') {
                try { $cfg[substr($k, 0, -4)] = Tiger_Crypto::decrypt($v); } catch (Throwable $e) { /* leave unset */ }
            }
        }
        return $cfg;
    }

    // -- IP lookup cache (APCu; keyed by provider+IP so a provider switch re-resolves) ---------------

    private static function _apcuOn(): bool
    {
        return function_exists('apcu_fetch') && (bool) ini_get('apc.enabled');
    }

    private static function _cacheTtl(): int
    {
        return (int) self::_config('location.cache_ttl', 86400);
    }

    /** @return array{0:bool,1:?Tiger_Location_Place} [found, place] */
    private static function _cacheGet(string $provider, string $ip): array
    {
        if (self::_cacheTtl() <= 0 || !self::_apcuOn()) { return [false, null]; }
        $ok = false;
        $v  = @apcu_fetch('tiger:loc:ip:' . $provider . ':' . $ip, $ok);
        if (!$ok) { return [false, null]; }
        return [true, ($v instanceof Tiger_Location_Place) ? $v : null];
    }

    private static function _cacheSet(string $provider, string $ip, ?Tiger_Location_Place $place): void
    {
        $ttl = self::_cacheTtl();
        if ($ttl <= 0 || !self::_apcuOn()) { return; }
        // Negative results expire sooner — a failed/unknown lookup may succeed once configured.
        @apcu_store('tiger:loc:ip:' . $provider . ':' . $ip, $place, $place ? $ttl : min($ttl, 3600));
    }

    /** Read a dotted key under the `tiger` config; returns a scalar, an array (toArray), or $default. */
    protected static function _config(string $dotKey, $default = null)
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
