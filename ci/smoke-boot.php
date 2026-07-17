<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * CI smoke: boot the app through the real Tiger_Application path and assert the platform is live —
 * the DB adapter is wired and the migrated schema + founding admin exist. Run from the app root
 * (cwd = the skeleton app dir): `php <tiger-core>/ci/smoke-boot.php`. Exit non-zero on any failure.
 */
$root = getcwd();
define('APPLICATION_ROOT', $root);
require $root . '/vendor/autoload.php';

(new Tiger_Application($root))->boot();

if (!class_exists('Tiger_Version') || Tiger_Version::VERSION === '') {
    fwrite(STDERR, "smoke: Tiger_Version::VERSION is empty after boot\n");
    exit(1);
}
$db = Zend_Db_Table_Abstract::getDefaultAdapter();
if (!$db) {
    fwrite(STDERR, "smoke: no default DB adapter after boot (check tiger.db.* in local.ini)\n");
    exit(1);
}

// migrations applied → core tables exist; install:admin ran → one founding org + user.
$orgs  = (int) $db->fetchOne('SELECT COUNT(*) FROM `org`');
$users = (int) $db->fetchOne('SELECT COUNT(*) FROM `user`');
if ($orgs < 1 || $users < 1) {
    fwrite(STDERR, "smoke: expected the founding org+user (org=$orgs user=$users)\n");
    exit(1);
}

// Seed a PUBLISHED CMS page stamped with the site org, so the HTTP smoke can prove public page dispatch
// resolves org-owned content (the org_id write-stamp + read-scope path). Idempotent-ish: fresh CI DB.
$siteOrg = Tiger_Model_Org::siteOrgId();
if ($siteOrg === '') {
    fwrite(STDERR, "smoke: could not resolve the site org\n");
    exit(1);
}
Tiger_Model_Table::setOrg($siteOrg);   // the base model will stamp org_id from this
$pageModel = new Tiger_Model_Page();
$pageModel->insert([
    'type'         => Tiger_Model_Page::TYPE_PAGE,
    'page_key'     => 'ci-smoke-page',
    'slug'         => 'ci-smoke-page',
    'locale'       => 'en',
    'title'        => 'CI Smoke Page',
    'body'         => 'CI_SMOKE_PAGE_OK',
    'format'       => 'html',
    'status'       => Tiger_Model_Page::STATUS_PUBLISHED,
    'published_at' => null,
]);
$seeded = $db->fetchRow("SELECT org_id, status FROM page WHERE slug = 'ci-smoke-page'");
if (!$seeded || (string) $seeded['org_id'] !== (string) $siteOrg) {
    fwrite(STDERR, "smoke: seeded page missing or not org-stamped (got org_id=" . ($seeded['org_id'] ?? 'none') . ", want $siteOrg)\n");
    exit(1);
}

echo "smoke boot OK — tiger-core v" . Tiger_Version::VERSION . ", org=$orgs user=$users; seeded page org_id=" . $seeded['org_id'] . "\n";
