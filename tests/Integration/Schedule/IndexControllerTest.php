<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Schedule_IndexController;
use Tiger\Tests\Support\ModuleControllerTestCase;
use Tiger_Schedule;

/**
 * Schedule_IndexController — the Scheduler admin screen. Thin: it walks the Tiger_Schedule registry and
 * the latest-run-per-job state into a $jobs view model for the initial render; every mutation is an /api
 * call. The harness dispatches index rendering-off and asserts the assembled model — with a job seeded
 * into the registry so the per-job loop body runs.
 */
#[CoversClass(Schedule_IndexController::class)]
final class IndexControllerTest extends ModuleControllerTestCase
{
    protected function tearDown(): void
    {
        // The registry is process-static — clear the seeded job so it can't leak into other suites.
        $ref = new ReflectionClass(Tiger_Schedule::class);
        $ref->getProperty('_jobs')->setValue(null, []);
        $ref->getProperty('_discovered')->setValue(null, false);
        parent::tearDown();
    }

    #[Test]
    public function index_assembles_the_job_list_from_the_registry(): void
    {
        Tiger_Schedule::register([
            'key'   => 'w6.test.job',
            'label' => 'W6 Test Job',
            'every' => 'daily',
            'at'    => '03:00',
            'run'   => static function () { return true; },
        ]);

        $this->loginAs('admin');
        $this->dispatchAction(Schedule_IndexController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Scheduler', (string) $view->title);
        $this->assertIsArray($view->jobs);
        $this->assertNotEmpty($view->jobs);
        $this->assertIsString($view->cronCommand);

        $keys = array_column($view->jobs, 'key');
        $this->assertContains('w6.test.job', $keys);

        $job = $view->jobs[array_search('w6.test.job', $keys, true)];
        $this->assertSame('W6 Test Job', $job['label']);
        $this->assertArrayHasKey('next_run', $job);
        $this->assertArrayHasKey('enabled', $job);
        $this->assertNull($job['last'], 'no run recorded yet');
    }
}
