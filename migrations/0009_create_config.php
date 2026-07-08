<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0009 — create `config` (the runtime config override layer).
 *
 * The top tier of the config cascade (core.ini <- application.ini <- local.ini <-
 * DB). Rows here override the ini config at REQUEST time, no deploy. Two scopes
 * matter today:
 *   - global (scope='global', scope_id='')     — platform-wide overrides.
 *   - org    (scope='org',    scope_id=<org_id>) — per-tenant overrides.
 * (user scope is reserved for later.)
 *
 * `config_key` is a DOT-NOTATION path (e.g. 'tiger.skin', 'site.name') that
 * Tiger_Application_Bootstrap::_initConfigs folds into the nested Zend_Config.
 *
 * THIS IS THE PER-ORG THEMING RESOLVER: an org row `tiger.skin = 'cheetah'`
 * reskins that org — config resolution and per-org theming are the same mechanism.
 *
 * `scope_id` is NOT NULL DEFAULT '' (not NULL) so the UNIQUE key enforces one value
 * per (scope, scope_id, key) even for global (a NULL would let MySQL store dupes).
 * `key`/`value` are reserved words, hence `config_key`/`config_value`.
 */
return [
    'up' => [
        "CREATE TABLE `config` (
            `config_id`    CHAR(36)     NOT NULL,
            `scope`        VARCHAR(16)  NOT NULL DEFAULT 'global',  -- global | org | user
            `scope_id`     VARCHAR(36)  NOT NULL DEFAULT '',        -- org_id/user_id; '' = global
            `config_key`   VARCHAR(191) NOT NULL,                    -- dot-notation path
            `config_value` TEXT             NULL,                    -- string value (ini semantics)
            `status`       VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`      TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`   CHAR(36)         NULL,
            `updated_by`   CHAR(36)         NULL,
            `created_at`   DATETIME     NOT NULL,
            `updated_at`   DATETIME         NULL,
            PRIMARY KEY (`config_id`),
            UNIQUE KEY `uq_config`       (`scope`, `scope_id`, `config_key`),
            KEY        `ix_config_scope` (`scope`, `scope_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `config`",
    ],
];
