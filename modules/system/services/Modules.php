<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Modules — the /api service for the Module manager (activate / deactivate).
 *
 * Toggling flips the `module.active` flag; the activation gate
 * (Tiger_Application_Resource_Modules) picks it up on the NEXT request — a deactivated module
 * drops off routing + bootstrapping entirely. Gated to `superadmin`+ (managing modules is a
 * platform-admin privilege). A PROTECTED set can never be deactivated so you can't lock
 * yourself out of the manager, user admin, or core dispatch.
 *
 * @api
 */
class System_Service_Modules extends Tiger_Service_Service
{
    /** Modules that must always stay active. */
    const PROTECTED = ['default', 'system', 'access'];

    /**
     * Activate a module (by `slug`), publishing its assets.
     *
     * @param  array $params the /api payload (expects `slug`)
     * @return void
     */
    public function activate(array $params): void   { $this->_toggle($params, true); }

    /**
     * Deactivate a module (by `slug`), unpublishing its assets.
     *
     * @param  array $params the /api payload (expects `slug`)
     * @return void
     */
    public function deactivate(array $params): void { $this->_toggle($params, false); }

    protected function _toggle(array $params, $on): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($params['slug'] ?? ''));
        if ($slug === '') { $this->_error('core.api.error.general'); return; }
        if (in_array($slug, self::PROTECTED, true)) { $this->_error('system.error.protected'); return; }

        $discovered = Tiger_Module_Discovery::all();
        if (!isset($discovered[$slug])) { $this->_error('system.error.unknown'); return; }

        try {
            $d = $discovered[$slug];

            // Capability detection, not a declared type: activating anything that ships a
            // migrations/ folder applies its schema now (idempotent; no-op without one). This is
            // why `type` stays a mere label — a theme that owns tables migrates just like a module.
            if ($on) { Tiger_Module_Installer::migrateModule($slug); }

            // Themes activate differently (THEMES.md §5a): not the module.active flag, but the
            // `tiger.theme` config (one active per scope) + the asset-base symlink. No build/deploy.
            if (($d['type'] ?? 'module') === 'theme') {
                $this->_toggleTheme($slug, $d, $on);
                return;
            }

            $model = new Tiger_Model_Module();
            if ($on) {
                $model->setActive($slug, $on, ['name' => $d['name'], 'version' => $d['version']]);
                Tiger_Module_Installer::publishAssets($slug);   // symlink assets/ into public/_modules/<slug> if present
                // Convenience alert (non-blocking): required modules that aren't active.
                $data = ['slug' => $slug, 'active' => $on, 'requires_missing' => Tiger_Module_Dependency::missing($slug)];
            } else {
                $data = ['slug' => $slug, 'active' => $on, 'dependents' => Tiger_Module_Dependency::dependents($slug)];
                $model->setActive($slug, $on, ['name' => $d['name'], 'version' => $d['version']]);
                Tiger_Module_Installer::unpublishAssets($slug);
            }
            $this->_success(
                $data,
                $on ? 'system.module.activated' : 'system.module.deactivated',
                '/system/modules'
            );
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Activate/deactivate a THEME (THEMES.md §5a). Activation writes `tiger.theme` (global scope —
     * one active theme per scope) and symlinks the theme's assets to its `assetBase`; deactivation
     * clears the config back to the platform base theme. No module.active flag, no build, no deploy.
     *
     * @param  string $slug the theme slug
     * @param  array  $d     its discovery row (type/asset_base/area)
     * @param  bool   $on    activate (true) or deactivate (false)
     * @return void
     */
    protected function _toggleTheme($slug, array $d, $on): void
    {
        $key = (string) ($d['key'] ?? preg_replace('/^theme-/', '', $slug));   // tiger.theme stores the KEY
        $cfg = new Tiger_Model_Config();
        if ($on) {
            $cfg->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.theme', $key);   // one active per scope
            $base = ((string) ($d['asset_base'] ?? '')) !== '' ? $d['asset_base'] : '/_' . $key;
            $this->_linkThemeAssets($slug, $base, (string) ($d['area'] ?? 'app'));
        } elseif ($cfg->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.theme') === $key) {
            $cfg->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.theme', '');       // -> platform base theme
        }
        $this->_success(
            ['slug' => $slug, 'theme' => true, 'active' => (bool) $on],
            $on ? 'system.theme.activated' : 'system.theme.deactivated',
            '/system/modules'
        );
    }

    /** Symlink a theme's assets/ to public/<assetBase> (copy fallback where symlinks are blocked). */
    protected function _linkThemeAssets($slug, $base, $area): void
    {
        $root   = ($area === 'app' && defined('APPLICATION_PATH')) ? APPLICATION_PATH : TIGER_CORE_PATH;
        $assets = $root . '/modules/' . $slug . '/assets';
        if (!is_dir($assets)) { return; }
        $link = PUBLIC_PATH . '/' . ltrim((string) $base, '/');
        if (is_link($link)) { @unlink($link); }
        if (!@symlink($assets, $link) && !is_dir($link)) {
            Tiger_Module_Installer::publishAssets($slug);   // best-effort; symlink is the norm on cPanel
        }
    }

    /**
     * Search the Vendor Registry (empty + available=false when the registry isn't reachable).
     *
     * @param  array $params the /api payload (expects `q`; optional `sort`, `refresh`)
     * @return void
     */
    public function search(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $sort    = (string) ($params['sort'] ?? 'featured');
        $refresh = !empty($params['refresh']);   // "Refresh directory" — bypass the 3h cache
        $this->_success([
            'results'   => Tiger_Module_Registry::search((string) ($params['q'] ?? ''), $sort, $refresh),
            'available' => Tiger_Module_Registry::available(),   // reads the copy search() just refreshed
            'sort'      => $sort,
        ]);
    }

    /**
     * Preview a module before install: pull module.json + TIGER.md from the public repo and
     * return the manifest + rendered description. No side effects — the "review before you
     * install" step.
     *
     * @param  array $params the /api payload (expects `url`, optional `ref`)
     * @return void
     */
    public function inspect(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $r = Tiger_Module_Github::parseRepo((string) ($params['url'] ?? ''));
        if (!$r) { $this->_error('That doesn\'t look like a GitHub repository URL.'); return; }

        $ref = trim((string) ($params['ref'] ?? ''));
        if ($ref === '') { $ref = Tiger_Module_Github::latestRef($r['org'], $r['repo']); }
        if (!$ref) { $this->_error('Couldn\'t resolve a release — is the repo public?'); return; }

        // A code module ships module.json; a theme ships theme.json (slug = 'theme-' + key).
        $mj = Tiger_Module_Github::fetchRaw($r['org'], $r['repo'], $ref, 'module.json');
        if ($mj !== null) {
            $m = json_decode($mj, true);
            if (!is_array($m) || empty($m['slug'])) { $this->_error('That repo\'s module.json is invalid.'); return; }
        } else {
            $tj = Tiger_Module_Github::fetchRaw($r['org'], $r['repo'], $ref, 'theme.json');
            if ($tj === null) { $this->_error('No module.json or theme.json found (or the repo isn\'t public).'); return; }
            $t = json_decode($tj, true);
            if (!is_array($t) || empty($t['key'])) { $this->_error('That repo\'s theme.json is invalid.'); return; }
            $m = [
                'slug'        => 'theme-' . $t['key'],
                'name'        => $t['name'] ?? $t['key'],
                'version'     => $t['version'] ?? null,
                'author'      => $t['vendor'] ?? '',
                'license'     => $t['license'] ?? '',
                'description' => $t['description'] ?? '',
                'requires'    => $t['requires'] ?? new stdClass(),
                'type'        => 'theme',
            ];
        }

        $tigerMd  = Tiger_Module_Github::fetchRaw($r['org'], $r['repo'], $ref, 'TIGER.md');
        $descHtml = '';
        if ($tigerMd !== null) {
            try { $descHtml = $this->_scrub((new Tiger_Cms_Renderer())->renderBody($tigerMd, 'markdown')); } catch (Throwable $e) {}
        }

        // "Installed" = recorded by the installer OR simply present on disk (discovered) — the
        // latter covers a theme/module placed manually or activated without an installer row.
        $row        = (new Tiger_Model_Module())->bySlug($m['slug']);
        $discovered = Tiger_Module_Discovery::all();
        $present    = $row || isset($discovered[$m['slug']]);
        $instVer    = $row ? $row->version : ($discovered[$m['slug']]['version'] ?? null);
        $author     = $m['author'] ?? '';
        if (is_array($author)) { $author = $author['name'] ?? ''; }

        $this->_success([
            'repo'             => "https://github.com/{$r['org']}/{$r['repo']}",
            'ref'              => $ref,
            'manifest'         => [
                'slug'        => $m['slug'],
                'name'        => $m['name'] ?? $m['slug'],
                'version'     => $m['version'] ?? null,
                'author'      => (string) $author,
                'license'     => $m['license'] ?? '',
                'description' => $m['description'] ?? '',
                'requires'    => $m['requires'] ?? new stdClass(),
                'pricing'     => $m['pricing']['model'] ?? null,
            ],
            'description_html' => $descHtml,
            'installed'        => (bool) $present,
            'installed_version'=> $instVer,
        ]);
    }

    /** Strip active content from untrusted vendor markdown (the TIGER.md preview). */
    protected function _scrub($html)
    {
        $html = (string) $html;
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|link|meta|base)\b[^>]*>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>]*\2#i', '$1=$2#$2', $html);
        return $html;
    }

    /**
     * Install (or update, with force) a module from a public GitHub URL.
     *
     * @param  array $params the /api payload (expects `url`, optional `ref`, `force`)
     * @return void
     */
    public function install(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $url = (string) ($params['url'] ?? '');
        if (!Tiger_Module_Github::parseRepo($url)) { $this->_error('That doesn\'t look like a GitHub repository URL.'); return; }
        $ref = trim((string) ($params['ref'] ?? ''));

        try {
            $r = Tiger_Module_Installer::installFromUrl($url, $ref !== '' ? $ref : null, ['force' => !empty($params['force'])]);
            $this->_success($r, 'system.module.installed', '/system/modules');
        } catch (Throwable $e) {
            $this->_error('Install failed — ' . $e->getMessage());
        }
    }

    /**
     * Install a module from an uploaded .zip. Multipart POST to /api; the archive rides in
     * $_FILES['archive'] (not the JSON message body), so we read it directly. Same extract →
     * validate → place → migrate → publish → record path as a URL install (source=upload).
     *
     * @param  array $params the /api payload (optional `force` to update in place)
     * @return void
     */
    public function upload(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $f = $_FILES['archive'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->_error('Choose a module .zip to upload.'); return;
        }
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $this->_error(($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
                ? 'That file is larger than the server allows.' : 'Upload failed — please try again.');
            return;
        }
        if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) { $this->_error('Invalid upload.'); return; }
        if (!preg_match('/\.zip$/i', (string) ($f['name'] ?? ''))) { $this->_error('Upload a .zip archive.'); return; }

        try {
            $r = Tiger_Module_Installer::installFromUpload($f['tmp_name'], ['force' => !empty($params['force'])]);
            $this->_success($r, 'system.module.installed', '/system/modules');
        } catch (Throwable $e) {
            $this->_error('Install failed — ' . $e->getMessage());
        }
    }
}
