<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_I18n_Country — the country list, localized + biased-sorted.
 *
 * Reads a standalone data file, library/Tiger/I18n/country.ini (NOT in the config cascade —
 * it's static reference data, loaded lazily here, cached for the request). Each ISO-3166
 * alpha-2 section carries its alpha-3 code and a `sort` weight; a positive weight is a
 * "common" country that floats to the top of the picker (so United States sits at the top,
 * not buried under "U"), and 0 means normal alphabetical placement.
 *
 * The NAME is never stored here: it resolves as a Tiger translation key `country.<ISO2>`
 * (so an install can override it), falling back to CLDR via Zend_Locale (authoritative,
 * localized for every locale — no 250×N strings to hand-maintain).
 *
 * @api
 */
class Tiger_I18n_Country
{
    /** @var array<string,array>|null iso2 => ['iso3'=>string,'sort'=>int] */
    protected static $_data;

    protected static function _data(): array
    {
        if (self::$_data === null) {
            self::$_data = parse_ini_file(__DIR__ . '/country.ini', true) ?: [];
        }
        return self::$_data;
    }

    /** Localized name for a country: translation override (country.<ISO2>) then CLDR. */
    public static function name(string $iso2, ?string $locale = null): string
    {
        $iso2 = strtoupper($iso2);
        $key  = 'country.' . $iso2;
        if (Zend_Registry::isRegistered('Zend_Translate')) {
            $t = Zend_Registry::get('Zend_Translate');
            if ($t->isTranslated($key, false, $locale)) { return $t->translate($key, $locale); }
        }
        $cldr = self::_cldr($locale);
        return $cldr[$iso2] ?? $iso2;
    }

    /** All countries as [iso2 => localized name], biased sort (common first, then A–Z). */
    public static function all(?string $locale = null): array
    {
        $data = self::_data();
        $cldr = self::_cldr($locale);
        $t    = Zend_Registry::isRegistered('Zend_Translate') ? Zend_Registry::get('Zend_Translate') : null;

        $rows = [];
        foreach ($data as $iso2 => $meta) {
            $key  = 'country.' . $iso2;
            $name = ($t && $t->isTranslated($key, false, $locale)) ? $t->translate($key, $locale) : ($cldr[$iso2] ?? $iso2);
            $rows[] = ['iso2' => $iso2, 'sort' => (int) ($meta['sort'] ?? 0), 'name' => $name];
        }
        usort($rows, static function ($a, $b) {
            $ap = $a['sort'] > 0 ? 0 : 1;   // common block before the rest
            $bp = $b['sort'] > 0 ? 0 : 1;
            if ($ap !== $bp) { return $ap - $bp; }
            if ($ap === 0 && $a['sort'] !== $b['sort']) { return $a['sort'] - $b['sort']; }
            return strcasecmp($a['name'], $b['name']);
        });

        $out = [];
        foreach ($rows as $r) { $out[$r['iso2']] = $r['name']; }
        return $out;
    }

    /**
     * <select> multiOptions: a placeholder, then a "Common" optgroup (the biased set), then
     * "All countries" — so the top picks are one tap away and the divider is semantic.
     */
    public static function options(?string $locale = null, string $placeholder = '— Select —'): array
    {
        $data = self::_data();
        $common = $rest = [];
        foreach (self::all($locale) as $iso2 => $name) {
            if ((int) ($data[$iso2]['sort'] ?? 0) > 0) { $common[$iso2] = $name; } else { $rest[$iso2] = $name; }
        }
        $opts = ['' => $placeholder];
        if ($common) { $opts['Common'] = $common; }
        $opts['All countries'] = $rest;
        return $opts;
    }

    /** ISO-3166 alpha-3 for an alpha-2 code (e.g. for providers that want alpha-3). */
    public static function iso3(string $iso2): ?string
    {
        $d = self::_data();
        $v = $d[strtoupper($iso2)]['iso3'] ?? null;
        return ($v !== null && $v !== '') ? strtoupper($v) : null;
    }

    /** Every valid alpha-2 code (e.g. for an InArray validator). */
    public static function codes(): array
    {
        return array_keys(self::_data());
    }

    /** CLDR territory names (alpha-2 => name) for a locale, cached per locale. */
    protected static function _cldr(?string $locale): array
    {
        static $cache = [];
        $key = $locale ?: '_';
        if (!array_key_exists($key, $cache)) {
            $names = [];
            try {
                foreach ((array) Zend_Locale::getTranslationList('territory', $locale) as $code => $name) {
                    if (preg_match('/^[A-Z]{2}$/', (string) $code)) { $names[$code] = $name; }
                }
            } catch (Throwable $e) {
                $names = [];
            }
            $cache[$key] = $names;
        }
        return $cache[$key];
    }
}
