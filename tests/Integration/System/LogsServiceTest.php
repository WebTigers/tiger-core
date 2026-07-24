<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Logs;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Config;
use Zend_Registry;

// `System_Service_Logs` resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * System_Service_Logs — read-only application-log viewer (the read side of the write-only Tiger_Log).
 * Superadmin+ per modules/system/configs/acl.ini (log context can carry error detail / PII). When the
 * sink is a file/stream it tails the file, parses the JSON-per-line records, filters by minimum level
 * + free text, and returns the newest first (bounded); on a non-file sink it reports where the logs go.
 *
 * These tests drive `search` against a controlled Zend_Config: a non-file sink (available=false) and a
 * real temp log file (available=true) exercising the level filter, the free-text filter, newest-first
 * ordering, and the result limit — plus the superadmin-only ACL gate.
 */
#[CoversClass(System_Service_Logs::class)]
final class LogsServiceTest extends IntegrationTestCase
{
    private ?Zend_Config $priorConfig = null;
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) { @unlink($f); }
        $this->tmpFiles = [];
        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif (Zend_Registry::isRegistered('Zend_Config')) {
            Zend_Registry::set('Zend_Config', new Zend_Config([]));
        }
        parent::tearDown();
    }

    private function setLogConfig(array $log): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['log' => $log]]));
    }

    /** Write JSON-per-line log records to a temp file and point the stream sink at it. */
    private function seedLogFile(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tigerlog') . '.log';
        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->tmpFiles[] = $path;
        $this->setLogConfig(['writer' => 'stream', 'stream' => ['path' => $path]]);
        return $path;
    }

    private function dispatch(array $msg): object
    {
        return (new System_Service_Logs($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- ACL: superadmin+ ------------------------------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_the_log_viewer_to_superadmin_and_up(): void
    {
        $this->loginAs('superadmin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('System_Service_Logs'), 'the acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('superadmin', 'System_Service_Logs'));
        $this->assertFalse($acl->isAllowed('admin', 'System_Service_Logs'), 'a plain admin cannot read logs');
        $this->assertFalse($acl->isAllowed('guest', 'System_Service_Logs'));
    }

    #[Test]
    public function a_plain_admin_is_denied_reading_logs(): void
    {
        $this->loginAs('admin');
        $this->setLogConfig(['writer' => 'errorlog']);
        $res = $this->dispatch(['action' => 'search']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }

    // ---- non-file sink: report where the logs go -------------------------------------------------

    #[Test]
    public function search_on_a_non_file_sink_reports_the_sink_and_no_entries(): void
    {
        $this->loginAs('superadmin');
        $this->setLogConfig(['writer' => 'errorlog']);
        $res = $this->dispatch(['action' => 'search']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertFalse($res->data['available'], 'a non-file sink is not tailable');
        $this->assertSame('errorlog', $res->data['sink']);
        $this->assertSame([], $res->data['entries']);
        $this->assertNotEmpty($res->data['levels']);
    }

    // ---- file sink: tail + filter ----------------------------------------------------------------

    #[Test]
    public function search_tails_a_file_sink_newest_first(): void
    {
        $this->loginAs('superadmin');
        $this->seedLogFile([
            json_encode(['ts' => '2026-07-24T10:00:00Z', 'level' => 'INFO', 'msg' => 'first']),
            json_encode(['ts' => '2026-07-24T10:01:00Z', 'level' => 'WARN', 'msg' => 'second']),
            'not-a-json-marker-line',
            json_encode(['ts' => '2026-07-24T10:02:00Z', 'level' => 'ERR', 'msg' => 'third']),
        ]);
        $res = $this->dispatch(['action' => 'search']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertTrue($res->data['available']);
        $this->assertSame('stream', $res->data['sink']);

        $msgs = array_column($res->data['entries'], 'msg');
        $this->assertSame(['third', 'second', 'first'], $msgs, 'newest first, non-JSON lines skipped');
    }

    #[Test]
    public function search_filters_by_minimum_level(): void
    {
        $this->loginAs('superadmin');
        $this->seedLogFile([
            json_encode(['ts' => '1', 'level' => 'DEBUG', 'msg' => 'd']),
            json_encode(['ts' => '2', 'level' => 'INFO', 'msg' => 'i']),
            json_encode(['ts' => '3', 'level' => 'WARN', 'msg' => 'w']),
            json_encode(['ts' => '4', 'level' => 'ERR', 'msg' => 'e']),
        ]);
        $res = $this->dispatch(['action' => 'search', 'level' => 'WARN']);

        $msgs = array_column($res->data['entries'], 'msg');
        $this->assertSame(['e', 'w'], $msgs, 'only WARN and above, newest first');
    }

    #[Test]
    public function search_filters_by_free_text_across_message_and_context(): void
    {
        $this->loginAs('superadmin');
        $this->seedLogFile([
            json_encode(['ts' => '1', 'level' => 'ERR', 'msg' => 'database exploded', 'context' => []]),
            json_encode(['ts' => '2', 'level' => 'ERR', 'msg' => 'all good', 'context' => ['note' => 'database ok']]),
            json_encode(['ts' => '3', 'level' => 'ERR', 'msg' => 'unrelated', 'context' => []]),
        ]);
        $res = $this->dispatch(['action' => 'search', 'q' => 'database']);

        $msgs = array_column($res->data['entries'], 'msg');
        $this->assertSame(['all good', 'database exploded'], $msgs, 'matches msg OR context, newest first');
    }

    #[Test]
    public function search_honors_the_result_limit(): void
    {
        $this->loginAs('superadmin');
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = json_encode(['ts' => (string) $i, 'level' => 'INFO', 'msg' => 'line' . $i]);
        }
        $this->seedLogFile($lines);
        $res = $this->dispatch(['action' => 'search', 'limit' => 3]);

        $this->assertSame(1, (int) $res->result);
        $this->assertCount(3, $res->data['entries'], 'capped at the requested limit');
        $this->assertSame(3, $res->data['count']);
        $this->assertSame('line10', $res->data['entries'][0]['msg'], 'newest kept when limited');
    }
}
