<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Schedule_IndexController — the Scheduler admin screen (/schedule). Thin: it assembles the current
 * job list (registry + live state) for the initial render; every mutation (reschedule, run-now)
 * goes back through Schedule_Service_Schedule over /api. Admin+. See ADMIN.md.
 */
class Schedule_IndexController extends Tiger_Controller_Admin_Action
{
    public function init()
    {
        parent::init();
    }

    public function indexAction()
    {
        $latest = (new Tiger_Model_ScheduleRun())->latestPerJob();
        $now    = time();

        $jobs = [];
        foreach (Tiger_Schedule::all() as $key => $def) {
            $job  = Tiger_Schedule::effective($def);
            $last = $latest[$key] ?? null;
            $jobs[] = [
                'key'      => $key,
                'label'    => (string) ($def['label'] ?? $key),
                'every'    => (string) $job['every'],
                'at'       => (string) $job['at'],
                'dow'      => (int) $job['dow'],
                'dom'      => (int) $job['dom'],
                'managed'  => (bool) ($def['managed'] ?? true),
                'enabled'  => Tiger_Schedule::enabled($key),
                'next_run' => Tiger_Schedule::nextRun($job, $now),
                'last'     => $last ? [
                    'at'      => $last['started_at'],
                    'outcome' => $last['outcome'],
                    'ms'      => $last['duration_ms'],
                    'error'   => $last['error'],
                ] : null,
            ];
        }

        $this->view->title       = 'Scheduler — Tiger Admin';
        $this->view->jobs        = $jobs;
        $this->view->cronCommand = Schedule_Service_Schedule::cronCommand();
    }
}
