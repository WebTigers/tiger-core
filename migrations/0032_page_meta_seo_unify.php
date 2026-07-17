<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Unify page SEO metadata onto `page.meta.seo`. The CMS wrote a flat `meta.description`; the blog
 * already writes the nested `meta.seo.{title,description,…}` shape. This moves every existing
 * `meta.description` into `meta.seo.description` (creating `seo` if absent, never clobbering an existing
 * one) and drops the flat key — so there is ONE shape, not two. (A reader that tolerates both shapes
 * forever is how you keep both shapes forever; unify the data instead.)
 *
 * DATA migration — a PHP callable (Tiger_Db_Migrator runs an SQL string OR a fn($db)). Touches the live
 * `page` rows only; historical `page_version` snapshots keep their shape (the resolver reads live meta).
 * One-way: `down` is a no-op, because a reversal couldn't tell a moved description from a blog article's
 * natively-nested one.
 */
return [
    'up' => [
        function ($db) {
            $rows = $db->fetchAll("SELECT page_id, meta FROM page WHERE meta IS NOT NULL AND meta <> ''");
            foreach ($rows as $r) {
                $meta = json_decode((string) $r['meta'], true);
                if (!is_array($meta) || !array_key_exists('description', $meta)) {
                    continue;   // nothing flat to move (blog rows, already-unified rows)
                }
                $desc = trim((string) $meta['description']);
                unset($meta['description']);
                if ($desc !== '') {
                    if (!isset($meta['seo']) || !is_array($meta['seo'])) { $meta['seo'] = []; }
                    if (empty($meta['seo']['description'])) { $meta['seo']['description'] = $desc; }
                }
                $db->update(
                    'page',
                    ['meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    $db->quoteInto('page_id = ?', $r['page_id'])
                );
            }
        },
    ],
    'down' => [],
];
