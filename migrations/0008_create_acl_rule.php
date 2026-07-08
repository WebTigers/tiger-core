<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0008 — create `acl_rule` (runtime allow/deny rules, DB layer).
 *
 * Each row is one Zend_Acl rule. `role`/`resource`/`privilege` hold a name or the
 * wildcard `*` (mapped to Zend_Acl "all" = null by the loader). `permission` is
 * allow|deny|removeAllow|removeDeny. DB rules load LAST, so they win on conflict
 * (Zend_Acl is last-write-wins per role/resource/privilege).
 *
 * The deny-all baseline itself is just data (`role=* resource=* privilege=* deny`),
 * shipped in core `acl.ini` — the engine hard-codes no policy.
 */
return [
    'up' => [
        "CREATE TABLE `acl_rule` (
            `acl_rule_id` CHAR(36)     NOT NULL,
            `role`        VARCHAR(64)      NULL,   -- role name, or '*' = all
            `resource`    VARCHAR(191)     NULL,   -- resource, or '*' = all
            `privilege`   VARCHAR(191)     NULL,   -- action/privilege, or '*' = all
            `permission`  VARCHAR(16)  NOT NULL DEFAULT 'allow',  -- allow|deny|removeAllow|removeDeny
            `status`      VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`     TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`  CHAR(36)         NULL,
            `updated_by`  CHAR(36)         NULL,
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME         NULL,
            PRIMARY KEY (`acl_rule_id`),
            KEY `ix_acl_rule` (`role`, `resource`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `acl_rule`",
    ],
];
