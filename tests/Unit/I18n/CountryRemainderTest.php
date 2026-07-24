<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_I18n_Country;
use Zend_Registry;
use Zend_Translate;

/**
 * Tiger_I18n_Country — remainder coverage (the branches CountryTest doesn't already exercise).
 *
 * CountryTest owns the mainstream cases (iso3/codes/name/all/grouped/options + the bias sort). This
 * file closes the translator-interaction gaps: a translator that is registered but has NO override for
 * the requested key must fall through to CLDR (both in name() and in the all() loop), and a registered
 * override must win inside all()/grouped() the same way it wins in name(). Static `$_data` is reset per
 * test, and the translator lives in the per-test-isolated registry.
 */
#[CoversClass(Tiger_I18n_Country::class)]
final class CountryRemainderTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetData();
    }

    protected function tearDown(): void
    {
        $this->resetData();
        parent::tearDown();
    }

    private function resetData(): void
    {
        (new ReflectionProperty(Tiger_I18n_Country::class, '_data'))->setValue(null, null);
    }

    #[Test]
    public function name_falls_through_to_cldr_when_the_translator_lacks_the_key(): void
    {
        // A translator is present but only overrides a DIFFERENT country — the requested one must still
        // resolve from CLDR (the isTranslated()==false branch of name()).
        Zend_Registry::set('Zend_Translate', new Zend_Translate([
            'adapter' => 'array', 'content' => ['country.FR' => 'La France'], 'locale' => 'en',
        ]));

        $this->assertSame('United States', Tiger_I18n_Country::name('US', 'en'), 'un-overridden => CLDR');
        $this->assertSame('La France', Tiger_I18n_Country::name('FR', 'en'), 'overridden => the override');
    }

    #[Test]
    public function all_applies_a_translation_override_within_the_localized_list(): void
    {
        Zend_Registry::set('Zend_Translate', new Zend_Translate([
            'adapter' => 'array', 'content' => ['country.US' => 'ZZ Last Place'], 'locale' => 'en',
        ]));

        $all = Tiger_I18n_Country::all('en');
        $this->assertSame('ZZ Last Place', $all['US'], 'the override flows through all()');
        // an un-overridden country in the same pass still comes from CLDR.
        $this->assertSame('Canada', $all['CA']);
    }

    #[Test]
    public function grouped_uses_the_override_and_still_partitions_the_full_set(): void
    {
        Zend_Registry::set('Zend_Translate', new Zend_Translate([
            'adapter' => 'array', 'content' => ['country.US' => 'Estados Unidos'], 'locale' => 'en',
        ]));

        $grouped = Tiger_I18n_Country::grouped('en');
        $this->assertSame('Estados Unidos', $grouped['priority']['US']);
        $this->assertSame(
            count(Tiger_I18n_Country::codes()),
            count($grouped['priority']) + count($grouped['rest'])
        );
    }

    #[Test]
    public function options_uses_the_default_placeholder_when_none_is_given(): void
    {
        $opts = Tiger_I18n_Country::options('en');
        $this->assertSame('— Select —', $opts[''], 'the built-in default placeholder');
    }
}
