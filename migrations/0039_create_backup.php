<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0039 — create `backup` (TigerBackup's catalog).
 *
 * One row per backup archive. Metadata lives here (the Tiger way — bytes in storage, index in the
 * DB), so listing, rolling retention, download, and restore never need a remote `list()` call: the
 * row knows the `disk` + `storage_key` where its zip lives. `pinned` marks manual backups so
 * retention (max N scheduled) never prunes them — "manual remove only". `outcome`/`source` mirror
 * schedule_run (not the standard `status`, whose semantics differ). Additive-only per AGENTS.md.
 */
return [
    'up' => [
        "CREATE TABLE `backup` (
            `backup_id`   CHAR(36)      NOT NULL,
            `filename`    VARCHAR(191)  NOT NULL,                    -- TigerBackup-YYYY-MM-DD-HH-MM.zip
            `disk`        VARCHAR(64)   NOT NULL DEFAULT 'local',    -- destination: local | a media disk name
            `storage_key` VARCHAR(512)      NULL,                    -- adapter-relative key / local path
            `components`  VARCHAR(191)  NOT NULL DEFAULT '',         -- csv: database,media,modules,platform
            `size_bytes`  BIGINT            NULL,
            `checksum`    CHAR(64)          NULL,                    -- sha256 of the archive
            `outcome`     VARCHAR(16)   NOT NULL DEFAULT 'running',  -- running | ok | error
            `error`       VARCHAR(1000)     NULL,
            `source`      VARCHAR(16)   NOT NULL DEFAULT 'manual',   -- manual | scheduled
            `pinned`      TINYINT(1)    NOT NULL DEFAULT 0,          -- 1 = never auto-pruned (manual)
            `duration_ms` INT               NULL,
            `manifest`    TEXT              NULL,                    -- manifest.json (small)
            `status`      VARCHAR(32)   NOT NULL DEFAULT 'active',
            `deleted`     TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`  CHAR(36)          NULL,
            `updated_by`  CHAR(36)          NULL,
            `created_at`  DATETIME      NOT NULL,
            `updated_at`  DATETIME          NULL,
            PRIMARY KEY (`backup_id`),
            KEY `ix_backup_list` (`deleted`, `created_at`),
            KEY `ix_backup_prune` (`source`, `pinned`, `outcome`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `backup`",
    ],
];
