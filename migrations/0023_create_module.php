<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0023 — create `module` (the module lifecycle registry).
 *
 * Source of truth for which modules Tiger MANAGES the activation of. Absence of a row = a
 * module is active by default (custom/first-party modules the admin never touched). A row with
 * `active = 0` deactivates a module: Tiger_Application_Resource_Modules removes it from the
 * front controller's controller-directory map before dispatch, so it neither routes nor
 * bootstraps. Rows also record install provenance (repo + pinned ref + source) for the Module
 * Installer.
 *
 * Not soft-deleted — a module is installed or it isn't (uninstall hard-deletes the row +
 * files). `slug` = the module directory name (== manifest slug).
 */
return [
    'up' => [
        "CREATE TABLE `module` (
            `module_id`   CHAR(36)     NOT NULL,                     -- UUID v7
            `slug`        VARCHAR(191) NOT NULL,                     -- module dir name (== module.json slug)
            `name`        VARCHAR(191)     NULL,                     -- display name (from manifest)
            `version`     VARCHAR(32)      NULL,                     -- installed version
            `repository`  VARCHAR(255)     NULL,                     -- public GitHub repo (installer)
            `ref`         VARCHAR(191)     NULL,                     -- pinned tag/SHA installed
            `source`      VARCHAR(24)  NOT NULL DEFAULT 'discovered', -- registry | url | discovered
            `active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `status`      VARCHAR(16)  NOT NULL DEFAULT 'active',
            `created_by`  CHAR(36)         NULL,
            `updated_by`  CHAR(36)         NULL,
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME         NULL,
            PRIMARY KEY (`module_id`),
            UNIQUE KEY `uq_module_slug` (`slug`),
            KEY `ix_module_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `module`",
    ],
];
