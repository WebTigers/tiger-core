<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Profile_Types — the configurable contact/address type lists for the profile tabs.
 *
 * Tiger ships base defaults (Phone/Email/Other, Home/Office/Mailing) but the sets are CONFIG, so a
 * product-builder tailors them per install without code — `tiger.profile.contact.types` and
 * `tiger.profile.address.types` are comma-separated lists resolved through the normal config
 * cascade (core.ini → application.ini → local.ini → DB). Each item becomes a `value => label`
 * option where the value is the lowercased slug (stored on the row) and the label is shown as-is.
 *
 * Used by both the controller (render the <select>) and the services (validate the submitted type),
 * so a submitted type is always one the install actually offers.
 *
 * @api
 */
class Tiger_Profile_Types
{
    const CONTACT_KEY     = 'tiger.profile.contact.types';
    const ADDRESS_KEY     = 'tiger.profile.address.types';
    const CONTACT_DEFAULT = 'Phone,Email,Other';
    const ADDRESS_DEFAULT = 'Home,Office,Mailing';

    /** @return array<string,string> value(slug) => label for contact types */
    public static function contact()
    {
        return self::_parse(self::CONTACT_KEY, self::CONTACT_DEFAULT);
    }

    /** @return array<string,string> value(slug) => label for address types */
    public static function address()
    {
        return self::_parse(self::ADDRESS_KEY, self::ADDRESS_DEFAULT);
    }

    /**
     * Parse a comma-separated config list into a value=>label map, falling back to the default.
     *
     * @param  string $configKey dotted config key
     * @param  string $default   comma-separated fallback
     * @return array<string,string>
     */
    protected static function _parse($configKey, $default)
    {
        $raw   = (string) self::_cfg($configKey, $default);
        $items = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if (!$items) {
            $items = array_values(array_filter(array_map('trim', explode(',', $default))));
        }
        $out = [];
        foreach ($items as $label) {
            $out[strtolower($label)] = $label;   // value = slug, label = as-configured
        }
        return $out;
    }

    /**
     * Resolve a dotted config key from the merged Zend_Config, or the default.
     *
     * @param  string $dotted
     * @param  mixed  $default
     * @return mixed
     */
    protected static function _cfg($dotted, $default)
    {
        try {
            $node = Zend_Registry::get('Zend_Config');
        } catch (Throwable $e) {
            return $default;
        }
        foreach (explode('.', $dotted) as $k) {
            if (!($node instanceof Zend_Config)) {
                return $default;
            }
            $node = $node->get($k);
            if ($node === null) {
                return $default;
            }
        }
        return $node instanceof Zend_Config ? $default : $node;
    }
}
