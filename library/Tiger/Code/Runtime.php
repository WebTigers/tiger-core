<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
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
    const CACHE_SUBDIR = 'storage/cache/code';     // private: PHP bundles + inject manifests (included)
    const PUBLIC_URL   = '/_code';                 // public: css/js assets (served, browser-cached)
    const MARKER       = '__tiger_code_running';   // $GLOBALS key: the snippet currently executing

    protected static $_booted     = [];      // once per request per location
    protected static $_guardArmed = false;

    /**
     * Per-request loader for a run location (default: global). Safe to call once per location.
     *
     * @param  string $location the run location
     * @return void
     */
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

        self::_armGuard();   // backstop for UNcatchable fatals (memory/timeout) — see _onShutdown
        $GLOBALS[self::MARKER] = null;
        try {
            self::_include($file);
        } catch (Throwable $ex) {
            // In PHP 8 most snippet errors (undefined function, TypeError, …) are CATCHABLE.
            // The marker names the snippet that was loading — deactivate it + heal this request.
            self::_deactivateRunning($ex->getMessage() . ' (line ' . $ex->getLine() . ')');
        }
        $GLOBALS[self::MARKER] = null;   // clear so the shutdown backstop doesn't re-fire
    }

    /** Deactivate the snippet named by the marker + rebuild (self-heal). Best-effort. */
    protected static function _deactivateRunning($why)
    {
        $bad = isset($GLOBALS[self::MARKER]) ? $GLOBALS[self::MARKER] : null;
        if (!$bad) {
            return;
        }
        try {
            (new Tiger_Model_Code())->markError($bad, $why);
            self::rebuild();
            self::_log("auto-deactivated code {$bad}: {$why}");
        } catch (Throwable $t) {
            // nothing more we can safely do
        }
    }

    /**
     * Recompile a location's bundle, then (only if it's valid) bump the version token to
     * promote it. Returns the new version. THROWS if the assembled bundle doesn't compile
     * (a cross-snippet redeclare, or a parse error) — the version stays put, the last-good
     * bundle keeps serving, and the caller surfaces the error. This is what makes bricking
     * impossible: a bad set never becomes live.
     *
     * @param  string $location the run location
     * @return int              the new version token
     */
    public static function rebuild($location = self::LOC_GLOBAL)
    {
        $cfg = new Tiger_Model_Config();
        $cur = (int) $cfg->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.code.version');
        $new = $cur + 1;
        self::compile($location, $new);         // PHP bundle (validated; throws if invalid)
        self::compileClient($location, $new);   // CSS/JS assets + injection manifest (best-effort)
        $cfg->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.code.version', (string) $new);
        return $new;
    }

    /**
     * Compile active PHP rows for a location into one bundle file (atomic write). Each snippet
     * is preceded by a $GLOBALS marker so the shutdown guard can name the one that fatals.
     * Always writes (even an empty bundle) so a request never re-queries for the same version.
     *
     * @param  string $location the run location
     * @param  int    $version  the version being compiled
     * @return string           the written bundle file path
     * @throws RuntimeException if the cache dir can't be created or the bundle fails to lint
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

        $buf = "<?php\n/* Tiger Code — compiled {$location} bundle v{$version}. Generated from the `code` table + active module snippets; DO NOT EDIT. */\n";
        foreach ($rows as $r) {
            $buf .= "\n\$GLOBALS['" . self::MARKER . "'] = " . var_export((string) $r->code_id, true) . ";\n";
            $buf .= '/* == ' . str_replace('*/', '* /', (string) $r->name) . " == */\n";
            $buf .= $model->normalize($r->code) . "\n";
        }

        // Active MODULE snippets — file bodies read live from installed `code` modules, never copied
        // into the DB. Each is linted on its own first, so one broken file is skipped instead of
        // failing the whole bundle (the bundle-level lint below is still the final gate for cross-
        // snippet conflicts). Module snippets are define-only + function_exists-guarded, so they carry
        // no per-snippet self-heal marker — the marker stays null across them.
        $buf .= "\n\$GLOBALS['" . self::MARKER . "'] = null;\n";
        foreach (Tiger_Code_Modules::activeForLoad($location) as $key => $s) {
            $body = Tiger_Code_Modules::body($key);
            if ($body === '' || !$model->lint($body)['ok']) {
                continue;
            }
            $buf .= "\n/* == module:" . str_replace('*/', '* /', (string) $key) . " == */\n";
            $buf .= $body . "\n";
        }
        $buf .= "\n\$GLOBALS['" . self::MARKER . "'] = null;\n";

        $file = self::bundlePath($location, $version);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $buf, LOCK_EX);

        // Validate the WHOLE assembled bundle out-of-process — `php -l` catches parse errors
        // AND cross-snippet redeclarations (which the per-snippet lint can't see). Only a
        // valid bundle is promoted; an invalid one never goes live.
        $lint = self::_lintFile($tmp);
        if (!$lint['ok']) {
            @unlink($tmp);
            throw new RuntimeException($lint['error']);
        }

        @rename($tmp, $file);   // atomic swap
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        self::_gc($location, $file);
        return $file;
    }

    /** `php -l` a file (never executed). Returns {ok, error}. */
    protected static function _lintFile($file)
    {
        $bin = (defined('PHP_BINDIR') && @is_executable(PHP_BINDIR . '/php')) ? PHP_BINDIR . '/php' : 'php';
        $out = [];
        $rc  = 1;
        exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            return ['ok' => true, 'error' => null];
        }
        $msg = trim(implode("\n", $out));
        $msg = str_replace($file, 'the compiled bundle', $msg);
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'the compiled bundle failed to compile'];
    }

    /**
     * Compile the CLIENT tier for a run location: active css/js concatenate into versioned,
     * browser-cacheable PUBLIC assets (public/_code/<loc>.<ver>.css|js); html/phtml become
     * inline items in a private injection MANIFEST the view helper reads. Best-effort — client
     * code can't brick the server, so this never throws.
     *
     * @param  string $location the run location
     * @param  int    $version  the version being compiled
     * @return void
     */
    public static function compileClient($location, $version)
    {
        $loc   = preg_replace('/[^a-z]/', '', (string) $location);
        $model = new Tiger_Model_Code();
        $rows  = $model->activeClient($location, '');

        $css = '';
        $js  = ['head' => '', 'footer' => ''];
        $inline = ['head' => [], 'footer' => []];
        foreach ($rows as $r) {
            $pos  = ($r->auto_insert === Tiger_Model_Code::AUTO_FOOTER) ? 'footer' : 'head';
            $code = (string) $r->code;
            $tag  = '/* ' . str_replace('*/', '* /', (string) $r->name) . " */\n";
            switch ($r->language) {
                case Tiger_Model_Code::LANG_CSS:  $css .= $tag . $code . "\n"; break;
                case Tiger_Model_Code::LANG_JS:   $js[$pos] .= $tag . ";\n" . $code . "\n"; break;   // leading ; guards ASI
                case Tiger_Model_Code::LANG_HTML: $inline[$pos][] = ['type' => 'html', 'html' => $code]; break;
                case Tiger_Model_Code::LANG_PHTML:$inline[$pos][] = ['type' => 'phtml', 'code' => $code]; break;
            }
        }

        $manifest = ['head' => [], 'footer' => []];
        if ($css !== '') {
            self::_writePublic("{$loc}.{$version}.css", $css);
            $manifest['head'][] = ['type' => 'css_asset', 'url' => self::PUBLIC_URL . "/{$loc}.{$version}.css"];
        }
        foreach (['head', 'footer'] as $pos) {
            if ($js[$pos] !== '') {
                self::_writePublic("{$loc}.{$version}.{$pos}.js", $js[$pos]);
                $manifest[$pos][] = ['type' => 'js_asset', 'url' => self::PUBLIC_URL . "/{$loc}.{$version}.{$pos}.js"];
            }
            foreach ($inline[$pos] as $item) {
                $manifest[$pos][] = $item;   // html/phtml, in priority order, after the asset link
            }
        }

        // Private manifest (included by the view helper — never served).
        $mf  = self::cacheDir() . "/inject.{$loc}.{$version}.php";
        $tmp = $mf . '.' . getmypid() . '.tmp';
        if (!is_dir(self::cacheDir())) { @mkdir(self::cacheDir(), 0775, true); }
        file_put_contents($tmp, "<?php\nreturn " . var_export($manifest, true) . ";\n", LOCK_EX);
        @rename($tmp, $mf);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($mf, true); }
        self::_gcClient($loc, $version);
    }

    /**
     * The injection manifest for a version+location (compile-if-missing). Returns head/footer arrays.
     *
     * @param  int    $version  the version to load
     * @param  string $location the run location
     * @return array            ['head' => [...], 'footer' => [...]]
     */
    public static function injectManifest($version, $location = self::LOC_GLOBAL)
    {
        $loc = preg_replace('/[^a-z]/', '', (string) $location);
        $mf  = self::cacheDir() . "/inject.{$loc}.{$version}.php";
        if (!is_file($mf)) {
            try { self::compileClient($location, $version); } catch (Throwable $e) { return ['head' => [], 'footer' => []]; }
        }
        if (!is_file($mf)) {
            return ['head' => [], 'footer' => []];
        }
        $m = include $mf;
        return is_array($m) ? $m : ['head' => [], 'footer' => []];
    }

    protected static function publicDir()
    {
        $base = defined('APPLICATION_ROOT') ? rtrim(APPLICATION_ROOT, '/') : rtrim(getcwd(), '/');
        return $base . '/public/_code';
    }

    /** Write a versioned public asset atomically. */
    protected static function _writePublic($name, $content)
    {
        $dir = self::publicDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::_log('cannot create public asset dir ' . $dir);
            return;
        }
        $file = $dir . '/' . $name;
        $tmp  = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $content, LOCK_EX);
        @rename($tmp, $file);
    }

    /** Remove superseded client assets + manifests for a location. */
    protected static function _gcClient($loc, $version)
    {
        $keep = ".{$version}.";
        foreach (glob(self::publicDir() . "/{$loc}.*") ?: [] as $f) {
            if (strpos(basename($f), $keep) === false) { @unlink($f); }
        }
        foreach (glob(self::cacheDir() . "/inject.{$loc}.*.php") ?: [] as $f) {
            if (strpos(basename($f), $keep) === false) { @unlink($f); }
        }
    }

    /**
     * Kill-switch: a DISABLED file (fastest recovery) or config `tiger.code.enabled = 0`.
     *
     * @return bool true if Tiger Code execution is enabled
     */
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

    /**
     * Current version token from the already-loaded config (no query). 0 = none.
     *
     * @return int the current version token (0 = none)
     */
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
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
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
