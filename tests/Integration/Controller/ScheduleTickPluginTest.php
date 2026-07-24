<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Controller_Plugin_ScheduleTick;
use Zend_Config;
use Zend_Controller_Request_Http;
use Zend_Registry;

/**
 * Tiger_Controller_Plugin_ScheduleTick — the WordPress-style pseudo-cron. When no real cron is wired,
 * a visitor request quietly drives the scheduler: at most once/~minute (a file-mtime throttle), never
 * when a real cron ticked recently, and the actual work deferred to AFTER the response. Its routeShutdown
 * hook is the cheap decision — claim this minute (markTick) and register the deferred run — gated by
 * three Tiger_Schedule checks (pseudo-cron enabled? real cron recent? a tick due?).
 *
 * The gating is file/config driven, so the observable is the tick-claim file: the guard branches leave
 * it untouched, the happy path stamps it. The deferred shutdown run itself is verified at the smoke
 * level (no jobs are registered in the harness, so it's a silent no-op) — see WAVE5-FINDINGS-ctrl.md.
 */
#[CoversClass(Tiger_Controller_Plugin_ScheduleTick::class)]
final class ScheduleTickPluginTest extends IntegrationTestCase
{
    private string $stateDir;
    private string $tickAt;
    private string $cronAt;
    private bool $hadConfig;
    private $priorConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = (defined('APPLICATION_ROOT') ? APPLICATION_ROOT : getcwd()) . '/var/schedule';
        @mkdir($this->stateDir, 0775, true);
        $this->tickAt = $this->stateDir . '/tick.at';
        $this->cronAt = $this->stateDir . '/cron.at';
        $this->clearState();

        $this->hadConfig   = Zend_Registry::isRegistered('Zend_Config');
        $this->priorConfig = $this->hadConfig ? Zend_Registry::get('Zend_Config') : null;
    }

    protected function tearDown(): void
    {
        $this->clearState();
        if ($this->hadConfig) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif (Zend_Registry::isRegistered('Zend_Config')) {
            Zend_Registry::getInstance()->offsetUnset('Zend_Config');
        }
        parent::tearDown();
    }

    private function clearState(): void
    {
        foreach ([$this->tickAt, $this->cronAt] as $f) { if (is_file($f)) { @unlink($f); } }
    }

    private function setPseudoCron(string $flag): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['schedule' => ['pseudo_cron' => $flag]]]));
    }

    private function tick(): void
    {
        (new Tiger_Controller_Plugin_ScheduleTick())->routeShutdown(new Zend_Controller_Request_Http());
    }

    #[Test]
    public function it_yields_when_pseudo_cron_is_disabled_by_config(): void
    {
        $this->setPseudoCron('0');
        $this->tick();
        $this->assertFileDoesNotExist($this->tickAt, 'disabled => no tick claimed');
    }

    #[Test]
    public function it_yields_when_a_real_cron_ticked_recently(): void
    {
        $this->setPseudoCron('1');
        touch($this->cronAt);   // a real cron heartbeat within the last ~2 minutes
        $this->tick();
        $this->assertFileDoesNotExist($this->tickAt, 'a recent real cron makes pseudo-cron stand down');
    }

    #[Test]
    public function it_throttles_when_a_tick_is_not_yet_due(): void
    {
        $this->setPseudoCron('1');
        // A tick claimed 10s ago is still within the ~1-minute throttle window.
        touch($this->tickAt, time() - 10);
        $before = filemtime($this->tickAt);

        $this->tick();

        clearstatcache();
        $this->assertSame($before, filemtime($this->tickAt), 'the throttle short-circuits before re-claiming');
    }

    #[Test]
    public function it_claims_the_tick_when_all_gates_pass(): void
    {
        $this->setPseudoCron('1');
        // No cron.at (no real cron), no tick.at (a tick is due) => the plugin claims this minute.
        $this->tick();
        $this->assertFileExists($this->tickAt, 'the happy path stamps the tick-claim file');
    }
}
