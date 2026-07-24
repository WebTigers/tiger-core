<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_AclController;
use System_LogsController;
use System_ModulesController;
use System_SettingsController;
use System_UpdatesController;
use Tiger\Tests\Support\ControllerTestCase;
use Zend_Config;
use Zend_Registry;
use Zend_Session;

/**
 * The System module's five admin-shell controllers (the Modules Manager, System Settings, Logs viewer,
 * ACL Simulator, and Updates screen). Each is deliberately THIN — it renders the screen and delegates
 * every mutation + data pull to an `/api` service (ADMIN.md) — so these dispatch tests cover the action
 * BODIES (view-var assignment, the model/config reads that build the screen) with rendering off, the way
 * `CoreControllerDispatchTest` does for the default-namespace controllers.
 *
 * The controllers were 0% before this test (never instantiated in the service-only Wave 4 suite); the
 * network-touching helpers (registry availability, the update checker) are made deterministic by seeding
 * their file caches so no dispatch reaches out to GitHub/Packagist.
 */
#[CoversClass(System_AclController::class)]
#[CoversClass(System_LogsController::class)]
#[CoversClass(System_ModulesController::class)]
#[CoversClass(System_SettingsController::class)]
#[CoversClass(System_UpdatesController::class)]
final class SystemControllerDispatchTest extends ControllerTestCase
{
    private bool $priorUnitTestMode;
    /** @var array<int,string> cache files this test created (removed in tearDown). */
    private array $seededCaches = [];
    private bool $setConfig = false;

    protected function setUp(): void
    {
        parent::setUp();

        // FlashMessenger (armed in Tiger_Controller_Action::init) uses a Zend_Session namespace —
        // enable ZF1's CLI-safe unit-test session mode exactly as CoreControllerDispatchTest does.
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];

        // System_SettingsController reads Zend_Config unconditionally; register a minimal one (an empty
        // `tiger` node exercises the "no override → defaults" branch cleanly).
        if (!Zend_Registry::isRegistered('Zend_Config')) {
            Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => []]));
            $this->setConfig = true;
        }

        // Make the registry-availability + update-check reads offline-deterministic (else a 30–60s
        // network timeout, and possibly a live version diff). A low "latest" → no pending update →
        // the Updates screen never fetches changelog notes.
        $this->seedCache($this->registryCacheFile(), json_encode(['fetched' => time(), 'modules' => []]));
        $this->seedCache($this->updateCacheFile('core'), json_encode(['v' => '0.0.1']));
    }

    protected function tearDown(): void
    {
        foreach ($this->seededCaches as $f) { @unlink($f); }
        if ($this->setConfig) {
            $reg = Zend_Registry::getInstance();
            unset($reg['Zend_Config']);
        }
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        parent::tearDown();
    }

    // ---- the thin render actions -----------------------------------------------------------------

    #[Test]
    public function the_acl_simulator_screen_dispatches_and_sets_its_title(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(System_AclController::class, 'index');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertStringContainsString('ACL Simulator', (string) $this->controller()->view->title);
    }

    #[Test]
    public function the_logs_screen_dispatches_and_sets_its_title(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(System_LogsController::class, 'index');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertStringContainsString('Logs', (string) $this->controller()->view->title);
    }

    #[Test]
    public function the_modules_screen_builds_the_on_disk_module_list(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(System_ModulesController::class, 'index');

        $this->assertSame(200, $res->getHttpResponseCode());
        $modules = $this->controller()->view->modules;
        $this->assertIsArray($modules);
        $this->assertNotEmpty($modules, 'the disk scan surfaced the bundled modules');

        // Each row carries the manifest + the derived activation/guard fields the view renders.
        $row = $modules[0];
        $this->assertArrayHasKey('active', $row);
        $this->assertArrayHasKey('protected', $row);
        $this->assertArrayHasKey('source', $row);

        // The PROTECTED core modules must be flagged so the view disables their toggle.
        $bySlug = [];
        foreach ($modules as $m) { $bySlug[$m['slug']] = $m; }
        $this->assertTrue($bySlug['system']['protected'], 'system is a protected module');
    }

    #[Test]
    public function the_add_module_screen_exposes_the_registry_state(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(System_ModulesController::class, 'add');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertNotEmpty((string) $this->controller()->view->registryUrl, 'the registry index URL is exposed');
        // Seeded cache → available() resolves true without any network call.
        $this->assertTrue((bool) $this->controller()->view->registryHasData);
    }

    #[Test]
    public function the_settings_screen_prefills_the_form_from_live_config(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(System_SettingsController::class, 'index');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertInstanceOf(\System_Form_Settings::class, $this->controller()->view->form);
        $this->assertStringContainsString('System Settings', (string) $this->controller()->view->title);
        // The signup kill-switch (lazy option tier) resolves to a bool for the checkbox.
        $this->assertIsBool($this->controller()->view->signupDisabled);
    }

    #[Test]
    public function the_updates_screen_reports_detection_without_reaching_the_network(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatchAction(System_UpdatesController::class, 'index');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertIsArray($this->controller()->view->updates, 'the checker result is passed to the view');
        // Seeded low "latest" → nothing pending → the pending list is empty (and no notes were fetched).
        $this->assertSame([], $this->controller()->view->pending);
        $this->assertIsArray($this->controller()->view->history);
    }

    // ---- cache seeding helpers -------------------------------------------------------------------

    private function seedCache(string $file, string $body): void
    {
        if (is_file($file)) { return; }        // never clobber a real cache
        @mkdir(dirname($file), 0775, true);
        if (@file_put_contents($file, $body) !== false) {
            $this->seededCaches[] = $file;
        }
    }

    /** Tiger_Module_Registry::_cacheFile() → APPLICATION_ROOT/storage/cache/registry-index.json. */
    private function registryCacheFile(): string
    {
        return rtrim(APPLICATION_ROOT, '/') . '/storage/cache/registry-index.json';
    }

    /** Tiger_Update_Checker::_cacheDir() → dirname(APPLICATION_PATH)/var/cache/updates/<key>.json. */
    private function updateCacheFile(string $key): string
    {
        return dirname(APPLICATION_PATH) . '/var/cache/updates/' . $key . '.json';
    }
}
