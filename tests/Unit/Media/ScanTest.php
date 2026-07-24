<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Log;
use Tiger_Media_Scan;
use Tiger_Model_Media;

/**
 * Tiger_Media_Scan — the config-gated pre-store scan orchestrator.
 *
 * Everything defaults OFF (`media.scan.*`), so the platform-critical property is: with nothing
 * configured, `preStore()` is a no-op that returns `{ok:true, status:skipped}` and NEVER blocks an
 * upload. These tests pin:
 *   - the all-off / no-config no-op (an upload is never gated by an unconfigured scanner);
 *   - the FAIL-OPEN policy: clamav turned on but unavailable (no `clamdscan`/`clamscan` binary on this
 *     host — verified absent) yields a scanner `error`, which is logged and treated as `skipped` +
 *     `ok:true` — a clamd hiccup can't halt every upload;
 *   - the image-moderation KIND guard: image scanning on but a non-image `kind` never constructs the
 *     Rekognition scanner (no AWS call), still `ok:true`;
 *   - `videoReview()` reflects `media.scan.video`; and the threshold/region defaults.
 *
 * The infected/rejected BLOCK arms need a live ClamAV daemon / AWS Rekognition and are out of a unit
 * test's reach — see WAVE5-FINDINGS-media.md (the exec/AWS boundary).
 */
#[CoversClass(Tiger_Media_Scan::class)]
final class ScanTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Tiger_Log::reset();
        parent::tearDown();
    }

    /** A throwaway file to hand the scanner (its bytes never matter when scanners are off). */
    private function sampleFile(): string
    {
        $p = tempnam(sys_get_temp_dir(), 'tscan');
        file_put_contents($p, 'hello');
        return $p;
    }

    // ---- The no-op default (nothing configured) ------------------------------------------------

    #[Test]
    public function pre_store_is_a_no_op_with_no_config_at_all(): void
    {
        // Base setUp cleared the registry — there is no Zend_Config. Every `_on()` is false.
        $file = $this->sampleFile();
        $r = (new Tiger_Media_Scan())->preStore($file, 'image/png', Tiger_Model_Media::KIND_IMAGE);
        @unlink($file);

        $this->assertTrue($r['ok']);
        $this->assertSame(Tiger_Model_Media::SCAN_SKIPPED, $r['status']);
        $this->assertNull($r['message']);
        $this->assertSame([], $r['meta']);
    }

    #[Test]
    public function pre_store_is_a_no_op_when_every_scanner_is_explicitly_off(): void
    {
        $this->setConfig(['media' => ['scan' => ['clamav' => 0, 'image' => 0, 'video' => 0]]]);
        $file = $this->sampleFile();
        $r = (new Tiger_Media_Scan())->preStore($file, 'image/jpeg', Tiger_Model_Media::KIND_IMAGE);
        @unlink($file);

        $this->assertTrue($r['ok']);
        $this->assertSame(Tiger_Model_Media::SCAN_SKIPPED, $r['status']);
        $this->assertSame([], $r['meta']);
    }

    // ---- Fail-open: clamav on but the binary is absent -----------------------------------------

    #[Test]
    public function clamav_on_but_unavailable_fails_open_to_skipped_and_does_not_block(): void
    {
        // clamdscan/clamscan are absent on the test host (asserted here so the intent is explicit).
        $this->assertClamAvAbsent();

        // A null log sink so the fail-open warning doesn't trip the strict output check.
        $this->setConfig(['tiger' => ['log' => ['writer' => 'null']], 'media' => ['scan' => ['clamav' => 1]]]);
        Tiger_Log::reset();

        $file = $this->sampleFile();
        $r = (new Tiger_Media_Scan())->preStore($file, 'application/pdf', Tiger_Model_Media::KIND_DOCUMENT);
        @unlink($file);

        // The scanner errored (no binary) → fail-open: the upload is allowed, status stays skipped,
        // and the clamav scanner's own verdict is recorded in meta.
        $this->assertTrue($r['ok'], 'a scanner ERROR must never block the upload (fail-open)');
        $this->assertSame(Tiger_Model_Media::SCAN_SKIPPED, $r['status']);
        $this->assertArrayHasKey('clamav', $r['meta']);
        $this->assertSame('error', $r['meta']['clamav']['status']);
    }

    // ---- The image-moderation KIND guard -------------------------------------------------------

    #[Test]
    public function image_scanning_on_but_a_non_image_kind_never_runs_moderation(): void
    {
        // `_on('image')` is true, but kind=VIDEO fails the `&& kind === KIND_IMAGE` guard, so the
        // Rekognition scanner is never even constructed (no AWS SDK / no network touched).
        $this->setConfig(['media' => ['scan' => ['image' => 1]]]);

        $file = $this->sampleFile();
        $r = (new Tiger_Media_Scan())->preStore($file, 'video/mp4', Tiger_Model_Media::KIND_VIDEO);
        @unlink($file);

        $this->assertTrue($r['ok']);
        $this->assertSame(Tiger_Model_Media::SCAN_SKIPPED, $r['status']);
        $this->assertArrayNotHasKey('image', $r['meta'], 'moderation must not run for a non-image kind');
    }

    #[Test]
    public function image_moderation_on_for_an_image_fails_open_when_the_sdk_is_absent(): void
    {
        // aws/aws-sdk-php is not installed in the test vendor tree, so Rekognition::scan() returns an
        // `error` up front (no AWS call) → the orchestrator fail-opens: the upload is allowed, status
        // stays skipped, and the moderation verdict is recorded in meta. Exercises the full image arm.
        if (class_exists('Aws\\Rekognition\\RekognitionClient')) {
            $this->markTestSkipped('aws-sdk is installed — the image fail-open path would make a real call.');
        }
        $this->setConfig([
            'tiger' => ['log' => ['writer' => 'null']],
            'media' => ['scan' => ['image' => 1, 'image_threshold' => 90]],
        ]);
        Tiger_Log::reset();

        $file = $this->sampleFile();
        $r = (new Tiger_Media_Scan())->preStore($file, 'image/png', Tiger_Model_Media::KIND_IMAGE);
        @unlink($file);

        $this->assertTrue($r['ok'], 'a moderation ERROR must never block the upload (fail-open)');
        $this->assertSame(Tiger_Model_Media::SCAN_SKIPPED, $r['status']);
        $this->assertArrayHasKey('image', $r['meta']);
        $this->assertSame('error', $r['meta']['image']['status']);
    }

    // ---- videoReview() -------------------------------------------------------------------------

    #[Test]
    public function video_review_is_off_by_default_and_tracks_the_config(): void
    {
        // No config → false.
        $this->assertFalse((new Tiger_Media_Scan())->videoReview());

        // Explicitly on → true.
        $this->setConfig(['media' => ['scan' => ['video' => 1]]]);
        $this->assertTrue((new Tiger_Media_Scan())->videoReview());

        // Explicitly off → false.
        $this->setConfig(['media' => ['scan' => ['video' => 0]]]);
        $this->assertFalse((new Tiger_Media_Scan())->videoReview());
    }

    // ---- Defaults on the protected config helpers ----------------------------------------------

    #[Test]
    public function threshold_defaults_to_eighty_and_reads_the_configured_value(): void
    {
        $scan = new Tiger_Media_Scan();
        $m = new ReflectionMethod($scan, '_threshold');

        // No config → the 80.0 default.
        $this->assertSame(80.0, $m->invoke($scan));

        // A configured threshold is used verbatim.
        $this->setConfig(['media' => ['scan' => ['image_threshold' => 95]]]);
        $this->assertSame(95.0, $m->invoke($scan));
    }

    #[Test]
    public function region_is_us_east_1(): void
    {
        $scan = new Tiger_Media_Scan();
        $m = new ReflectionMethod($scan, '_region');
        $this->assertSame('us-east-1', $m->invoke($scan));
    }

    /** Guard: the fail-open assertions only hold when ClamAV really is absent on this host. */
    private function assertClamAvAbsent(): void
    {
        foreach (['clamdscan', 'clamscan'] as $bin) {
            $out = [];
            $code = 1;
            exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out)) {
                $this->markTestSkipped("ClamAV ($bin) is installed on this host — the fail-open path can't be forced.");
            }
        }
    }
}
