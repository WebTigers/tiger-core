<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Media;

use Media_Service_Media;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Config;
use Zend_Registry;

/**
 * A subclass exposing Media_Service_Media's protected helpers for direct testing. Constructing with no
 * `action` dispatches nothing (the base returns early on an empty action), so this is a clean seam onto
 * the pure config/derivation helpers that otherwise only run inside the `upload()` pipeline — which is
 * unreachable from CLI because PHP's `is_uploaded_file()` refuses a synthesized upload (see below).
 */
final class ExposedMediaService extends Media_Service_Media
{
    public function callVariantKey($key, $preset, $ext) { return $this->_variantKey($key, $preset, $ext); }
    public function callPresets() { return $this->_presets(); }
    public function callQuality() { return $this->_quality(); }
    public function callServerEnabled() { return $this->_serverEnabled(); }
    public function callMime($tmp, $ext) { return $this->_mime($tmp, $ext); }
    public function callCfgInt($key, $default) { return $this->_cfgInt($key, $default); }
    public function callPresent($row) { return $this->_present($row); }
}

/**
 * Media_Service_Media — the pure helper seam (variant key derivation, config-backed variant presets /
 * quality / server-GD toggle, MIME sniffing, and the config-int reader). These run only inside the
 * `upload()` byte pipeline in production, so a subclass exposes them for direct coverage without a real
 * multipart upload. The upload pipeline itself is covered (ACL + no-file) in MediaServiceTest.
 */
#[CoversClass(Media_Service_Media::class)]
final class MediaHelpersTest extends IntegrationTestCase
{
    private function service(array $variants = []): ExposedMediaService
    {
        $media = ['default_disk' => 'local'];
        if ($variants) { $media['variants'] = $variants; }
        Zend_Registry::set('Zend_Config', new Zend_Config(['media' => $media], true));
        return new ExposedMediaService([]);   // no action → constructs without dispatching
    }

    #[Test]
    public function variant_key_inserts_the_preset_alongside_the_original(): void
    {
        $svc = $this->service();
        $this->assertSame('org/image/base.thumbnail.png', $svc->callVariantKey('org/image/base.png', 'thumbnail', 'png'));
        $this->assertSame('org/image/base.small.jpg', $svc->callVariantKey('org/image/base.webp', 'small', 'JPG'));
        // No extension on the key → the preset is appended and a default ext used.
        $this->assertSame('org/image/base.large.img', $svc->callVariantKey('org/image/base', 'large', ''));
    }

    #[Test]
    public function presets_returns_only_the_positive_configured_sizes(): void
    {
        $svc = $this->service(['thumbnail' => 200, 'small' => 480, 'medium' => 0, 'large' => -5]);
        $this->assertSame(['thumbnail' => 200, 'small' => 480], $svc->callPresets(), 'zero/negative sizes are dropped');
    }

    #[Test]
    public function presets_is_empty_without_a_variants_config(): void
    {
        $this->assertSame([], $this->service()->callPresets());
    }

    #[Test]
    public function quality_defaults_to_ninety_and_honors_a_positive_override(): void
    {
        $this->assertSame(90, $this->service()->callQuality(), 'default JPEG/WebP quality');
        $this->assertSame(70, $this->service(['quality' => 70])->callQuality());
        $this->assertSame(90, $this->service(['quality' => 0])->callQuality(), 'a non-positive override falls back');
    }

    #[Test]
    public function server_enabled_defaults_on_and_is_disabled_by_zero(): void
    {
        $this->assertTrue($this->service()->callServerEnabled(), 'GD server variants default on');
        $this->assertTrue($this->service(['server' => 1])->callServerEnabled());
        $this->assertFalse($this->service(['server' => 0])->callServerEnabled(), 'server=0 disables GD variants');
    }

    #[Test]
    public function cfg_int_reads_media_config_with_a_default(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['media' => ['max_upload' => 1048576]], true));
        $svc = new ExposedMediaService([]);
        $this->assertSame(1048576, $svc->callCfgInt('max_upload', 52428800), 'configured value wins');
        $this->assertSame(999, $svc->callCfgInt('not_set', 999), 'default when unset');
    }

    #[Test]
    public function mime_sniffs_a_real_file(): void
    {
        $svc = $this->service();

        $png = tempnam(sys_get_temp_dir(), 'w4mime');
        // A real 1x1 PNG (header + IHDR + IDAT + IEND) so finfo reports image/png.
        file_put_contents($png, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));
        try {
            $this->assertSame('image/png', $svc->callMime($png, 'png'), 'finfo sniffs the real bytes');
        } finally {
            @unlink($png);
        }
        // (The octet-stream fallback fires only when finfo can't read the file — a filesystem edge
        // noted in WAVE4-FINDINGS-mediaan.md; exercising it emits a PHP warning, so it's left uncovered.)
    }

    #[Test]
    public function present_shapes_a_row_with_urls_and_hides_storage_internals(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['media' => ['default_disk' => 'local']], true));
        $svc = new ExposedMediaService([]);

        $shaped = $svc->callPresent([
            'media_id'    => 'abc-123',
            'kind'        => 'image',
            'mime_type'   => 'image/png',
            'extension'   => 'png',
            'file_size'   => 2048,
            'filename'    => 'pic.png',
            'title'       => 'Pic',
            'visibility'  => 'public',
            'width'       => 800,
            'height'      => 600,
            'storage_key' => 'org/image/pic.png',
            'disk'        => 'local',
        ]);

        $this->assertSame('abc-123', $shaped['media_id']);
        $this->assertSame(2048, $shaped['file_size'], 'cast to int');
        $this->assertSame(800, $shaped['width']);
        $this->assertArrayHasKey('url', $shaped);
        $this->assertArrayHasKey('thumb', $shaped);
        $this->assertArrayHasKey('large', $shaped, 'per-size sources present');
        $this->assertArrayNotHasKey('storage_key', $shaped, 'storage internals are not exposed');
        $this->assertArrayNotHasKey('disk', $shaped);
    }
}
