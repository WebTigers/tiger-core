<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Profile_Types;

/**
 * Tiger_Profile_Types — the configurable contact/address type lists for the profile tabs. Reads
 * `tiger.profile.*.types` from the resolved config (comma-separated) into a `value(slug) => label`
 * map, with a base default and a graceful fall-through when config is absent or empty. Tests seed the
 * registry config via the base helper and assert the parse, the slug lowercasing, and every fallback.
 */
#[CoversClass(Tiger_Profile_Types::class)]
final class TypesTest extends UnitTestCase
{
    #[Test]
    public function the_base_defaults_apply_with_no_config(): void
    {
        // No Zend_Config in the registry → _cfg falls back to the DEFAULT constants.
        $this->assertSame(
            ['phone' => 'Phone', 'email' => 'Email', 'website' => 'Website', 'other' => 'Other'],
            Tiger_Profile_Types::contact()
        );
        $this->assertSame(
            ['home' => 'Home', 'office' => 'Office', 'mailing' => 'Mailing'],
            Tiger_Profile_Types::address()
        );
    }

    #[Test]
    public function a_config_list_overrides_the_defaults_and_slugs_the_value(): void
    {
        $this->setConfig(['tiger' => ['profile' => [
            'contact' => ['types' => 'Mobile, Fax , Skype'],
            'address' => ['types' => 'Billing,Shipping'],
        ]]]);

        $this->assertSame(
            ['mobile' => 'Mobile', 'fax' => 'Fax', 'skype' => 'Skype'],
            Tiger_Profile_Types::contact(),
            'items are trimmed; value = lowercased slug, label = as configured'
        );
        $this->assertSame(
            ['billing' => 'Billing', 'shipping' => 'Shipping'],
            Tiger_Profile_Types::address()
        );
    }

    #[Test]
    public function an_empty_or_whitespace_config_falls_back_to_the_default(): void
    {
        $this->setConfig(['tiger' => ['profile' => [
            'contact' => ['types' => '   ,  , '],   // nothing survives the filter → default
        ]]]);

        $this->assertSame(
            ['phone' => 'Phone', 'email' => 'Email', 'website' => 'Website', 'other' => 'Other'],
            Tiger_Profile_Types::contact()
        );
    }

    #[Test]
    public function a_missing_leaf_key_falls_back_to_the_default(): void
    {
        // tiger.profile exists but not .contact.types → _cfg returns the default string.
        $this->setConfig(['tiger' => ['profile' => ['address' => ['types' => 'Home']]]]);

        $this->assertSame(
            ['phone' => 'Phone', 'email' => 'Email', 'website' => 'Website', 'other' => 'Other'],
            Tiger_Profile_Types::contact(),
            'a partially-configured tree still defaults the unset list'
        );
        $this->assertSame(['home' => 'Home'], Tiger_Profile_Types::address());
    }
}
