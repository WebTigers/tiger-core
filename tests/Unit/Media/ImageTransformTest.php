<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Media_Image;

/**
 * Tiger_Media_Image — the TRANSFORM + ENCODE seam Wave 1 left uncovered.
 *
 * ImageTest pinned the never-upscale / contain-fit / MIME-map INVARIANTS on PNG. This file drives
 * the actual per-format decode→resample→encode pipeline with real GD images so the format-specific
 * arms of `_load`, `_save`, and `_canvas` (jpeg/gif/webp — not just png) run for real:
 *   - a produced variant is a VALID, correctly-typed, correctly-sized image on disk (round-tripped
 *     back through getimagesize) — proving the encode wrote a real file of the right format;
 *   - the alpha-carrying formats (png/gif/webp) go through the transparent `_canvas` arm and the
 *     lossless png / gif encoders; jpeg/webp go through the lossy quality encoders;
 *   - the quality argument is clamped (an out-of-range quality still yields a valid file);
 *   - `supports()` tracks the GD build for every format this build actually has.
 *
 * Only formats the running GD build supports are exercised (each format self-skips otherwise), so the
 * suite stays green on a minimal GD. Every temp file is tracked + unlinked in tearDown.
 */
#[CoversClass(Tiger_Media_Image::class)]
final class ImageTransformTest extends UnitTestCase
{
    /** @var string[] temp files to clean up */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!Tiger_Media_Image::hasGd()) {
            $this->markTestSkipped('GD is not available in this PHP build.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** Synthesize a real w×h image on disk in the given format; returns its path (tracked). */
    private function source(int $w, int $h, string $format): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 10, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'tsrc') . '.' . $format;
        match ($format) {
            'jpg'  => imagejpeg($img, $path, 90),
            'gif'  => imagegif($img, $path),
            'webp' => imagewebp($img, $path, 90),
            default => imagepng($img, $path),
        };
        $this->tmp[] = $path;
        return $path;
    }

    private function track(array $variants): array
    {
        foreach ($variants as $v) {
            if (isset($v['path'])) {
                $this->tmp[] = $v['path'];
            }
        }
        return $variants;
    }

    /** Assert the emitted variant file is a real image of the expected type + pixel size. */
    private function assertValidVariant(array $variant, int $expW, int $expH, int $expType): void
    {
        $this->assertFileExists($variant['path']);
        $info = getimagesize($variant['path']);
        $this->assertIsArray($info, 'the emitted variant is a decodable image');
        $this->assertSame($expType, $info[2], 'the encoder wrote the expected image format');
        $this->assertSame($expW, $info[0], 'width matches the computed contain-fit width');
        $this->assertSame($expH, $info[1], 'height matches the computed contain-fit height');
        $this->assertSame($expW, $variant['width']);
        $this->assertSame($expH, $variant['height']);
    }

    // ---- JPEG: the lossy encoder + the non-alpha canvas + the EXIF orient hook -----------------

    #[Test]
    public function jpeg_source_produces_a_valid_downscaled_jpeg(): void
    {
        if (!Tiger_Media_Image::supports('image/jpeg')) {
            $this->markTestSkipped('GD JPEG support absent.');
        }
        // 200×100 → edge 100 → 100×50. Runs _load(jpeg) → _orient(no EXIF, no rotate) → _canvas(opaque)
        // → imagecopyresampled → _save(imagejpeg).
        $src = $this->source(200, 100, 'jpg');
        $out = $this->track(Tiger_Media_Image::variants($src, 'image/jpeg', ['m' => 100]));

        $this->assertArrayHasKey('m', $out);
        $this->assertSame('image/jpeg', $out['m']['mime']);
        $this->assertValidVariant($out['m'], 100, 50, IMAGETYPE_JPEG);
    }

    #[Test]
    public function jpeg_quality_argument_is_clamped_out_of_range(): void
    {
        if (!Tiger_Media_Image::supports('image/jpeg')) {
            $this->markTestSkipped('GD JPEG support absent.');
        }
        // quality 5000 is clamped to 100 inside _save (max(1,min(100,q))); a valid file still results.
        $src  = $this->source(200, 100, 'jpg');
        $high = $this->track(Tiger_Media_Image::variants($src, 'image/jpeg', ['m' => 100], 5000));
        $this->assertArrayHasKey('m', $high);
        $this->assertValidVariant($high['m'], 100, 50, IMAGETYPE_JPEG);

        // quality -20 is clamped up to 1 (still encodes).
        $low = $this->track(Tiger_Media_Image::variants($src, 'image/jpeg', ['m' => 100], -20));
        $this->assertArrayHasKey('m', $low);
        $this->assertValidVariant($low['m'], 100, 50, IMAGETYPE_JPEG);
    }

    // ---- GIF: the alpha canvas arm + the gif encoder -------------------------------------------

    #[Test]
    public function gif_source_produces_a_valid_downscaled_gif(): void
    {
        if (!Tiger_Media_Image::supports('image/gif')) {
            $this->markTestSkipped('GD GIF support absent.');
        }
        // gif goes through the transparent _canvas arm and imagegif().
        $src = $this->source(160, 80, 'gif');
        $out = $this->track(Tiger_Media_Image::variants($src, 'image/gif', ['t' => 80]));

        $this->assertArrayHasKey('t', $out);
        $this->assertSame('image/gif', $out['t']['mime']);
        $this->assertValidVariant($out['t'], 80, 40, IMAGETYPE_GIF);
    }

    // ---- WebP: the lossy encoder via the alpha canvas arm --------------------------------------

    #[Test]
    public function webp_source_produces_a_valid_downscaled_webp(): void
    {
        if (!Tiger_Media_Image::supports('image/webp')) {
            $this->markTestSkipped('GD WebP support absent.');
        }
        $src = $this->source(200, 200, 'webp');
        $out = $this->track(Tiger_Media_Image::variants($src, 'image/webp', ['s' => 100]));

        $this->assertArrayHasKey('s', $out);
        $this->assertSame('image/webp', $out['s']['mime']);
        $this->assertValidVariant($out['s'], 100, 100, IMAGETYPE_WEBP);
    }

    // ---- PNG: the lossless encoder keeps a valid, transparent-capable file ---------------------

    #[Test]
    public function png_source_produces_a_valid_downscaled_png(): void
    {
        if (!Tiger_Media_Image::supports('image/png')) {
            $this->markTestSkipped('GD PNG support absent.');
        }
        // png uses the fixed compression level (quality is ignored for the lossless encoder).
        $src = $this->source(300, 150, 'png');
        $out = $this->track(Tiger_Media_Image::variants($src, 'image/png', ['h' => 150], 10));

        $this->assertArrayHasKey('h', $out);
        $this->assertValidVariant($out['h'], 150, 75, IMAGETYPE_PNG);
    }

    // ---- Multiple presets in one call: only the sub-source ones emit, each valid ---------------

    #[Test]
    public function multiple_presets_emit_only_the_downscales_each_a_valid_file(): void
    {
        if (!Tiger_Media_Image::supports('image/jpeg')) {
            $this->markTestSkipped('GD JPEG support absent.');
        }
        // Source long edge = 400. thumbnail(50) + small(200) run; large(800) is an upscale → skipped.
        $src = $this->source(400, 300, 'jpg');
        $out = $this->track(Tiger_Media_Image::variants(
            $src,
            'image/jpeg',
            ['thumbnail' => 50, 'small' => 200, 'large' => 800]
        ));

        $this->assertArrayNotHasKey('large', $out);
        $this->assertValidVariant($out['thumbnail'], 50, 38, IMAGETYPE_JPEG);   // 400→50 = 0.125 → 300*0.125=37.5→38
        $this->assertValidVariant($out['small'], 200, 150, IMAGETYPE_JPEG);
    }

    // ---- _load returns false for a corrupt / non-image source → [] -----------------------------

    #[Test]
    public function a_corrupt_source_of_a_supported_mime_yields_no_variants(): void
    {
        if (!Tiger_Media_Image::supports('image/png')) {
            $this->markTestSkipped('GD PNG support absent.');
        }
        // A supported MIME but the bytes aren't a real PNG → _load()'s @imagecreatefrompng returns
        // false → variants() short-circuits to [] (no warning surfaced, no crash).
        $bogus = tempnam(sys_get_temp_dir(), 'tbad') . '.png';
        file_put_contents($bogus, 'this is definitely not a PNG');
        $this->tmp[] = $bogus;

        $this->assertSame([], Tiger_Media_Image::variants($bogus, 'image/png', ['t' => 50]));
    }

    // ---- supports() tracks the actual GD build for every format --------------------------------

    #[Test]
    public function supports_agrees_with_the_gd_build_for_each_format(): void
    {
        $gd = gd_info();
        $this->assertSame(!empty($gd['JPEG Support']), Tiger_Media_Image::supports('image/jpeg'));
        $this->assertSame(!empty($gd['PNG Support']), Tiger_Media_Image::supports('image/png'));
        $this->assertSame(!empty($gd['GIF Read Support']), Tiger_Media_Image::supports('image/gif'));
        $this->assertSame(!empty($gd['WebP Support']), Tiger_Media_Image::supports('image/webp'));
    }
}
