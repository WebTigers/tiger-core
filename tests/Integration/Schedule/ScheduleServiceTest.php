<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Schedule_Service_Schedule;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Log;
use Tiger_Model_ScheduleRun;
use Tiger_Schedule;
use Zend_Config;
use Zend_Registry;

// `Schedule_Service_Schedule` resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * Tiger_Schedule (the reusable job scheduler + pseudo-cron) and Schedule_Service_Schedule (its /api).
 *
 * Two halves, both deterministic:
 *   1. The DUE-CALCULATION engine — clock-anchored slots for every frequency, driven by FIXED
 *      timestamps (the platform forbids Date.now-style nondeterminism), plus the config-override
 *      `effective()`/`enabled()` merge. Pure logic, no wall clock.
 *   2. The /api service — ACL (admin+, deny-by-default per modules/schedule/configs/acl.ini) plus
 *      `jobs` (list + cron status), `setSchedule` (writes the config-tier overrides + validates
 *      frequency/time), and `runNow` (fires a job, records a schedule_run row, surfaces failures).
 *
 * The Tiger_Schedule registry is process-static, so setUp resets it and marks discovery done (no
 * module `schedule.ini` bleeds in) — each test registers exactly the jobs it asserts on.
 */
#[CoversClass(Schedule_Service_Schedule::class)]
#[CoversClass(Tiger_Schedule::class)]
final class ScheduleServiceTest extends IntegrationTestCase
{
    /** whatever Zend_Config was registered before this test (restored in tearDown). */
    private ?Zend_Config $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('UTC');
        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        // Silence the log sink: the service logs schedule.rescheduled/ran/failed, which the strict
        // output check would otherwise flag as printed output. A null writer keeps it quiet.
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['log' => ['writer' => 'null']]]));
        Tiger_Log::reset();
        $this->resetScheduleRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetScheduleRegistry();
        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif (Zend_Registry::isRegistered('Zend_Config')) {
            Zend_Registry::set('Zend_Config', new Zend_Config([]));
        }
        Tiger_Log::reset();
        parent::tearDown();
    }

    /** Empty the static job registry and short-circuit discover() so only our jobs exist. */
    private function resetScheduleRegistry(): void
    {
        // (PHP 8.1+ reflection ignores visibility, so no setAccessible() needed — and it's deprecated in 8.5.)
        $ref = new ReflectionClass(Tiger_Schedule::class);
        $ref->getProperty('_jobs')->setValue(null, []);
        $ref->getProperty('_discovered')->setValue(null, true);   // pretend module schedule.ini scan already ran (none in the harness)
    }

    private function dispatch(array $msg): object
    {
        return (new Schedule_Service_Schedule($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    private function job(array $over = []): array
    {
        return $over + ['key' => 'k', 'every' => 'daily', 'at' => '00:00', 'dow' => 1, 'dom' => 1];
    }

    // ---- due calculation (clock-anchored, fixed times) -------------------------------------------

    #[Test]
    public function sub_daily_slots_align_to_their_interval_and_are_at_or_before_now(): void
    {
        $now = strtotime('2026-07-15 14:37:20 UTC');

        $this->assertSame(0, Tiger_Schedule::dueSlot($this->job(['every' => 'every_minute']), $now) % 60);
        $this->assertSame(0, Tiger_Schedule::dueSlot($this->job(['every' => 'every_5_min']), $now) % 300);
        $this->assertSame(0, Tiger_Schedule::dueSlot($this->job(['every' => 'every_15_min']), $now) % 900);

        $hourly = Tiger_Schedule::dueSlot($this->job(['every' => 'hourly']), $now);
        $this->assertSame('14:00:00', date('H:i:s', $hourly));

        foreach (['every_minute', 'every_5_min', 'every_15_min', 'hourly'] as $f) {
            $slot = Tiger_Schedule::dueSlot($this->job(['every' => $f]), $now);
            $this->assertLessThanOrEqual($now, $slot, "$f slot is at or before now");
        }
    }

    #[Test]
    public function a_daily_slot_is_today_when_the_time_has_passed_and_yesterday_when_it_has_not(): void
    {
        $now = strtotime('2026-07-15 14:37:20 UTC');

        // 02:30 already passed today → today's slot.
        $passed = Tiger_Schedule::dueSlot($this->job(['every' => 'daily', 'at' => '02:30']), $now);
        $this->assertSame('02:30', date('H:i', $passed));
        $this->assertSame(date('Y-m-d', $now), date('Y-m-d', $passed));

        // 20:00 not yet today → yesterday's slot (the most recent one at/before now).
        $future = Tiger_Schedule::dueSlot($this->job(['every' => 'daily', 'at' => '20:00']), $now);
        $this->assertSame('20:00', date('H:i', $future));
        $this->assertSame(date('Y-m-d', $now - 86400), date('Y-m-d', $future));
        $this->assertLessThan($now, $future);
    }

    #[Test]
    public function weekly_and_monthly_slots_land_on_the_configured_dow_dom(): void
    {
        $now = strtotime('2026-07-15 14:37:20 UTC');   // a Wednesday (w=3)

        $weekly = Tiger_Schedule::dueSlot($this->job(['every' => 'weekly', 'at' => '00:00', 'dow' => 1]), $now);
        $this->assertSame('1', date('w', $weekly), 'lands on Monday (dow=1)');
        $this->assertLessThanOrEqual($now, $weekly);

        $monthly = Tiger_Schedule::dueSlot($this->job(['every' => 'monthly', 'at' => '00:00', 'dom' => 1]), $now);
        $this->assertSame('01', date('d', $monthly), 'lands on the 1st (dom=1)');
        $this->assertSame(date('Y-m', $now), date('Y-m', $monthly));
    }

    #[Test]
    public function next_run_is_one_interval_after_the_due_slot_and_never_in_the_past(): void
    {
        $now = strtotime('2026-07-15 14:37:20 UTC');

        foreach (['every_5_min', 'hourly', 'daily', 'weekly', 'monthly'] as $f) {
            $job  = $this->job(['every' => $f, 'at' => '02:30']);
            $slot = Tiger_Schedule::dueSlot($job, $now);
            $next = Tiger_Schedule::nextRun($job, $now);
            $this->assertGreaterThanOrEqual($now, $next, "$f next run is at/after now");
            $this->assertGreaterThanOrEqual($slot, $next, "$f next run is at/after the due slot");
        }
    }

    #[Test]
    public function an_unknown_frequency_yields_no_slot(): void
    {
        $now = strtotime('2026-07-15 14:37:20 UTC');
        $this->assertNull(Tiger_Schedule::dueSlot($this->job(['every' => 'nope']), $now));
        $this->assertNull(Tiger_Schedule::nextRun($this->job(['every' => 'nope']), $now));
    }

    // ---- effective schedule + enabled (config-override merge) -------------------------------------

    #[Test]
    public function effective_merges_live_config_overrides_over_the_registered_defaults(): void
    {
        // A dotless key so the dot-notation config lookup (tiger.schedule.<key>.<field>) nests cleanly.
        Tiger_Schedule::register(['key' => 'demojob', 'label' => 'Demo', 'run' => 'strlen', 'every' => 'daily', 'at' => '00:00']);
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['schedule' => ['demojob' => ['every' => 'weekly', 'at' => '05:45', 'dow' => 4]]],
        ]));

        $eff = Tiger_Schedule::effective(Tiger_Schedule::get('demojob'));
        $this->assertSame('weekly', $eff['every']);
        $this->assertSame('05:45', $eff['at']);
        $this->assertSame(4, $eff['dow']);
    }

    #[Test]
    public function enabled_defaults_to_the_registration_and_a_config_row_can_switch_it_off(): void
    {
        Tiger_Schedule::register(['key' => 'demojob', 'label' => 'Demo', 'run' => 'strlen', 'enabled' => true]);

        Zend_Registry::set('Zend_Config', new Zend_Config([]));
        $this->assertTrue(Tiger_Schedule::enabled('demojob'), 'the registered default (true) wins with no override');

        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['schedule' => ['demojob' => ['enabled' => '0']]],
        ]));
        $this->assertFalse(Tiger_Schedule::enabled('demojob'), 'the config override switches it off');
        $this->assertFalse(Tiger_Schedule::enabled('no.such.job'), 'an unknown job is not enabled');
    }

    // ---- ACL: admin+, deny-by-default ------------------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_the_scheduler_to_admin_and_up(): void
    {
        $this->loginAs('admin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('Schedule_Service_Schedule'), 'the module acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('admin', 'Schedule_Service_Schedule'));
        $this->assertFalse($acl->isAllowed('user', 'Schedule_Service_Schedule'), 'a plain user is denied');
        $this->assertFalse($acl->isAllowed('guest', 'Schedule_Service_Schedule'), 'a guest is denied');
    }

    #[Test]
    public function a_guest_is_denied_every_scheduler_verb(): void
    {
        $this->login('anon', 'o-1', 'guest');
        foreach (['jobs', 'setSchedule', 'runNow'] as $action) {
            $res = $this->dispatch(['action' => $action, 'key' => 'x']);
            $this->assertSame(0, (int) $res->result, "$action is denied to a guest");
            $this->assertStringContainsString('not_allowed', $this->messages($res), "the ACL denial fired for $action");
        }
    }

    // ---- jobs (list + cron status) ---------------------------------------------------------------

    #[Test]
    public function jobs_lists_every_registered_job_with_its_schedule_and_cron_status(): void
    {
        Tiger_Schedule::register(['key' => 'demo.job', 'label' => 'Demo Job', 'run' => 'strlen', 'every' => 'daily', 'at' => '01:15']);
        $this->loginAs('admin');

        $res = $this->dispatch(['action' => 'jobs']);
        $this->assertSame(1, (int) $res->result);

        $keys = array_column($res->data['jobs'], 'key');
        $this->assertContains('demo.job', $keys);
        $mine = $res->data['jobs'][array_search('demo.job', $keys, true)];
        $this->assertSame('Demo Job', $mine['label']);
        $this->assertSame('daily', $mine['every']);
        $this->assertSame('01:15', $mine['at']);
        $this->assertTrue($mine['enabled']);
        $this->assertNull($mine['last'], 'a never-run job has no last-run record');
        $this->assertIsInt($mine['next_run']);

        $this->assertSame(Tiger_Schedule::FREQUENCIES, $res->data['frequencies']);
        $this->assertArrayHasKey('pseudo_cron', $res->data);
        $this->assertStringContainsString('schedule:run', $res->data['cron_command']);
    }

    // ---- setSchedule (the reusable "schedule this" writer + validation) --------------------------

    #[Test]
    public function set_schedule_writes_the_config_tier_overrides_for_one_job(): void
    {
        Tiger_Schedule::register(['key' => 'demo.job', 'label' => 'Demo', 'run' => 'strlen']);
        $this->loginAs('admin');

        $res = $this->dispatch([
            'action' => 'setSchedule', 'key' => 'demo.job',
            'every' => 'weekly', 'at' => '03:15', 'dow' => 5, 'dom' => 12, 'enabled' => 1,
        ]);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $cfg = new \Tiger_Model_Config();
        $g   = \Tiger_Model_Config::SCOPE_GLOBAL;
        $this->assertSame('weekly', $cfg->get($g, '', 'tiger.schedule.demo.job.every'));
        $this->assertSame('03:15', $cfg->get($g, '', 'tiger.schedule.demo.job.at'));
        $this->assertSame('5', $cfg->get($g, '', 'tiger.schedule.demo.job.dow'));
        $this->assertSame('12', $cfg->get($g, '', 'tiger.schedule.demo.job.dom'));
        $this->assertSame('1', $cfg->get($g, '', 'tiger.schedule.demo.job.enabled'));
    }

    #[Test]
    public function set_schedule_rejects_an_unknown_job_and_bad_frequency_or_time(): void
    {
        Tiger_Schedule::register(['key' => 'demo.job', 'label' => 'Demo', 'run' => 'strlen']);
        $this->loginAs('admin');

        $unknown = $this->dispatch(['action' => 'setSchedule', 'key' => 'no.such.job', 'every' => 'daily']);
        $this->assertSame(0, (int) $unknown->result);
        $this->assertStringContainsString('unknown_job', $this->messages($unknown));

        $badFreq = $this->dispatch(['action' => 'setSchedule', 'key' => 'demo.job', 'every' => 'fortnightly']);
        $this->assertSame(0, (int) $badFreq->result);
        $this->assertStringContainsString('bad_frequency', $this->messages($badFreq));

        $badTime = $this->dispatch(['action' => 'setSchedule', 'key' => 'demo.job', 'at' => '99:99']);
        $this->assertSame(0, (int) $badTime->result);
        $this->assertStringContainsString('bad_time', $this->messages($badTime));
    }

    // ---- runNow (fire a job on demand) -----------------------------------------------------------

    #[Test]
    public function run_now_executes_the_job_and_records_a_schedule_run(): void
    {
        $GLOBALS['__tiger_test_ran'] = false;
        Tiger_Schedule::register([
            'key' => 'demo.job', 'label' => 'Demo', 'run' => function () { $GLOBALS['__tiger_test_ran'] = true; },
        ]);
        $this->loginAs('admin');

        $res = $this->dispatch(['action' => 'runNow', 'key' => 'demo.job']);
        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertTrue($GLOBALS['__tiger_test_ran'], 'the job callable actually ran');
        $this->assertArrayHasKey('ms', (array) $res->data);

        $last = (new Tiger_Model_ScheduleRun())->latestPerJob()['demo.job'] ?? null;
        $this->assertNotNull($last, 'a schedule_run row was recorded');
        $this->assertSame('ok', $last['outcome']);
        unset($GLOBALS['__tiger_test_ran']);
    }

    #[Test]
    public function run_now_reports_a_failing_job_as_an_error(): void
    {
        Tiger_Schedule::register([
            'key' => 'demo.job', 'label' => 'Demo', 'run' => function () { throw new \RuntimeException('boom'); },
        ]);
        $this->loginAs('admin');

        $res = $this->dispatch(['action' => 'runNow', 'key' => 'demo.job']);
        $this->assertSame(0, (int) $res->result);
        // non-production surfaces the reason; the run is still recorded as an error.
        $this->assertStringContainsString('boom', $this->messages($res));

        $last = (new Tiger_Model_ScheduleRun())->latestPerJob()['demo.job'] ?? null;
        $this->assertNotNull($last);
        $this->assertSame('error', $last['outcome']);
    }

    #[Test]
    public function run_now_rejects_an_unknown_job(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'runNow', 'key' => 'no.such.job']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('unknown_job', $this->messages($res));
    }
}
