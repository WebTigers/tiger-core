<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Discovery — find the modules present on disk (active or not).
 *
 * The activation gate strips inactive modules from the runtime controller-directory map, so
 * the Modules admin can't enumerate from there — it scans the module DIRECTORIES instead
 * (app + first-party core), reading each `module.json` manifest when present. The (future)
 * installer reuses this to know what's installed. Pure filesystem read — no DB, no state.
 *
 * @api
 */
class Tiger_Module_Discovery
{
    /**
     * All modules on disk, keyed by slug: {slug, area, name, version, description, author, license, homepage, pricing, has_manifest}.
     *
     * @return array<string,array> module metadata rows keyed by slug (sorted)
     */
    public static function all()
    {
        $roots = [];
        if (defined('APPLICATION_PATH')) { $roots[] = ['area' => 'app',  'path' => APPLICATION_PATH . '/modules']; }
        if (defined('TIGER_CORE_PATH'))  { $roots[] = ['area' => 'core', 'path' => TIGER_CORE_PATH . '/modules']; }

        $modules = [];
        foreach ($roots as $root) {
            foreach (glob($root['path'] . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $slug = basename($dir);
                if (isset($modules[$slug])) { continue; }   // app dir wins if a slug collides
                // Detect a module by what it ships (capability-detection): a ROUTED module has a
                // Bootstrap/controllers; a THEME ships theme.json (resolved by path, not scanned as
                // MVC); a CODE module ships module.json + a snippets/ dir (no routes at all). A
                // module.json alone is enough — it's the manifest that declares "this is a module".
                $isRouted   = is_file($dir . '/Bootstrap.php') || is_dir($dir . '/controllers');
                $isTheme    = is_file($dir . '/theme.json');
                $isSnippets = is_dir($dir . '/snippets');
                if (!$isRouted && !$isTheme && !is_file($dir . '/module.json')) { continue; }

                $m = self::_manifest($dir);
                $author = $m['author'] ?? '';
                if (is_array($author)) { $author = $author['name'] ?? ''; }
                $type = (string) ($m['type'] ?? ($isTheme ? 'theme' : ($isSnippets ? 'code' : 'module')));

                $modules[$slug] = [
                    'slug'         => $slug,
                    'area'         => $root['area'],
                    'type'         => $type,                                  // module | theme
                    // The theme KEY (what tiger.theme stores → _initTheme resolves modules/theme-<key>).
                    // From the manifest, else the slug minus its `theme-` prefix.
                    'key'          => (string) ($m['key'] ?? preg_replace('/^theme-/', '', $slug)),
                    'name'         => (string) ($m['name'] ?? ucfirst($slug)),
                    'version'      => isset($m['version']) ? (string) $m['version'] : null,
                    'description'  => (string) ($m['description'] ?? ''),
                    'author'       => (string) $author,
                    'license'      => (string) ($m['license'] ?? ''),
                    'homepage'     => (string) ($m['homepage'] ?? ''),
                    'pricing'      => $m['pricing']['model'] ?? null,
                    'asset_base'   => (string) ($m['assetBase'] ?? ''),       // themes: the public/_<x> symlink base
                    // Advisory compatibility metadata (min/max tested Tiger version) — passed through
                    // for Tiger_Module_Compat to interpret; legacy `requires.tiger` doubles as the min.
                    'requires'     => isset($m['requires']) && is_array($m['requires']) ? $m['requires'] : [],
                    'compat'       => isset($m['compat']) && is_array($m['compat']) ? $m['compat'] : [],
                    'has_manifest' => (bool) $m,
                ];
            }
        }
        ksort($modules);
        return $modules;
    }

    /**
     * The full manifest (module.json / theme.json) for an INSTALLED module slug, or null. The app modules
     * dir wins over the first-party core one on a slug collision.
     *
     * @param  string $slug the module slug
     * @return array|null the decoded manifest, or null when not found
     */
    public static function manifestFor($slug)
    {
        $slug = basename((string) $slug);
        foreach ([defined('APPLICATION_PATH') ? APPLICATION_PATH . '/modules' : null,
                  defined('TIGER_CORE_PATH')  ? TIGER_CORE_PATH  . '/modules' : null] as $base) {
            if ($base && is_dir($base . '/' . $slug)) {
                $m = self::_manifest($base . '/' . $slug);
                if ($m) { return $m; }
            }
        }
        return null;
    }

    protected static function _manifest($dir)
    {
        // A code module's manifest is module.json; a theme's is theme.json (same shape).
        foreach (['module.json', 'theme.json'] as $name) {
            $f = $dir . '/' . $name;
            if (is_file($f)) {
                $j = json_decode((string) @file_get_contents($f), true);
                return is_array($j) ? $j : [];
            }
        }
        return [];
    }
}
