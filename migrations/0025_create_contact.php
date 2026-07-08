<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0025 — create `contact` (a point of contact).
 *
 * A single contact channel — a phone number, an email address, or something else —
 * stored generically so any owner links to it through a join table (org_contact /
 * user_contact) rather than a column on this row. Like `address`, the contact holds
 * no owner and no relationship label; those live on the link.
 *
 * `kind` is the broad channel (phone | email | other) and drives how `value` is
 * interpreted/validated; `type` is the finer role of that channel (mobile | work |
 * home | fax | main). `kind` is indexed (ix_contact_kind) for "all phones" / "all
 * emails" sweeps. This is contact-as-DATA (reach someone); it is NOT the `sms` auth
 * factor — phone-for-login lives in user_credential, not here.
 */
return [
    'up' => [
        "CREATE TABLE `contact` (
            `contact_id` CHAR(36)      NOT NULL,                     -- UUID v7 (time-ordered)
            `kind`       VARCHAR(16)   NOT NULL DEFAULT 'phone',     -- phone | email | other
            `type`       VARCHAR(32)       NULL,                     -- mobile | work | home | fax | main
            `value`      VARCHAR(191)      NULL,                     -- the number / address / handle
            `status`     VARCHAR(16)   NOT NULL DEFAULT 'active',
            `deleted`    TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by` CHAR(36)          NULL,
            `updated_by` CHAR(36)          NULL,
            `created_at` DATETIME      NOT NULL,
            `updated_at` DATETIME          NULL,
            PRIMARY KEY (`contact_id`),
            KEY `ix_contact_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `contact`",
    ],
];
