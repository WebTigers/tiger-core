<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0005 — create `auth_challenge` (transient, single-use proofs).
 *
 * A DIFFERENT concern from user_credential: these are the short-lived, one-time
 * challenges — SMS OTP codes, email-verification links, password-reset tokens,
 * magic links. They're ephemeral and must never mix with durable credentials.
 *
 * Security choices (this is the sharp end of auth — get it right):
 *   - `challenge_id` is a **v4** UUID (opaque). Unlike entity ids it must NOT leak
 *     creation time, because it can appear in a magic-link / reset URL. (This is the
 *     "secrets/tokens -> v4" half of the Tiger_Uuid rule.)
 *   - `code_hash` stores a HASH of the code/token, never the plaintext. If the DB
 *     leaks, the codes are useless. SHA-256 is fine here (codes are short-lived AND
 *     attempt-limited); verification uses hash_equals() for timing safety.
 *   - `expires_at`: hard TTL — expired challenges never redeem.
 *   - `consumed_at`: single-use — set on first successful redeem; a second attempt
 *     fails even with the right code.
 *   - `attempts`: brute-force guard — lock the challenge after N wrong tries so a
 *     6-digit OTP can't be walked.
 *   - `user_id` is NULLable: some flows (e.g. "enter your email to reset") issue a
 *     challenge before a user is resolved. FK is ON DELETE CASCADE.
 *
 * Index on `expires_at` supports a periodic cleanup sweep of dead challenges.
 */
return [
    'up' => [
        "CREATE TABLE `auth_challenge` (
            `challenge_id` CHAR(36)     NOT NULL,   -- v4 (opaque)
            `user_id`      CHAR(36)         NULL,   -- NULL during pre-login flows
            `type`         VARCHAR(32)  NOT NULL,   -- sms_otp|email_verify|password_reset|magic_link
            `code_hash`    VARCHAR(255) NOT NULL,   -- hash of the code/token (never plaintext)
            `expires_at`   DATETIME     NOT NULL,
            `consumed_at`  DATETIME         NULL,   -- single-use
            `attempts`     INT          NOT NULL DEFAULT 0,
            `status`       VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`      TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`   CHAR(36)         NULL,
            `updated_by`   CHAR(36)         NULL,
            `created_at`   DATETIME     NOT NULL,
            `updated_at`   DATETIME         NULL,
            PRIMARY KEY (`challenge_id`),
            KEY `ix_auth_challenge_user`   (`user_id`),
            KEY `ix_auth_challenge_expiry` (`expires_at`),
            CONSTRAINT `fk_auth_challenge_user`
                FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `auth_challenge`",
    ],
];
