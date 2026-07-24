<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Media_Image;

/**
 * Tiger_Media_Image — GD-backed variant generation (thumbnails / sized copies).
 *
 * The whole class is OPTIONAL on GD, so the suite skips wholesale when GD (or PNG support) is
 * absent rather than failing. The invariants under test are the product-spec ones:
 *   - **never upscale** — a preset at/above the source's longest edge is skipped (the original
 *     serves that size), so a variant is never bigger than its source;
 *   - **contain, never crop** — the longest edge is scaled to the preset and the other edge
 *     follows the aspect ratio (exact fit math);
 *   - the **MIME→GD-type map** — recognized image MIMEs (and the jpeg aliases) are supported,
 *     unknown MIMEs are not, and an unsupported MIME yields [] rather than an error.
 *
 * Sources are tiny truecolor PNGs synthesized in-memory with GD; every temp file the code emits
 * is tracked and unlinked in tearDown.
 */
#[CoversClass(Tiger_Media_Image::class)]
final class ImageTest extends UnitTestCase
{
    /** @var string[] temp files to clean up (synthesized sources + emitted variants) */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!Tiger_Media_Image::hasGd() || !Tiger_Media_Image::supports('image/png')) {
            $this->markTestSkipped('GD (with PNG support) is not available in this PHP build.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** Synthesize a real w×h PNG on disk and return its path (tracked for cleanup). */
    private function pngSource(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 10, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'tsrc') . '.png';
        imagepng($img, $path);
        // (no imagedestroy — it's a deprecated no-op since PHP 8.0; GC reclaims the GdImage)
        $this->tmp[] = $path;
        return $path;
    }

    /** Track any variant temp files the class emitted so tearDown removes them. */
    private function trackVariants(array $variants): array
    {
        foreach ($variants as $v) {
            if (isset($v['path'])) {
                $this->tmp[] = $v['path'];
            }
        }
        return $variants;
    }

    // ---- Never upscale ------------------------------------------------------------------

    #[Test]
    public function a_preset_larger_than_the_source_is_skipped_never_upscaled(): void
    {
        // Source longest edge = 100. A 500px preset would be an upscale → skipped; a 50px one runs.
        $src = $this->pngSource(100, 80);
        $out = $this->trackVariants(
            Tiger_Media_Image::variants($src, 'image/png', ['big' => 500, 'thumb' => 50])
        );

        $this->assertArrayNotHasKey('big', $out, 'a preset >= the source longest edge must not be produced');
        $this->assertArrayHasKey('thumb', $out);
        // 50/100 = 0.5 → 100×80 becomes 50×40. Never larger than the source in either dimension.
        $this->assertSame(50, $out['thumb']['width']);
        $this->assertSame(40, $out['thumb']['height']);
        $this->assertLessThanOrEqual(100, $out['thumb']['width']);
        $this->assertLessThanOrEqual(80, $out['thumb']['height']);
    }

    #[Test]
    public function a_preset_equal_to_the_source_longest_edge_is_skipped(): void
    {
        // edge >= long is the skip condition (>=, not >): a 100px preset on a 100px source is
        // the original, so it's not re-emitted.
        $src = $this->pngSource(100, 100);
        $out = $this->trackVariants(Tiger_Media_Image::variants($src, 'image/png', ['same' => 100]));
        $this->assertArrayNotHasKey('same', $out);
        $this->assertSame([], $out);
    }

    // ---- Contain (never crop) fit math --------------------------------------------------

    #[Test]
    public function contain_scales_the_longest_edge_and_keeps_aspect_ratio(): void
    {
        // 200×100 (long edge = 200). edge=100 → scale 0.5 → 100×50 (aspect preserved, contained).
        $src = $this->pngSource(200, 100);
        $out = $this->trackVariants(Tiger_Media_Image::variants($src, 'image/png', ['m' => 100]));

        $this->assertArrayHasKey('m', $out);
        $this->assertSame(100, $out['m']['width'], 'longest edge scales to the preset');
        $this->assertSame(50, $out['m']['height'], 'the short edge follows the aspect ratio');
        $this->assertSame('image/png', $out['m']['mime']);
        $this->assertFileExists($out['m']['path']);
    }

    #[Test]
    public function contain_uses_the_taller_edge_when_the_image_is_portrait(): void
    {
        // 80×160 (long edge = 160, vertical). edge=80 → scale 0.5 → 40×80 — the HEIGHT is the
        // constrained longest edge, proving it's max(w,h), not just width.
        $src = $this->pngSource(80, 160);
        $out = $this->trackVariants(Tiger_Media_Image::variants($src, 'image/png', ['p' => 80]));

        $this->assertArrayHasKey('p', $out);
        $this->assertSame(40, $out['p']['width']);
        $this->assertSame(80, $out['p']['height']);
    }

    // ---- The MIME → GD-type map ---------------------------------------------------------

    #[Test]
    public function supports_recognizes_the_core_image_mimes(): void
    {
        // PNG is guaranteed (the suite skips otherwise); it exercises the map's happy path.
        $this->assertTrue(Tiger_Media_Image::supports('image/png'));
    }

    #[Test]
    public function supports_treats_the_jpeg_aliases_identically(): void
    {
        // image/jpg and image/pjpeg both map to the 'jpeg' GD type — so they can't diverge from
        // the canonical image/jpeg answer for a given GD build.
        $canonical = Tiger_Media_Image::supports('image/jpeg');
        $this->assertSame($canonical, Tiger_Media_Image::supports('image/jpg'));
        $this->assertSame($canonical, Tiger_Media_Image::supports('image/pjpeg'));
    }

    #[Test]
    public function supports_rejects_a_mime_the_map_does_not_know(): void
    {
        $this->assertFalse(Tiger_Media_Image::supports('application/pdf'));
        $this->assertFalse(Tiger_Media_Image::supports('image/svg+xml'));   // vector — not a GD raster type
        $this->assertFalse(Tiger_Media_Image::supports(''));
    }

    #[Test]
    public function variants_returns_empty_for_an_unsupported_mime(): void
    {
        // Even with a real file on disk, an unsupported MIME short-circuits to [] (no error).
        $src = $this->pngSource(200, 100);
        $this->assertSame([], Tiger_Media_Image::variants($src, 'application/zip', ['thumb' => 50]));
    }

    #[Test]
    public function a_zero_or_negative_preset_edge_is_ignored(): void
    {
        // Guard against a bad config: edge <= 0 is skipped alongside the upscale guard.
        $src = $this->pngSource(200, 100);
        $out = $this->trackVariants(
            Tiger_Media_Image::variants($src, 'image/png', ['zero' => 0, 'neg' => -10, 'ok' => 50])
        );
        $this->assertArrayNotHasKey('zero', $out);
        $this->assertArrayNotHasKey('neg', $out);
        $this->assertArrayHasKey('ok', $out);
    }
}
