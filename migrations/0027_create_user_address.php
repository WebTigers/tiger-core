<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0027 — create `user_address` (user ↔ address link).
 *
 * The user-side twin of org_address (0026): joins a user to an owner-agnostic address
 * (0024) and carries the relationship metadata on the link — `label` (what this
 * address is to the user) and `is_primary` (their default). Same shape as the org
 * link so the two read and query identically.
 *
 * UNIQUE (user_id, address_id) prevents double-linking; the single-column indexes
 * serve "addresses for a user" and "users at an address".
 */
return [
    'up' => [
        "CREATE TABLE `user_address` (
            `user_address_id` CHAR(36)      NOT NULL,                -- UUID v7 (time-ordered)
            `user_id`         CHAR(36)      NOT NULL,
            `address_id`      CHAR(36)      NOT NULL,
            `label`           VARCHAR(32)       NULL,                -- primary | billing | shipping | home
            `is_primary`      TINYINT(1)    NOT NULL DEFAULT 0,
            `status`          VARCHAR(16)   NOT NULL DEFAULT 'active',
            `deleted`         TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`      CHAR(36)          NULL,
            `updated_by`      CHAR(36)          NULL,
            `created_at`      DATETIME      NOT NULL,
            `updated_at`      DATETIME          NULL,
            PRIMARY KEY (`user_address_id`),
            UNIQUE KEY `uq_user_address` (`user_id`, `address_id`),
            KEY `ix_ua_user` (`user_id`),
            KEY `ix_ua_address` (`address_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `user_address`",
    ],
];
