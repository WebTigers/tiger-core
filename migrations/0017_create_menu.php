<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0017 — create `menu` (custom navigation menus).
 *
 * ONE flat, self-referential table: a "menu" is simply the set of rows sharing
 * (`org_id`, `menu_key`) — exactly how `page` groups content by org/locale. Top-level
 * items have `parent_id` NULL; nesting is an adjacency list (`parent_id` + `sort_order`),
 * the same shape as `page`'s content tree. No separate container table: an empty menu is
 * just a `menu_key` with no rows yet.
 *
 * TENANCY (`org_id`, scope-style like page/config): '' = global/platform; a real org_id
 * = tenant-owned. Resolution is MENU-LEVEL override — if a tenant has any rows for a
 * `menu_key`, that whole menu replaces the global one (you don't merge item trees).
 *
 * Properties are stored 1-to-1 (no JSON blob, no type+target gang) so the admin editor
 * and the renderer read plain columns:
 *   - `label`      a translation key OR literal text — ALWAYS run through Zend_Translate.
 *   - `page_key`   link a CMS page by key (resolves to its current locale/tenant slug).
 *   - `url`        a literal href (path or absolute). page_key wins over url; neither set
 *                  = a non-link heading.
 *   - `dom_id`     the rendered element's HTML id (JS targeting).
 *   - `css_class`  one or more space-separated classes.
 *   - `icon`       an icon class (e.g. Font Awesome).
 *   - `link_target`/`link_rel`  anchor target (_blank) / rel.
 *   - `resource`/`privilege`    ACL gate — Tiger_Menu::getHTML() hides an item the live
 *                  role can't reach; getData() ignores these (raw).
 *
 * Rendering compiles rows to a Zend_Navigation tree (Tiger_Menu / getHTML), so auth-hide,
 * href assembly, and active-state ride the nav layer — see library/Tiger/Menu.php.
 */
return [
    'up' => [
        "CREATE TABLE `menu` (
            `menu_id`     CHAR(36)      NOT NULL,                      -- UUID v7 (time-ordered)
            `org_id`      VARCHAR(36)   NOT NULL DEFAULT '',          -- '' = global; else tenant (tenant menu wins)
            `menu_key`    VARCHAR(191)  NOT NULL,                      -- which menu ('primary','footer',…)
            `parent_id`   CHAR(36)          NULL,                      -- self-ref nesting (NULL = top level)
            `sort_order`  INT UNSIGNED  NOT NULL DEFAULT 0,           -- order among siblings
            `label`       VARCHAR(255)      NULL,                      -- key OR literal; always Zend_Translate'd
            `page_key`    VARCHAR(191)      NULL,                      -- link a CMS page by key
            `url`         VARCHAR(2048)     NULL,                      -- literal href (page_key wins over this)
            `dom_id`      VARCHAR(191)      NULL,                      -- HTML id for JS targeting
            `css_class`   VARCHAR(255)      NULL,                      -- one or more classes
            `icon`        VARCHAR(64)       NULL,                      -- icon class (FA)
            `link_target` VARCHAR(16)       NULL,                      -- _self | _blank
            `link_rel`    VARCHAR(64)       NULL,                      -- rel (noopener/nofollow)
            `resource`    VARCHAR(191)      NULL,                      -- ACL resource (getHTML honors)
            `privilege`   VARCHAR(64)       NULL,                      -- ACL privilege
            `status`      VARCHAR(16)   NOT NULL DEFAULT 'published', -- published | draft
            `deleted`     TINYINT(1)    NOT NULL DEFAULT 0,
            `created_by`  CHAR(36)          NULL,
            `updated_by`  CHAR(36)          NULL,
            `created_at`  DATETIME      NOT NULL,
            `updated_at`  DATETIME          NULL,
            PRIMARY KEY (`menu_id`),
            KEY `ix_menu_group` (`org_id`, `menu_key`, `status`, `deleted`),
            KEY `ix_menu_tree`  (`parent_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `menu`",
    ],
];
