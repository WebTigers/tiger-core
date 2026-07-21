<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0040 — add `media.description` and fold it into the ft_media FULLTEXT index.
 *
 * A longer, searchable description of a media item — hand-entered in the media editor, or (the
 * intended edge case) auto-populated from an AWS Rekognition label/scene scan so an image becomes
 * findable by what's *in* it. Rebuilds `ft_media` to cover it so it ranks in site search alongside
 * filename/title/caption (Tiger_Model_Media::search / the Tiger_Search "media" provider).
 */
return [
    'up' => [
        "ALTER TABLE `media` ADD COLUMN `description` TEXT NULL AFTER `alt_text`",
        "ALTER TABLE `media` DROP INDEX `ft_media`, ADD FULLTEXT `ft_media` (`filename`,`title`,`caption`,`description`)",
    ],
    'down' => [
        "ALTER TABLE `media` DROP INDEX `ft_media`, ADD FULLTEXT `ft_media` (`filename`,`title`,`caption`)",
        "ALTER TABLE `media` DROP COLUMN `description`",
    ],
];
