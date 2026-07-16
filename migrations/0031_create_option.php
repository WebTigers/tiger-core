<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0031 — create `option` (the LAZY, on-demand key/value store).
 *
 * The sibling of `config` (migration 0009), and the deliberate other half of the
 * config-discipline split:
 *
 *   - `config` is EAGER + lean — every row is folded into the Zend_Config cascade on
 *     EVERY request (Tiger_Application_Bootstrap::_initConfigs). It's for settings the
 *     whole app reads: skin, site name, session TTL, tiger.tigershield.*, …
 *   - `option` is LAZY + scoped — NOTHING here is auto-loaded. A row is fetched only
 *     when the feature that owns it asks (Tiger_Model_Option::get). It's for on-demand
 *     per-user / per-entity state that would be pure waste in the eager cascade — e.g.
 *     a user's dashboard widget layout, dismissed-notice flags, a wizard's progress.
 *
 * This is NOT a wp_options-style grab-bag: it's the same disciplined (scope, scope_id,
 * key) shape as `config`, just read on demand. If a value should influence the request
 * cascade, it belongs in `config`; if it's private state read only by its owner, here.
 *
 * Same column conventions as `config`: `scope_id` NOT NULL DEFAULT '' so the UNIQUE key
 * enforces one value per (scope, scope_id, key); `key`/`value` are reserved words, hence
 * `option_key`/`option_value`. `option_value` is LONGTEXT (a layout JSON blob can exceed
 * TEXT's 64KB in pathological cases).
 */
return [
    'up' => [
        "CREATE TABLE `option` (
            `option_id`    CHAR(36)     NOT NULL,
            `scope`        VARCHAR(16)  NOT NULL DEFAULT 'user',   -- global | org | user
            `scope_id`     VARCHAR(36)  NOT NULL DEFAULT '',       -- user_id/org_id; '' = global
            `option_key`   VARCHAR(191) NOT NULL,                   -- dot-notation path
            `option_value` LONGTEXT         NULL,                   -- string value (often JSON)
            `status`       VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`      TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`   CHAR(36)         NULL,
            `updated_by`   CHAR(36)         NULL,
            `created_at`   DATETIME     NOT NULL,
            `updated_at`   DATETIME         NULL,
            PRIMARY KEY (`option_id`),
            UNIQUE KEY `uq_option`       (`scope`, `scope_id`, `option_key`),
            KEY        `ix_option_scope` (`scope`, `scope_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `option`",
    ],
];
