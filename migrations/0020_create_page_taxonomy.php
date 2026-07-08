<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0020 — create `page_taxonomy` (the post ↔ term join).
 *
 * A plain many-to-many link between a `page` row (a post) and a `taxonomy` term. The
 * composite PK makes each link unique; `ix_ptx_term` is the reverse index that powers
 * category/tag archives and term counts ("all posts in term X"). No soft-delete — a
 * link is either present or hard-removed on re-tagging (the post and term keep their
 * own soft-delete). `sort_order` preserves the author's term ordering on a post.
 */
return [
    'up' => [
        "CREATE TABLE `page_taxonomy` (
            `page_id`     CHAR(36)     NOT NULL,                     -- the post (page row)
            `taxonomy_id` CHAR(36)     NOT NULL,                     -- the term
            `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,           -- term order on the post
            `created_at`  DATETIME     NOT NULL,
            PRIMARY KEY (`page_id`, `taxonomy_id`),
            KEY `ix_ptx_term` (`taxonomy_id`, `page_id`)             -- reverse: posts in a term (archives, counts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `page_taxonomy`",
    ],
];
