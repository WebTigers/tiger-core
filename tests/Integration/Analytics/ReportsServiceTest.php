<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Analytics;

use Analytics_Service_Reports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Crypto;
use Zend_Config;
use Zend_Registry;

/**
 * Analytics_Service_Reports — the /api service the analytics dashboard + widget fetch from. Admin-only,
 * read-only. Wave-4 coverage of the reachable surface: the ACL gate on both actions, `summary`'s
 * not-connected short-circuit, and `test`'s diagnosis envelope (a not-connected install still returns a
 * successful CALL with `data.test.ok=false` + a `not_connected` code — that's the Troubleshooting seam).
 *
 * The connected/live-GA4 paths need a real signed refresh token + an HTTP round-trip to Google, so
 * they're out of scope for a hermetic integration test — see WAVE4-FINDINGS-mediaan.md.
 */
#[CoversClass(Analytics_Service_Reports::class)]
final class ReportsServiceTest extends IntegrationTestCase
{
    private const PROPERTY_ID = '123456789';
    private ?string $cacheFile = null;

    protected function tearDown(): void
    {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) { @unlink($this->cacheFile); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Analytics_Service_Reports(['action' => $action] + $params))->getResponse();
    }

    #[Test]
    public function guest_is_denied_on_summary_and_test(): void
    {
        $this->login('anon', 'org-test', 'guest');
        foreach (['summary', 'test'] as $action) {
            $res = $this->call($action);
            $this->assertSame(0, (int) $res->result, "guest denied on {$action}");
            $this->assertStringContainsString('not_allowed', json_encode($res->messages));
        }
    }

    #[Test]
    public function a_plain_user_is_denied(): void
    {
        $this->loginAs('user');
        $res = $this->call('summary');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function summary_reports_not_connected_when_ga_is_unconfigured(): void
    {
        // No GA config anywhere → Tiger_Google_Analytics::isConnected() is false → the guard fires.
        $this->loginAs('admin');
        $res = $this->call('summary', ['days' => 28]);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('analytics.reports.not_connected', json_encode($res->messages));
    }

    #[Test]
    public function test_returns_a_not_connected_diagnosis_as_a_successful_call(): void
    {
        // The self-test is a successful CALL even when the connection is down — the client reads
        // data.test.ok to render the message/hint. Unconfigured → ok=false, code=not_connected.
        $this->loginAs('admin');
        $res = $this->call('test');
        $this->assertSame(1, (int) $res->result, 'the call itself succeeds');
        $this->assertIsArray($res->data['test']);
        $this->assertFalse($res->data['test']['ok']);
        $this->assertSame('not_connected', $res->data['test']['code']);
    }

    #[Test]
    public function summary_returns_the_cached_ga_data_when_connected(): void
    {
        // Connect GA (crypto key + property + decryptable refresh token) and pre-seed the report cache
        // so summary() serves it WITHOUT any network round-trip to Google — covers the connected path.
        $this->connectGa();
        $expected = ['range' => ['days' => 28], 'totals' => [10, 5, 42], 'series' => [['users' => 3]]];
        $this->seedCache(28, $expected);

        $this->loginAs('admin');
        $res = $this->call('summary', ['days' => 28]);

        $this->assertSame(1, (int) $res->result, 'connected + cached → success');
        $this->assertSame($expected, $res->data['summary'], 'the cached summary is returned verbatim');
    }

    /** Make Tiger_Google_Analytics::isConnected() true (broker mode: crypto key + property + refresh token). */
    private function connectGa(): void
    {
        $key = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';   // 32 zero bytes, base64
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['crypto' => ['key' => $key]]], true));
        $enc = Tiger_Crypto::encrypt('fake-refresh-token');
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => [
            'crypto'    => ['key' => $key],
            'analytics' => [
                'property_id' => self::PROPERTY_ID,
                'oauth'       => ['mode' => 'broker', 'refresh_token_enc' => $enc],
            ],
        ]], true));
    }

    /** Write a fresh report-cache file at the path Tiger_Google_Analytics::_cacheFile() computes. */
    private function seedCache(int $days, array $data): void
    {
        $base = (defined('APPLICATION_PATH') ? dirname(APPLICATION_PATH) : sys_get_temp_dir()) . '/var/cache/analytics';
        if (!is_dir($base)) { @mkdir($base, 0775, true); }
        $this->cacheFile = $base . '/ga-' . substr(md5(self::PROPERTY_ID), 0, 10) . '-' . $days . 'd.json';
        file_put_contents($this->cacheFile, json_encode($data));
    }
}
