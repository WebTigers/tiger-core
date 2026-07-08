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
 */
class System_Service_Modules extends Tiger_Service_Service
{
    /** Modules that must always stay active. */
    const PROTECTED = ['default', 'system', 'access'];

    public function activate(array $params): void   { $this->_toggle($params, true); }
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
            (new Tiger_Model_Module())->setActive($slug, $on, ['name' => $d['name'], 'version' => $d['version']]);
            $this->_success(
                ['slug' => $slug, 'active' => $on],
                $on ? 'system.module.activated' : 'system.module.deactivated',
                '/system/modules'
            );
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Search the Vendor Registry (empty + available=false when the registry isn't reachable). */
    public function search(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $this->_success([
            'results'   => Tiger_Module_Registry::search((string) ($params['q'] ?? '')),
            'available' => Tiger_Module_Registry::available(),
        ]);
    }

    /**
     * Preview a module before install: pull module.json + TIGER.md from the public repo and
     * return the manifest + rendered description. No side effects — the "review before you
     * install" step.
     */
    public function inspect(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $r = Tiger_Module_Github::parseRepo((string) ($params['url'] ?? ''));
        if (!$r) { $this->_error('That doesn\'t look like a GitHub repository URL.'); return; }

        $ref = trim((string) ($params['ref'] ?? ''));
        if ($ref === '') { $ref = Tiger_Module_Github::latestRef($r['org'], $r['repo']); }
        if (!$ref) { $this->_error('Couldn\'t resolve a release — is the repo public?'); return; }

        $mj = Tiger_Module_Github::fetchRaw($r['org'], $r['repo'], $ref, 'module.json');
        if ($mj === null) { $this->_error('No module.json found (or the repo isn\'t public).'); return; }
        $m = json_decode($mj, true);
        if (!is_array($m) || empty($m['slug'])) { $this->_error('That repo\'s module.json is invalid.'); return; }

        $tigerMd  = Tiger_Module_Github::fetchRaw($r['org'], $r['repo'], $ref, 'TIGER.md');
        $descHtml = '';
        if ($tigerMd !== null) {
            try { $descHtml = $this->_scrub((new Tiger_Cms_Renderer())->renderBody($tigerMd, 'markdown')); } catch (Throwable $e) {}
        }

        $installed = (new Tiger_Model_Module())->bySlug($m['slug']);
        $author    = $m['author'] ?? '';
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
            'installed'        => (bool) $installed,
            'installed_version'=> $installed ? $installed->version : null,
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

    /** Install (or update, with force) a module from a public GitHub URL. */
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
}
