<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0022 — create `code_version` (snapshots of `code`, like page_version).
 *
 * Every save snapshots the row so you can diff/restore and audit exactly what code ran —
 * essential when the content is executable. Same shape + restore story as the CMS's
 * page_version.
 */
return [
    'up' => [
        "CREATE TABLE `code_version` (
            `code_version_id` CHAR(36)     NOT NULL,          -- UUID v7
            `code_id`         CHAR(36)     NOT NULL,          -- the code row this snapshots
            `version`         INT UNSIGNED NOT NULL,          -- 1,2,3… per code row
            `name`            VARCHAR(191)     NULL,
            `language`        VARCHAR(12)      NULL,
            `code`            MEDIUMTEXT       NULL,
            `run_location`    VARCHAR(24)      NULL,
            `auto_insert`     VARCHAR(24)      NULL,
            `priority`        INT UNSIGNED     NULL,
            `active`          TINYINT(1)       NULL,          -- state at snapshot time
            `status`          VARCHAR(16)      NULL,
            `created_by`      CHAR(36)         NULL,          -- who saved this version
            `created_at`      DATETIME     NOT NULL,
            PRIMARY KEY (`code_version_id`),
            UNIQUE KEY `uq_code_version` (`code_id`, `version`),
            KEY `ix_code_version` (`code_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `code_version`",
    ],
];
