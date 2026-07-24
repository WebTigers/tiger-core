<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Compat;

/**
 * Tiger_Module_Compat — the advisory "tested for which Tiger versions?" verdict + the shared version
 * comparator. Pure logic (the running version is injected), and ALWAYS advisory: a verdict is a
 * message, never a block. Covers the WordPress-style "not tested for Tiger X" notice, the below-min
 * notice, the legacy `requires.tiger`-as-min fallback, beta-suffix ordering, and satisfies().
 */
final class CompatTest extends UnitTestCase
{
    #[Test]
    public function noCompatMetadataIsAlwaysOk(): void
    {
        $v = Tiger_Module_Compat::check([], '0.40.0-beta');
        $this->assertTrue($v['ok']);
        $this->assertSame(Tiger_Module_Compat::OK, $v['status']);
        $this->assertSame('', $v['message']);
    }

    #[Test]
    public function runningNewerThanTestedMaxIsUntested_notABlock(): void
    {
        $manifest = ['compat' => ['tiger' => ['min' => '0.36.0-beta', 'max' => '0.39.0-beta']]];
        $v = Tiger_Module_Compat::check($manifest, '0.40.0-beta');
        $this->assertFalse($v['ok']);
        $this->assertSame(Tiger_Module_Compat::UNTESTED, $v['status']);
        $this->assertStringContainsString('has not been tested for Tiger 0.40.0-beta', $v['message']);
        $this->assertStringContainsString('tested up to 0.39.0-beta', $v['message']);
    }

    #[Test]
    public function withinTheTestedRangeIsOk(): void
    {
        $manifest = ['compat' => ['tiger' => ['min' => '0.36.0-beta', 'max' => '0.40.0-beta']]];
        $this->assertTrue(Tiger_Module_Compat::check($manifest, '0.38.0-beta')['ok']);
        $this->assertTrue(Tiger_Module_Compat::check($manifest, '0.40.0-beta')['ok']);   // inclusive max
    }

    #[Test]
    public function runningOlderThanMinIsBelowMin(): void
    {
        $manifest = ['compat' => ['tiger' => ['min' => '0.40.0-beta']]];
        $v = Tiger_Module_Compat::check($manifest, '0.36.0-beta');
        $this->assertSame(Tiger_Module_Compat::BELOW_MIN, $v['status']);
        $this->assertStringContainsString('built for Tiger 0.40.0-beta or newer', $v['message']);
    }

    #[Test]
    public function legacyRequiresTigerActsAsTheMin(): void
    {
        $v = Tiger_Module_Compat::check(['requires' => ['tiger' => '0.40.0-beta']], '0.36.0-beta');
        $this->assertSame(Tiger_Module_Compat::BELOW_MIN, $v['status']);
        // ...and compat.tiger.min wins over requires.tiger when both are present.
        $manifest = ['requires' => ['tiger' => '0.99.0'], 'compat' => ['tiger' => ['min' => '0.10.0']]];
        $this->assertTrue(Tiger_Module_Compat::check($manifest, '0.40.0-beta')['ok']);
    }

    #[Test]
    public function betaOrdersBeforeItsRelease(): void
    {
        // 0.40.0-beta < 0.40.0 — a module tested up to the beta is "untested" on the stable release.
        $manifest = ['compat' => ['tiger' => ['max' => '0.40.0-beta']]];
        $this->assertFalse(Tiger_Module_Compat::check($manifest, '0.40.0')['ok']);
        $this->assertTrue(Tiger_Module_Compat::check($manifest, '0.40.0-beta')['ok']);
    }

    #[Test]
    public function satisfiesHandlesTheConstraintForms(): void
    {
        $this->assertTrue(Tiger_Module_Compat::satisfies('0.6.0-beta', '>=0.5.0-beta'));
        $this->assertFalse(Tiger_Module_Compat::satisfies('0.4.0', '>=0.5.0'));
        $this->assertTrue(Tiger_Module_Compat::satisfies('1.4.0', '^1.0'));    // ^ ⇒ >=
        $this->assertTrue(Tiger_Module_Compat::satisfies('2.0.0', '~1.9'));    // ~ ⇒ >=
        $this->assertTrue(Tiger_Module_Compat::satisfies('0.5.0', '0.5.0'));   // bare ⇒ >=
        $this->assertFalse(Tiger_Module_Compat::satisfies('0.4.0', '=0.5.0')); // exact
        $this->assertTrue(Tiger_Module_Compat::satisfies('anything', ''));     // empty ⇒ always
        $this->assertTrue(Tiger_Module_Compat::satisfies('0.5.0', 'v0.5.0'));  // leading v tolerated
    }
}
