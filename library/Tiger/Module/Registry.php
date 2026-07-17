<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Registry — the client for the open Vendor Registry (WebTigers/TigerVendors).
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
    const DEFAULT_INDEX     = 'https://raw.githubusercontent.com/WebTigers/TigerVendors/main/data/index.json';
    const DEFAULT_SPONSORS  = 'https://raw.githubusercontent.com/WebTigers/TigerSponsors/main/sponsors.json';
    const CACHE_TTL         = 10800;   // 3h — a few refreshes a day, per the discovery model
    const CACHE_FILE        = 'registry-index.json';
    const CACHE_FILE_SPONS  = 'registry-sponsored.json';

    /** Result orderings for the directory. Default = featured (curated placement floats up). */
    const SORTS = ['featured', 'title', 'latest'];

    /**
     * True if the registry index is reachable (fetch or fresh cache).
     *
     * @param  bool $refresh bypass the cache and re-fetch now
     * @return bool true if the index could be loaded
     */
    public static function available($refresh = false)
    {
        return self::index($refresh) !== null;
    }

    /**
     * Search the registry; [] when unavailable or no match. Matches name/slug/description/
     * keywords/vendor/type. Each hit is enriched with curated placement (priority + a `sponsored`
     * badge) from the sponsored overlay, then ordered by $sort.
     *
     * @param  string $query   the search term ('' returns all modules)
     * @param  string $sort    'featured' (default: sponsored priority, then title), 'title', or 'latest'
     * @param  bool   $refresh bypass the cache and re-fetch the index (+ sponsor overlay) now
     * @return array the matching module entries
     */
    public static function search($query, $sort = 'featured', $refresh = false)
    {
        $index = self::index($refresh);
        if (!$index) {
            return [];
        }
        if ($refresh) {
            self::sponsored(true);   // re-fetch the placement overlay too, so a refresh is a full one
        }
        $modules = isset($index['modules']) && is_array($index['modules']) ? $index['modules'] : (array) $index;
        $q = strtolower(trim((string) $query));

        $out = [];
        foreach ($modules as $m) {
            if (!is_array($m)) { continue; }
            if ($q !== '') {
                $hay = strtolower(self::_title($m) . ' ' . ($m['slug'] ?? '') . ' ' . ($m['description'] ?? '')
                    . ' ' . implode(' ', (array) ($m['keywords'] ?? [])) . ' ' . ($m['vendor'] ?? $m['author'] ?? '')
                    . ' ' . ($m['type'] ?? ''));
                if (strpos($hay, $q) === false) { continue; }
            }
            $out[] = self::_resolveImages(self::_mergeSponsor($m));
        }

        self::_sort($out, in_array($sort, self::SORTS, true) ? $sort : 'featured');
        return $out;
    }

    /**
     * Attach curated placement to a listing from the sponsored overlay (keyed by <Org>_<Repo>,
     * derived from the repo URL). Sets `priority` (0 when unsponsored) + a `sponsored` flag/label.
     *
     * @param  array $m the listing
     * @return array the listing with placement fields
     */
    protected static function _mergeSponsor(array $m)
    {
        $m['priority'] = 0;
        $key = preg_match('#github\.com/([^/]+)/([^/]+?)/?$#i', (string) ($m['repository'] ?? ''), $r)
            ? $r[1] . '_' . $r[2] : '';
        $sp = $key ? (self::sponsored()[$key] ?? null) : null;
        if (is_array($sp)) {
            $m['priority']        = (int) ($sp['priority'] ?? 0);
            $m['sponsored']       = true;
            $m['sponsored_label'] = (string) ($sp['label'] ?? 'Sponsored');
        }
        return $m;
    }

    /**
     * Resolve a listing's image paths to absolute raw URLs. Images live in the module's OWN repo
     * (the registry only points at them); a listing may store repo-relative paths (e.g.
     * "assets/screenshots/01.png") which resolve against the pinned ref
     * (raw.githubusercontent.com/<org>/<repo>/<ref>/…) so the SAME paths are reusable in the repo's
     * README.md. A value already starting with http(s) is passed through unchanged (back-compat with
     * full-URL logo/hero). Covers logo, hero, the screenshots[] gallery, and the video (self-hosted
     * mp4 → raw, YouTube/Vimeo → a click-only nocookie embed).
     *
     * @param  array $m the listing
     * @return array the listing with absolute media URLs
     */
    protected static function _resolveImages(array $m)
    {
        if (!preg_match('#github\.com/([^/]+)/([^/]+?)/?$#i', (string) ($m['repository'] ?? ''), $r)) {
            return $m;
        }
        $ref  = (string) ($m['ref'] ?? $m['version'] ?? 'main');
        $base = "https://raw.githubusercontent.com/{$r[1]}/{$r[2]}/{$ref}/";
        $abs  = static function ($p) use ($base) {
            $p = (string) $p;
            return ($p === '' || preg_match('#^https?://#i', $p)) ? $p : $base . ltrim($p, '/');
        };
        foreach (['logo', 'hero'] as $k) {
            if (!empty($m[$k])) { $m[$k] = $abs($m[$k]); }
        }
        if (!empty($m['screenshots']) && is_array($m['screenshots'])) {
            $m['screenshots'] = array_values(array_filter(array_map($abs, $m['screenshots'])));
        }

        // video: a self-hosted .mp4/.webm (repo-relative → raw, or a full CDN URL) plays inline;
        // a YouTube/Vimeo link becomes a privacy-enhanced embed that only loads on click (the
        // lightbox builds the iframe on open, so nothing phones home until the admin plays it).
        // An optional repo-hosted poster avoids a third-party thumbnail.
        if (!empty($m['video'])) {
            $v   = is_array($m['video']) ? $m['video'] : ['src' => (string) $m['video']];
            $src = (string) ($v['src'] ?? '');
            if ($src === '') {
                unset($m['video']);
            } else {
                if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([\w-]+)#i', $src, $y)) {
                    $v['src']  = 'https://www.youtube-nocookie.com/embed/' . $y[1];
                    $v['type'] = 'iframe';
                } elseif (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $src, $vm)) {
                    $v['src']  = 'https://player.vimeo.com/video/' . $vm[1];
                    $v['type'] = 'iframe';
                } else {
                    $v['src']  = $abs($src);
                    $v['type'] = 'video';
                }
                if (!empty($v['poster'])) { $v['poster'] = $abs($v['poster']); }
                $m['video'] = $v;
            }
        }
        return $m;
    }

    /** Order results in place: featured (priority then title), title (A–Z), or latest (newest review). */
    protected static function _sort(array &$out, $sort)
    {
        if ($sort === 'title') {
            usort($out, static fn($a, $b) => strcmp(self::_title($a), self::_title($b)));
        } elseif ($sort === 'latest') {
            $at = static fn($m) => (string) ($m['review']['reviewed_at'] ?? '');
            usort($out, static fn($a, $b) => strcmp($at($b), $at($a)) ?: strcmp(self::_title($a), self::_title($b)));
        } else { // featured
            usort($out, static fn($a, $b) => (($b['priority'] ?? 0) <=> ($a['priority'] ?? 0))
                ?: strcmp(self::_title($a), self::_title($b)));
        }
    }

    /** A listing's display title (the registry uses `module`; tolerate a legacy `name`). */
    protected static function _title(array $m)
    {
        return strtolower((string) ($m['module'] ?? $m['name'] ?? ''));
    }

    /**
     * The curated sponsorship overlay — a { "<Org>_<Repo>": {priority,label,until} } map fetched
     * from data/sponsored.json alongside the index and cached like it (so placement updates need
     * no index recompile). Expired (`until` < today) or malformed entries are dropped. [] if none.
     *
     * @param  bool $refresh bypass the per-request memo + file cache and re-fetch now
     * @return array the active sponsorship map
     */
    public static function sponsored($refresh = false)
    {
        static $mem = null;
        if ($mem !== null && !$refresh) { return $mem; }

        $cache = self::_cacheFile(self::CACHE_FILE_SPONS);
        $body  = (!$refresh && $cache && is_file($cache) && (time() - filemtime($cache)) < self::CACHE_TTL)
            ? (string) @file_get_contents($cache) : '';
        if ($body === '') {
            $fetched = Tiger_Module_Github::get(self::sponsoredUrl());
            if ($fetched !== null) {
                $body = $fetched;
                if ($cache) { @file_put_contents($cache, $body); }
            } elseif ($cache && is_file($cache)) {
                $body = (string) @file_get_contents($cache);   // stale is fine (offline)
            }
        }

        $j    = $body !== '' ? json_decode($body, true) : null;
        $list = (is_array($j) && isset($j['listings']) && is_array($j['listings'])) ? $j['listings'] : [];
        $today = gmdate('Y-m-d');
        $mem = [];
        foreach ($list as $k => $v) {
            if (is_array($v) && (empty($v['until']) || $v['until'] >= $today)) { $mem[$k] = $v; }
        }
        return $mem;
    }

    /**
     * The sponsors-overlay URL — the curated placement map. Its own repo (WebTigers/TigerSponsors), not a
     * file in the open Vendors registry, so access control is just repo permissions. Overridable via
     * `tiger.modules.sponsors` (a fork can disable placement or point at its own file).
     *
     * @return string the sponsors.json URL
     */
    public static function sponsoredUrl()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $t   = $cfg ? $cfg->get('tiger') : null;
        $mod = $t ? $t->get('modules') : null;
        $url = ($mod && $mod->get('sponsors')) ? (string) $mod->sponsors : '';
        return $url !== '' ? $url : self::DEFAULT_SPONSORS;
    }

    /**
     * The (cached) registry index array, or null if unreachable.
     *
     * @param  bool $refresh bypass the cache and re-fetch now (the fresh copy is written back)
     * @return array|null the decoded index, or null if unreachable
     */
    public static function index($refresh = false)
    {
        $cache = self::_cacheFile();
        if (!$refresh && $cache && is_file($cache) && (time() - filemtime($cache)) < self::CACHE_TTL) {
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

    /**
     * The registry index URL — the configured `tiger.modules.registry`, else DEFAULT_INDEX.
     *
     * @return string the index URL
     */
    public static function indexUrl()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $t   = $cfg ? $cfg->get('tiger') : null;
        $mod = $t ? $t->get('modules') : null;
        $url = ($mod && $mod->get('registry')) ? (string) $mod->registry : '';
        return $url !== '' ? $url : self::DEFAULT_INDEX;
    }

    protected static function _cacheFile($name = self::CACHE_FILE)
    {
        $base = defined('APPLICATION_ROOT') ? rtrim(APPLICATION_ROOT, '/') : rtrim(getcwd(), '/');
        $dir  = $base . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir . '/' . $name;
    }
}
