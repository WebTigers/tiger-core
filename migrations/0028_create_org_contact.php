<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0028 — create `org_contact` (org ↔ contact link).
 *
 * Joins an org to a contact channel (0025), with the relationship metadata on the
 * link, not the contact: `label` names the channel's role for this org and
 * `is_primary` marks the org's default point of contact. One shared contact row can
 * therefore mean different things to different orgs.
 *
 * UNIQUE (org_id, contact_id) prevents double-linking; the single-column indexes
 * serve "contacts for an org" and "orgs at a contact".
 */
return [
    'up' => [
        "CREATE TABLE `org_contact` (
            `org_contact_id` CHAR(36)      NOT NULL,                 -- UUID v7 (time-ordered)
            `org_id`         CHAR(36)      NOT NULL,
            `contact_id`     CHAR(36)      NOT NULL,
            `label`          VARCHAR(32)       NULL,                 -- primary | billing | support | main
            `is_primary`     TINYINT(1)    NOT NULL DEFAULT 0,
            `status`         VARCHAR(16)   NOT NULL DEFAULT 'active',
            `deleted`        TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`     CHAR(36)          NULL,
            `updated_by`     CHAR(36)          NULL,
            `created_at`     DATETIME      NOT NULL,
            `updated_at`     DATETIME          NULL,
            PRIMARY KEY (`org_contact_id`),
            UNIQUE KEY `uq_org_contact` (`org_id`, `contact_id`),
            KEY `ix_oc_org` (`org_id`),
            KEY `ix_oc_contact` (`contact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `org_contact`",
    ],
];
