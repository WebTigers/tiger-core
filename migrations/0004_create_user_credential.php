<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0004 — create `user_credential` (durable auth factors, 1-to-many).
 *
 * One row per authentication factor a user holds. Auth is multi-factor/multi-method
 * by nature — a user may have a password AND an SMS number AND a TOTP app AND
 * several passkeys AND an SSO link — so this is a COLLECTION, not a wide 1-to-1 row.
 * New factor types slot in with zero schema change (just a new `type` value).
 *
 * Column notes:
 *   - `type`: 'password' | 'sms' | 'totp' | 'webauthn' | 'oauth' (extensible).
 *   - `identifier`: the factor's sub-key — phone E.164 for sms, provider subject
 *     ("google:1234") for oauth, the authenticator credential id for webauthn.
 *     NOT NULL DEFAULT '' (empty) for singleton types (password/totp) so the UNIQUE
 *     below enforces "one password per user" — a NULL identifier would let MySQL
 *     store duplicates (NULL != NULL).
 *   - `secret`: VARBINARY so it holds either an ASCII hash or binary bytes. Semantics
 *     are per-type and enforced by the model, NOT the schema:
 *       * password  -> a one-way hash (bcrypt via password_hash) — store as-is.
 *       * totp/oauth -> a SHARED secret / refresh token — the app MUST encrypt it
 *         before storing (it's reversible; a hash won't do).
 *       * webauthn  -> the credential public key.
 *   - `verified_at`: NULL = pending (e.g. an SMS number awaiting OTP confirmation).
 *   - `last_used_at`: for "last sign-in with this factor" + stale-factor cleanup.
 *
 * Indexes:
 *   - UNIQUE (user_id, type, identifier): one factor per (user, type, identifier).
 *     NOTE: this 3-column key on utf8mb4 exceeds the legacy 767-byte prefix limit;
 *     it needs a modern InnoDB large-prefix (default on MySQL 5.7+/MariaDB 10.2+).
 *   - (type, identifier): reverse lookup for "log in by phone / by oauth subject".
 */
return [
    'up' => [
        "CREATE TABLE `user_credential` (
            `credential_id` CHAR(36)        NOT NULL,
            `user_id`       CHAR(36)        NOT NULL,
            `type`          VARCHAR(32)     NOT NULL,             -- password|sms|totp|webauthn|oauth
            `identifier`    VARCHAR(191)    NOT NULL DEFAULT '',  -- phone/subject/cred-id; '' for singletons
            `secret`        VARBINARY(1024)     NULL,             -- hash / encrypted secret / pubkey (per type)
            `verified_at`   DATETIME            NULL,             -- NULL = pending confirmation
            `last_used_at`  DATETIME            NULL,
            `failed_count`  INT             NOT NULL DEFAULT 0,  -- consecutive failures (login lockout)
            `locked_until`  DATETIME            NULL,            -- lockout expiry (brute-force guard)
            `status`        VARCHAR(32)     NOT NULL DEFAULT 'active',
            `deleted`       TINYINT(1)      NOT NULL DEFAULT 0,
            `created_by`    CHAR(36)            NULL,
            `updated_by`    CHAR(36)            NULL,
            `created_at`    DATETIME        NOT NULL,
            `updated_at`    DATETIME            NULL,
            PRIMARY KEY (`credential_id`),
            UNIQUE KEY `uq_user_credential`     (`user_id`, `type`, `identifier`),
            KEY        `ix_user_credential_look` (`type`, `identifier`),
            CONSTRAINT `fk_user_credential_user`
                FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `user_credential`",
    ],
];
