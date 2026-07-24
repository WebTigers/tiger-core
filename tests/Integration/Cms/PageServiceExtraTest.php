<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Cms;

use Cms_Service_Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Zend_Registry;

/**
 * Cms_Service_Page — the two authoring methods Wave 3 left uncovered: forkTheme (customize a file-based
 * theme template into an editable DB page that overrides it — the live-override tier) and saveDesign
 * (persist the GrapesJS builder's HTML+CSS+project, stripping <script> to keep the SAFE builder format).
 *
 * forkTheme + saveDesign both defer to Tiger_Model_Page::save() (its own write+version transaction), so —
 * like the Wave 3 save/restore tests — these commit the harness transaction first (escapeTxn) and lean on
 * the tearDown scrub. A throwaway theme (a temp dir + a `content/` template) is wired in via the
 * `Tiger_ThemeDir` seam so forkTheme has a real template to fork.
 */
#[CoversClass(Cms_Service_Page::class)]
final class PageServiceExtraTest extends IntegrationTestCase
{
    private string $themeDir;
    private bool $hadThemeDir = false;
    private $priorThemeDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeDir = sys_get_temp_dir() . '/w6-cms-forktheme-' . getmypid();
        @mkdir($this->themeDir . '/content', 0777, true);
        file_put_contents($this->themeDir . '/theme.json', json_encode(['key' => 'testtheme', 'name' => 'Test Theme']));
        file_put_contents(
            $this->themeDir . '/content/about.phtml',
            "<!-- tiger:page title=\"About Us\" layout=\"page\" -->\n<h1>About</h1>"
        );

        $reg = Zend_Registry::getInstance();
        $this->hadThemeDir = $reg->offsetExists('Tiger_ThemeDir');
        if ($this->hadThemeDir) { $this->priorThemeDir = Zend_Registry::get('Tiger_ThemeDir'); }
        Zend_Registry::set('Tiger_ThemeDir', $this->themeDir);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->query('DELETE FROM page_version');
            $this->db->query('DELETE FROM page_redirect');
            $this->db->query('DELETE FROM page');
        } catch (\Throwable $e) {
            // ignore
        }
        $reg = Zend_Registry::getInstance();
        if ($this->hadThemeDir) { Zend_Registry::set('Tiger_ThemeDir', $this->priorThemeDir); }
        elseif ($reg->offsetExists('Tiger_ThemeDir')) { $reg->offsetUnset('Tiger_ThemeDir'); }
        $this->rmrf($this->themeDir);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function call(string $action, array $params = []): object
    {
        return (new Cms_Service_Page(['action' => $action] + $params))->getResponse();
    }

    private function escapeTxn(): void
    {
        $this->db->commit();
    }

    // ----- forkTheme ----------------------------------------------------------------------------

    #[Test]
    public function fork_theme_is_denied_to_a_guest(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call('forkTheme', ['slug' => 'about']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages), 'guest denied');
    }

    #[Test]
    public function fork_theme_errors_when_the_template_is_unavailable(): void
    {
        $this->loginAs('admin');
        $res = $this->call('forkTheme', ['slug' => 'no-such-template']);
        $this->assertSame(0, (int) $res->result, 'an unknown slug is a clean error, not a crash');
    }

    #[Test]
    public function fork_theme_creates_an_editable_page_overriding_the_file(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();

        $res = $this->call('forkTheme', ['slug' => 'about']);
        $this->assertSame(1, (int) $res->result, 'the template forked into a page');
        $id = $res->data['page_id'];
        $this->assertStringContainsString('/cms/page/edit/id/', $res->data['edit_url']);

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame('about', $row->slug, 'the fork claims the template slug (so it overrides the file)');
        $this->assertSame('About Us', $row->title, 'title comes from the template hint');
        $this->assertStringContainsString('About', (string) $row->body);
        $this->assertSame(Tiger_Model_Page::STATUS_PUBLISHED, $row->status);
        // Pure-markup body → html (opens in the visual builder); origin tagged in meta.
        $this->assertSame(Tiger_Model_Page::FORMAT_HTML, $row->format);
        $meta = json_decode((string) $row->meta, true);
        $this->assertSame('theme', $meta['source'], 'the origin is recorded for a later revert');
        $this->assertSame('testtheme', $meta['source_key']);
    }

    #[Test]
    public function fork_theme_returns_the_existing_page_when_the_slug_is_already_customized(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();

        // First fork creates the row; a second fork must find it and return it (idempotent — no duplicate).
        $first = $this->call('forkTheme', ['slug' => 'about']);
        $existingId = $first->data['page_id'];

        $second = $this->call('forkTheme', ['slug' => 'about']);
        $this->assertSame(1, (int) $second->result);
        $this->assertSame($existingId, $second->data['page_id'], 'the already-customized page is returned, not re-forked');
        $this->assertStringContainsString('exists', json_encode($second->messages));

        $count = (int) $this->db->fetchOne("SELECT COUNT(*) FROM page WHERE slug = 'about' AND deleted = 0");
        $this->assertSame(1, $count, 'exactly one page claims the slug');
    }

    // ----- saveDesign ---------------------------------------------------------------------------

    #[Test]
    public function save_design_is_denied_to_a_guest(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call('saveDesign', ['page_id' => 'x', 'html' => '<div></div>']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function save_design_errors_on_a_missing_page_id(): void
    {
        $this->loginAs('admin');
        $res = $this->call('saveDesign', ['page_id' => '', 'html' => '<div></div>']);
        $this->assertSame(0, (int) $res->result, 'no page_id is refused up front');
    }

    #[Test]
    public function save_design_errors_when_the_page_does_not_exist(): void
    {
        $this->loginAs('admin');
        $res = $this->call('saveDesign', ['page_id' => 'no-such-page', 'html' => '<div></div>']);
        $this->assertSame(0, (int) $res->result, 'a non-existent row is a clean error');
    }

    #[Test]
    public function save_design_stores_the_builder_body_strips_script_and_keeps_the_project(): void
    {
        $this->loginAs('admin');
        $id = (new Tiger_Model_Page())->insert([
            'type' => Tiger_Model_Page::TYPE_PAGE, 'locale' => 'en', 'title' => 'Canvas',
            'slug' => 'canvas', 'page_key' => 'canvas', 'body' => '', 'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_DRAFT,
        ]);
        $this->escapeTxn();

        $res = $this->call('saveDesign', [
            'page_id' => $id,
            'html'    => '<section>Hero</section><script>alert(1)</script>',
            'css'     => '.hero{color:red}',
            'project' => json_encode(['pages' => [['name' => 'p']]]),
        ]);
        $this->assertSame(1, (int) $res->result);

        $row = (new Tiger_Model_Page())->findById($id);
        $this->assertSame(Tiger_Model_Page::FORMAT_BUILDER, $row->format, 'the builder format is set');
        $this->assertStringContainsString('<style>', (string) $row->body, 'the CSS is wrapped in a <style> block');
        $this->assertStringContainsString('Hero', (string) $row->body);
        $this->assertStringNotContainsString('<script', (string) $row->body, 'script is stripped — builder is a SAFE format');

        $meta = json_decode((string) $row->meta, true);
        $this->assertArrayHasKey('builder', $meta, 'the lossless project JSON is preserved in meta.builder');
        $this->assertSame('p', $meta['builder']['pages'][0]['name']);
    }
}
