<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0002 — create `user` (thin identity table).
 *
 * `user` is a reserved word in MySQL/MariaDB, hence the backticks everywhere. We
 * keep the name unprefixed (matching AskLevi's convention) for readability; the
 * models quote identifiers automatically.
 *
 * Deliberately minimal — PURE IDENTITY: who someone is + lifecycle state. There is
 * NO password here — credentials (password, SMS, TOTP, passkeys, SSO) live in
 * `user_credential` (1-to-many; migration 0004), because auth is multi-factor by
 * nature and a credential is not identity. Profile data (name, avatar, phone as
 * contact, prefs) lives in an Account module's own table. `username` is UNIQUE but
 * NULLable: MySQL permits many NULLs in a unique index (NULL != NULL), so
 * username-less users are fine.
 */
return [
    'up' => [
        "CREATE TABLE `user` (
            `user_id`       CHAR(36)     NOT NULL,
            `email`         VARCHAR(191) NOT NULL,   -- canonical login id
            `username`      VARCHAR(64)      NULL,
            `status`        VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`       TINYINT(1)   NOT NULL DEFAULT 0,   -- soft-delete flag (1 = deleted)
            `created_by`    CHAR(36)         NULL,             -- user_id who created (NULL = system/genesis)
            `updated_by`    CHAR(36)         NULL,             -- user_id who last updated
            `created_at`    DATETIME     NOT NULL,
            `updated_at`    DATETIME         NULL,
            PRIMARY KEY (`user_id`),
            UNIQUE KEY `uq_user_email`    (`email`),
            UNIQUE KEY `uq_user_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `user`",
    ],
];
