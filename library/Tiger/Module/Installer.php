<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Installer — install / update / remove modules from public GitHub repos.
 *
 * Flow (WordPress-familiar, no git/composer): resolve a PINNED release ref → read module.json
 * → download the release tarball → extract (guarded) → move into application/modules/<slug>/ →
 * run its migrations → publish assets → record in the `module` table. installFromTarball() is
 * the shared tail (also usable for offline/local installs + testing).
 *
 * Safety: public-repo-only (raw 404 = not installable), slug + reserved-name validation,
 * extraction confined to a temp dir then moved, and `remove()` only touches installer-managed
 * modules — never a developer's custom module.
 *
 * @api
 */
class Tiger_Module_Installer
{
    const RESERVED = ['default', 'system', 'access', 'core', 'tiger', 'zend', 'application', 'library', 'public'];

    /**
     * Install (or update, with opts[force]) a module from a public GitHub URL.
     *
     * @param  string      $repoUrl a GitHub repo URL or "org/repo" slug
     * @param  string|null $ref     a specific ref to pin, or null to resolve the latest release
     * @param  array       $opts    install options (e.g. ['force' => true] to update in place)
     * @return array{slug:string,name:string,version:?string,ref:?string} the installed module summary
     * @throws RuntimeException if the URL, ref, manifest, or download is invalid/unreachable
     */
    public static function installFromUrl($repoUrl, $ref = null, array $opts = [])
    {
        $r = Tiger_Module_Github::parseRepo($repoUrl);
        if (!$r) {
            throw new RuntimeException('Not a GitHub repository URL.');
        }
        $org = $r['org'];
        $repo = $r['repo'];

        $ref = $ref ?: Tiger_Module_Github::latestRef($org, $repo);
        if (!$ref) {
            throw new RuntimeException("Couldn't resolve a version for {$org}/{$repo} — is it a public repo?");
        }
        // Fail early if it isn't installable (or is private): probe for a manifest — a code
        // module ships module.json, a theme ships theme.json.
        if (Tiger_Module_Github::fetchRaw($org, $repo, $ref, 'module.json') === null
            && Tiger_Module_Github::fetchRaw($org, $repo, $ref, 'theme.json') === null) {
            throw new RuntimeException("{$org}/{$repo}@{$ref} has no module.json or theme.json (or the repo isn't public).");
        }

        $tar = tempnam(sys_get_temp_dir(), 'tigermod') . '.tar.gz';
        if (!Tiger_Module_Github::download(Tiger_Module_Github::tarballUrl($org, $repo, $ref), $tar)) {
            @unlink($tar);
            throw new RuntimeException('Failed to download the release tarball.');
        }
        try {
            return self::installFromTarball($tar, [
                'repository' => "https://github.com/{$org}/{$repo}",
                'ref'        => $ref,
                'source'     => Tiger_Model_Module::SOURCE_URL,
            ], $opts);
        } finally {
            @unlink($tar);
        }
    }

    /**
     * Install a module from an uploaded archive on the local filesystem (a .zip — or a .tar.gz).
     * Same extract → validate → place → migrate → publish → record tail as a URL install, recorded
     * with source=upload (no repository/ref). The caller validates the upload before calling this.
     *
     * @param  string $archivePath path to the uploaded archive
     * @param  array  $opts        install options (e.g. ['force' => true] to update in place)
     * @return array the installed module summary
     * @throws RuntimeException on extraction, manifest, slug, or placement failure
     */
    public static function installFromUpload($archivePath, array $opts = [])
    {
        return self::installFromTarball($archivePath, ['source' => Tiger_Model_Module::SOURCE_UPLOAD], $opts);
    }

    /**
     * Shared install tail: extract an archive (.tar.gz or .zip), validate, place, migrate, publish, record.
     *
     * @param  string $tarPath    path to the module's archive package
     * @param  array  $provenance install provenance (repository, ref, source)
     * @param  array  $opts       install options (e.g. ['force' => true] to update in place)
     * @return array{slug:string,name:string,version:?string,ref:?string} the installed module summary
     * @throws RuntimeException if extraction, the manifest, the slug, or placement fails
     */
    public static function installFromTarball($tarPath, array $provenance = [], array $opts = [])
    {
        $parent = sys_get_temp_dir() . '/tigerinstall_' . getmypid() . '_' . substr(md5($tarPath . mt_rand()), 0, 8);
        if (!@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Could not create a temp extraction dir.');
        }
        try {
            self::_extract($tarPath, $parent);

            $root = self::_findModuleRoot($parent);
            $manifest = $root ? self::_readManifest($root) : null;
            if (!$manifest) {
                throw new RuntimeException('Package has no valid module.json or theme.json at its root.');
            }
            $slug = self::_validSlug($manifest['slug']);
            self::_checkRequires($manifest['requires'] ?? []);

            $target = self::modulesDir() . '/' . $slug;
            if (is_dir($target) && empty($opts['force'])) {
                throw new RuntimeException("Module '{$slug}' is already installed (pass force to update).");
            }
            if (!is_dir(self::modulesDir()) && !@mkdir(self::modulesDir(), 0775, true) && !is_dir(self::modulesDir())) {
                throw new RuntimeException('application/modules is not writable.');
            }

            // Swap into place; keep a backup until we're done. The backup goes in a `modules-backup/`
            // sibling of modules/ — NEVER inside modules/ itself: ZF1's module scan treats every
            // subdir of modules/ as a module, so a leftover ".bak" there (if cleanup fails — e.g. the
            // web user can't delete files the original install owns) would try to bootstrap a class
            // that doesn't exist and brick the whole app. Outside modules/, a stray backup is inert.
            $backup = null;
            if (is_dir($target)) {
                $backupDir = dirname(self::modulesDir()) . '/modules-backup';
                @mkdir($backupDir, 0775, true);
                $backup = $backupDir . '/' . $slug . '.bak-' . getmypid();
                @rename($target, $backup);
            }
            if (!@rename($root, $target)) {
                self::_rcopy($root, $target);
            }
            if ($backup) { self::_rrmdir($backup); }

            self::_migrate();
            self::_publishAssets($slug, $target);
            $deps = self::_provisionDependencies($manifest, $target);

            (new Tiger_Model_Module())->install($slug, [
                'name'       => (string) ($manifest['name'] ?? ucfirst($slug)),
                'version'    => isset($manifest['version']) ? (string) $manifest['version'] : null,
                'repository' => $provenance['repository'] ?? null,
                'ref'        => $provenance['ref'] ?? null,
                'source'     => $provenance['source'] ?? Tiger_Model_Module::SOURCE_URL,
            ]);

            return ['slug' => $slug, 'name' => $manifest['name'] ?? $slug, 'version' => $manifest['version'] ?? null,
                    'ref' => $provenance['ref'] ?? null, 'dependencies' => $deps];
        } finally {
            self::_rrmdir($parent);
        }
    }

    /**
     * Remove an INSTALLER-MANAGED module: delete files, unpublish assets, drop the row.
     *
     * @param  string $slug the module slug to remove
     * @return bool true on success
     * @throws RuntimeException if the slug is invalid or the module isn't installer-managed
     */
    public static function remove($slug)
    {
        $slug = self::_validSlug($slug);
        $row  = (new Tiger_Model_Module())->bySlug($slug);
        if (!$row || !in_array($row->source, [Tiger_Model_Module::SOURCE_URL, Tiger_Model_Module::SOURCE_REGISTRY, Tiger_Model_Module::SOURCE_UPLOAD], true)) {
            throw new RuntimeException("'{$slug}' isn't an installer-managed module — won't remove it.");
        }
        (new Tiger_Model_Module())->uninstall($slug);

        $link = self::publicModulesDir() . '/' . $slug;
        if (is_link($link)) { @unlink($link); } elseif (is_dir($link)) { self::_rrmdir($link); }

        $target = self::modulesDir() . '/' . $slug;
        if (is_dir($target)) { self::_rrmdir($target); }
        return true;
    }

    // ---- helpers ---------------------------------------------------------------

    protected static function _extract($archivePath, $into)
    {
        // ZIP (uploads) — detected by the "PK" magic, since an upload temp file has no extension.
        $fh    = @fopen($archivePath, 'rb');
        $magic = $fh ? (string) fread($fh, 4) : '';
        if ($fh) { fclose($fh); }
        if (strncmp($magic, "PK\x03\x04", 4) === 0 || strncmp($magic, "PK\x05\x06", 4) === 0) {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($archivePath) === true) {
                    $zip->extractTo($into);
                    $zip->close();
                    return;
                }
            }
            if (function_exists('exec')) {
                $out = []; $rc = 1;
                exec('unzip -q ' . escapeshellarg($archivePath) . ' -d ' . escapeshellarg($into) . ' 2>&1', $out, $rc);
                if ($rc === 0) { return; }
            }
            // PharData reads zip too (Phar is always loaded — tar.gz uses it), but it detects the
            // zip format from a .zip extension, so hand it a .zip-named copy of the upload temp file.
            try {
                $zp = preg_match('/\.zip$/i', $archivePath) ? $archivePath : $archivePath . '.zip';
                if ($zp !== $archivePath) { @copy($archivePath, $zp); }
                (new PharData($zp))->extractTo($into, null, true);
                if ($zp !== $archivePath) { @unlink($zp); }
                return;
            } catch (Throwable $e) {
                // fall through to the error below
            }
            throw new RuntimeException('No zip extractor available (ZipArchive/unzip/Phar).');
        }

        // TAR.GZ (release tarballs)
        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($into, null, true);
            return;
        } catch (Throwable $e) {
            // fall through to shell tar
        }
        if (function_exists('exec')) {
            $out = [];
            $rc  = 1;
            exec('tar -xzf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($into) . ' 2>&1', $out, $rc);
            if ($rc === 0) { return; }
            throw new RuntimeException('Extract failed: ' . trim(implode("\n", $out)));
        }
        throw new RuntimeException('No archive extractor available (PharData/tar/unzip).');
    }

    /** A bare package tar has its manifest at the top; a GitHub tarball wraps it in one dir. */
    protected static function _findModuleRoot($parent)
    {
        $has = static function ($d) { return is_file($d . '/module.json') || is_file($d . '/theme.json'); };
        if ($has($parent)) { return $parent; }
        $dirs = glob($parent . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $d) {
            if ($has($d)) { return $d; }
        }
        return count($dirs) === 1 ? $dirs[0] : null;
    }

    /**
     * Read a package's manifest — a code module's module.json, or a theme's theme.json normalized to
     * the same shape (slug = "theme-" + key, plus name/version/license/requires and type=theme). This
     * is why themes install through the same path as modules. Returns null if neither is present or
     * the manifest is invalid (missing slug/key).
     *
     * @param  string $dir the package root
     * @return array|null the normalized manifest, or null
     */
    protected static function _readManifest($dir)
    {
        if (is_file($dir . '/module.json')) {
            $m = json_decode((string) file_get_contents($dir . '/module.json'), true);
            return (is_array($m) && !empty($m['slug'])) ? $m : null;
        }
        if (is_file($dir . '/theme.json')) {
            $t = json_decode((string) file_get_contents($dir . '/theme.json'), true);
            if (!is_array($t) || empty($t['key'])) { return null; }
            return [
                'slug'     => 'theme-' . $t['key'],
                'name'     => $t['name'] ?? $t['key'],
                'version'  => $t['version'] ?? null,
                'license'  => $t['license'] ?? null,
                'requires' => $t['requires'] ?? [],
                'type'     => 'theme',
            ];
        }
        return null;
    }

    protected static function _validSlug($slug)
    {
        $slug = strtolower(trim((string) $slug));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            throw new RuntimeException("Invalid module slug '{$slug}'.");
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw new RuntimeException("Reserved module slug '{$slug}' — can't install over the platform.");
        }
        return $slug;
    }

    protected static function _checkRequires(array $req)
    {
        if (!empty($req['php']) && !self::_satisfies(PHP_VERSION, $req['php'])) {
            throw new RuntimeException('This module requires PHP ' . $req['php'] . ' (this server has ' . PHP_VERSION . ').');
        }
        if (!empty($req['tiger']) && defined('TIGER_VERSION') && !self::_satisfies(TIGER_VERSION, $req['tiger'])) {
            throw new RuntimeException('This module requires Tiger ' . $req['tiger'] . '.');
        }
    }

    protected static function _satisfies($have, $constraint)
    {
        if (!preg_match('/^\s*(>=|<=|>|<|=|==|\^|~)?\s*([0-9][0-9.]*)/', (string) $constraint, $m)) {
            return true;
        }
        $op  = $m[1] ?: '>=';
        $op  = ($op === '^' || $op === '~') ? '>=' : (($op === '=') ? '==' : $op);
        return version_compare((string) $have, $m[2], $op);
    }

    protected static function _publishAssets($slug, $moduleDir)
    {
        $assets = $moduleDir . '/assets';
        if (!is_dir($assets)) { return; }
        $base = self::publicModulesDir();
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) { return; }

        $link = $base . '/' . $slug;
        if (is_link($link)) { @unlink($link); } elseif (is_dir($link)) { self::_rrmdir($link); }
        if (!@symlink($assets, $link)) { self::_rcopy($assets, $link); }   // copy where symlinks aren't allowed
    }

    /**
     * Publish a module's assets to public/_modules/<slug> — IF the module has an assets/ dir.
     * A symlink (copy fallback where symlinks are blocked); a no-op otherwise. Called on ACTIVATE
     * (and install), so a module's css/js is served the moment it's turned on. Finds the module in
     * the app OR the first-party core modules dir.
     *
     * @param  string $slug the module slug whose assets to publish
     * @return void
     */
    public static function publishAssets($slug)
    {
        $slug = basename((string) $slug);
        foreach (self::_moduleRoots() as $root) {
            if (is_dir($root . '/' . $slug . '/assets')) {
                self::_publishAssets($slug, $root . '/' . $slug);
                return;
            }
        }
    }

    /**
     * Run a module's own migrations IF it ships a migrations/ dir — capability detection, not a
     * declared type. Activation calls this so a module (or a theme that happens to own tables)
     * applies its schema the moment it's turned on, without the operator running the CLI. The
     * scan is what decides, so `type` never has to: a `type:theme` with a migrations/ folder just
     * migrates. Idempotent — the migrator skips already-applied files; a no-op when there's no
     * migrations/ dir. Finds the module in the app OR the first-party core modules dir.
     *
     * @param  string $slug the module slug whose migrations to apply
     * @return bool true if a migrations/ dir was found and run, false if there was none
     */
    public static function migrateModule($slug)
    {
        $slug = basename((string) $slug);
        foreach (self::_moduleRoots() as $root) {
            $dir = $root . '/' . $slug . '/migrations';
            if (is_dir($dir)) {
                $db = Zend_Db_Table_Abstract::getDefaultAdapter();
                (new Tiger_Db_Migrator($db, [$dir]))->migrate();
                return true;
            }
        }
        return false;
    }

    /**
     * Remove a module's published assets from public/_modules/<slug>. Called on DEACTIVATE.
     *
     * @param  string $slug the module slug whose assets to unpublish
     * @return void
     */
    public static function unpublishAssets($slug)
    {
        $link = self::publicModulesDir() . '/' . basename((string) $slug);
        if (is_link($link)) { @unlink($link); } elseif (is_dir($link)) { self::_rrmdir($link); }
    }

    /** The module directories to search (app first, then first-party core). */
    protected static function _moduleRoots()
    {
        $roots = [];
        if (defined('APPLICATION_PATH')) { $roots[] = APPLICATION_PATH . '/modules'; }
        if (defined('TIGER_CORE_PATH'))  { $roots[] = TIGER_CORE_PATH . '/modules'; }
        if (!$roots) { $roots[] = self::modulesDir(); }
        return $roots;
    }

    protected static function _migrate()
    {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        (new Tiger_Db_Migrator($db, self::migrationPaths()))->migrate();
    }

    /**
     * Provision a module's declared third-party PHP libraries (module.json `dependencies.php`) via
     * Tiger_Vendor — Composer / pre-built bundle / raw tarball, best available tier, fail-closed. We
     * DON'T throw on a failed dep (that would leave the module half-registered); the per-dep statuses
     * are returned so the caller (admin/CLI) can surface a required-but-missing library and the fix.
     * See DEPENDENCIES.md.
     *
     * @param  array  $manifest  the module.json
     * @param  string $moduleDir the installed module's root dir (target for `asset` deps)
     * @return array a status per dependency ({ok, tier?, name, message, required})
     */
    protected static function _provisionDependencies(array $manifest, $moduleDir)
    {
        $out  = [];
        $deps = $manifest['dependencies'] ?? [];

        // PHP libraries → the shared vendor-libs/ store (Composer / bundle / tarball).
        foreach ((is_array($deps['php'] ?? null) ? $deps['php'] : []) as $dep) {
            if (is_array($dep) && !empty($dep['name'])) {
                $status = Tiger_Vendor::ensure($dep);
                $status['required'] = empty($dep['optional']);
                $out[] = $status;
            }
        }
        // Front-end assets → the module's own assets dir.
        foreach ((is_array($deps['asset'] ?? null) ? $deps['asset'] : []) as $asset) {
            if (is_array($asset) && !empty($asset['name'])) {
                $status = Tiger_Vendor::installAsset($asset, $moduleDir);
                $status['required'] = empty($asset['optional']);
                $out[] = $status;
            }
        }
        return $out;
    }

    protected static function migrationPaths()
    {
        $paths = [];
        if (defined('TIGER_CORE_PATH'))  { $paths[] = TIGER_CORE_PATH . '/migrations'; }
        if (defined('APPLICATION_PATH')) { $paths[] = APPLICATION_PATH . '/migrations'; }
        foreach ([defined('APPLICATION_PATH') ? APPLICATION_PATH . '/modules' : null,
                  defined('TIGER_CORE_PATH') ? TIGER_CORE_PATH . '/modules' : null] as $mods) {
            if ($mods) {
                foreach (glob($mods . '/*/migrations') ?: [] as $p) { $paths[] = $p; }
            }
        }
        return $paths;
    }

    protected static function modulesDir()
    {
        return (defined('APPLICATION_PATH') ? APPLICATION_PATH : (defined('APPLICATION_ROOT') ? APPLICATION_ROOT . '/application' : getcwd())) . '/modules';
    }

    protected static function publicModulesDir()
    {
        $base = defined('APPLICATION_ROOT') ? rtrim(APPLICATION_ROOT, '/') : rtrim(getcwd(), '/');
        return $base . '/public/_modules';
    }

    protected static function _rcopy($src, $dst)
    {
        @mkdir($dst, 0775, true);
        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            is_dir($s) ? self::_rcopy($s, $d) : @copy($s, $d);
        }
    }

    protected static function _rrmdir($dir)
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $p = $dir . '/' . $item;
            (is_dir($p) && !is_link($p)) ? self::_rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
