<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0014 — create `page` (the CMS content store).
 *
 * One table holds all three rendering primitives via `type`: **page** (routed by
 * slug), **layout** (chrome with a content placeholder), **partial** (a reusable
 * fragment). A "post"/"article" is just a page row — content is static HTML in the
 * table, made dynamic by the renderer (a phtml body can use view vars like a
 * $posts array; html/markdown bodies can use [shortcodes]).
 *
 * Content lives in the DB, not in code — editing a page is a row update, not a
 * deploy (the live-override philosophy, like config/translation).
 *
 * TENANCY (`org_id`, scope-style like config/translation): '' = global/platform;
 * a real org_id = tenant-owned. The resolver walks org_id IN (currentOrg, '') and
 * the tenant row WINS over global — ownership AND per-tenant override in one axis.
 *
 * I18N: same logical page = one row per `locale` (language-only), sharing `page_key`.
 *
 * SCHEDULING: a `published` row with `published_at` in the FUTURE is scheduled — no
 * separate status. Live = status='published' AND (published_at IS NULL OR <= now).
 *
 * FORMAT / security: `html` + `markdown` are SAFE (no code); `phtml` is CODE — the
 * power tool, restricted to trusted authors by the CMS. Use phtml carefully.
 */
return [
    'up' => [
        "CREATE TABLE `page` (
            `page_id`      CHAR(36)     NOT NULL,                     -- UUID v7 (time-ordered)
            `org_id`       VARCHAR(36)  NOT NULL DEFAULT '',         -- '' = global; else tenant-owned (org wins over global)
            `type`         VARCHAR(16)  NOT NULL DEFAULT 'page',     -- page | layout | partial (+ app types: post, article, …)
            `page_key`     VARCHAR(191)     NULL,                     -- stable handle (layouts/partials by name; optional for pages)
            `slug`         VARCHAR(191)     NULL,                     -- URL path (type=page); NULL for layouts/partials
            `locale`       VARCHAR(8)   NOT NULL DEFAULT 'en',       -- language-only; one row per locale, shared page_key
            `title`        VARCHAR(255)     NULL,
            `body`         MEDIUMTEXT       NULL,                     -- template source, rendered per `format`
            `format`       VARCHAR(16)  NOT NULL DEFAULT 'html',     -- html | markdown (safe) | phtml (code — trusted only)
            `layout_key`   VARCHAR(191)     NULL,                     -- which layout wraps a page (NULL = theme's file layout)
            `parent_id`    CHAR(36)         NULL,                     -- content / nav tree
            `sort_order`   INT UNSIGNED NOT NULL DEFAULT 0,
            `meta`         JSON             NULL,                     -- SEO / <head> / per-page settings
            `published_at` DATETIME         NULL,                     -- go-live time; future = scheduled
            `status`       VARCHAR(16)  NOT NULL DEFAULT 'draft',    -- draft | published | archived
            `deleted`      TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`   CHAR(36)         NULL,
            `updated_by`   CHAR(36)         NULL,
            `created_at`   DATETIME     NOT NULL,
            `updated_at`   DATETIME         NULL,
            PRIMARY KEY (`page_id`),
            UNIQUE KEY `uq_page_key`  (`org_id`, `page_key`, `locale`),
            UNIQUE KEY `uq_page_slug` (`org_id`, `slug`, `locale`),
            KEY `ix_page_route` (`slug`, `locale`, `org_id`, `status`),
            KEY `ix_page_type`  (`type`, `org_id`, `locale`),
            KEY `ix_page_tree`  (`parent_id`, `sort_order`),
            FULLTEXT KEY `ft_page` (`title`, `body`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `page`",
    ],
];
