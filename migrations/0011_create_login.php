<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0011 — create `login` (append-only authentication audit log).
 *
 * One row per login ATTEMPT (success, failure, or locked). This is the durable
 * security trail — distinct from `session` (live state, GC'd) and from general
 * change-audit tables (an app's job). It's the substrate for "recent sign-in
 * activity", brute-force/anomaly detection, and (later) rate-limiting.
 *
 * DELIBERATELY append-only + immutable: NO standard mutable columns
 * (deleted/updated_by/updated_at) — a log row is never edited. `created_at` +
 * a v7 PK give natural chronological ordering.
 *
 * Columns:
 *   - user_id: NULL when the identifier matched no user (a failed login for an
 *     unknown/typo'd email still gets logged — that's the point for brute-force
 *     detection).
 *   - identifier: what was actually typed (email/phone) — for failed-login analysis.
 *   - method: password | sms | oauth | … (how they authenticated).
 *   - result: success | failure | locked.
 *   - fingerprint: device fingerprint hash — reserved/nullable, TBD.
 *
 * Retention: grows forever; purge old rows on a schedule (Tiger_Model_Login::
 * purgeOlderThan) per your GDPR/retention policy — don't keep IPs indefinitely.
 */
return [
    'up' => [
        "CREATE TABLE `login` (
            `login_id`    CHAR(36)     NOT NULL,   -- v7 (time-ordered)
            `user_id`     CHAR(36)         NULL,   -- NULL if identifier matched no user
            `org_id`      CHAR(36)         NULL,   -- active org on success
            `identifier`  VARCHAR(191)     NULL,   -- what was typed (email/phone)
            `method`      VARCHAR(32)  NOT NULL DEFAULT 'password',  -- password|sms|oauth|…
            `result`      VARCHAR(16)  NOT NULL,   -- success|failure|locked
            `ip_address`  VARCHAR(64)      NULL,
            `user_agent`  VARCHAR(255)     NULL,
            `fingerprint` VARCHAR(128)     NULL,   -- device fingerprint hash (TBD)
            `created_at`  DATETIME     NOT NULL,
            PRIMARY KEY (`login_id`),
            KEY `ix_login_user`       (`user_id`, `created_at`),
            KEY `ix_login_ip`         (`ip_address`, `created_at`),
            KEY `ix_login_identifier` (`identifier`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `login`",
    ],
];
