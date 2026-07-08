<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_View_Helper_CodeInject — emit Tiger Code's client tier into the page.
 *
 * Themes call `<?= $this->codeInject('head') ?>` and `<?= $this->codeInject('footer') ?>`
 * at those points. It reads the compiled injection manifest (Tiger_Code_Runtime — cached,
 * no per-request DB query; the token rides config like the PHP tier) and emits:
 *   - css/js  → a <link>/<script> to the versioned, browser-cached public asset,
 *   - html    → the raw markup, verbatim,
 *   - phtml   → rendered server-side via the CMS renderer (guarded — a bad snippet can't
 *               break the page).
 *
 * @api
 */
class Tiger_View_Helper_CodeInject extends Zend_View_Helper_Abstract
{
    /** @param string $position 'head' | 'footer' */
    public function codeInject($position)
    {
        if (!Tiger_Code_Runtime::enabled()) {
            return '';
        }
        $version = Tiger_Code_Runtime::version();
        if ($version <= 0) {
            return '';
        }

        $manifest = Tiger_Code_Runtime::injectManifest($version, Tiger_Code_Runtime::LOC_GLOBAL);
        $items    = isset($manifest[$position]) ? $manifest[$position] : [];
        if (!$items) {
            return '';
        }

        $out = '';
        foreach ($items as $it) {
            switch ($it['type'] ?? '') {
                case 'css_asset':
                    $out .= '<link rel="stylesheet" href="' . htmlspecialchars((string) $it['url'], ENT_QUOTES) . '">' . "\n";
                    break;
                case 'js_asset':
                    $out .= '<script src="' . htmlspecialchars((string) $it['url'], ENT_QUOTES) . '"></script>' . "\n";
                    break;
                case 'html':
                    $out .= (string) $it['html'] . "\n";
                    break;
                case 'phtml':
                    try {
                        $out .= (new Tiger_Cms_Renderer())->renderBody((string) $it['code'], 'phtml') . "\n";
                    } catch (Throwable $e) {
                        // a bad phtml snippet renders nothing rather than breaking the page
                    }
                    break;
            }
        }
        return $out;
    }
}
