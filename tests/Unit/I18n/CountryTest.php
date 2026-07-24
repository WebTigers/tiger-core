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
 * Tiger_I18n_Country — the localized, bias-sorted country list read from library/Tiger/I18n/country.ini.
 *
 * The behaviors that matter: alpha-2 -> alpha-3 lookup (case-insensitive, empty/unknown -> null), name
 * resolution with the translation-override-then-CLDR fallback, and the BIAS SORT — the six flagged
 * "common" countries (sort>0) float to the top of the picker in sort-weight order, everything else A–Z
 * by localized name. We always pass an explicit `en` locale so assertions are deterministic regardless
 * of the host's default locale.
 *
 * No DB — the class is a static reader over a shipped ini + CLDR (Zend_Locale, offline). The `$_data`
 * cache is reset via reflection each test for hygiene; the translator lives in the registry that
 * UnitTestCase isolates per test.
 */
#[CoversClass(Tiger_I18n_Country::class)]
final class CountryTest extends UnitTestCase
{
    /** The biased "common" set, in ascending sort-weight order (US=1, CA=2, GB=3, AU=4, NZ=5, IE=6). */
    private const COMMON = ['US', 'CA', 'GB', 'AU', 'NZ', 'IE'];

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

    /** Reset the lazy static ini cache so a test that seeded a translator can't affect a later read. */
    private function resetData(): void
    {
        $prop = new ReflectionProperty(Tiger_I18n_Country::class, '_data');
        $prop->setValue(null, null);   // reflection reaches the private static without setAccessible (PHP 8.1+)
    }

    #[Test]
    public function iso3_resolves_alpha3_case_insensitively(): void
    {
        $this->assertSame('USA', Tiger_I18n_Country::iso3('US'));
        $this->assertSame('USA', Tiger_I18n_Country::iso3('us'), 'input is upper-cased');
        $this->assertSame('GBR', Tiger_I18n_Country::iso3('GB'));
    }

    #[Test]
    public function iso3_is_null_for_an_unknown_or_empty_code(): void
    {
        $this->assertNull(Tiger_I18n_Country::iso3('QX'), 'unknown code');
        // AC (Ascension Island) ships with an EMPTY iso3 in the ini — empty must normalize to null,
        // not the empty string.
        $this->assertNull(Tiger_I18n_Country::iso3('AC'), 'empty iso3 => null');
    }

    #[Test]
    public function codes_lists_every_alpha2_in_the_data_file(): void
    {
        $codes = Tiger_I18n_Country::codes();
        $this->assertContains('US', $codes);
        $this->assertContains('GB', $codes);
        $this->assertGreaterThan(200, count($codes), 'the ISO-3166 set is ~250 entries');
    }

    #[Test]
    public function name_resolves_via_cldr_when_no_translation_override_exists(): void
    {
        $this->assertSame('United States', Tiger_I18n_Country::name('US', 'en'));
        $this->assertSame('United States', Tiger_I18n_Country::name('us', 'en'), 'code is upper-cased');
    }

    #[Test]
    public function name_falls_back_to_the_code_when_wholly_unresolved(): void
    {
        // QX is neither in a translator nor in CLDR — the last resort is the code itself.
        $this->assertSame('QX', Tiger_I18n_Country::name('QX', 'en'));
    }

    #[Test]
    public function a_translation_override_beats_cldr(): void
    {
        // An install can override a country name via the `country.<ISO2>` translation key; that wins
        // over CLDR's authoritative name.
        $t = new Zend_Translate([
            'adapter' => 'array',
            'content' => ['country.US' => 'The States'],
            'locale'  => 'en',
        ]);
        Zend_Registry::set('Zend_Translate', $t);

        $this->assertSame('The States', Tiger_I18n_Country::name('US', 'en'));
        // an un-overridden country still comes from CLDR.
        $this->assertSame('Canada', Tiger_I18n_Country::name('CA', 'en'));
    }

    #[Test]
    public function all_floats_the_common_countries_to_the_top_in_sort_weight_order(): void
    {
        $keys = array_keys(Tiger_I18n_Country::all('en'));
        $this->assertSame(self::COMMON, array_slice($keys, 0, 6), 'biased block leads, in weight order');
    }

    #[Test]
    public function all_orders_the_remainder_alphabetically_by_localized_name(): void
    {
        $all  = Tiger_I18n_Country::all('en');
        $rest = array_slice($all, 6, null, true);   // everything after the common block
        $names = array_values($rest);

        $sorted = $names;
        usort($sorted, 'strcasecmp');
        $this->assertSame($sorted, $names, 'the non-common tail is A–Z by name');
    }

    #[Test]
    public function all_returns_the_full_set_localized(): void
    {
        $all = Tiger_I18n_Country::all('en');
        $this->assertSame(count(Tiger_I18n_Country::codes()), count($all));
        $this->assertSame('United States', $all['US']);
    }

    #[Test]
    public function grouped_splits_the_biased_block_from_the_rest(): void
    {
        $grouped = Tiger_I18n_Country::grouped('en');
        $this->assertSame(self::COMMON, array_keys($grouped['priority']));
        $this->assertArrayNotHasKey('US', $grouped['rest'], 'a common country is not also in the rest');
        // the two blocks partition the whole set with no overlap or loss.
        $this->assertSame(
            count(Tiger_I18n_Country::codes()),
            count($grouped['priority']) + count($grouped['rest'])
        );
    }

    #[Test]
    public function options_builds_a_placeholder_then_common_then_all_countries_optgroups(): void
    {
        $opts = Tiger_I18n_Country::options('en', '— Pick one —');

        // leading empty-value placeholder.
        $this->assertArrayHasKey('', $opts);
        $this->assertSame('— Pick one —', $opts['']);

        // the "Common" optgroup carries exactly the biased six.
        $this->assertArrayHasKey('Common', $opts);
        $this->assertSame(self::COMMON, array_keys($opts['Common']));

        // and the rest live under "All countries".
        $this->assertArrayHasKey('All countries', $opts);
        $this->assertArrayNotHasKey('US', $opts['All countries']);
    }
}
