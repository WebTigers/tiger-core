<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0006 — create `acl_role` (runtime role graph, DB layer).
 *
 * The canonical role graph ships in code (core + module `configs/acl.ini`). This
 * table is the DB layer *on top*: a derived app can add roles by data without a
 * code change. Tiger_Acl_Acl loads ini roles first (canonical), then these (adds
 * only genuinely new roles — it can't silently re-parent a canonical role).
 *
 * `parent_role` is a comma-separated list of parent role names (Zend_Acl supports
 * multiple inheritance). `role` matches the value stored in `org_user.role`.
 */
return [
    'up' => [
        "CREATE TABLE `acl_role` (
            `acl_role_id` CHAR(36)     NOT NULL,
            `role`        VARCHAR(64)  NOT NULL,   -- role name (matches org_user.role)
            `parent_role` VARCHAR(255)     NULL,   -- comma-sep parent role names
            `description` VARCHAR(255)     NULL,
            `status`      VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`     TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`  CHAR(36)         NULL,
            `updated_by`  CHAR(36)         NULL,
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME         NULL,
            PRIMARY KEY (`acl_role_id`),
            UNIQUE KEY `uq_acl_role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `acl_role`",
    ],
];
