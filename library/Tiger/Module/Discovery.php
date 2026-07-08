<?php
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
    /** All modules on disk, keyed by slug: {slug, area, name, version, description, author, license, homepage, pricing, has_manifest}. */
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
                if (!is_file($dir . '/Bootstrap.php') && !is_dir($dir . '/controllers')) { continue; }

                $m = self::_manifest($dir);
                $author = $m['author'] ?? '';
                if (is_array($author)) { $author = $author['name'] ?? ''; }

                $modules[$slug] = [
                    'slug'         => $slug,
                    'area'         => $root['area'],
                    'name'         => (string) ($m['name'] ?? ucfirst($slug)),
                    'version'      => isset($m['version']) ? (string) $m['version'] : null,
                    'description'  => (string) ($m['description'] ?? ''),
                    'author'       => (string) $author,
                    'license'      => (string) ($m['license'] ?? ''),
                    'homepage'     => (string) ($m['homepage'] ?? ''),
                    'pricing'      => $m['pricing']['model'] ?? null,
                    'has_manifest' => (bool) $m,
                ];
            }
        }
        ksort($modules);
        return $modules;
    }

    protected static function _manifest($dir)
    {
        $f = $dir . '/module.json';
        if (!is_file($f)) {
            return [];
        }
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }
}
