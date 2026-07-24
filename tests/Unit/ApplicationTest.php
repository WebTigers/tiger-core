<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Application;
use Tiger_Version;
use ReflectionMethod;
use ReflectionProperty;
use Zend_Config;

/**
 * Tiger_Application — the front door (proxy normalization, path constants, the include path, the
 * config cascade, and the guarded dispatch). The full boot needs a live Zend_Application + a request,
 * so this exercises the PURE, seam-able helpers directly (reflection + a controlled `$_SERVER`) and
 * leaves the orchestration (`run()`/`boot()`/`fail()`) to the live-boot boundary — see
 * WAVE5-FINDINGS-app.md. Path constants are already defined by the test bootstrap (pointing at the
 * repo, exactly as a real boot sets them), so the constant + config helpers run against real files.
 */
#[CoversClass(Tiger_Application::class)]
final class ApplicationTest extends UnitTestCase
{
    private array $server;
    private string $includePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server      = $_SERVER;         // proxy normalization mutates $_SERVER — snapshot it
        $this->includePath = get_include_path();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        set_include_path($this->includePath);
        parent::tearDown();
    }

    private function app(): Tiger_Application
    {
        return new Tiger_Application(APPLICATION_ROOT);
    }

    private function call(Tiger_Application $app, string $method, array $args = [])
    {
        return (new ReflectionMethod(Tiger_Application::class, $method))->invokeArgs($app, $args);
    }

    #[Test]
    public function the_constructor_normalizes_the_root_path(): void
    {
        $app  = new Tiger_Application('C:\\sites\\my-app\\');   // backslashes + a trailing slash
        $root = (new ReflectionProperty(Tiger_Application::class, 'root'))->getValue($app);
        $this->assertSame('C:/sites/my-app', $root);
    }

    #[Test]
    public function normalize_proxy_applies_the_forwarded_client_and_https(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR']   = '203.0.113.7, 10.0.0.1, 10.0.0.2';   // client is leftmost
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);

        $this->call($this->app(), 'normalizeProxy');

        $this->assertSame('203.0.113.7', $_SERVER['REMOTE_ADDR'], 'the original client, not an ALB hop');
        $this->assertSame('on', $_SERVER['HTTPS']);
        $this->assertSame(443, $_SERVER['SERVER_PORT']);
        $this->assertTrue(defined('HTTPS'), 'the HTTPS boolean constant is exposed');
    }

    #[Test]
    public function normalize_proxy_leaves_plain_http_alone(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 80;

        $this->call($this->app(), 'normalizeProxy');

        $this->assertArrayNotHasKey('HTTPS', $_SERVER, 'no forwarded-proto → HTTPS is not forced on');
        $this->assertSame(80, $_SERVER['SERVER_PORT']);
    }

    /**
     * Runs in a SEPARATE PROCESS: `defineConstants()` mints ~8 process-global constants the test
     * bootstrap deliberately leaves unset (MODULES_PATH, PROJECT_LIBRARY_PATH, …). Defining them in
     * the parent would leak — e.g. `ScanServiceTest` conditionally defines MODULES_PATH itself — so
     * this test forks. `setIncludePath()` depends on those constants, so it's asserted here too.
     */
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function define_constants_and_include_path_from_the_root(): void
    {
        $app = $this->app();

        $this->call($app, 'defineConstants');
        $this->assertTrue(defined('TIGER_VERSION'));
        $this->assertSame(Tiger_Version::VERSION, TIGER_VERSION, 'the canonical version IS tiger-core’s');
        $this->assertTrue(defined('MODULES_PATH'));
        $this->assertTrue(defined('APPLICATION_ENV'));

        $this->call($app, 'setIncludePath');
        $parts = explode(PATH_SEPARATOR, get_include_path());
        $this->assertSame(PROJECT_LIBRARY_PATH, $parts[0], 'the app library resolves ahead of the framework');
        $this->assertContains(TIGER_CORE_PATH . '/library', $parts);
    }

    #[Test]
    public function load_custom_hook_is_a_no_op_when_absent(): void
    {
        // No custom.php at the (repo) root → the hook simply does nothing and never fatals.
        $this->assertNull($this->call($this->app(), 'loadCustomHook'));
    }

    #[Test]
    public function build_config_merges_the_ini_cascade_into_a_read_only_config(): void
    {
        $config = $this->call($this->app(), 'buildConfig');

        $this->assertInstanceOf(Zend_Config::class, $config);
        $this->assertTrue($config->readOnly(), 'the published config is frozen');
        // core.ini is the base of the cascade — its keys are present.
        $this->assertSame('puma', (string) $config->tiger->theme);
        $this->assertNotNull($config->resources, 'the resources tree loaded from core.ini');
    }

    #[Test]
    public function update_in_progress_is_false_without_the_maintenance_flag(): void
    {
        // No var/update/.maintenance flag on disk → normal dispatch (returns false, serves nothing).
        $this->assertFalse($this->call($this->app(), '_updateInProgress'));
    }
}
