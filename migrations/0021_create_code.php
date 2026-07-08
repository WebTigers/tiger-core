<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0021 — create `code` (Tiger Code: the snippet store).
 *
 * Holds executable PHP and client-injected CSS/JS/HTML alike — the `language` column is
 * the security boundary (PHP is server-run and platform-only; CSS/JS/HTML are shipped to
 * the browser and tenant-safe — see the Tiger Code design). A dedicated table, NOT the
 * `page` content table: this is code that RUNS, on a different path and blast radius, kept
 * explicit and lockable on its own.
 *
 * The DB row is the source of truth; execution goes through a compiled cache bundle keyed
 * by a version token (config `tiger.code.version`) — no per-request query. `ix_code_load`
 * is exactly the compiler's rebuild query (active + language + location + org + priority).
 */
return [
    'up' => [
        "CREATE TABLE `code` (
            `code_id`      CHAR(36)     NOT NULL,                     -- UUID v7
            `org_id`       VARCHAR(36)  NOT NULL DEFAULT '',          -- '' = platform; PHP is '' ONLY
            `name`         VARCHAR(191) NOT NULL,
            `description`  VARCHAR(255)     NULL,
            `language`     VARCHAR(12)  NOT NULL DEFAULT 'php',       -- php | js | css | html
            `code`         MEDIUMTEXT       NULL,                     -- the source of truth
            `run_location` VARCHAR(24)  NOT NULL DEFAULT 'global',    -- global | admin | frontend | page
            `auto_insert`  VARCHAR(24)      NULL,                     -- head | footer | before_content | after_content (client)
            `priority`     INT UNSIGNED NOT NULL DEFAULT 100,         -- load order (lower first)
            `active`       TINYINT(1)   NOT NULL DEFAULT 0,           -- only active rows compile into a bundle
            `last_error`   TEXT             NULL,                     -- set by auto-deactivate-on-fatal
            `status`       VARCHAR(16)  NOT NULL DEFAULT 'draft',     -- draft | active | error
            `deleted`      TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`   CHAR(36)         NULL,
            `updated_by`   CHAR(36)         NULL,
            `created_at`   DATETIME     NOT NULL,
            `updated_at`   DATETIME         NULL,
            PRIMARY KEY (`code_id`),
            KEY `ix_code_load` (`active`, `language`, `run_location`, `org_id`, `priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `code`",
    ],
];
