<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * ScheduleRun — one execution record of a Tiger_Schedule job (the run log + the "last run" state).
 *
 * The scheduler is stateless about *next* run (slots are clock-anchored), so this table only records
 * what actually happened: a `running` row is written at start (which also serves as the in-flight
 * lock) and flipped to `ok`/`error` at finish. `lastRunTs()` answers "has this job run for the
 * current slot?" and the admin screen reads the recent history.
 *
 * @api
 */
class Tiger_Model_ScheduleRun extends Tiger_Model_Table
{
    protected $_name    = 'schedule_run';
    protected $_primary = 'schedule_run_id';

    /**
     * Open a run record (status `running`). Doubles as the in-flight marker for the overlap guard.
     *
     * @param  string $jobKey
     * @param  int    $slotTs  the scheduled slot this run satisfies
     * @param  string $source  cron|pseudo|manual
     * @return string the new schedule_run_id
     */
    public function begin($jobKey, $slotTs, $source)
    {
        return $this->insert([
            'job_key'    => (string) $jobKey,
            'slot_at'    => date('Y-m-d H:i:s', (int) $slotTs),
            'started_at' => date('Y-m-d H:i:s'),
            'outcome'    => 'running',
            'source'     => (string) $source,
        ]);
    }

    /**
     * Close a run record.
     *
     * @param  string      $id
     * @param  string      $outcome ok|error
     * @param  string|null $error
     * @param  int         $ms
     * @return void
     */
    public function finish($id, $outcome, $error, $ms)
    {
        $this->update([
            'finished_at' => date('Y-m-d H:i:s'),
            'outcome'     => (string) $outcome,
            'error'       => $error !== null ? mb_substr((string) $error, 0, 1000) : null,
            'duration_ms' => (int) $ms,
        ], $this->getAdapter()->quoteInto('schedule_run_id = ?', (string) $id));
    }

    /**
     * Unix timestamp of a job's most recent start, or null if it has never run.
     *
     * @param  string $jobKey
     * @return int|null
     */
    public function lastRunTs($jobKey)
    {
        $db = $this->getAdapter();
        $v  = $db->fetchOne($db->select()->from('schedule_run', [new Zend_Db_Expr('MAX(started_at)')])
            ->where('job_key = ?', (string) $jobKey));
        return $v ? (int) strtotime((string) $v) : null;
    }

    /**
     * Is a fresh `running` row present for this job (the overlap guard)?
     *
     * @param  string $jobKey
     * @param  int    $staleSeconds a running row older than this is treated as dead
     * @return bool
     */
    public function isRunning($jobKey, $staleSeconds = 1800)
    {
        $db = $this->getAdapter();
        return (int) $db->fetchOne($db->select()->from('schedule_run', [new Zend_Db_Expr('COUNT(*)')])
            ->where('job_key = ?', (string) $jobKey)->where('outcome = ?', 'running')
            ->where('started_at > ?', date('Y-m-d H:i:s', time() - $staleSeconds))) > 0;
    }

    /**
     * The latest run row per job_key (for the jobs list).
     *
     * @return array<string,array<string,mixed>> job_key => row
     */
    public function latestPerJob()
    {
        $db   = $this->getAdapter();
        $rows = $db->fetchAll($db->select()->from('schedule_run',
            ['job_key', 'started_at', 'finished_at', 'outcome', 'error', 'duration_ms', 'source'])
            ->order('started_at DESC'));
        $out = [];
        foreach ($rows as $r) { if (!isset($out[$r['job_key']])) { $out[$r['job_key']] = $r; } }
        return $out;
    }

    /**
     * Recent run history for one job.
     *
     * @param  string $jobKey
     * @param  int    $limit
     * @return array<int,array<string,mixed>>
     */
    public function history($jobKey, $limit = 20)
    {
        $db = $this->getAdapter();
        return $db->fetchAll($db->select()->from('schedule_run',
            ['started_at', 'finished_at', 'outcome', 'error', 'duration_ms', 'source'])
            ->where('job_key = ?', (string) $jobKey)->order('started_at DESC')->limit((int) $limit));
    }
}
