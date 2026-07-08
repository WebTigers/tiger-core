<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0016 — create `page_redirect` (slug-change redirects).
 *
 * When a published page's slug changes, its old URL must 301 to the new one so
 * links and SEO don't break. The page dispatcher checks this table on a miss and
 * redirects. Tenant-scoped like pages (`org_id`), locale-aware. Rows are
 * hard-deleted if a slug is later reused by a live page (no redirect loops).
 */
return [
    'up' => [
        "CREATE TABLE `page_redirect` (
            `page_redirect_id` CHAR(36)     NOT NULL,          -- UUID v7
            `org_id`           VARCHAR(36)  NOT NULL DEFAULT '',
            `from_slug`        VARCHAR(191) NOT NULL,          -- the retired path
            `to_slug`          VARCHAR(191) NOT NULL,          -- the current path
            `locale`           VARCHAR(8)   NOT NULL DEFAULT 'en',
            `code`             SMALLINT     NOT NULL DEFAULT 301,  -- 301 permanent | 302 temporary
            `created_at`       DATETIME     NOT NULL,
            PRIMARY KEY (`page_redirect_id`),
            UNIQUE KEY `uq_page_redirect` (`org_id`, `from_slug`, `locale`),
            KEY `ix_page_redirect_to` (`to_slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `page_redirect`",
    ],
];
