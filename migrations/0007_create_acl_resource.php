<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0007 — create `acl_resource` (runtime resources, DB layer).
 *
 * An ACL resource is a class name the ACL governs — a controller class
 * (`Admin_IndexController`) or a service class (`Billing_Service_Invoice`). The
 * canonical set ships in `configs/acl.ini`; this table layers runtime additions.
 */
return [
    'up' => [
        "CREATE TABLE `acl_resource` (
            `acl_resource_id` CHAR(36)     NOT NULL,
            `resource`        VARCHAR(191) NOT NULL,   -- class name (controller/service)
            `description`     VARCHAR(255)     NULL,
            `status`          VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`         TINYINT(1)   NOT NULL DEFAULT 0,
            `created_by`      CHAR(36)         NULL,
            `updated_by`      CHAR(36)         NULL,
            `created_at`      DATETIME     NOT NULL,
            `updated_at`      DATETIME         NULL,
            PRIMARY KEY (`acl_resource_id`),
            UNIQUE KEY `uq_acl_resource` (`resource`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `acl_resource`",
    ],
];
