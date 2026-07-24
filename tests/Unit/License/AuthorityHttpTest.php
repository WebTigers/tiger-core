<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_License_Authority;

/**
 * Tiger_License_Authority — the REAL transport (`_post`) error path. AuthorityTest exercises download()
 * through the injected transport seam; this one leaves the transport at its default so the actual
 * stream-context POST runs, against a dead local endpoint (connection refused — no real network), and
 * asserts the documented fail-soft: an unreachable authority yields a null descriptor, never an exception.
 */
#[CoversClass(Tiger_License_Authority::class)]
final class AuthorityHttpTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Tiger_License_Authority::_reset();
        parent::tearDown();
    }

    #[Test]
    public function anUnreachableAuthorityOverRealHttpYieldsNull(): void
    {
        Tiger_License_Authority::_reset();   // no injected transport → the real _post runs
        $desc = Tiger_License_Authority::download('http://127.0.0.1:9/authority', 'LIC-1', 'widget', 'example.test');
        $this->assertNull($desc, 'an unreachable authority download is null, not a thrown error');
    }
}
