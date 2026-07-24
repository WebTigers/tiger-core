<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_License_Authority;

/**
 * Tiger_License_Authority — the client for an authority's download endpoint. Driven with a fake transport,
 * so the request shape + reply normalization are asserted with no network. A licensed download MUST carry
 * an http(s) `url` and a `signature`; anything else (a refusal, an unreachable authority, a malformed or
 * unsigned reply) normalizes to null so the installer fails closed.
 */
#[CoversClass(Tiger_License_Authority::class)]
final class AuthorityTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_License_Authority::_reset();
    }

    protected function tearDown(): void
    {
        Tiger_License_Authority::_reset();
        parent::tearDown();
    }

    #[Test]
    public function aSignedDownloadIsNormalized(): void
    {
        Tiger_License_Authority::setTransport(static fn($url, $p) => [
            'url'       => 'https://objects.githubusercontent.com/x?sig=abc',
            'signature' => 'SIGN==',
            'sha256'    => 'deadbeef',
            'version'   => '1.4.0',
            'ignored'   => 'field',
        ]);
        $d = Tiger_License_Authority::download('https://store.example/mkt', 'KEY', 'shop', 'my.site');
        $this->assertSame('https://objects.githubusercontent.com/x?sig=abc', $d['url']);
        $this->assertSame('SIGN==', $d['signature']);
        $this->assertSame('deadbeef', $d['sha256']);
        $this->assertSame('1.4.0', $d['version']);
    }

    #[Test]
    public function itPostsToTheDownloadEndpointWithKeyProductDomain(): void
    {
        $seen = [];
        Tiger_License_Authority::setTransport(static function ($url, $p) use (&$seen) {
            $seen = ['url' => $url, 'payload' => $p];
            return ['url' => 'https://a.example/z', 'signature' => 'S'];
        });
        Tiger_License_Authority::download('https://store.example/mkt/', 'KEY-9', 'download', 'host.tld');
        $this->assertSame('https://store.example/mkt/download', $seen['url']);   // trailing slash collapsed
        $this->assertSame('KEY-9', $seen['payload']['key']);
        $this->assertSame('download', $seen['payload']['product']);
        $this->assertSame('host.tld', $seen['payload']['domain']);
    }

    #[Test]
    public function optionalFieldsDefaultToNull(): void
    {
        Tiger_License_Authority::setTransport(static fn($url, $p) => ['url' => 'https://a.example/z', 'signature' => 'S']);
        $d = Tiger_License_Authority::download('https://a.example', 'K', 'shop');
        $this->assertNull($d['sha256']);
        $this->assertNull($d['version']);
    }

    #[Test]
    public function aReplyWithoutASignatureIsRefused(): void
    {
        Tiger_License_Authority::setTransport(static fn($url, $p) => ['url' => 'https://a.example/z']);
        $this->assertNull(Tiger_License_Authority::download('https://a.example', 'K', 'shop'));
    }

    #[Test]
    public function aReplyWithoutAUrlIsRefused(): void
    {
        Tiger_License_Authority::setTransport(static fn($url, $p) => ['signature' => 'S']);
        $this->assertNull(Tiger_License_Authority::download('https://a.example', 'K', 'shop'));
    }

    #[Test]
    public function aNonHttpUrlIsRefused(): void
    {
        Tiger_License_Authority::setTransport(static fn($url, $p) => ['url' => 'file:///etc/passwd', 'signature' => 'S']);
        $this->assertNull(Tiger_License_Authority::download('https://a.example', 'K', 'shop'));
    }

    #[Test]
    public function anUnreachableAuthorityIsNull(): void
    {
        Tiger_License_Authority::setTransport(static fn($url, $p) => null);
        $this->assertNull(Tiger_License_Authority::download('https://a.example', 'K', 'shop'));
    }
}
