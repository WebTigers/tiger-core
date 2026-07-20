<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Schedule_Service_Schedule — the /api behind the Scheduler screen, and the reusable seam any module
 * uses to let a user schedule that module's own job WITHOUT visiting /schedule.
 *
 * `jobs` lists every registered job + its live schedule + last/next run + the cron-setup status.
 * `setSchedule` writes the config-tier overrides for one job (frequency / time / enabled) — that's
 * what Backup's "schedule this backup" control posts to. `runNow` fires a job on demand. Admin+.
 *
 * @api
 */
class Schedule_Service_Schedule extends Tiger_Service_Service
{
    /**
     * Every registered job with its effective schedule + state, plus the cron-setup status.
     *
     * @param  array $params (unused)
     * @return void
     */
    public function jobs(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $latest = (new Tiger_Model_ScheduleRun())->latestPerJob();
        $now = time();
        $out = [];
        foreach (Tiger_Schedule::all() as $key => $def) {
            $job  = Tiger_Schedule::effective($def);
            $last = $latest[$key] ?? null;
            $out[] = [
                'key'      => $key,
                'label'    => (string) $def['label'],
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

        $this->_success([
            'jobs'         => $out,
            'frequencies'  => Tiger_Schedule::FREQUENCIES,
            'real_cron'    => Tiger_Schedule::realCronRecent(),
            'pseudo_cron'  => Tiger_Schedule::pseudoCronEnabled(),
            'cron_command' => self::cronCommand(),
        ], 'core.api.success');
    }

    /**
     * Set (or clear) the schedule + enabled flag for one job — the reusable "schedule this" call.
     *
     * @param  array $params key, every, at, dow, dom, enabled
     * @return void
     */
    public function setSchedule(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $key = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($params['key'] ?? ''));
        if ($key === '' || !Tiger_Schedule::get($key)) { $this->_error('schedule.unknown_job'); return; }

        $every = (string) ($params['every'] ?? '');
        if ($every !== '' && !in_array($every, Tiger_Schedule::FREQUENCIES, true)) { $this->_error('schedule.bad_frequency', ['field' => 'every']); return; }
        $at = (string) ($params['at'] ?? '');
        if ($at !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at)) { $this->_error('schedule.bad_time', ['field' => 'at']); return; }

        $cfg = new Tiger_Model_Config();
        $g   = Tiger_Model_Config::SCOPE_GLOBAL;
        if ($every !== '') { $cfg->set($g, '', 'tiger.schedule.' . $key . '.every', $every); }
        if ($at !== '')    { $cfg->set($g, '', 'tiger.schedule.' . $key . '.at', $at); }
        if (array_key_exists('dow', $params)) { $cfg->set($g, '', 'tiger.schedule.' . $key . '.dow', (string) (int) $params['dow']); }
        if (array_key_exists('dom', $params)) { $cfg->set($g, '', 'tiger.schedule.' . $key . '.dom', (string) (int) $params['dom']); }
        if (array_key_exists('enabled', $params)) {
            $cfg->set($g, '', 'tiger.schedule.' . $key . '.enabled', !empty($params['enabled']) ? '1' : '0');
        }

        Tiger_Log::info('schedule.rescheduled', ['job' => $key, 'every' => $every, 'at' => $at]);
        $this->_success([], 'schedule.saved');
    }

    /**
     * Run a job immediately (ignores its schedule).
     *
     * @param  array $params key
     * @return void
     */
    public function runNow(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $key = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($params['key'] ?? ''));
        if ($key === '' || !Tiger_Schedule::get($key)) { $this->_error('schedule.unknown_job'); return; }
        $r = Tiger_Schedule::runNow($key);
        if (($r['status'] ?? '') === 'ok') {
            $this->_success(['ms' => $r['ms'] ?? 0], 'schedule.ran');
        } else {
            $this->_error(APPLICATION_ENV !== 'production' ? ('Job failed: ' . ($r['error'] ?? '')) : 'schedule.run_failed');
        }
    }

    /**
     * The exact cron line an operator pastes into cPanel / Plesk / crontab.
     *
     * @return string
     */
    public static function cronCommand()
    {
        $root = defined('APPLICATION_ROOT') ? APPLICATION_ROOT : (defined('APPLICATION_PATH') ? dirname(APPLICATION_PATH) : '/path/to/app');
        return 'php ' . $root . '/vendor/bin/tiger schedule:run >/dev/null 2>&1';
    }
}
