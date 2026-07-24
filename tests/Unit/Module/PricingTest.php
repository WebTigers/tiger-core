<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Pricing;

/**
 * Tiger_Module_Pricing — the single interpreter of a manifest's `pricing` block. Normalizes the model
 * (unknown → free, legacy `pro` → paid), parses authority/vendor for the licensed model, and asserts a
 * licensed manifest is well-formed — the gate the installer relies on.
 */
#[CoversClass(Tiger_Module_Pricing::class)]
final class PricingTest extends UnitTestCase
{
    #[Test]
    public function absentPricingIsFreeWithAFreeTier(): void
    {
        $n = Tiger_Module_Pricing::of([]);
        $this->assertSame('free', $n['model']);
        $this->assertTrue($n['free_tier']);
        $this->assertNull($n['authority']);
        $this->assertNull($n['vendor']);
    }

    #[Test]
    public function legacyProIsReadAsPaid(): void
    {
        $n = Tiger_Module_Pricing::of(['pricing' => ['model' => 'pro', 'pro_url' => 'https://x.example/buy']]);
        $this->assertSame('paid', $n['model']);
        $this->assertFalse($n['free_tier']);
        $this->assertSame('https://x.example/buy', $n['pro_url']);
    }

    #[Test]
    public function unknownModelFallsBackToFree(): void
    {
        $this->assertSame('free', Tiger_Module_Pricing::of(['pricing' => ['model' => 'bananas']])['model']);
    }

    #[Test]
    public function freemiumHasAFreeTierPaidDoesNot(): void
    {
        $this->assertTrue(Tiger_Module_Pricing::of(['pricing' => ['model' => 'freemium']])['free_tier']);
        $this->assertFalse(Tiger_Module_Pricing::of(['pricing' => ['model' => 'paid']])['free_tier']);
    }

    #[Test]
    public function licensedParsesAuthorityAndVendor(): void
    {
        $m = ['pricing' => ['model' => 'licensed', 'authority' => 'https://store.example/marketplace', 'vendor' => 'Acme/TigerVendor']];
        $n = Tiger_Module_Pricing::of($m);
        $this->assertSame('licensed', $n['model']);
        $this->assertTrue(Tiger_Module_Pricing::isLicensed($m));
        $this->assertSame('https://store.example/marketplace', $n['authority']);
        $this->assertSame('Acme/TigerVendor', $n['vendor']);
        $this->assertFalse($n['free_tier']);
    }

    #[Test]
    public function vendorAcceptsAGithubUrlAndRejectsJunk(): void
    {
        $this->assertSame(
            'Acme/TigerVendor',
            Tiger_Module_Pricing::of(['pricing' => ['model' => 'licensed', 'authority' => 'https://a.example', 'vendor' => 'https://github.com/Acme/TigerVendor']])['vendor']
        );
        $this->assertNull(
            Tiger_Module_Pricing::of(['pricing' => ['model' => 'licensed', 'authority' => 'https://a.example', 'vendor' => 'not a repo']])['vendor']
        );
    }

    #[Test]
    public function urlFieldsRejectNonHttp(): void
    {
        $n = Tiger_Module_Pricing::of(['pricing' => ['model' => 'licensed', 'authority' => 'javascript:alert(1)', 'vendor' => 'a/b']]);
        $this->assertNull($n['authority']);
    }

    #[Test]
    public function ofAcceptsABarePricingBlock(): void
    {
        $this->assertSame('freemium', Tiger_Module_Pricing::of(['model' => 'freemium'])['model']);
    }

    #[Test]
    public function assertValidPassesForNonLicensed(): void
    {
        $this->expectNotToPerformAssertions();
        Tiger_Module_Pricing::assertValid(['pricing' => ['model' => 'free']]);
    }

    #[Test]
    public function assertValidThrowsForLicensedMissingAuthority(): void
    {
        $this->expectException(RuntimeException::class);
        Tiger_Module_Pricing::assertValid(['pricing' => ['model' => 'licensed', 'vendor' => 'a/b']]);
    }

    #[Test]
    public function assertValidThrowsForLicensedMissingVendor(): void
    {
        $this->expectException(RuntimeException::class);
        Tiger_Module_Pricing::assertValid(['pricing' => ['model' => 'licensed', 'authority' => 'https://a.example']]);
    }

    #[Test]
    public function assertValidPassesForWellFormedLicensed(): void
    {
        $this->expectNotToPerformAssertions();
        Tiger_Module_Pricing::assertValid(['pricing' => ['model' => 'licensed', 'authority' => 'https://a.example', 'vendor' => 'a/b']]);
    }
}
