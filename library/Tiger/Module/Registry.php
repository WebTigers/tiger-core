<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Registry — the client for the open Vendor Registry (WebTigers/Vendors).
 *
 * The registry is just a public git repo: one JSON file per module under /data, compiled by
 * CI into a single `index.json` search index Tiger fetches + caches (a few times/day). No
 * server, no DB — GitHub is the infrastructure. If the registry isn't reachable yet (the repo
 * doesn't exist / offline), search returns empty and the admin falls back to Install-from-URL.
 *
 * The index URL is config-overridable (`tiger.modules.registry`) so a fork can point Tiger at
 * a different catalog — the whole thing is decentralized by design.
 *
 * @api
 */
class Tiger_Module_Registry
{
    const DEFAULT_INDEX = 'https://raw.githubusercontent.com/WebTigers/Vendors/main/data/index.json';
    const CACHE_TTL     = 10800;   // 3h — a few refreshes a day, per the discovery model
    const CACHE_FILE    = 'registry-index.json';

    /** True if the registry index is reachable (fetch or fresh cache). */
    public static function available()
    {
        return self::index() !== null;
    }

    /** Search the registry; [] when unavailable or no match. Matches name/slug/description/keywords. */
    public static function search($query)
    {
        $index = self::index();
        if (!$index) {
            return [];
        }
        $modules = isset($index['modules']) && is_array($index['modules']) ? $index['modules'] : (array) $index;
        $q = strtolower(trim((string) $query));

        $out = [];
        foreach ($modules as $m) {
            if (!is_array($m)) { continue; }
            if ($q === '') { $out[] = $m; continue; }
            $hay = strtolower(($m['name'] ?? '') . ' ' . ($m['slug'] ?? '') . ' ' . ($m['description'] ?? '')
                . ' ' . implode(' ', (array) ($m['keywords'] ?? [])) . ' ' . ($m['author'] ?? ''));
            if (strpos($hay, $q) !== false) { $out[] = $m; }
        }
        return $out;
    }

    /** The (cached) registry index array, or null if unreachable. */
    public static function index()
    {
        $cache = self::_cacheFile();
        if ($cache && is_file($cache) && (time() - filemtime($cache)) < self::CACHE_TTL) {
            $j = json_decode((string) @file_get_contents($cache), true);
            if (is_array($j)) { return $j; }
        }

        $body = Tiger_Module_Github::get(self::indexUrl());
        if ($body === null) {
            // serve a stale cache if we have one (offline resilience), else null
            if ($cache && is_file($cache)) {
                $j = json_decode((string) @file_get_contents($cache), true);
                return is_array($j) ? $j : null;
            }
            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j)) { return null; }
        if ($cache) { @file_put_contents($cache, $body); }
        return $j;
    }

    public static function indexUrl()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $t   = $cfg ? $cfg->get('tiger') : null;
        $mod = $t ? $t->get('modules') : null;
        $url = ($mod && $mod->get('registry')) ? (string) $mod->registry : '';
        return $url !== '' ? $url : self::DEFAULT_INDEX;
    }

    protected static function _cacheFile()
    {
        $base = defined('APPLICATION_ROOT') ? rtrim(APPLICATION_ROOT, '/') : rtrim(getcwd(), '/');
        $dir  = $base . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir . '/' . self::CACHE_FILE;
    }
}
