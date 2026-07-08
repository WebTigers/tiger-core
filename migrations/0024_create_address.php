<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0024 — create `address` (a postal location).
 *
 * A plain, reusable street/postal address, kept deliberately generic so ANY owner
 * (org, user, or a future module entity) can point at it via a link table — the
 * address itself carries no owner, no role, no label. That lives on the link row
 * (see org_address / user_address), so one physical address can be shared and each
 * relationship can label it independently (billing here, shipping there).
 *
 * Country is ISO-3166-1 alpha-2 (`US`, `GB`); `region` is the state/province in the
 * local vocabulary. Optional `latitude`/`longitude` cache a geocode for map/proximity
 * work — indexed together (ix_address_geo) for bounding-box lookups.
 */
return [
    'up' => [
        "CREATE TABLE `address` (
            `address_id` CHAR(36)      NOT NULL,                     -- UUID v7 (time-ordered)
            `line1`      VARCHAR(191)      NULL,                     -- street address, line 1
            `line2`      VARCHAR(191)      NULL,                     -- unit / suite / line 2
            `city`       VARCHAR(128)      NULL,
            `region`     VARCHAR(128)      NULL,                     -- state / province
            `postal`     VARCHAR(32)       NULL,                     -- postal / ZIP code
            `country`    CHAR(2)           NULL,                     -- ISO-3166-1 alpha-2
            `latitude`   DECIMAL(10,7)     NULL,                     -- cached geocode
            `longitude`  DECIMAL(10,7)     NULL,                     -- cached geocode
            `status`     VARCHAR(16)   NOT NULL DEFAULT 'active',
            `deleted`    TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by` CHAR(36)          NULL,
            `updated_by` CHAR(36)          NULL,
            `created_at` DATETIME      NOT NULL,
            `updated_at` DATETIME          NULL,
            PRIMARY KEY (`address_id`),
            KEY `ix_address_geo` (`latitude`, `longitude`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `address`",
    ],
];
