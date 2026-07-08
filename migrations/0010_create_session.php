<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0010 — create `session` (DB-backed session store).
 *
 * WHY DB sessions: behind a load balancer with MORE THAN ONE app instance, PHP's
 * default file sessions live on separate boxes → random logouts. A shared DB (or
 * Redis) store fixes that. Tiger uses this handler whenever a DB is configured (see
 * Tiger_Session_SaveHandler_DbTable + Bootstrap::_initSession); a single-box dev can
 * still fall back to files.
 *
 * A SYSTEM/transient table (not a domain entity), so NO standard columns. Columns
 * match Zend_Session_SaveHandler_DbTable's expectations (session_id/modified/
 * lifetime/data) plus stamped context (user_id/username/role/org_id/ip) for
 * auditing + admin "your sessions" / force-logout. `data` is MEDIUMTEXT (sessions
 * can be large). GC reaps rows where (now - modified) > lifetime.
 */
return [
    'up' => [
        "CREATE TABLE `session` (
            `session_id`    VARCHAR(128) NOT NULL,
            `modified`      INT              NULL,
            `lifetime`      INT              NULL,
            `data`          MEDIUMTEXT       NULL,
            `user_id`       VARCHAR(36)      NULL,
            `username`      VARCHAR(64)      NULL,
            `role`          VARCHAR(32)      NULL,
            `org_id`        VARCHAR(36)      NULL,   -- active tenant (Tiger addition)
            `ip_address`    VARCHAR(64)      NULL,
            `last_activity` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`session_id`),
            KEY `idx_session_user` (`user_id`),
            KEY `idx_session_role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `session`",
    ],
];
