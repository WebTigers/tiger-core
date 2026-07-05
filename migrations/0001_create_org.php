<?php
/**
 * Migration 0001 — create `org` (the tenant table).
 *
 * A migration file returns ['up' => [...sql], 'down' => [...sql]] — arrays of
 * statements run in order. Tiger_Db_Migrator applies un-applied migrations by
 * ascending version (the numeric filename prefix) and records each in the
 * `tiger_migration` table so it never runs twice.
 *
 * Schema choices (apply to the whole substrate):
 *   - CHAR(36) UUID PKs (see Tiger_Uuid): portable, human-readable, v7 = index-local.
 *   - InnoDB: we need foreign keys + row-level locking; the substrate is relational.
 *   - utf8mb4 / utf8mb4_unicode_ci: full Unicode (incl. emoji) — the modern default.
 *   - VARCHAR(191) on UNIQUE-indexed text: 191 * 4 bytes = 764 < the 767-byte index
 *     limit on older MySQL (5.6). Keeps the schema portable to any MySQL/MariaDB.
 *   - DATETIME (not TIMESTAMP): no 2038 problem, no implicit timezone surprises;
 *     timestamps are written by the app in UTC (Tiger_Model_Table::_now).
 *
 * STANDARD COLUMNS — every Tiger DOMAIN table carries these, maintained
 * automatically by Tiger_Model_Table:
 *   status      VARCHAR(32)  — lifecycle state (active/suspended/…)
 *   deleted     TINYINT(1)   — soft-delete flag (0/1); reads exclude deleted by default
 *   created_by  CHAR(36)     — user_id who created (NULL = system/genesis)
 *   updated_by  CHAR(36)     — user_id who last updated
 *   created_at  DATETIME     — set on insert
 *   updated_at  DATETIME     — refreshed on update
 * created_by/updated_by are intentionally NOT foreign-keyed: they're informational
 * stamps that must not block deleting the user they point at. Audit TRAILS (change
 * history) are an app concern, not core's.
 *
 * NOTE (soft-delete + UNIQUE): a soft-deleted row still occupies its unique values
 * (e.g. org.slug). If an app needs to reuse a slug/email after soft-delete, it
 * handles that policy (e.g. rename-on-delete) — core doesn't force one.
 */
return array(
    'up' => array(
        "CREATE TABLE `org` (
            `org_id`        CHAR(36)     NOT NULL,
            `parent_org_id` CHAR(36)         NULL,   -- self-ref hierarchy; NULL = root org
            `name`          VARCHAR(255) NOT NULL,
            `slug`          VARCHAR(191) NOT NULL,   -- URL/route-safe identifier
            `status`        VARCHAR(32)  NOT NULL DEFAULT 'active',
            `deleted`       TINYINT(1)   NOT NULL DEFAULT 0,   -- soft-delete flag (1 = deleted)
            `created_by`    CHAR(36)         NULL,             -- user_id who created (NULL = system/genesis)
            `updated_by`    CHAR(36)         NULL,             -- user_id who last updated
            `created_at`    DATETIME     NOT NULL,
            `updated_at`    DATETIME         NULL,
            PRIMARY KEY (`org_id`),
            UNIQUE KEY `uq_org_slug` (`slug`),
            KEY `ix_org_parent` (`parent_org_id`),
            -- Deleting a parent org orphans (does not delete) its children:
            CONSTRAINT `fk_org_parent`
                FOREIGN KEY (`parent_org_id`) REFERENCES `org` (`org_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ),
    'down' => array(
        "DROP TABLE IF EXISTS `org`",
    ),
);
