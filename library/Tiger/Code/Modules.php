<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Code_Modules — file-based code snippets shipped by installed `code` modules.
 *
 * Unlike local snippets (the `code` table), a module snippet stays as a FILE in the module
 * (`<module>/snippets/<id>.php` — a self-describing `tiger:snippet` header + a define-only body).
 * This reads them LIVE: nothing is ever copied into the DB, so a module update flows through and
 * an uninstall removes them. The Code Area lists them alongside local snippets; the runtime
 * includes the ACTIVE ones' file bodies in the compiled bundle. "Active" is a small config set
 * (`tiger.code.modules`), the same live-override tier as everything else.
 *
 * @api
 */
class Tiger_Code_Modules
{
    /** Config key holding the comma-separated set of active snippet keys. */
    const CONFIG_KEY = 'tiger.code.modules';

    /**
     * All discovered module snippets, keyed by `<module_slug>/<file>`, from modules that are
     * present and not deactivated.
     *
     * @return array<string,array> [key => ['key','module','file','label','category','scope','description']]
     */
    public static function all()
    {
        $inactive = [];
        try { $inactive = array_flip((new Tiger_Model_Module())->inactiveSlugs()); } catch (Throwable $e) {}

        $out = [];
        foreach (self::_moduleRoots() as $root) {
            foreach (glob($root . '/*/snippets/*.php') ?: [] as $file) {
                $slug = basename(dirname(dirname($file)));
                if (isset($inactive[$slug])) { continue; }
                $base = preg_replace('/\.php$/', '', basename($file));
                $key  = $slug . '/' . $base;
                $hint = self::_parseHint($file);
                $out[$key] = [
                    'key'         => $key,
                    'module'      => $slug,
                    'file'        => $file,
                    'label'       => $hint['label'] ?? $base,
                    'category'    => $hint['category'] ?? '',
                    'scope'       => $hint['scope'] ?? Tiger_Model_Code::LOC_GLOBAL,
                    'description' => $hint['description'] ?? '',
                ];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * One discovered snippet by key, or null.
     *
     * @param  string $key the `<module>/<file>` key
     * @return array|null   the snippet info, or null
     */
    public static function get($key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /**
     * The active snippet keys (from the config set).
     *
     * @return array<int,string> active keys
     */
    public static function activeKeys()
    {
        $cfg = new Tiger_Model_Config();
        $raw = (string) $cfg->get(Tiger_Model_Config::SCOPE_GLOBAL, '', self::CONFIG_KEY);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Is a snippet active?
     *
     * @param  string $key the snippet key
     * @return bool
     */
    public static function isActive($key)
    {
        return in_array($key, self::activeKeys(), true);
    }

    /**
     * Add/remove a snippet key from the active config set. The caller rebuilds the bundle.
     *
     * @param  string $key the snippet key
     * @param  bool   $on  activate (true) or deactivate (false)
     * @return void
     */
    public static function setActive($key, $on)
    {
        $keys = array_values(array_filter(self::activeKeys(), static fn($k) => $k !== $key));
        if ($on) { $keys[] = $key; }
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', self::CONFIG_KEY, implode(',', array_unique($keys)));
    }

    /**
     * Active discovered snippets for a run location (their `scope`), keyed by key. The compiler's
     * source for module snippets. A key whose file has vanished (module uninstalled) is skipped.
     *
     * @param  string $location the run location (matched against each snippet's scope)
     * @return array<string,array> active snippets for the location
     */
    public static function activeForLoad($location = Tiger_Model_Code::LOC_GLOBAL)
    {
        $active = array_flip(self::activeKeys());
        $out = [];
        foreach (self::all() as $key => $s) {
            if (!isset($active[$key])) { continue; }
            if (($s['scope'] !== '' ? $s['scope'] : Tiger_Model_Code::LOC_GLOBAL) !== $location) { continue; }
            $out[$key] = $s;
        }
        return $out;
    }

    /**
     * The snippet's normalized PHP body (opening tag stripped), for the compiler. '' if unreadable.
     *
     * @param  string $key the snippet key
     * @return string the PHP body
     */
    public static function body($key)
    {
        $s = self::get($key);
        if (!$s || !is_file($s['file'])) { return ''; }
        return (new Tiger_Model_Code())->normalize((string) file_get_contents($s['file']));
    }

    /**
     * The raw source (for the read-only "view source"). '' if unreadable.
     *
     * @param  string $key the snippet key
     * @return string the raw file contents
     */
    public static function source($key)
    {
        $s = self::get($key);
        return ($s && is_file($s['file'])) ? (string) file_get_contents($s['file']) : '';
    }

    /** Parse the leading `// tiger:snippet key="value" …` header (may span comment lines). */
    protected static function _parseHint($file)
    {
        $fh = @fopen($file, 'r');
        if (!$fh) { return []; }
        $block = '';
        while (($line = fgets($fh)) !== false) {
            $t = trim($line);
            if ($t === '' || $t === '<?php' || $t === '<?') { continue; }   // skip blanks + opening tag
            if (strncmp($t, '//', 2) === 0) { $block .= ' ' . substr($t, 2); continue; }
            break;                                                          // first real line ends the header
        }
        fclose($fh);

        $attrs = [];
        if (strpos($block, 'tiger:snippet') !== false
            && preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $block, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) { $attrs[$a[1]] = $a[2]; }
        }
        return $attrs;
    }

    /** The module directories to scan (app first, then first-party core). */
    protected static function _moduleRoots()
    {
        $roots = [];
        if (defined('APPLICATION_PATH')) { $roots[] = APPLICATION_PATH . '/modules'; }
        if (defined('TIGER_CORE_PATH'))  { $roots[] = TIGER_CORE_PATH . '/modules'; }
        return $roots;
    }
}
