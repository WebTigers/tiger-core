<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Identity;

use Identity_Plugin_Favicon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Media;
use Zend_Config;
use Zend_Controller_Request_Simple;
use Zend_Registry;
use Zend_View;

/**
 * Identity_Plugin_Favicon — contributes the site favicon (config `tiger.site.favicon`, a media id) to
 * the head via TigerZF's `headLink` registry, as both `rel=icon` and `rel=apple-touch-icon`. Fail-open:
 * an unset or unresolvable favicon emits nothing. Wave-4 coverage: no config → silent, an unresolvable
 * id → silent, and a real media id → two head links pointing at the resolved media URL.
 *
 * The plugin has a process-wide emit-once latch (`$_done`); each test resets it via reflection.
 */
#[CoversClass(Identity_Plugin_Favicon::class)]
final class FaviconPluginTest extends IntegrationTestCase
{
    private Zend_View $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new Zend_View();
        Zend_Registry::set('Zend_View', $this->view);
        // headLink is a process-wide singleton container — clear it so links from a prior test don't leak.
        $this->view->headLink()->exchangeArray([]);
        $this->resetLatch();
    }

    protected function tearDown(): void
    {
        $this->resetLatch();
        parent::tearDown();
    }

    private function resetLatch(): void
    {
        $p = new ReflectionProperty(Identity_Plugin_Favicon::class, '_done');
        $p->setValue(null, false);
    }

    private function faviconConfig(string $id): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['site' => ['favicon' => $id]]], true));
    }

    private function headLinks(): string
    {
        return (string) $this->view->headLink();
    }

    private function dispatch(): void
    {
        (new Identity_Plugin_Favicon())->preDispatch(new Zend_Controller_Request_Simple());
    }

    #[Test]
    public function emits_nothing_when_no_favicon_is_configured(): void
    {
        $this->faviconConfig('');
        $this->dispatch();
        $this->assertSame('', trim($this->headLinks()), 'no config → no head links');
    }

    #[Test]
    public function emits_nothing_for_an_unresolvable_media_id(): void
    {
        $this->faviconConfig('deadbeef-0000-7000-8000-000000000000');   // no such media row
        $this->dispatch();
        $this->assertSame('', trim($this->headLinks()), 'unresolvable id → fail-open, nothing emitted');
    }

    #[Test]
    public function emits_icon_and_apple_touch_icon_for_a_real_media_id(): void
    {
        $id = (new Tiger_Model_Media())->insert([
            'org_id'      => '',
            'disk'        => 'local',
            'storage_key' => 'favicon/site-icon.png',
            'visibility'  => Tiger_Model_Media::VISIBILITY_PUBLIC,
            'kind'        => Tiger_Model_Media::KIND_IMAGE,
            'mime_type'   => 'image/png',
            'extension'   => 'png',
            'filename'    => 'icon.png',
        ]);
        $this->faviconConfig($id);

        $this->dispatch();
        $out = $this->headLinks();

        $this->assertStringContainsString('rel="icon"', $out, 'browser icon link emitted');
        $this->assertStringContainsString('rel="apple-touch-icon"', $out, 'iOS touch icon emitted');
        // With no storage disk configured, url() falls back to the ACL-checked stream route for the id.
        $this->assertStringContainsString($id, $out, 'the link points at the resolved media URL');
    }

    #[Test]
    public function the_emit_once_latch_prevents_duplicate_links(): void
    {
        $id = (new Tiger_Model_Media())->insert([
            'org_id'      => '',
            'disk'        => 'local',
            'storage_key' => 'favicon/icon2.png',
            'visibility'  => Tiger_Model_Media::VISIBILITY_PUBLIC,
            'kind'        => Tiger_Model_Media::KIND_IMAGE,
            'mime_type'   => 'image/png',
            'extension'   => 'png',
            'filename'    => 'icon2.png',
        ]);
        $this->faviconConfig($id);

        $plugin = new Identity_Plugin_Favicon();
        $plugin->preDispatch(new Zend_Controller_Request_Simple());
        $plugin->preDispatch(new Zend_Controller_Request_Simple());   // second dispatch/forward
        $this->assertSame(1, substr_count($this->headLinks(), 'rel="icon"'), 'the icon link is added exactly once');
    }
}
