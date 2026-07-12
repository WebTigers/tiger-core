<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Controller_Plugin_ThemeContent — serve a theme's BUNDLED STATIC pages.
 *
 * The last hop in the slug-resolution chain (ROUTING.md §5), sitting AFTER PageDispatch:
 *
 *   real controller  →  CMS `page` row (PageDispatch)  →  THEME content partial (this)  →  404
 *
 * So a DB page always wins (the live-override tier); when there's no DB page, the active
 * theme's own `content/<slug>.phtml` answers. That lets a vendor theme ship hundreds of
 * pages as lightweight body partials rendered through the ONE theme layout — no per-page
 * DB rows, no per-page chrome. The theme's stock ".html" links resolve too (the suffix is
 * stripped), so the vendor's original navigation just works.
 *
 * Non-greedy + safe: only fires when nothing real (a controller, or a CMS page that
 * PageDispatch already claimed) will handle the request; the slug is a strict
 * dot-free token so it can never traverse out of the theme's `content/` dir.
 *
 * Runs at routeShutdown; graceful when no theme dir is registered.
 *
 * @api
 */
class Tiger_Controller_Plugin_ThemeContent extends Zend_Controller_Plugin_Abstract
{
    /**
     * Route an otherwise-unmatched URL to the active theme's static content partial.
     *
     * @param  Zend_Controller_Request_Abstract $request the current request
     * @return void
     */
    public function routeShutdown(Zend_Controller_Request_Abstract $request)
    {
        if (!$request instanceof Zend_Controller_Request_Http) {
            return;
        }

        // Only step in when nothing real (controller or CMS page) already claims this URL.
        $front = Zend_Controller_Front::getInstance();
        if ($front->getDispatcher()->isDispatchable($request)) {
            return;
        }

        $themeDir = Zend_Registry::isRegistered('Tiger_ThemeDir')
            ? (string) Zend_Registry::get('Tiger_ThemeDir') : '';
        if ($themeDir === '') {
            return;
        }

        $slug = trim($request->getPathInfo(), '/');
        $slug = preg_replace('/\.html$/i', '', $slug);   // accept the theme's stock ".html" links
        // A strict, DOT-FREE token: [a-z0-9] segments with -,_,/ separators. No '.' => no traversal.
        if ($slug === '' || !preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $slug)) {
            return;
        }

        $file = $themeDir . '/content/' . $slug . '.phtml';
        if (!is_file($file)) {
            return;   // no partial -> untouched -> ErrorHandler 404
        }

        $request->setModuleName($front->getDispatcher()->getDefaultModule())
                ->setControllerName('page')
                ->setActionName('theme-content')
                ->setParam('theme_content_slug', $slug);
    }
}
