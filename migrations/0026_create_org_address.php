<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0026 — create `org_address` (org ↔ address link).
 *
 * Joins an org to an address, keeping the address itself owner-agnostic (see 0024).
 * The RELATIONSHIP metadata lives here, not on the address: `label` says what this
 * address is TO this org (primary | billing | shipping | hq), and `is_primary` flags
 * the org's default. Because the label rides the link, one shared address row can be
 * "billing" to one org and "hq" to another.
 *
 * UNIQUE (org_id, address_id) stops linking the same address to the same org twice;
 * the two single-column indexes serve the "addresses for an org" and "orgs at an
 * address" lookups.
 */
return [
    'up' => [
        "CREATE TABLE `org_address` (
            `org_address_id` CHAR(36)      NOT NULL,                 -- UUID v7 (time-ordered)
            `org_id`         CHAR(36)      NOT NULL,
            `address_id`     CHAR(36)      NOT NULL,
            `label`          VARCHAR(32)       NULL,                 -- primary | billing | shipping | hq
            `is_primary`     TINYINT(1)    NOT NULL DEFAULT 0,
            `status`         VARCHAR(16)   NOT NULL DEFAULT 'active',
            `deleted`        TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`     CHAR(36)          NULL,
            `updated_by`     CHAR(36)          NULL,
            `created_at`     DATETIME      NOT NULL,
            `updated_at`     DATETIME          NULL,
            PRIMARY KEY (`org_address_id`),
            UNIQUE KEY `uq_org_address` (`org_id`, `address_id`),
            KEY `ix_oa_org` (`org_id`),
            KEY `ix_oa_address` (`address_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `org_address`",
    ],
];
