<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0015 — create `page_version` (page history / rollback).
 *
 * Append-only: on every save the CMS snapshots the page's editable fields with an
 * incrementing `version`. Never lose content; restore = copy a version's fields
 * back onto the page (which itself creates a new version). No soft-delete / no
 * updates — a version is immutable once written.
 */
return [
    'up' => [
        "CREATE TABLE `page_version` (
            `page_version_id` CHAR(36)     NOT NULL,          -- UUID v7
            `page_id`         CHAR(36)     NOT NULL,          -- the page this snapshots
            `version`         INT UNSIGNED NOT NULL,          -- 1,2,3… per page
            `title`           VARCHAR(255)     NULL,
            `body`            MEDIUMTEXT       NULL,
            `format`          VARCHAR(16)  NOT NULL DEFAULT 'html',
            `meta`            JSON             NULL,
            `status`          VARCHAR(16)      NULL,          -- status at snapshot time
            `created_by`      CHAR(36)         NULL,          -- who saved this version
            `created_at`      DATETIME     NOT NULL,
            PRIMARY KEY (`page_version_id`),
            UNIQUE KEY `uq_page_version` (`page_id`, `version`),
            KEY `ix_page_version_page` (`page_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `page_version`",
    ],
];
