<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Media_Storage_S3;

/**
 * Tiger_Media_Storage_S3 — the client-free surface Wave 1's S3Test deliberately skipped.
 *
 * The AWS SDK is an OPTIONAL dependency and is NOT installed in the test vendor tree, so any method
 * that reaches `_c()` throws "aws-sdk not installed". That is exactly what makes the adapter's
 * error-handling contract testable here with zero network:
 *   - `url()` for a PUBLIC object is pure string-building (`_publicUrl`) — no client — so every
 *     public-URL shape is a known-answer test: the default virtual-hosted S3 URL, a CDN host, and an
 *     S3-compatible `endpoint` in both path-style and virtual-host style, with path segments encoded;
 *   - `url()` for a PRIVATE object returns '' when presigning is disabled (`presign_ttl=0`) WITHOUT a
 *     client, and returns '' (falls back to the streamer route) when the client can't be built;
 *   - the best-effort readers degrade gracefully when the client is unavailable — `exists()`→false,
 *     `size()`→0, `delete()` swallows — while `get()`/`stream()` surface a clean RuntimeException.
 *
 * A real putObject/getObject round trip needs the SDK + a bucket and belongs to an integration/live
 * suite, not this one — see WAVE5-FINDINGS-media.md.
 */
#[CoversClass(Tiger_Media_Storage_S3::class)]
final class S3MoreTest extends UnitTestCase
{
    private function adapter(array $overrides = []): Tiger_Media_Storage_S3
    {
        return new Tiger_Media_Storage_S3(['bucket' => 'my-bucket', 'region' => 'us-west-2'] + $overrides);
    }

    // ---- public url(): the four shapes of _publicUrl -------------------------------------------

    #[Test]
    public function public_url_defaults_to_the_virtual_hosted_s3_url(): void
    {
        // No cdn, no endpoint → https://<bucket>.s3.<region>.amazonaws.com/<public-prefix><key>.
        $s3 = $this->adapter();
        $this->assertSame(
            'https://my-bucket.s3.us-west-2.amazonaws.com/public/org-1/a.jpg',
            $s3->url('org-1/a.jpg', 'public')
        );
    }

    #[Test]
    public function public_url_prefers_a_cdn_host_when_set(): void
    {
        $s3 = $this->adapter(['cdn' => 'cdn.example.com/']);   // trailing slash trimmed
        $this->assertSame('https://cdn.example.com/public/x.png', $s3->url('x.png', 'public'));
    }

    #[Test]
    public function public_url_encodes_path_segments(): void
    {
        // Spaces + unicode in the key are rawurlencode()'d per path segment (slashes preserved).
        $s3 = $this->adapter();
        $this->assertSame(
            'https://my-bucket.s3.us-west-2.amazonaws.com/public/org-1/my%20photo.jpg',
            $s3->url('org-1/my photo.jpg', 'public')
        );
    }

    #[Test]
    public function public_url_honors_a_path_style_endpoint(): void
    {
        // use_path_style=1 → <endpoint>/<bucket>/<key>.
        $s3 = $this->adapter(['endpoint' => 'https://minio.local:9000', 'use_path_style' => 1]);
        $this->assertSame('https://minio.local:9000/my-bucket/public/x.png', $s3->url('x.png', 'public'));
    }

    #[Test]
    public function public_url_honors_a_virtual_host_endpoint(): void
    {
        // Virtual-host style (default) → the bucket is spliced in after the scheme.
        $s3 = $this->adapter(['endpoint' => 'https://sfo3.digitaloceanspaces.com']);
        $this->assertSame('https://my-bucket.sfo3.digitaloceanspaces.com/public/x.png', $s3->url('x.png', 'public'));
    }

    // ---- private url(): presign disabled + client-unavailable both yield '' --------------------

    #[Test]
    public function private_url_is_empty_when_presigning_is_disabled(): void
    {
        // presign_ttl=0 → url() returns '' up front (no client), so the media layer streams instead.
        $s3 = $this->adapter(['presign_ttl' => 0]);
        $this->assertSame('', $s3->url('org-1/book.epub', 'private'));
    }

    #[Test]
    public function private_url_falls_back_to_empty_when_the_client_cannot_be_built(): void
    {
        // Presigning enabled, but the AWS SDK is absent → createPresignedRequest can't run → the
        // try/catch returns '' (fall back to the ACL-checked streamer route), never throwing.
        $s3 = $this->adapter(['presign_ttl' => 600]);
        $this->assertSame('', $s3->url('org-1/book.epub', 'private'));
    }

    // ---- best-effort readers degrade when the client is unavailable ----------------------------

    #[Test]
    public function exists_is_false_and_size_is_zero_without_a_client(): void
    {
        $s3 = $this->adapter();
        $this->assertFalse($s3->exists('org-1/a.jpg', 'public'));
        $this->assertSame(0, $s3->size('org-1/a.jpg', 'public'));
    }

    #[Test]
    public function delete_swallows_a_client_failure(): void
    {
        // Idempotent + best-effort: a delete that can't reach S3 must not throw.
        $s3 = $this->adapter();
        $s3->delete('org-1/a.jpg', 'private');
        $this->assertTrue(true, 'delete() returned without throwing');
    }

    #[Test]
    public function get_surfaces_a_runtime_exception_without_a_client(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->adapter()->get('org-1/a.jpg', 'public');
    }

    #[Test]
    public function stream_surfaces_a_runtime_exception_without_a_client(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->adapter()->stream('org-1/a.jpg', 'public');
    }

    // ---- the traversal guard still fires through the public entry points -----------------------

    #[Test]
    public function url_refuses_a_traversal_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid key/i');
        $this->adapter()->url('../../etc/passwd', 'public');
    }
}
