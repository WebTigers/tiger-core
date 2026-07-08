<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0019 — create `taxonomy` (categories, tags, and future vocabularies).
 *
 * The blog feature's ONLY new content tables are this + `page_taxonomy` (0020). An
 * article itself is a `page` row (`type='article'`) whose scalar metadata rides in
 * `page.meta` JSON — see MEDIA.md's sibling design (the posts-on-page decision). But
 * categories/tags are many-to-many, which a column or a JSON scan can't serve at
 * scale, so terms get a real table.
 *
 * Mirrors the `page` conventions: UUID v7 PK, `org_id` tenancy ('' = global; a tenant
 * row overrides), language-only `locale` (one row per language, shared `term_key`),
 * and the standard columns. `vocabulary` discriminates category | tag (+ series, …),
 * exactly like `page.type` discriminates the content primitives. Categories nest via
 * `parent_id`; tags stay flat.
 */
return [
    'up' => [
        "CREATE TABLE `taxonomy` (
            `taxonomy_id` CHAR(36)     NOT NULL,                     -- UUID v7 (time-ordered)
            `org_id`      VARCHAR(36)  NOT NULL DEFAULT '',          -- '' = global; else tenant-owned (tenant wins)
            `vocabulary`  VARCHAR(32)  NOT NULL DEFAULT 'tag',       -- category | tag (+ series, …)
            `term_key`    VARCHAR(191) NOT NULL,                     -- stable handle, shared across locales
            `locale`      VARCHAR(8)   NOT NULL DEFAULT 'en',        -- language-only; one row per locale
            `name`        VARCHAR(191) NOT NULL,
            `slug`        VARCHAR(191) NOT NULL,                     -- archive URL: /blog/<vocabulary>/<slug>
            `parent_id`   CHAR(36)         NULL,                     -- categories nest; tags stay flat
            `description` TEXT             NULL,
            `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
            `status`      VARCHAR(16)  NOT NULL DEFAULT 'active',
            `deleted`     TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`  CHAR(36)         NULL,
            `updated_by`  CHAR(36)         NULL,
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME         NULL,
            PRIMARY KEY (`taxonomy_id`),
            UNIQUE KEY `uq_tax_slug` (`org_id`, `vocabulary`, `slug`, `locale`),
            UNIQUE KEY `uq_tax_key`  (`org_id`, `vocabulary`, `term_key`, `locale`),
            KEY `ix_tax_vocab` (`vocabulary`, `org_id`, `locale`, `status`),
            KEY `ix_tax_tree`  (`parent_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `taxonomy`",
    ],
];
