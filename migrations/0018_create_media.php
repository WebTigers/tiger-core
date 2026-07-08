<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0018 — create `media` (the file store's metadata; see MEDIA.md).
 *
 * One row per stored file. The BYTES live in a storage adapter (filesystem / S3 / …,
 * named by `disk` + `storage_key`); this table is the queryable metadata + generated
 * variants + scan state. Tenant- and locale-scoped like `page` (org row wins over the
 * global '' row; `locale` for localized assets/captions, '' = language-neutral).
 *
 * `variants` (JSON) records generated derivatives — {thumbnail:{key,w,h}, small:{…},
 * pdf_preview:{key}, …} — each a key back into the same disk. `scan_status`/`scan_meta`
 * carry the optional virus + AI-moderation results (a video stays `in_review` + private
 * until an async webhook approves it). `kind` is the coarse category the library filters
 * on and that selects the variant pipeline.
 */
return [
    'up' => [
        "CREATE TABLE `media` (
            `media_id`    CHAR(36)       NOT NULL,                       -- UUID v7
            `org_id`      VARCHAR(36)    NOT NULL DEFAULT '',           -- '' = global; else tenant (tenant wins)
            `locale`      VARCHAR(8)     NOT NULL DEFAULT '',           -- language tag; '' = language-neutral
            `disk`        VARCHAR(32)    NOT NULL DEFAULT 'local',      -- which storage adapter holds the bytes
            `storage_key` VARCHAR(512)   NOT NULL,                       -- adapter-relative key/path
            `visibility`  VARCHAR(16)    NOT NULL DEFAULT 'public',     -- public | private
            `kind`        VARCHAR(16)    NOT NULL DEFAULT 'other',      -- image|document|pdf|video|audio|archive|other
            `mime_type`   VARCHAR(191)       NULL,
            `extension`   VARCHAR(16)        NULL,
            `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,          -- bytes
            `checksum`    CHAR(64)           NULL,                       -- sha256 of the bytes (dedupe/integrity)
            `width`       INT UNSIGNED       NULL,                       -- images
            `height`      INT UNSIGNED       NULL,
            `duration`    INT UNSIGNED       NULL,                       -- audio/video seconds
            `filename`    VARCHAR(255)       NULL,                       -- original upload name
            `title`       VARCHAR(255)       NULL,
            `caption`     VARCHAR(1024)      NULL,
            `alt_text`    VARCHAR(512)       NULL,
            `variants`    JSON               NULL,                       -- generated derivatives (thumbs, previews)
            `scan_status` VARCHAR(16)    NOT NULL DEFAULT 'skipped',    -- pending|skipped|clean|infected|rejected|in_review|approved
            `scan_meta`   JSON               NULL,                       -- ClamAV sig / AI scores / async job id
            `sort_order`  INT UNSIGNED   NOT NULL DEFAULT 0,
            `status`      VARCHAR(16)    NOT NULL DEFAULT 'active',
            `deleted`     TINYINT(1)     NOT NULL DEFAULT 0,
            `created_by`  CHAR(36)           NULL,
            `updated_by`  CHAR(36)           NULL,
            `created_at`  DATETIME       NOT NULL,
            `updated_at`  DATETIME           NULL,
            PRIMARY KEY (`media_id`),
            KEY `ix_media_lib`   (`org_id`, `kind`, `status`, `deleted`),
            KEY `ix_media_sum`   (`checksum`),
            KEY `ix_media_scan`  (`scan_status`),
            FULLTEXT KEY `ft_media` (`filename`, `title`, `caption`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `media`",
    ],
];
