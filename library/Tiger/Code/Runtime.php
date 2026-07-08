<?php
/**
 * Tiger_Code_Runtime — compile-and-run for Tiger Code's PHP tier.
 *
 * The DB is the source of truth; this executes a COMPILED CACHE FILE, never the table
 * per request. Per-request cost is one OPcache-warm include — cache invalidation rides
 * the config token `tiger.code.version` that's already loaded each request (no query).
 *
 * boot()      — the per-request loader: kill-switch → version → compile-if-missing → guard → include.
 * rebuild()   — bump the version token + recompile (called by the admin on save/toggle/delete).
 * The shutdown guard auto-deactivates a snippet that fatals *while loading*, so the next
 * request self-heals instead of white-screening forever.
 *
 * @api
 */
class Tiger_Code_Runtime
{
    const LOC_GLOBAL   = 'global';
    const CACHE_SUBDIR = 'storage/cache/code';
    const MARKER       = '__tiger_code_running';   // $GLOBALS key: the snippet currently executing

    protected static $_booted     = [];      // once per request per location
    protected static $_guardArmed = false;

    /** Per-request loader for a run location (default: global). Safe to call once per location. */
    public static function boot($location = self::LOC_GLOBAL)
    {
        if (!empty(self::$_booted[$location])) {
            return;
        }
        self::$_booted[$location] = true;

        if (!self::enabled()) {
            return;   // kill-switch
        }
        $version = self::version();
        if ($version <= 0) {
            return;   // nothing has ever been activated
        }

        $file = self::bundlePath($location, $version);
        if (!is_file($file)) {
            try {
                self::compile($location, $version);   // first hit after a change (per box)
            } catch (Throwable $e) {
                self::_log('compile failed: ' . $e->getMessage());
                return;
            }
        }
        if (!is_file($file)) {
            return;
        }

        self::_armGuard();
        $GLOBALS[self::MARKER] = null;
        self::_include($file);
        $GLOBALS[self::MARKER] = null;   // include finished cleanly
    }

    /** Bump the version token + recompile a location's bundle. Returns the new version. */
    public static function rebuild($location = self::LOC_GLOBAL)
    {
        $cfg = new Tiger_Model_Config();
        $cur = (int) $cfg->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.code.version');
        $new = $cur + 1;
        $cfg->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.code.version', (string) $new);
        self::compile($location, $new);
        return $new;
    }

    /**
     * Compile active PHP rows for a location into one bundle file (atomic write). Each snippet
     * is preceded by a $GLOBALS marker so the shutdown guard can name the one that fatals.
     * Always writes (even an empty bundle) so a request never re-queries for the same version.
     */
    public static function compile($location, $version)
    {
        $dir = self::cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Tiger_Code_Runtime: cannot create cache dir ' . $dir);
        }

        $model = new Tiger_Model_Code();
        // PHP is platform-scope only (org_id = '') — the security boundary.
        $rows = $model->activeForLoad(Tiger_Model_Code::LANG_PHP, $location, '');

        $buf = "<?php\n/* Tiger Code — compiled {$location} bundle v{$version}. Generated from the `code` table; DO NOT EDIT. */\n";
        foreach ($rows as $r) {
            $buf .= "\n\$GLOBALS['" . self::MARKER . "'] = " . var_export((string) $r->code_id, true) . ";\n";
            $buf .= '/* == ' . str_replace('*/', '* /', (string) $r->name) . " == */\n";
            $buf .= $model->normalize($r->code) . "\n";
        }
        $buf .= "\n\$GLOBALS['" . self::MARKER . "'] = null;\n";

        $file = self::bundlePath($location, $version);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $buf, LOCK_EX);
        @rename($tmp, $file);   // atomic swap
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        self::_gc($location, $file);
        return $file;
    }

    /** Kill-switch: a DISABLED file (fastest recovery) or config `tiger.code.enabled = 0`. */
    public static function enabled()
    {
        if (is_file(self::cacheDir() . '/DISABLED')) {
            return false;
        }
        $c = self::_cfgNode();
        if ($c && $c->get('enabled') !== null) {
            return (int) $c->get('enabled') === 1;
        }
        return true;
    }

    /** Current version token from the already-loaded config (no query). 0 = none. */
    public static function version()
    {
        $c = self::_cfgNode();
        return $c ? (int) $c->get('version') : 0;
    }

    protected static function bundlePath($location, $version)
    {
        return self::cacheDir() . '/' . preg_replace('/[^a-z]/', '', (string) $location) . '.' . (int) $version . '.php';
    }

    protected static function cacheDir()
    {
        $base = defined('APPLICATION_ROOT') ? rtrim(APPLICATION_ROOT, '/') : rtrim(getcwd(), '/');
        return $base . '/' . self::CACHE_SUBDIR;
    }

    /** The `tiger.code` config node, or null. */
    protected static function _cfgNode()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $t   = $cfg ? $cfg->get('tiger') : null;
        return $t ? $t->get('code') : null;
    }

    /** Include in an isolated closure scope: snippet functions/classes still land globally,
     *  but stray local vars in a snippet don't pollute the caller. */
    protected static function _include($file)
    {
        (static function () use ($file) { include $file; })();
    }

    /** Remove superseded bundles for a location (safe: an in-use inode survives unlink). */
    protected static function _gc($location, $keep)
    {
        $loc = preg_replace('/[^a-z]/', '', (string) $location);
        foreach (glob(self::cacheDir() . '/' . $loc . '.*.php') ?: [] as $f) {
            if ($f !== $keep) { @unlink($f); }
        }
    }

    protected static function _armGuard()
    {
        if (self::$_guardArmed) {
            return;
        }
        self::$_guardArmed = true;
        register_shutdown_function([self::class, '_onShutdown']);
    }

    /**
     * If the request died with a FATAL while a snippet was loading, deactivate that snippet +
     * record the error + rebuild — so the next request recovers automatically.
     */
    public static function _onShutdown()
    {
        $running = isset($GLOBALS[self::MARKER]) ? $GLOBALS[self::MARKER] : null;
        if (!$running) {
            return;   // bundle finished cleanly, or no snippet was mid-execution
        }
        $e = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_COMPILE, E_CORE_ERROR, E_RECOVERABLE_ERROR];
        if (!$e || !in_array($e['type'], $fatal, true)) {
            return;
        }
        try {
            (new Tiger_Model_Code())->markError($running, ($e['message'] ?? 'fatal') . ' (line ' . ($e['line'] ?? '?') . ')');
            self::rebuild();
            self::_log("auto-deactivated code {$running} after fatal: " . ($e['message'] ?? ''));
        } catch (Throwable $t) {
            // nothing more we can safely do inside shutdown
        }
    }

    protected static function _log($message)
    {
        if (class_exists('Tiger_Log')) {
            try { Tiger_Log::warn('Tiger_Code: ' . $message); return; } catch (Throwable $e) {}
        }
        error_log('Tiger_Code: ' . $message);
    }
}
