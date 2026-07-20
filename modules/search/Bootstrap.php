<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Search module bootstrap — the public search surface (⌘K + /search) over the Tiger_Search registry.
 *
 * It registers the built-in "pages" provider here (CMS pages are core content — Tiger_Model_Page — so
 * search works out of the box). Other modules add their own providers from their own Bootstraps
 * (the blog registers "articles"); this module never needs to know about them.
 */
class Search_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Register the built-in CMS-pages search provider. */
    protected function _initSearchProviders()
    {
        if (!class_exists('Tiger_Search')) {
            return;
        }
        Tiger_Search::register([
            'key'    => 'pages',
            'label'  => 'Pages',
            'icon'   => 'fa-file-lines',
            'weight' => 10,
            'search' => function ($term, $ctx) {
                $out = [];
                $rows = (new Tiger_Model_Page())->search($term, $ctx['locale'], $ctx['orgId'], $ctx['limit'], Tiger_Model_Page::TYPE_PAGE);
                foreach ($rows as $r) {
                    $out[] = [
                        'title'   => (string) $r['title'],
                        'url'     => '/' . ltrim((string) $r['slug'], '/'),
                        'snippet' => Tiger_Search::snippet($r['body'], $term),
                        'score'   => (float) $r['score'],
                    ];
                }
                return $out;
            },
        ]);
    }
}
