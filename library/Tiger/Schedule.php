<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Schedule — a shared-host-friendly job scheduler. A reusable platform primitive: any module
 * registers a recurring job (a callable) with a simple frequency, and the platform runs it — driven
 * by a real cron (`bin/tiger schedule:run`, the reliable path) OR a WordPress-style pseudo-cron that
 * ticks on visitor traffic (the zero-setup fallback, which auto-yields to real cron when present).
 *
 * Slots are CLOCK-ANCHORED (a "daily at 02:30" job runs at that wall-clock time, not last-run + 24h),
 * so a missed tick simply runs late within the catch-up window and never double-fires — no fragile
 * next-run bookkeeping. Run state + history live in `schedule_run` (Tiger_Model_ScheduleRun), and a
 * lock file makes overlapping ticks (traffic + cron at once) safe.
 *
 * Register from a module Bootstrap (code) or a module `configs/schedule.ini` (declarative, like
 * acl.ini/navigation.ini). The schedule for any job is config-overridable at runtime
 * (`tiger.schedule.<key>.every` / `.at` / `.enabled`) — no deploy — which is how the admin screen
 * (and Backup's own "schedule this" control) edit it.
 *
 * @api
 */
class Tiger_Schedule
{
    const EVERY_MINUTE = 'every_minute';
    const EVERY_5      = 'every_5_min';
    const EVERY_15     = 'every_15_min';
    const HOURLY       = 'hourly';
    const DAILY        = 'daily';
    const WEEKLY       = 'weekly';
    const MONTHLY      = 'monthly';

    const FREQUENCIES = [self::EVERY_MINUTE, self::EVERY_5, self::EVERY_15, self::HOURLY, self::DAILY, self::WEEKLY, self::MONTHLY];

    /** @var array<string,array> key => job definition */
    protected static $_jobs = [];
    /** @var bool module configs/schedule.ini scanned this request */
    protected static $_discovered = false;

    /**
     * Register (or override) a recurring job.
     *
     * @param  array $def key, label, every (a FREQUENCIES value), at ('HH:MM'), dow (0-6, weekly),
     *                    dom (1-28, monthly), run (callable | 'Class::method'), catch_up (bool),
     *                    email_on_fail (bool), enabled (default bool), managed (bool — user-schedulable)
     * @return void
     */
    public static function register(array $def)
    {
        $key = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($def['key'] ?? ''));
        if ($key === '' || empty($def['run'])) { return; }
        self::$_jobs[$key] = $def + [
            'key'           => $key,
            'label'         => $key,
            'every'         => self::DAILY,
            'at'            => '00:00',
            'dow'           => 1,
            'dom'           => 1,
            'catch_up'      => true,
            'email_on_fail' => false,
            'enabled'       => true,
            'managed'       => true,
        ];
    }

    /** All registered jobs (config-discovered), keyed by job key. @return array<string,array> */
    public static function all()
    {
        self::discover();
        return self::$_jobs;
    }

    /** One job definition, or null. @param string $key @return array|null */
    public static function get($key)
    {
        self::discover();
        return self::$_jobs[$key] ?? null;
    }

    /**
     * Discover jobs declared in each ACTIVE module's `configs/schedule.ini` (mirrors Tiger_Admin_Nav
     * ::discover). Shape: `schedule.<key>.label/every/at/run/...`.
     *
     * @return void
     */
    public static function discover()
    {
        if (self::$_discovered) { return; }
        self::$_discovered = true;
        // Skip inactive modules' schedules. Guarded: if the DB adapter isn't up yet (early boot / a
        // CLI path that skips db), fall back to discovering all — a missing adapter never breaks boot.
        $inactive = [];
        if (class_exists('Tiger_Model_Module')) {
            try { $inactive = (array) (new Tiger_Model_Module())->inactiveSlugs(); } catch (Throwable $e) { $inactive = []; }
        }
        foreach (self::_moduleDirs() as $dir) {
            $slug = basename($dir);
            if (in_array($slug, $inactive, true)) { continue; }
            $ini = $dir . '/configs/schedule.ini';
            if (!is_file($ini)) { continue; }
            try {
                $cfg = new Zend_Config_Ini($ini, null, ['allowModifications' => false]);
                $node = $cfg->get('schedule');
                if (!($node instanceof Zend_Config)) { continue; }
                foreach ($node as $key => $j) {
                    self::register(['key' => $key] + $j->toArray());
                }
            } catch (Throwable $e) { /* a malformed schedule.ini never breaks boot */ }
        }
    }

    // ----- due computation (clock-anchored) ----------------------------------

    /**
     * The most recent scheduled slot at or before $now (unix ts), or null if the frequency is unknown.
     *
     * @param  array $job an effective job definition
     * @param  int   $now
     * @return int|null
     */
    public static function dueSlot(array $job, $now)
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', (string) ($job['at'] ?? '00:00'))), 2, 0);
        switch ((string) ($job['every'] ?? '')) {
            case self::EVERY_MINUTE: return $now - ($now % 60);
            case self::EVERY_5:      return $now - ($now % 300);
            case self::EVERY_15:     return $now - ($now % 900);
            case self::HOURLY:       return (int) strtotime(date('Y-m-d H:00:00', $now));
            case self::DAILY:
                $slot = (int) strtotime(date('Y-m-d', $now) . sprintf(' %02d:%02d:00', $h, $m));
                return $slot <= $now ? $slot : $slot - 86400;
            case self::WEEKLY:
                $dow  = (int) ($job['dow'] ?? 1) % 7;
                $slot = (int) strtotime(date('Y-m-d', $now) . sprintf(' %02d:%02d:00', $h, $m));
                $slot -= (((int) date('w', $now) - $dow + 7) % 7) * 86400;
                return $slot <= $now ? $slot : $slot - 7 * 86400;
            case self::MONTHLY:
                $dom  = max(1, min(28, (int) ($job['dom'] ?? 1)));
                $slot = (int) strtotime(date('Y-m', $now) . sprintf('-%02d %02d:%02d:00', $dom, $h, $m));
                return $slot <= $now ? $slot : (int) strtotime('-1 month', $slot);
            default: return null;
        }
    }

    /** The nominal interval (seconds) of a frequency — used for the catch-up window. */
    protected static function _interval(array $job)
    {
        return [
            self::EVERY_MINUTE => 60, self::EVERY_5 => 300, self::EVERY_15 => 900,
            self::HOURLY => 3600, self::DAILY => 86400, self::WEEKLY => 604800, self::MONTHLY => 2592000,
        ][(string) ($job['every'] ?? '')] ?? 86400;
    }

    /** Next scheduled run at or after $now (for display), or null. @return int|null */
    public static function nextRun(array $job, $now)
    {
        $slot = self::dueSlot($job, $now);
        if ($slot === null) { return null; }
        return $slot >= $now ? $slot : $slot + self::_interval($job);
    }

    // ----- effective (config-overridable) schedule ---------------------------

    /** Merge live config overrides (tiger.schedule.<key>.*) over a job's registered defaults. */
    public static function effective(array $job)
    {
        $key = $job['key'];
        foreach (['every', 'at', 'dow', 'dom'] as $f) {
            $v = self::_cfg('schedule.' . $key . '.' . $f, null);
            if ($v !== null && $v !== '') { $job[$f] = $f === 'dow' || $f === 'dom' ? (int) $v : (string) $v; }
        }
        return $job;
    }

    /** Whether a job is enabled (config override wins over its registered default). @return bool */
    public static function enabled($key)
    {
        $job = self::get($key);
        if (!$job) { return false; }
        $v = self::_cfg('schedule.' . $key . '.enabled', null);
        if ($v === null || $v === '') { return (bool) $job['enabled']; }
        return !in_array(strtolower((string) $v), ['0', 'off', 'false', 'no'], true);
    }

    // ----- the runner --------------------------------------------------------

    /**
     * Run every due job. Bounded by $budget seconds; serialized by a lock file so overlapping ticks
     * (traffic + cron) never double-run. Records each run in schedule_run.
     *
     * @param  int    $budget  max seconds to spend starting jobs
     * @param  string $trigger cron|pseudo|manual
     * @return array           {skipped?:string, ran:array}
     */
    public static function runDue($budget = 55, $trigger = 'cron')
    {
        $lock = self::_lock();
        if ($lock === false) { return ['skipped' => 'a tick is already running', 'ran' => []]; }

        $start = time();
        if ($trigger === 'cron') { self::markCron(); }
        $ran = [];
        try {
            $model = new Tiger_Model_ScheduleRun();
            foreach (self::all() as $key => $def) {
                if (time() - $start >= $budget) { break; }
                if (!self::enabled($key)) { continue; }
                $job  = self::effective($def);
                $slot = self::dueSlot($job, time());
                if ($slot === null) { continue; }
                if (time() - $slot > max(2 * self::_interval($job), 3600)) { continue; }   // slot too stale
                $last = $model->lastRunTs($key);
                if ($last !== null && $last >= $slot) { continue; }                        // already ran this slot
                if ($model->isRunning($key)) { continue; }                                 // in flight
                $ran[] = self::_runOne($model, $key, $job, $slot, $trigger);
            }
        } finally {
            self::_unlock($lock);
        }
        return ['ran' => $ran];
    }

    /** Run one job now, ignoring its schedule (the "Run now" button). @return array */
    public static function runNow($key)
    {
        $job = self::get($key);
        if (!$job) { return ['job' => $key, 'status' => 'error', 'error' => 'Unknown job.']; }
        return self::_runOne(new Tiger_Model_ScheduleRun(), $key, self::effective($job), time(), 'manual');
    }

    /** Execute + record one job. */
    protected static function _runOne(Tiger_Model_ScheduleRun $model, $key, array $job, $slot, $trigger)
    {
        $fn = self::_resolve($job['run']);
        $runId = $model->begin($key, $slot, $trigger);
        $t0 = microtime(true);
        try {
            if (!$fn) { throw new RuntimeException('Job callable could not be resolved.'); }
            call_user_func($fn);
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $model->finish($runId, 'ok', null, $ms);
            Tiger_Log::info('schedule.ran', ['job' => $key, 'ms' => $ms, 'trigger' => $trigger]);
            return ['job' => $key, 'status' => 'ok', 'ms' => $ms];
        } catch (Throwable $e) {
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $model->finish($runId, 'error', $e->getMessage(), $ms);
            Tiger_Log::error('schedule.failed', ['job' => $key, 'error' => $e->getMessage(), 'where' => $e->getFile() . ':' . $e->getLine()]);
            if (!empty($job['email_on_fail'])) { self::_emailFailure($key, $job, $e); }
            return ['job' => $key, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /** Resolve a 'run' definition (callable or "Class::method") to a callable, or null. */
    protected static function _resolve($run)
    {
        if (is_callable($run)) { return $run; }
        if (is_string($run) && strpos($run, '::') !== false) {
            [$c, $m] = explode('::', $run, 2);
            if (is_callable([$c, $m])) { return [$c, $m]; }
        }
        return null;
    }

    /** Email an admin that a job failed (best-effort). */
    protected static function _emailFailure($key, array $job, Throwable $e)
    {
        try {
            $to = trim((string) self::_cfg('schedule.email', ''));
            if ($to === '' || !class_exists('Tiger_Mail')) { return; }
            $label = (string) ($job['label'] ?? $key);
            Tiger_Mail::create()->to($to)->subject('Scheduled job failed: ' . $label)
                ->html('<p>The scheduled job <strong>' . htmlspecialchars($label) . '</strong> (<code>' . htmlspecialchars($key)
                    . '</code>) failed:</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>')->send();
        } catch (Throwable $x) { /* email failure must not cascade */ }
    }

    // ----- trigger coordination (real cron vs pseudo-cron) -------------------

    /** Pseudo-cron (traffic-driven) enabled? Config, default ON — the zero-setup fallback. */
    public static function pseudoCronEnabled()
    {
        $v = strtolower(trim((string) self::_cfg('schedule.pseudo_cron', '1')));
        return !in_array($v, ['0', 'off', 'false', 'no'], true);
    }

    /** Has a REAL cron ticked recently (within ~2 min)? If so, pseudo-cron yields. */
    public static function realCronRecent()
    {
        $f = self::_stateDir() . '/cron.at';
        return is_file($f) && (time() - (int) @filemtime($f)) < 130;
    }

    /** Is a pseudo-cron tick due? (Throttle: at most once/~minute.) */
    public static function tickDue()
    {
        $f = self::_stateDir() . '/tick.at';
        return !is_file($f) || (time() - (int) @filemtime($f)) >= 55;
    }

    /** Claim this minute's pseudo-cron tick. */
    public static function markTick() { @touch(self::_stateDir() . '/tick.at'); }
    /** Heartbeat that a REAL cron ran (called by bin/tiger schedule:run). */
    public static function markCron() { @touch(self::_stateDir() . '/cron.at'); }

    // ----- plumbing ----------------------------------------------------------

    /** The var/schedule state dir (created on demand). */
    protected static function _stateDir()
    {
        $root = defined('APPLICATION_ROOT') ? APPLICATION_ROOT : (defined('APPLICATION_PATH') ? dirname(APPLICATION_PATH) : getcwd());
        $dir  = $root . '/var/schedule';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir;
    }

    /** Acquire the global tick lock (non-blocking). Returns the handle, or false if held. */
    protected static function _lock()
    {
        $fh = @fopen(self::_stateDir() . '/tick.lock', 'c');
        if (!$fh) { return false; }
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        return $fh;
    }

    protected static function _unlock($fh)
    {
        if (is_resource($fh)) { flock($fh, LOCK_UN); fclose($fh); }
    }

    /** Module dirs to scan for schedule.ini (app + first-party). */
    protected static function _moduleDirs()
    {
        $dirs = [];
        foreach ([defined('MODULES_PATH') ? MODULES_PATH : '', defined('TIGER_CORE_PATH') ? TIGER_CORE_PATH . '/modules' : ''] as $base) {
            if ($base && is_dir($base)) { foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $d) { $dirs[] = $d; } }
        }
        return $dirs;
    }

    /** Read a `tiger.<dotKey>` config value with a default. */
    protected static function _cfg($dotKey, $default = '')
    {
        if (!Zend_Registry::isRegistered('Zend_Config')) { return $default; }
        $node = Zend_Registry::get('Zend_Config')->get('tiger');
        foreach (explode('.', $dotKey) as $seg) {
            if (!($node instanceof Zend_Config)) { return $default; }
            $node = $node->get($seg);
            if ($node === null) { return $default; }
        }
        return is_scalar($node) ? (string) $node : $default;
    }
}
