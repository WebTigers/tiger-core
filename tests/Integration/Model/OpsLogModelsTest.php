<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Backup;
use Tiger_Model_ScheduleRun;
use Tiger_Model_UpdateHistory;

/**
 * The three ops-log catalogs: backup archives, schedule-run history, and one-click-update history.
 *
 * Each follows the same begin/finish shape — a `running` row written at start, flipped to an
 * outcome at finish — plus read-back queries. The invariants worth pinning: a scheduled backup is
 * NOT pinned (so rolling retention can prune it) while a manual one IS; `lastRunTs`/`isRunning`
 * answer the scheduler's overlap-guard questions; and the JSON step log round-trips through
 * UpdateHistory.record()/recent().
 */
#[CoversClass(Tiger_Model_Backup::class)]
#[CoversClass(Tiger_Model_ScheduleRun::class)]
#[CoversClass(Tiger_Model_UpdateHistory::class)]
final class OpsLogModelsTest extends IntegrationTestCase
{
    // ---- Backup -------------------------------------------------------------

    #[Test]
    public function a_manual_backup_is_pinned_and_a_scheduled_one_is_not(): void
    {
        $b = new Tiger_Model_Backup();

        $manual = $b->begin('manual.zip', 'local', ['db', 'media'], 'manual');
        $sched  = $b->begin('nightly.zip', 'local', ['db'], 'scheduled');

        $mRow = $b->findById($manual);
        $this->assertSame('running', $mRow->outcome, 'a backup opens running');
        $this->assertSame(1, (int) $mRow->pinned, 'manual backups are pinned (manual-remove-only)');
        $this->assertSame('manual', $mRow->source);
        $this->assertSame('db,media', $mRow->components, 'components are stored comma-joined');

        $sRow = $b->findById($sched);
        $this->assertSame(0, (int) $sRow->pinned, 'scheduled backups are prunable (not pinned)');
        $this->assertSame('scheduled', $sRow->source);
    }

    #[Test]
    public function finish_records_the_outcome_and_only_the_supplied_fields(): void
    {
        $b  = new Tiger_Model_Backup();
        $id = $b->begin('x.zip', 'local', ['db'], 'manual');

        $b->finish($id, 'ok', ['storage_key' => 'backups/x.zip', 'size_bytes' => 4096, 'checksum' => str_repeat('a', 64)]);
        $row = $b->findById($id);
        $this->assertSame('ok', $row->outcome);
        $this->assertSame('backups/x.zip', $row->storage_key);
        $this->assertSame(4096, (int) $row->size_bytes);

        // An unknown outcome string is coerced to 'error'.
        $id2 = $b->begin('y.zip', 'local', ['db'], 'manual');
        $b->finish($id2, 'nonsense', ['error' => 'boom']);
        $this->assertSame('error', $b->findById($id2)->outcome, 'anything but ok becomes error');
        $this->assertSame('boom', $b->findById($id2)->error);
    }

    #[Test]
    public function recent_is_newest_first_and_prunable_excludes_pinned_and_failed(): void
    {
        $b = new Tiger_Model_Backup();

        // Distinct created_at so ordering is deterministic (not tie-broken at sub-second inserts).
        $stamp = function (string $id, int $agoSec): void {
            $this->db->update('backup', ['created_at' => date('Y-m-d H:i:s', time() - $agoSec)], $this->db->quoteInto('backup_id = ?', $id));
        };

        $old = $b->begin('old.zip', 'local', ['db'], 'scheduled');
        $b->finish($old, 'ok');
        $stamp($old, 3600);
        $new = $b->begin('new.zip', 'local', ['db'], 'scheduled');
        $b->finish($new, 'ok');
        $stamp($new, 10);
        $manual = $b->begin('keep.zip', 'local', ['db'], 'manual');
        $b->finish($manual, 'ok');
        $stamp($manual, 30);
        $failed = $b->begin('bad.zip', 'local', ['db'], 'scheduled');
        $b->finish($failed, 'error');
        $stamp($failed, 20);

        $recent = $b->recent(50);
        $this->assertSame('new.zip', $recent[0]['filename'], 'recent() is newest first');
        $this->assertSame('old.zip', $recent[count($recent) - 1]['filename'], 'oldest is last');

        $prunable = array_column($b->prunable(), 'filename');
        $this->assertSame(['old.zip', 'new.zip'], $prunable, 'oldest-first, only scheduled+ok+unpinned');
        $this->assertNotContains('keep.zip', $prunable, 'a pinned/manual backup is never prunable');
        $this->assertNotContains('bad.zip', $prunable, 'a failed backup is never prunable');
    }

    // ---- ScheduleRun --------------------------------------------------------

    #[Test]
    public function schedule_begin_finish_and_last_run_ts(): void
    {
        $s    = new Tiger_Model_ScheduleRun();
        $slot = time();

        $this->assertNull($s->lastRunTs('backup.nightly'), 'a job that never ran has no last-run');

        $id = $s->begin('backup.nightly', $slot, 'cron');
        $this->assertSame('running', $s->findById($id)->outcome);

        $ts = $s->lastRunTs('backup.nightly');
        $this->assertNotNull($ts);
        $this->assertEqualsWithDelta(time(), $ts, 5, 'last-run is the start time');

        $s->finish($id, 'ok', null, 1234);
        $done = $s->findById($id);
        $this->assertSame('ok', $done->outcome);
        $this->assertSame(1234, (int) $done->duration_ms);
        $this->assertNotNull($done->finished_at);
    }

    #[Test]
    public function is_running_flags_a_fresh_running_row_but_not_a_stale_one(): void
    {
        $s  = new Tiger_Model_ScheduleRun();
        $id = $s->begin('report.hourly', time(), 'pseudo');

        $this->assertTrue($s->isRunning('report.hourly'), 'a fresh running row is the overlap lock');
        $this->assertFalse($s->isRunning('some.other.job'), 'a different job is not running');

        // Backdate the running row past the stale threshold — it should no longer count as running.
        $this->db->update('schedule_run', ['started_at' => date('Y-m-d H:i:s', time() - 3600)], $this->db->quoteInto('schedule_run_id = ?', $id));
        $this->assertFalse($s->isRunning('report.hourly', 1800), 'a running row older than staleSeconds is treated as dead');
    }

    #[Test]
    public function latest_per_job_and_history(): void
    {
        $s = new Tiger_Model_ScheduleRun();

        $first = $s->begin('sync.job', time() - 200, 'cron');
        $s->finish($first, 'error', 'failed once', 10);
        $this->db->update('schedule_run', ['started_at' => date('Y-m-d H:i:s', time() - 200)], $this->db->quoteInto('schedule_run_id = ?', $first));
        $second = $s->begin('sync.job', time(), 'cron');
        $s->finish($second, 'ok', null, 20);
        $s->begin('other.job', time(), 'cron');

        $latest = $s->latestPerJob();
        $this->assertArrayHasKey('sync.job', $latest);
        $this->assertArrayHasKey('other.job', $latest);
        $this->assertSame('ok', $latest['sync.job']['outcome'], 'latestPerJob keeps the newest row per key');

        $history = $s->history('sync.job', 20);
        $this->assertCount(2, $history, 'history returns every run for the job');
        $this->assertSame('ok', $history[0]['outcome'], 'history is newest first');
    }

    #[Test]
    public function finish_truncates_a_long_error(): void
    {
        $s  = new Tiger_Model_ScheduleRun();
        $id = $s->begin('noisy.job', time(), 'manual');
        $s->finish($id, 'error', str_repeat('E', 2000), 5);
        $this->assertSame(1000, mb_strlen($s->findById($id)->error), 'the error is clamped to 1000 chars');
    }

    // ---- UpdateHistory ------------------------------------------------------

    #[Test]
    public function update_history_records_an_item_and_reads_back_with_a_decoded_log(): void
    {
        $u = new Tiger_Model_UpdateHistory();

        $u->record([
            'item_type'    => 'module',
            'item_slug'    => 'blog',
            'item_name'    => 'Blog',
            'from_version' => '0.1.0',
            'to_version'   => '0.2.0',
            'outcome'      => Tiger_Model_UpdateHistory::OUTCOME_SUCCESS,
            'log'          => ['downloaded', 'migrated', 'activated'],
        ]);

        $recent = $u->recent(20);
        $this->assertCount(1, $recent);
        $this->assertSame('blog', $recent[0]['item_slug']);
        $this->assertSame('success', $recent[0]['outcome']);
        $this->assertSame(['downloaded', 'migrated', 'activated'], $recent[0]['log'], 'the JSON step log decodes to an array');
    }

    #[Test]
    public function update_history_defaults_a_missing_outcome_to_failed_and_a_missing_log_to_empty(): void
    {
        $u = new Tiger_Model_UpdateHistory();
        $u->record(['item_slug' => 'core']);   // minimal — defaults kick in

        $row = $u->recent(1)[0];
        $this->assertSame('module', $row['item_type'], 'item_type defaults to module');
        $this->assertSame('failed', $row['outcome'], 'a missing outcome defaults to failed');
        $this->assertSame([], $row['log'], 'a null log decodes to an empty array');
    }

    #[Test]
    public function recent_is_newest_first_and_honors_the_limit(): void
    {
        $u = new Tiger_Model_UpdateHistory();
        // Distinct created_at so "newest first" is deterministic (sub-second inserts would tie).
        foreach (['a' => 30, 'b' => 20, 'c' => 5] as $slug => $ago) {
            $id = $u->record(['item_slug' => $slug, 'outcome' => 'success']);
            $this->db->update('update_history', ['created_at' => date('Y-m-d H:i:s', time() - $ago)], $this->db->quoteInto('update_id = ?', $id));
        }

        $one = $u->recent(1);
        $this->assertCount(1, $one, 'the limit is respected');
        $this->assertSame('c', $one[0]['item_slug'], 'newest first');
    }
}
