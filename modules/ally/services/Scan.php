<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Ally_Service_Scan — the /api behind the TigerAlly accessibility inspector.
 *
 * READ-ONLY, admin-gated. It renders content and runs Tiger_Ally over the HTML; it never edits
 * anything. Three actions: `scan` (a pasted HTML blob OR one CMS page by id → a findings report),
 * `pages` (the scannable CMS page list for the picker), and `scanAll` (every page → a per-page
 * pass/fail roll-up). `scan` is a Forge read-verb, so the in-app AI agent can run an a11y check on
 * demand (as the acting admin) with no approval step.
 *
 * @api
 */
class Ally_Service_Scan extends Tiger_Service_Service
{
    /**
     * Inspect pasted HTML (param `html`) or a rendered CMS page (param `page_id`).
     *
     * @param  array $params html | page_id
     * @return void
     */
    public function scan(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $html   = (string) ($params['html'] ?? '');
        $pageId = trim((string) ($params['page_id'] ?? ''));
        $source = null;

        if ($pageId !== '') {
            $row = (new Tiger_Model_Page())->findById($pageId);
            if (!$row) { $this->_error('ally.scan.page_not_found'); return; }
            try {
                $html = (new Tiger_Cms_Renderer())->renderBody($row->body, $row->format, ['page' => $row]);
            } catch (Throwable $e) {
                Tiger_Log::warn('ally.scan.render_failed', ['page_id' => $pageId, 'error' => $e->getMessage()]);
                $this->_error('ally.scan.render_failed');
                return;
            }
            $source = ['page_id' => $pageId, 'title' => (string) $row->title, 'slug' => (string) $row->slug, 'format' => (string) $row->format];
        }

        if (trim($html) === '') { $this->_error('ally.scan.empty'); return; }

        $report = Tiger_Ally::inspect($html);
        $report['source'] = $source;   // null = pasted HTML
        $this->_success($report, 'ally.scan.done');
    }

    /**
     * Scan a PUBLIC app module's view templates (application/modules/<module>/views) for a11y gaps,
     * attributing every finding to its source FILE — so the caller (a human, or the AI agent's Forge)
     * knows exactly which file to fix. PHP is neutralised before parsing so `<?= $x ?>` inside an
     * attribute reads as a present value (no false positive) while a genuinely-missing attribute is
     * still caught. Only app modules are scanned — those are the ones the Forge can actually write.
     *
     * A Forge read-verb, so the agent can run it on demand; the returned `file` paths are exactly what
     * a `file` write (or a Scout read) accepts.
     *
     * @param  array $params module
     * @return void
     */
    public function scanModule(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $mod = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($params['module'] ?? ''));
        if ($mod === '' || !defined('MODULES_PATH')) { $this->_error('ally.scan.module_not_found'); return; }

        $viewsDir = MODULES_PATH . '/' . $mod . '/views';
        if (!is_dir($viewsDir)) { $this->_error('ally.scan.module_not_found'); return; }

        $files  = [];
        $totals = ['scanned' => 0, 'files_with_issues' => 0, 'error' => 0, 'warning' => 0];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'phtml') { continue; }
            $totals['scanned']++;
            $src = (string) @file_get_contents($f->getPathname());
            // Neutralise PHP so DOMDocument sees static structure: a dynamic attribute value becomes a
            // present (non-empty) value, a missing attribute stays missing.
            $neutral = preg_replace('/<\?(?:php|=).*?\?>/s', 'x', $src);
            $r = Tiger_Ally::inspect((string) $neutral);
            if ($r['summary']['error'] > 0 || $r['summary']['warning'] > 0) {
                $totals['files_with_issues']++;
                $totals['error']   += $r['summary']['error'];
                $totals['warning'] += $r['summary']['warning'];
                $files[] = [
                    'file'     => 'application/modules/' . $mod . str_replace($viewsDir, '/views', $f->getPathname()),
                    'passed'   => $r['passed'],
                    'error'    => $r['summary']['error'],
                    'warning'  => $r['summary']['warning'],
                    'findings' => $r['findings'],
                ];
            }
        }

        $this->_success(['module' => $mod, 'totals' => $totals, 'files' => $files], 'ally.scan.done');
    }

    /**
     * The scannable CMS pages (type=page), for the picker + scanAll.
     *
     * @param  array $params (unused)
     * @return void
     */
    public function pages(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $this->_success(['pages' => $this->_pageList()], 'core.api.success');
    }

    /**
     * Scan every CMS page and return a per-page roll-up (pass/fail + error/warning counts).
     *
     * @param  array $params (unused)
     * @return void
     */
    public function scanAll(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $renderer = new Tiger_Cms_Renderer();
        $results  = [];
        $totals   = ['pages' => 0, 'passed' => 0, 'error' => 0, 'warning' => 0];
        foreach ($this->_pageList() as $p) {
            $row = (new Tiger_Model_Page())->findById($p['page_id']);
            if (!$row) { continue; }
            try {
                $html = $renderer->renderBody($row->body, $row->format, ['page' => $row]);
            } catch (Throwable $e) {
                $results[] = $p + ['passed' => null, 'error' => 0, 'warning' => 0, 'skipped' => true];
                continue;
            }
            $r = Tiger_Ally::inspect($html);
            $totals['pages']++;
            $totals['error']   += $r['summary']['error'];
            $totals['warning'] += $r['summary']['warning'];
            if ($r['passed']) { $totals['passed']++; }
            $results[] = $p + ['passed' => $r['passed'], 'error' => $r['summary']['error'], 'warning' => $r['summary']['warning']];
        }

        $this->_success(['totals' => $totals, 'results' => $results], 'ally.scan.done');
    }

    /**
     * Active CMS pages (type=page) as [page_id, title, slug, format, locale].
     *
     * @return array<int,array<string,string>>
     */
    private function _pageList(): array
    {
        $m  = new Tiger_Model_Page();
        $db = $m->getAdapter();
        return $db->fetchAll(
            $db->select()
               ->from('page', ['page_id', 'title', 'slug', 'format', 'locale'])
               ->where('type = ?', Tiger_Model_Page::TYPE_PAGE)
               ->where('deleted = ?', 0)
               ->order(['slug ASC', 'locale ASC'])
        );
    }
}
