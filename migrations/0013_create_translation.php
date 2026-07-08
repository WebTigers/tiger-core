<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0013 — create `translation` (live translation overrides).
 *
 * The DB tier of i18n, mirroring the `config` table: file translations
 * (core/app/module languages/<lang>/*.php) are the base; rows here override or add
 * strings at REQUEST time, no deploy. Loaded by Tiger_Application_Bootstrap::
 * _initTranslate on top of the files (last wins). Scopes: global (scope='global',
 * scope_id='') and org (scope='org', scope_id=<org_id>) — org reserved for later.
 *
 * `translation_key` is an owner-prefixed semantic key (core.api.success,
 * app.message.info, <module>.*). `locale` is language-only (en, es). `scope_id`
 * is NOT NULL DEFAULT '' so the UNIQUE key holds for global rows too.
 */
return [
    'up' => [
        "CREATE TABLE `translation` (
            `translation_id`    CHAR(36)     NOT NULL,
            `locale`            VARCHAR(8)   NOT NULL DEFAULT 'en',      -- language-only (en, es)
            `scope`             VARCHAR(16)  NOT NULL DEFAULT 'global',  -- global | org
            `scope_id`          VARCHAR(36)  NOT NULL DEFAULT '',        -- org_id; '' = global
            `translation_key`   VARCHAR(191) NOT NULL,                    -- semantic key (core.*, app.*, <module>.*)
            `translation_value` TEXT             NULL,
            `status`            VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`           TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`        CHAR(36)         NULL,
            `updated_by`        CHAR(36)         NULL,
            `created_at`        DATETIME     NOT NULL,
            `updated_at`        DATETIME         NULL,
            PRIMARY KEY (`translation_id`),
            UNIQUE KEY `uq_translation`       (`locale`, `scope`, `scope_id`, `translation_key`),
            KEY        `ix_translation_scope` (`locale`, `scope`, `scope_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `translation`",
    ],
];
