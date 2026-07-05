<?php
/**
 * Migration 0002 — create `user` (thin identity table).
 *
 * `user` is a reserved word in MySQL/MariaDB, hence the backticks everywhere. We
 * keep the name unprefixed (matching AskLevi's convention) for readability; the
 * models quote identifiers automatically.
 *
 * Deliberately minimal — just what's needed to authenticate and be referenced.
 * Profile data lives in an Account module's own table, not here (see
 * Tiger_Model_User for the rationale). `username` is UNIQUE but NULLable: MySQL
 * permits many NULLs in a unique index (NULL != NULL), so username-less users are
 * fine. `password_hash` is NULLable for SSO-only / not-yet-activated accounts.
 */
return array(
    'up' => array(
        "CREATE TABLE `user` (
            `user_id`       CHAR(36)     NOT NULL,
            `email`         VARCHAR(191) NOT NULL,   -- canonical login id
            `username`      VARCHAR(64)      NULL,
            `password_hash` VARCHAR(255)     NULL,   -- NULL = SSO-only / not activated
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
    ),
    'down' => array(
        "DROP TABLE IF EXISTS `user`",
    ),
);
