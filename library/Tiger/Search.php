<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Search — the pluggable site-search registry. One search box, many sources.
 *
 * Search is a *registry*, not a feature bolted onto one table: a module contributes a **provider**
 * that knows how to search its own content and return normalized hits, and `query()` fans out across
 * every registered provider, ACL-filtered, and merges the results. So the CMS registers a "pages"
 * provider, the blog an "articles" provider, a future store a "products" provider — and the same ⌘K
 * box and `/api` search them all, grouped by source, with zero changes to the search module.
 *
 * A module registers a provider from its Bootstrap (arrows point module → core):
 *
 *   Tiger_Search::register([
 *       'key'    => 'pages',
 *       'label'  => 'Pages',
 *       'icon'   => 'fa-file-lines',
 *       'weight' => 10,                         // group order (lower first)
 *       'resource' => null,                     // optional ACL resource to gate the whole provider
 *       'search' => function ($term, $ctx) {    // callable or 'Class::method'
 *           // $ctx = ['locale','orgId','role','limit']; MUST self-limit to what $ctx may see
 *           return [ ['title'=>…, 'url'=>…, 'snippet'=>…, 'score'=>…], … ];
 *       },
 *   ]);
 *
 * A provider is responsible for its own visibility (published/org/locale) — the registry never sees
 * the underlying rows, only the hits it returns. `Tiger_Search::snippet()` is shared help for building
 * a plain-text excerpt around the term.
 *
 * @api
 */
class Tiger_Search
{
    /** @var array<string,array> provider key => normalized provider */
    protected static $_providers = [];

    /**
     * Register (or replace, by key) a search provider. Requires key, label, and a `search` callable.
     *
     * @param  array $provider key, label, search, and optional icon/weight/resource/privilege
     * @return void
     */
    public static function register(array $provider)
    {
        $key = isset($provider['key']) ? preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $provider['key']) : '';
        if ($key === '' || empty($provider['label']) || empty($provider['search'])) {
            return;
        }
        self::$_providers[$key] = [
            'key'       => $key,
            'label'     => (string) $provider['label'],
            'icon'      => (string) ($provider['icon'] ?? 'fa-magnifying-glass'),
            'weight'    => (int) ($provider['weight'] ?? 100),
            'resource'  => $provider['resource'] ?? null,     // optional ACL resource
            'privilege' => $provider['privilege'] ?? 'search',
            'search'    => $provider['search'],
        ];
    }

    /** Registered providers, sorted by weight then label. */
    public static function providers()
    {
        $p = array_values(self::$_providers);
        usort($p, static function ($a, $b) { return [$a['weight'], $a['label']] <=> [$b['weight'], $b['label']]; });
        return $p;
    }

    /** One provider by key (or null). */
    public static function get($key)
    {
        return self::$_providers[$key] ?? null;
    }

    /**
     * Search every ACL-allowed provider and merge the results.
     *
     * @param  string $term  the query
     * @param  array  $opts  locale, orgId, role, limit (per provider), only (array of provider keys)
     * @return array  ['term'=>, 'total'=>, 'groups'=>[ ['key','label','icon','hits'=>[…]] ], 'results'=>[…flat, ranked…]]
     */
    public static function query($term, array $opts = [])
    {
        $term = trim((string) $term);
        $ctx  = [
            'locale' => (string) ($opts['locale'] ?? self::_currentLocale()),
            'orgId'  => (string) ($opts['orgId'] ?? ''),
            'role'   => (string) ($opts['role'] ?? 'guest'),
            'limit'  => max(1, min(50, (int) ($opts['limit'] ?? 8))),
        ];
        $only = isset($opts['only']) ? (array) $opts['only'] : null;

        $groups = []; $flat = [];
        if ($term !== '') {
            foreach (self::providers() as $p) {
                if ($only !== null && !in_array($p['key'], $only, true)) { continue; }
                if (!self::_allowed($p, $ctx['role'])) { continue; }
                $fn = self::_resolve($p['search']);
                if (!$fn) { continue; }
                try {
                    $hits = (array) call_user_func($fn, $term, $ctx);
                } catch (Throwable $e) {
                    Tiger_Log::warn('search.provider.failed', ['provider' => $p['key'], 'error' => $e->getMessage()]);
                    continue;
                }
                if (!$hits) { continue; }
                $norm = [];
                foreach ($hits as $h) {
                    $norm[] = [
                        'title'   => (string) ($h['title'] ?? ''),
                        'url'     => (string) ($h['url'] ?? '#'),
                        'snippet' => (string) ($h['snippet'] ?? ''),
                        'score'   => (float) ($h['score'] ?? 0),
                        'source'  => $p['key'],
                        'label'   => $p['label'],
                        'icon'    => $p['icon'],
                    ];
                }
                $groups[] = ['key' => $p['key'], 'label' => $p['label'], 'icon' => $p['icon'], 'hits' => $norm];
                foreach ($norm as $n) { $flat[] = $n; }
            }
        }
        usort($flat, static function ($a, $b) { return $b['score'] <=> $a['score']; });

        return ['term' => $term, 'total' => count($flat), 'groups' => $groups, 'results' => $flat];
    }

    /**
     * Build a plain-text excerpt around the first occurrence of the term (for a result snippet).
     *
     * @param  string $text raw content (HTML tolerated — tags stripped)
     * @param  string $term the query
     * @param  int    $len  target length
     * @return string
     */
    public static function snippet($text, $term, $len = 160)
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        if ($text === '') { return ''; }
        $term = trim((string) $term);
        $pos  = $term !== '' ? stripos($text, $term) : false;
        if ($pos === false || $pos < 30) {
            return mb_substr($text, 0, $len) . (mb_strlen($text) > $len ? '…' : '');
        }
        $start = max(0, $pos - 30);
        $out   = ($start > 0 ? '…' : '') . mb_substr($text, $start, $len);
        return $out . (($start + $len) < mb_strlen($text) ? '…' : '');
    }

    /** Reset the registry (tests). */
    public static function reset()
    {
        self::$_providers = [];
    }

    // ----- internals ---------------------------------------------------------

    /** ACL-gate a whole provider (open when it declares no resource). */
    protected static function _allowed(array $provider, $role)
    {
        if (empty($provider['resource']) || !class_exists('Tiger_Acl_Acl')) {
            return true;
        }
        try {
            $acl = Tiger_Acl_Acl::getInstance();
            return $acl->isAllowed($role, $provider['resource'], $provider['privilege']);
        } catch (Throwable $e) {
            return false;   // fail closed on an ACL error for a gated provider
        }
    }

    /** Resolve a provider's search target: a callable, or a 'Class::method' string. */
    protected static function _resolve($search)
    {
        if (is_callable($search)) { return $search; }
        if (is_string($search) && strpos($search, '::') !== false) {
            [$c, $m] = explode('::', $search, 2);
            if (is_callable([$c, $m])) { return [$c, $m]; }
        }
        return null;
    }

    /** The current request language (falls back to 'en'). */
    protected static function _currentLocale()
    {
        try {
            if (Zend_Registry::isRegistered('Zend_Locale')) {
                return (string) Zend_Registry::get('Zend_Locale')->getLanguage();
            }
        } catch (Throwable $e) {}
        return 'en';
    }
}
