<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Backfill `page.org_id` for content that predates org_id write-stamping. Historically CMS pages (and
 * blog articles — both live in `page`) were saved with `org_id = ''`; now that the base model stamps the
 * authenticated org and public reads scope to the site org, that legacy `''` content is assigned to the
 * **site org** (Tiger_Model_Org::siteOrgId() — the configured `tiger.site.org_id`, else the founding org)
 * so it's owned rather than shared. A fresh install (empty `page`) no-ops; a stock install stamps its one
 * site org; the read cascade [org, ''] means nothing 404s at any point during this transition.
 *
 * DATA migration (a PHP callable — see Tiger_Db_Migrator). One-way: `down` is a no-op.
 */
return [
    'up' => [
        function ($db) {
            $org = Tiger_Model_Org::siteOrgId();
            if ($org !== '') {
                $db->update('page', ['org_id' => $org], "org_id = ''");
            }
        },
    ],
    'down' => [],
];
