<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Seo_LlmsController — serves /llms.txt: a curated, Markdown map of the site's PUBLIC content for
 * language models (the llms.txt proposal — sibling to /sitemap.xml + /robots.txt, and like them a
 * ROUTE, never a docroot file). It reads the same `Tiger_Sitemap` registry that feeds the XML sitemap
 * — so every content module contributes its section by registering ONE provider (with optional
 * `title`/`desc`); TigerSEO never learns another module's routes. When TigerDocs is installed it points
 * at that module's own richer /docs/llms.txt rather than enumerating every doc page here.
 *
 * Public (guest ACL). Route declared in Seo_Bootstrap. Toggle: tiger.seo.llms.enabled (default on).
 */
class Seo_LlmsController extends Zend_Controller_Action
{
    /** Cap a single section so a huge content set can't produce a multi-megabyte file. */
    const MAX_PER_SECTION = 200;

    public function init()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $this->getResponse()->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
        @ini_set('display_errors', '0');
    }

    public function txtAction()
    {
        if (!self::_enabled()) {
            throw new Zend_Controller_Action_Exception('Not found', 404);
        }

        $request  = $this->getRequest();
        $base     = $request->getScheme() . '://' . $request->getHttpHost();
        $siteName = self::_config('site.name', '') ?: 'This site';
        $tagline  = self::_config('site.tagline', '');
        $intro    = self::_config('seo.llms.intro', '');

        $lines   = ['# ' . self::_clean($siteName)];
        if ($tagline !== '') {
            $lines[] = '';
            $lines[] = '> ' . self::_clean($tagline);
        }
        $lines[] = '';
        $lines[] = $intro !== '' ? self::_clean($intro)
            : 'A curated map of this site\'s public content for language models. Links are Markdown.';

        // Optional featured doc for agents (e.g. an "evaluate building here" brief). Config-gated, so a
        // stock/downstream install emits nothing — only a site that sets seo.llms.doc_url surfaces it.
        $docUrl = trim((string) self::_config('seo.llms.doc_url', ''));
        if ($docUrl !== '') {
            $docLabel = trim((string) self::_config('seo.llms.doc_label', '')) ?: 'For AI agents';
            $docDesc  = trim((string) self::_config('seo.llms.doc_desc', ''));
            $lines[] = '';
            $lines[] = '## For AI agents';
            $line = '- [' . self::_clean($docLabel) . '](' . $docUrl . ')';
            if ($docDesc !== '') {
                $line .= ': ' . self::_clean($docDesc);
            }
            $lines[] = $line;
        }

        // TigerDocs ships its OWN richer /docs/llms.txt — point at it instead of listing every page.
        if (class_exists('Docs_Model_Docs')) {
            $lines[] = '';
            $lines[] = '## Documentation';
            $lines[] = '- [Documentation](' . $base . '/docs): Guides and reference for this app.';
            $lines[] = '- [Docs LLM index](' . $base . '/docs/llms.txt): The full documentation, LLM-friendly.';
        }

        // One section per content provider (docs excluded — covered above).
        foreach (Tiger_Sitemap::grouped(self::_context()) as $key => $rows) {
            if ($key === 'docs') {
                continue;
            }
            $lines[] = '';
            $lines[] = '## ' . self::_humanize($key);
            $shown = 0;
            foreach ($rows as $r) {
                if ($shown >= self::MAX_PER_SECTION) {
                    $lines[] = '- …and ' . (count($rows) - $shown) . ' more (see ' . $base . '/sitemap.xml)';
                    break;
                }
                $title = $r['title'] !== '' ? $r['title'] : self::_humanize(basename(rtrim($r['loc'], '/')));
                $line  = '- [' . self::_clean($title) . '](' . $base . $r['loc'] . ')';
                if ($r['desc'] !== '') {
                    $line .= ': ' . self::_clean($r['desc']);
                }
                $lines[] = $line;
                $shown++;
            }
        }

        $this->getResponse()->setBody(implode("\n", $lines) . "\n");
    }

    /** The provider context: the site org + current locale. */
    private static function _context()
    {
        $orgId = (class_exists('Tiger_Model_Org') && method_exists('Tiger_Model_Org', 'siteOrgId'))
            ? (string) Tiger_Model_Org::siteOrgId() : '';
        return ['locale' => defined('LANG') ? LANG : 'en', 'orgId' => $orgId];
    }

    /** Feature toggle — on unless explicitly disabled. */
    private static function _enabled()
    {
        $v = self::_config('seo.llms.enabled', '1');
        return $v === null || (string) $v === '1';
    }

    /** Read a `tiger.<dotKey>` config value. */
    private static function _config($dotKey, $default = '')
    {
        if (!Zend_Registry::isRegistered('Zend_Config')) {
            return $default;
        }
        $node = Zend_Registry::get('Zend_Config')->get('tiger');
        foreach (explode('.', $dotKey) as $seg) {
            if (!($node instanceof Zend_Config)) { return $default; }
            $node = $node->get($seg);
            if ($node === null) { return $default; }
        }
        return is_scalar($node) ? (string) $node : $default;
    }

    /** Turn a provider key/slug into a section title ('blog' -> 'Blog', 'listing-pages' -> 'Listing Pages'). */
    private static function _humanize($s)
    {
        $s = trim(str_replace(['-', '_', '/'], ' ', (string) $s));
        return $s === '' ? 'Content' : ucwords($s);
    }

    /** One-line, bracket-safe text for a Markdown label/description. */
    private static function _clean($s)
    {
        $s = preg_replace('/\s+/', ' ', trim((string) $s));
        $s = str_replace([']', '[', "\n"], [')', '(', ' '], $s);
        return mb_substr($s, 0, 200);
    }
}
