<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0012 — create `password_history` (retained old password hashes).
 *
 * WHY: `user_credential` holds only the CURRENT password hash (setPassword
 * overwrites on change). Reuse-prevention needs the OLD hashes, so we archive them
 * here on every change. Tiger_Policy_Password checks a candidate against the current
 * hash + the last N of these.
 *
 * Append-only history (NO mutable standard columns — a history row is never edited).
 * FK cascade: deleting a user removes their history. `secret` is VARBINARY like
 * user_credential.secret (a bcrypt hash; one-way, stored as-is). Pruned to the
 * policy's `history` count by Tiger_Model_PasswordHistory::prune so it can't grow
 * unbounded.
 */
return [
    'up' => [
        "CREATE TABLE `password_history` (
            `password_history_id` CHAR(36)        NOT NULL,   -- v7
            `user_id`             CHAR(36)        NOT NULL,
            `secret`              VARBINARY(1024) NOT NULL,    -- the retired password hash
            `created_at`          DATETIME        NOT NULL,    -- when it was retired
            PRIMARY KEY (`password_history_id`),
            KEY `ix_pwhist_user` (`user_id`, `created_at`),
            CONSTRAINT `fk_pwhist_user`
                FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `password_history`",
    ],
];
