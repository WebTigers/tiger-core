<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * PHPUnit bootstrap for tiger-core.
 *
 * tiger-core is a framework *library* (PSR-0 `Tiger_*` + `Zend_*` from webtigers/tigerzf), normally
 * consumed from an app's vendor/. To test it in isolation we `composer install` its own dev deps
 * (tigerzf, sodium_compat, phpunit) into ./vendor and boot ONLY the autoloader here — never a full
 * web/app bootstrap. Unit tests exercise classes directly; integration tests (tests/Integration) opt
 * into a fixture DB via Tiger\Tests\Support\IntegrationTestCase.
 *
 * We intentionally do NOT run Tiger_Application: the point of the unit suite is to test units, not to
 * stand up a request. Anything a class needs from the environment (a config in Zend_Registry, a temp
 * dir, a key/pepper) is provided per-test by the Support base classes.
 */

error_reporting(E_ALL);

// APPLICATION_ENV governs a few code paths (error verbosity, throwExceptions). Tests run as 'testing'.
if (!defined('APPLICATION_ENV')) {
    define('APPLICATION_ENV', getenv('APPLICATION_ENV') ?: 'testing');
}

// APPLICATION_ROOT is referenced by a handful of path helpers; point it at the repo root so nothing
// resolves against a random cwd. (Most units never touch it.)
if (!defined('APPLICATION_ROOT')) {
    define('APPLICATION_ROOT', dirname(__DIR__));
}

// TIGER_CORE_PATH is the package root, which — when testing tiger-core in isolation — IS this repo.
// Tiger_Acl_Acl and a few others read core config (e.g. configs/acl.ini) relative to it, exactly as
// Tiger_Application sets it in a real boot.
if (!defined('TIGER_CORE_PATH')) {
    define('TIGER_CORE_PATH', dirname(__DIR__));
}

// APPLICATION_PATH is the consuming app's application/ dir — scanned for module acl.ini/config.
// In isolation there is no app, so we point it at an empty fixture: its modules/ glob is empty, so
// only tiger-core's OWN core+module config loads (deterministic — no app modules bleed in).
if (!defined('APPLICATION_PATH')) {
    define('APPLICATION_PATH', __DIR__ . '/fixtures/app');
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "\ntiger-core tests: vendor/ missing — run `composer install` in the repo root first.\n\n");
    exit(1);
}
require $autoload;

// ZF1 resolves some classes via the include_path (legacy Zend_Loader paths); make library/ + the
// tigerzf library reachable the same way the app bootstrap does, so nothing surprises us.
$includePaths = [
    dirname(__DIR__) . '/library',
    dirname(__DIR__) . '/vendor/webtigers/tigerzf/library',
    get_include_path(),
];
set_include_path(implode(PATH_SEPARATOR, array_filter($includePaths)));
