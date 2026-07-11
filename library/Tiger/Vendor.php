<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Vendor — provisions a third-party PHP library and makes it autoloadable, on any host.
 *
 * It picks the best tier the environment + the library allow — Composer (Tier 1), a pre-built
 * bundle (Tier 2), or a raw source tarball (Tier 3) — fails closed, and registers a shared
 * autoloader so every module can find the library. It beats dependency hell by CONSUMING
 * pre-resolved bundles (resolution runs off-box, once) rather than solving a dependency graph on a
 * shared host. See DEPENDENCIES.md for the full model.
 *
 * @api
 */
class Tiger_Vendor
{
    /**
     * Register the autoloader of every library already in the shared store. Call once at bootstrap
     * so `Aws\`, `Stripe\`, etc. resolve for every module. A no-op if the store is empty.
     *
     * @return void
     */
    public static function registerAutoloaders()
    {
        $store = Tiger_Vendor_Environment::storeDir();
        if (!is_dir($store)) {
            return;
        }
        foreach (glob($store . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $auto = $dir . '/autoload.php';
            if (is_file($auto)) {
                require_once $auto;
            }
        }
    }

    /**
     * Is a library already present — in the store (Tier 2/3) or Composer's vendor/ (Tier 1)?
     *
     * @param  string $name the package name (e.g. "aws/aws-sdk-php")
     * @return bool
     */
    public static function isInstalled($name)
    {
        return is_dir(Tiger_Vendor_Environment::storeDir() . '/' . self::_slug($name))
            || is_dir(Tiger_Vendor_Environment::appRoot() . '/vendor/' . $name);
    }

    /**
     * Ensure a PHP library is present + autoloadable, choosing the best tier. Never throws — returns
     * a status the installer can act on (and, for an optional dep, ignore).
     *
     * @param  array $dep {name, constraint?, bundle?, sha256?, tarball?} from module.json dependencies.php
     * @return array{ok:bool,tier:string,name:string,message:string}
     */
    public static function ensure(array $dep)
    {
        $name = (string) ($dep['name'] ?? '');
        if ($name === '') {
            return self::_status(false, 'none', $name, 'No dependency name given.');
        }
        if (self::isInstalled($name)) {
            return self::_status(true, 'present', $name, 'Already installed.');
        }

        // Tier 1 — Composer, only if it can genuinely run.
        if (!empty($dep['constraint']) && Tiger_Vendor_Environment::composerUsable()) {
            if (self::_composerRequire($name, (string) $dep['constraint'])['ok']) {
                return self::_status(true, 'composer', $name, 'Installed via Composer.');
            }
            // fall through — try a bundle/tarball rather than failing outright
        }

        // Tier 2 — pre-built, pre-resolved, checksummed bundle.
        if (!empty($dep['bundle'])) {
            $r = self::installTarball((string) $dep['bundle'], $name, $dep['sha256'] ?? null);
            return $r['ok']
                ? self::_status(true, 'bundle', $name, 'Installed from pre-built bundle.')
                : self::_status(false, 'bundle', $name, $r['message']);
        }

        // Tier 3 — raw source tarball (only sane for a dependency-free library).
        if (!empty($dep['tarball'])) {
            $r = self::installTarball((string) $dep['tarball'], $name, $dep['sha256'] ?? null, ['generate_autoload' => true]);
            return $r['ok']
                ? self::_status(true, 'tarball', $name, 'Installed from source tarball.')
                : self::_status(false, 'tarball', $name, $r['message']);
        }

        return self::_status(false, 'none', $name,
            'No usable Composer and no bundle/tarball source for this host — a pre-built bundle is needed.');
    }

    /**
     * Download a tarball → verify sha256 (if given) → unpack into the store → ensure an autoloader.
     * Atomic: stages in a temp dir and swaps into place, so a half-download never leaves a broken lib.
     *
     * @param  string      $url    the tarball URL (a bundle asset or a source archive)
     * @param  string      $name   the package name (→ the store subdir)
     * @param  string|null $sha256 expected hash; verified and enforced when provided
     * @param  array       $opts   {generate_autoload?:bool} build a PSR-4 autoloader for a raw lib
     * @return array{ok:bool,message:string,path?:string}
     */
    public static function installTarball($url, $name, $sha256 = null, array $opts = [])
    {
        $slug  = self::_slug($name);
        $store = Tiger_Vendor_Environment::storeDir();
        if (!is_dir($store) && !@mkdir($store, 0775, true) && !is_dir($store)) {
            return ['ok' => false, 'message' => 'Library store is not writable: ' . $store];
        }
        $tmp = $store . '/.tmp-' . $slug . '-' . getmypid();
        self::_rrmdir($tmp);
        if (!@mkdir($tmp, 0775, true)) {
            return ['ok' => false, 'message' => 'Could not create a temp dir in the store.'];
        }
        try {
            $tar = $tmp . '/pkg.tar.gz';
            if (!self::_download($url, $tar)) {
                return ['ok' => false, 'message' => 'Download failed: ' . $url];
            }
            if ($sha256 !== null && !hash_equals(strtolower((string) $sha256), strtolower((string) hash_file('sha256', $tar)))) {
                return ['ok' => false, 'message' => 'Checksum mismatch — refusing to install ' . $name . '.'];
            }
            $ex = $tmp . '/x';
            @mkdir($ex, 0775, true);
            if (!self::_extract($tar, $ex)) {
                return ['ok' => false, 'message' => 'Could not extract the archive (no PharData/tar).'];
            }
            // GitHub/source tarballs wrap everything in one top dir — unwrap it.
            $root = self::_singleChild($ex) ?: $ex;

            if (!empty($opts['generate_autoload']) && !is_file($root . '/autoload.php')) {
                self::_generateAutoloader($root);
            }
            $target = $store . '/' . $slug;
            self::_rrmdir($target);
            if (!@rename($root, $target)) {
                self::_rcopy($root, $target);
            }
            return ['ok' => true, 'message' => 'Installed.', 'path' => $target];
        } finally {
            self::_rrmdir($tmp);
        }
    }

    // ---- tiers -----------------------------------------------------------------

    protected static function _composerRequire($name, $constraint)
    {
        $bin = Tiger_Vendor_Environment::composerBinary();
        if ($bin === null || !function_exists('proc_open')) {
            return ['ok' => false];
        }
        $cmd  = $bin . ' require ' . escapeshellarg($name . ':' . $constraint) . ' --no-interaction --no-progress 2>&1';
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, Tiger_Vendor_Environment::appRoot(), self::_composerEnv());
        if (!is_resource($proc)) {
            return ['ok' => false];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['ok' => proc_close($proc) === 0, 'output' => $out];
    }

    /** Composer needs HOME/COMPOSER_HOME to write its cache — many web SAPIs have neither set. */
    protected static function _composerEnv()
    {
        $env  = $_ENV ?: [];
        $home = Tiger_Vendor_Environment::appRoot() . '/var/composer-home';
        @mkdir($home, 0775, true);
        $env['COMPOSER_HOME'] = $home;
        if (empty($env['HOME'])) { $env['HOME'] = $home; }
        return $env;
    }

    /**
     * Generate an autoload.php for a raw (Composer-less) library from its composer.json autoload
     * block — PSR-4 + files, which covers virtually every dependency-free modern lib (Tier 3). A
     * pre-built bundle (Tier 2) ships its own autoloader and never reaches here.
     */
    protected static function _generateAutoloader($dir)
    {
        $cj    = json_decode((string) @file_get_contents($dir . '/composer.json'), true);
        $auto  = (is_array($cj) && isset($cj['autoload']) && is_array($cj['autoload'])) ? $cj['autoload'] : [];
        $psr4  = isset($auto['psr-4']) && is_array($auto['psr-4']) ? $auto['psr-4'] : [];
        $files = isset($auto['files']) && is_array($auto['files']) ? $auto['files'] : [];

        $php  = "<?php\n";
        $php .= "// Auto-generated by Tiger_Vendor for a raw (Composer-less) library. See DEPENDENCIES.md.\n";
        $php .= '$base = __DIR__;' . "\n";
        foreach ($files as $f) {
            $php .= 'require_once $base . ' . var_export('/' . ltrim((string) $f, '/'), true) . ";\n";
        }
        $php .= 'spl_autoload_register(function ($class) use ($base) {' . "\n";
        $php .= '    static $psr4 = ' . var_export($psr4, true) . ";\n";
        $php .= '    foreach ($psr4 as $prefix => $paths) {' . "\n";
        $php .= '        if ($prefix !== "" && strncmp($class, $prefix, strlen($prefix)) !== 0) { continue; }' . "\n";
        $php .= '        $rel = str_replace("\\\\", "/", substr($class, strlen($prefix)));' . "\n";
        $php .= '        foreach ((array) $paths as $p) {' . "\n";
        $php .= '            $file = rtrim($base . "/" . trim((string) $p, "/"), "/") . "/" . $rel . ".php";' . "\n";
        $php .= '            if (is_file($file)) { require_once $file; return; }' . "\n";
        $php .= '        }' . "\n";
        $php .= '    }' . "\n";
        $php .= '});' . "\n";
        @file_put_contents($dir . '/autoload.php', $php);
    }

    // ---- io helpers ------------------------------------------------------------

    protected static function _download($url, $dest)
    {
        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'w');
            if (!$fp) { return false; }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_FAILONERROR    => true,
                CURLOPT_USERAGENT      => 'Tiger_Vendor',
            ]);
            $ok = curl_exec($ch);
            fclose($fp);   // curl handle is freed by GC (curl_close is a deprecated no-op since 8.0)
            return $ok !== false && is_file($dest) && filesize($dest) > 0;
        }
        $data = @file_get_contents($url);
        return $data !== false && @file_put_contents($dest, $data) !== false;
    }

    protected static function _extract($tar, $into)
    {
        try {
            (new PharData($tar))->extractTo($into, null, true);
            return true;
        } catch (Throwable $e) {
            // fall through to shell tar
        }
        if (Tiger_Vendor_Environment::execEnabled() && function_exists('exec')) {
            $rc = 1;
            @exec('tar -xzf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($into) . ' 2>&1', $o, $rc);
            return $rc === 0;
        }
        return false;
    }

    protected static function _singleChild($dir)
    {
        $items = glob($dir . '/*') ?: [];
        return (count($items) === 1 && is_dir($items[0])) ? $items[0] : null;
    }

    protected static function _slug($name)
    {
        return trim(preg_replace('/[^a-z0-9._-]+/', '-', strtolower((string) $name)), '-');
    }

    protected static function _status($ok, $tier, $name, $message)
    {
        return ['ok' => (bool) $ok, 'tier' => $tier, 'name' => $name, 'message' => $message];
    }

    protected static function _rrmdir($dir)
    {
        if (!is_dir($dir)) { if (is_file($dir)) { @unlink($dir); } return; }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::_rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected static function _rcopy($src, $dst)
    {
        @mkdir($dst, 0775, true);
        foreach (scandir($src) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            is_dir($s) ? self::_rcopy($s, $d) : @copy($s, $d);
        }
    }
}
