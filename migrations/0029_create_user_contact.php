<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0029 — create `user_contact` (user ↔ contact link).
 *
 * The user-side twin of org_contact (0028): joins a user to a contact channel (0025)
 * with `label` (the channel's role for this user) and `is_primary` (their default) on
 * the link. Same shape as the org link so the two behave identically. This is
 * contact-as-DATA (how to reach the person) — NOT the `sms` auth factor, which lives
 * in user_credential.
 *
 * UNIQUE (user_id, contact_id) prevents double-linking; the single-column indexes
 * serve "contacts for a user" and "users at a contact".
 */
return [
    'up' => [
        "CREATE TABLE `user_contact` (
            `user_contact_id` CHAR(36)      NOT NULL,                -- UUID v7 (time-ordered)
            `user_id`         CHAR(36)      NOT NULL,
            `contact_id`      CHAR(36)      NOT NULL,
            `label`           VARCHAR(32)       NULL,                -- primary | mobile | work | home
            `is_primary`      TINYINT(1)    NOT NULL DEFAULT 0,
            `status`          VARCHAR(16)   NOT NULL DEFAULT 'active',
            `deleted`         TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`      CHAR(36)          NULL,
            `updated_by`      CHAR(36)          NULL,
            `created_at`      DATETIME      NOT NULL,
            `updated_at`      DATETIME          NULL,
            PRIMARY KEY (`user_contact_id`),
            UNIQUE KEY `uq_user_contact` (`user_id`, `contact_id`),
            KEY `ix_uc_user` (`user_id`),
            KEY `ix_uc_contact` (`contact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `user_contact`",
    ],
];
