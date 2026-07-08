<?php
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

    /** Install (or update, with opts[force]) a module from a public GitHub URL. */
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
        // Fail early if it isn't a module (or is private): probe the manifest.
        if (Tiger_Module_Github::fetchRaw($org, $repo, $ref, 'module.json') === null) {
            throw new RuntimeException("{$org}/{$repo}@{$ref} has no module.json (or the repo isn't public).");
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

    /** Shared install tail: extract a .tar.gz, validate, place, migrate, publish, record. */
    public static function installFromTarball($tarPath, array $provenance = [], array $opts = [])
    {
        $parent = sys_get_temp_dir() . '/tigerinstall_' . getmypid() . '_' . substr(md5($tarPath . mt_rand()), 0, 8);
        if (!@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Could not create a temp extraction dir.');
        }
        try {
            self::_extract($tarPath, $parent);

            $root = self::_findModuleRoot($parent);
            if (!$root || !is_file($root . '/module.json')) {
                throw new RuntimeException('Package has no module.json at its root.');
            }
            $manifest = json_decode((string) file_get_contents($root . '/module.json'), true);
            if (!is_array($manifest) || empty($manifest['slug'])) {
                throw new RuntimeException('Invalid module.json (missing slug).');
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

            // Swap into place; keep a backup until we're done.
            $backup = null;
            if (is_dir($target)) { $backup = $target . '.bak-' . getmypid(); @rename($target, $backup); }
            if (!@rename($root, $target)) {
                self::_rcopy($root, $target);
            }
            if ($backup) { self::_rrmdir($backup); }

            self::_migrate();
            self::_publishAssets($slug, $target);

            (new Tiger_Model_Module())->install($slug, [
                'name'       => (string) ($manifest['name'] ?? ucfirst($slug)),
                'version'    => isset($manifest['version']) ? (string) $manifest['version'] : null,
                'repository' => $provenance['repository'] ?? null,
                'ref'        => $provenance['ref'] ?? null,
                'source'     => $provenance['source'] ?? Tiger_Model_Module::SOURCE_URL,
            ]);

            return ['slug' => $slug, 'name' => $manifest['name'] ?? $slug, 'version' => $manifest['version'] ?? null, 'ref' => $provenance['ref'] ?? null];
        } finally {
            self::_rrmdir($parent);
        }
    }

    /** Remove an INSTALLER-MANAGED module: delete files, unpublish assets, drop the row. */
    public static function remove($slug)
    {
        $slug = self::_validSlug($slug);
        $row  = (new Tiger_Model_Module())->bySlug($slug);
        if (!$row || !in_array($row->source, [Tiger_Model_Module::SOURCE_URL, Tiger_Model_Module::SOURCE_REGISTRY], true)) {
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

    protected static function _extract($tarPath, $into)
    {
        try {
            $phar = new PharData($tarPath);
            $phar->extractTo($into, null, true);
            return;
        } catch (Throwable $e) {
            // fall through to shell tar
        }
        if (function_exists('exec')) {
            $out = [];
            $rc  = 1;
            exec('tar -xzf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($into) . ' 2>&1', $out, $rc);
            if ($rc === 0) { return; }
            throw new RuntimeException('Extract failed: ' . trim(implode("\n", $out)));
        }
        throw new RuntimeException('No archive extractor available (PharData/tar).');
    }

    /** A bare module tar has module.json at the top; a GitHub tarball wraps it in one dir. */
    protected static function _findModuleRoot($parent)
    {
        if (is_file($parent . '/module.json')) { return $parent; }
        $dirs = glob($parent . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $d) {
            if (is_file($d . '/module.json')) { return $d; }
        }
        return count($dirs) === 1 ? $dirs[0] : null;
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

    protected static function _migrate()
    {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        (new Tiger_Db_Migrator($db, self::migrationPaths()))->migrate();
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
