<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0037 — add `locale` + `timezone` to `user`.
 *
 * The one deliberate exception to the thin-user rule (ARCHITECTURE §7): a user's language
 * and timezone are request-critical i18n primitives resolved on essentially every
 * authenticated request (LocalePrefix reads `user.locale` ahead of the device cookie so
 * the choice follows the person across devices). Co-locating them with identity — the way
 * Laravel/Django/Rails do — is the pragmatic call over a per-request join into the Account
 * extension. Open-ended profile data still belongs in that extension, not here.
 *
 * Both NULL = "no explicit preference" → the resolver falls through to cookie/browser/default.
 * Language-only per Tiger convention (`en`, `es`); the width leaves room for a region subtag.
 * Timezone holds an IANA name (e.g. `America/New_York`). Additive-only per AGENTS.md.
 */
return [
    'up' => [
        "ALTER TABLE `user`
            ADD COLUMN `locale`   VARCHAR(12) NULL DEFAULT NULL AFTER `username`,
            ADD COLUMN `timezone` VARCHAR(64) NULL DEFAULT NULL AFTER `locale`",
    ],
    'down' => [
        "ALTER TABLE `user` DROP COLUMN `timezone`, DROP COLUMN `locale`",
    ],
];
