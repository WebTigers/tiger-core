<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Media_Storage_S3;

/**
 * Tiger_Media_Storage_S3 — construct-only, NO network. The AWS SDK client is lazy (only built on
 * a real read/write), so we exercise only the two things that need no credentials and no calls:
 *
 *   - the **traversal guard** in _fullKey() rejects a `..` key exactly like the filesystem adapter
 *     (the guard is the same shape across every adapter — this pins the cloud side of it);
 *   - the **full-key mapping** is the known-answer `prefix + visibility-prefix + key`, so the
 *     public/private split lands objects under the expected key namespaces.
 *
 * The presigned-URL structure is deliberately NOT tested: createPresignedRequest() needs the AWS
 * SDK + credentials and would build a live client, which this UNIT suite must not do. (See report.)
 */
#[CoversClass(Tiger_Media_Storage_S3::class)]
final class S3Test extends UnitTestCase
{
    private function adapter(array $overrides = []): Tiger_Media_Storage_S3
    {
        // Construct only — bucket is the sole required field; the client stays unbuilt.
        return new Tiger_Media_Storage_S3(['bucket' => 'my-bucket', 'region' => 'us-east-1'] + $overrides);
    }

    /** Reach the protected key-mapper without a live client. */
    private function fullKey(Tiger_Media_Storage_S3 $s3, string $key, string $visibility): string
    {
        // setAccessible() is a no-op since PHP 8.1 — protected methods are reflectable directly.
        $m = new ReflectionMethod($s3, '_fullKey');
        return $m->invoke($s3, $key, $visibility);
    }

    #[Test]
    public function full_key_rejects_a_traversal_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid key/i');
        $this->fullKey($this->adapter(), '../../etc/passwd', 'private');
    }

    #[Test]
    public function full_key_maps_visibility_to_the_expected_prefix(): void
    {
        // Defaults: public_prefix 'public/', private_prefix 'private/', no base prefix.
        $s3 = $this->adapter();
        $this->assertSame('public/org-1/images/a.jpg', $this->fullKey($s3, 'org-1/images/a.jpg', 'public'));
        $this->assertSame('private/org-1/files/b.epub', $this->fullKey($s3, 'org-1/files/b.epub', 'private'));
    }

    #[Test]
    public function full_key_honors_a_base_prefix_and_normalizes_it(): void
    {
        // A base prefix is normalized to 'segment/' and prepended ahead of the visibility prefix.
        $s3 = $this->adapter(['prefix' => 'tenant', 'public_prefix' => 'pub', 'private_prefix' => 'priv']);
        $this->assertSame('tenant/pub/x.png', $this->fullKey($s3, 'x.png', 'public'));
        $this->assertSame('tenant/priv/x.png', $this->fullKey($s3, 'x.png', 'private'));
    }

    #[Test]
    public function a_missing_bucket_is_a_construct_time_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bucket is required/i');
        new Tiger_Media_Storage_S3(['region' => 'us-east-1']);   // no bucket
    }
}
