<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Ally;

use Ally_Service_Scan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;

/**
 * Ally_Service_Scan — the /api behind the TigerAlly accessibility inspector. READ-ONLY, admin-gated.
 *
 * Coverage: the ACL gate (admin+, deny-by-default), `scan` over a pasted HTML blob and over a rendered
 * CMS page (by id), the empty/not-found guards, `pages` (the picker list), `scanAll` (per-page roll-up),
 * and `scanModule` (view-template scan attributing findings to a file). The scan engine itself
 * (Tiger_Ally) is covered by its own unit test; here we prove the service wiring, gating, and shape.
 */
#[CoversClass(Ally_Service_Scan::class)]
final class ScanServiceTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        // scanModule needs MODULES_PATH; its natural production value is the modules root. Defining it
        // here (guarded) lets the module-scan path run against the real ally module's views.
        if (!defined('MODULES_PATH')) {
            define('MODULES_PATH', TIGER_CORE_PATH . '/modules');
        }
    }

    private function call(string $action, array $params = []): object
    {
        return (new Ally_Service_Scan(['action' => $action] + $params))->getResponse();
    }

    /** Seed a CMS page (html format) directly; returns its id. Stays inside the harness txn. */
    private function seedPage(array $overrides): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'org_id' => '',
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
            'title'  => 'Seed',
            'slug'   => 'seed',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'body'   => '<p>ok</p>',
            'meta'   => '{}',
        ], $overrides));
    }

    // ----- ACL gate -----------------------------------------------------------------------------

    #[Test]
    public function guest_and_plain_user_are_denied_every_action(): void
    {
        foreach (['scan', 'pages', 'scanAll', 'scanModule'] as $action) {
            $this->login('anon', 'org-test', 'guest');
            $res = $this->call($action, ['html' => '<p>x</p>', 'module' => 'ally']);
            $this->assertSame(0, (int) $res->result, "guest denied on $action");
            $this->assertStringContainsString('not_allowed', json_encode($res->messages), "ACL denial on $action");

            $this->loginAs('user');
            $res = $this->call($action, ['html' => '<p>x</p>', 'module' => 'ally']);
            $this->assertSame(0, (int) $res->result, "plain user denied on $action");
        }
    }

    // ----- scan (pasted HTML) -------------------------------------------------------------------

    #[Test]
    public function scan_reports_an_image_without_alt_from_pasted_html(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scan', ['html' => '<div><img src="/logo.png"></div>']);

        $this->assertSame(1, (int) $res->result);
        $this->assertFalse($res->data['passed'], 'a missing alt is an error → not passed');
        $this->assertGreaterThanOrEqual(1, $res->data['summary']['error']);
        $rules = array_column($res->data['findings'], 'rule');
        $this->assertContains('img-alt', $rules);
        $this->assertNull($res->data['source'], 'pasted HTML has no page source');
    }

    #[Test]
    public function clean_html_passes(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scan', ['html' => '<p>Just text, nothing to flag.</p>']);
        $this->assertSame(1, (int) $res->result);
        $this->assertTrue($res->data['passed']);
        $this->assertSame(0, $res->data['summary']['error']);
    }

    #[Test]
    public function scan_rejects_empty_html(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scan', ['html' => '   ']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('ally.scan.empty', json_encode($res->messages));
    }

    // ----- scan (a CMS page by id) --------------------------------------------------------------

    #[Test]
    public function scan_renders_a_cms_page_by_id_and_attaches_the_source(): void
    {
        $this->loginAs('admin');
        $id = $this->seedPage(['title' => 'Landing', 'slug' => 'landing', 'body' => '<img src="/hero.jpg">']);

        $res = $this->call('scan', ['page_id' => $id]);
        $this->assertSame(1, (int) $res->result);
        $this->assertIsArray($res->data['source']);
        $this->assertSame($id, $res->data['source']['page_id']);
        $this->assertSame('Landing', $res->data['source']['title']);
        $rules = array_column($res->data['findings'], 'rule');
        $this->assertContains('img-alt', $rules, 'the rendered page body is inspected');
    }

    #[Test]
    public function scan_errors_on_an_unknown_page_id(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scan', ['page_id' => 'does-not-exist']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('ally.scan.page_not_found', json_encode($res->messages));
    }

    // ----- pages (the picker list) --------------------------------------------------------------

    #[Test]
    public function pages_lists_the_scannable_cms_pages(): void
    {
        $this->loginAs('admin');
        $this->seedPage(['title' => 'Alpha', 'slug' => 'alpha']);
        $this->seedPage(['title' => 'Beta',  'slug' => 'beta']);

        $res = $this->call('pages');
        $this->assertSame(1, (int) $res->result);
        $slugs = array_column($res->data['pages'], 'slug');
        $this->assertContains('alpha', $slugs);
        $this->assertContains('beta', $slugs);
    }

    // ----- scanAll (per-page roll-up) -----------------------------------------------------------

    #[Test]
    public function scan_all_rolls_up_pass_fail_across_pages(): void
    {
        $this->loginAs('admin');
        $this->seedPage(['title' => 'Good', 'slug' => 'good', 'body' => '<p>fine</p>']);
        $this->seedPage(['title' => 'Bad',  'slug' => 'bad',  'body' => '<img src="/x.png">']);

        $res = $this->call('scanAll');
        $this->assertSame(1, (int) $res->result);
        $this->assertSame(2, $res->data['totals']['pages']);
        $this->assertGreaterThanOrEqual(1, $res->data['totals']['passed'], 'the clean page passes');
        $this->assertGreaterThanOrEqual(1, $res->data['totals']['error'], 'the img-alt page contributes an error');
        $this->assertCount(2, $res->data['results']);
    }

    // ----- scanModule (view-template scan) ------------------------------------------------------

    #[Test]
    public function scan_module_scans_a_modules_view_templates(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scanModule', ['module' => 'ally']);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame('ally', $res->data['module']);
        $this->assertGreaterThanOrEqual(1, $res->data['totals']['scanned'], 'at least the index.phtml was scanned');
        $this->assertArrayHasKey('files', $res->data);
    }

    #[Test]
    public function scan_module_attributes_findings_to_files_when_a_view_has_gaps(): void
    {
        // The blog module ships view templates with nominal a11y gaps — a good fixture for the
        // per-file attribution path (the files[] roll-up branch).
        $this->loginAs('admin');
        $res = $this->call('scanModule', ['module' => 'blog']);

        $this->assertSame(1, (int) $res->result);
        $this->assertGreaterThanOrEqual(1, $res->data['totals']['files_with_issues']);
        $this->assertNotEmpty($res->data['files'], 'offending files are listed');
        $first = $res->data['files'][0];
        $this->assertStringStartsWith('application/modules/blog/views', $first['file'], 'finding attributed to its source file');
        $this->assertArrayHasKey('findings', $first);
    }

    // ----- render-failure branches --------------------------------------------------------------

    #[Test]
    public function scan_reports_a_render_failure_for_a_broken_page(): void
    {
        $this->loginAs('admin');
        // A phtml page whose body throws at render time → the service's render_failed guard.
        $id = $this->seedPage([
            'title'  => 'Broken',
            'slug'   => 'broken',
            'format' => Tiger_Model_Page::FORMAT_PHTML,
            'body'   => '<?php throw new \\RuntimeException("boom"); ?>',
        ]);
        // The service logs the render failure to a stream sink (php://stdout, which bypasses PHP output
        // buffering) — swap Tiger_Log's logger for a null writer so the diagnostic line doesn't count as
        // unexpected test output under strict mode. Restored after.
        $logProp = new \ReflectionProperty(\Tiger_Log::class, '_log');
        $logProp->setAccessible(true);
        $prior = $logProp->getValue();
        $logProp->setValue(null, (new \Zend_Log())->addWriter(new \Zend_Log_Writer_Null()));
        try {
            $res = $this->call('scan', ['page_id' => $id]);
        } finally {
            $logProp->setValue(null, $prior);
        }
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('ally.scan.render_failed', json_encode($res->messages));
    }

    #[Test]
    public function scan_all_marks_a_page_that_fails_to_render_as_skipped(): void
    {
        $this->loginAs('admin');
        $this->seedPage(['title' => 'OK', 'slug' => 'ok-page', 'body' => '<p>fine</p>']);
        $this->seedPage([
            'title'  => 'Broken',
            'slug'   => 'broken-page',
            'format' => Tiger_Model_Page::FORMAT_PHTML,
            'body'   => '<?php throw new \\RuntimeException("boom"); ?>',
        ]);

        $res = $this->call('scanAll');
        $this->assertSame(1, (int) $res->result);
        $skipped = array_filter($res->data['results'], static fn ($r) => !empty($r['skipped']));
        $this->assertNotEmpty($skipped, 'the un-renderable page is flagged skipped, not fatal');
    }

    #[Test]
    public function scan_module_errors_on_an_unknown_module(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scanModule', ['module' => 'no-such-module-xyz']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('ally.scan.module_not_found', json_encode($res->messages));
    }

    #[Test]
    public function scan_module_rejects_a_blank_module(): void
    {
        $this->loginAs('admin');
        $res = $this->call('scanModule', ['module' => '']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('ally.scan.module_not_found', json_encode($res->messages));
    }
}
