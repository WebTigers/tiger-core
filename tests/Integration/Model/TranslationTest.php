<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Translation;
use Tiger_Uuid;

/**
 * Tiger_Model_Translation — the request-time i18n override tier (mirrors the `config` table): file
 * translations are the base, a row here OVERRIDES or ADDS a string with no deploy. The contract the
 * bootstrap depends on: `get()` returns an override's value scoped to (locale, scope, scope_id, key)
 * and null for an unknown key; `set()` UPSERTS (one row per key, later write wins); `getForLocale()`
 * hands back the whole scoped map for `Zend_Translate::addTranslation`. Scope is a hard boundary — a
 * global override must not leak into an org scope (or a different locale), or one tenant's copy
 * bleeds into another's.
 */
#[CoversClass(Tiger_Model_Translation::class)]
final class TranslationTest extends IntegrationTestCase
{
    private Tiger_Model_Translation $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = new Tiger_Model_Translation();
    }

    #[Test]
    public function set_then_get_returns_the_override_value(): void
    {
        $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.api.success', 'All good.');
        $this->assertSame(
            'All good.',
            $this->t->get('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.api.success'),
            'an override row wins — get returns exactly what was set'
        );
    }

    #[Test]
    public function get_returns_null_for_an_unknown_key(): void
    {
        $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.known', 'known');
        $this->assertNull(
            $this->t->get('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.does.not.exist'),
            'no row → null, so resolution falls through to the file base'
        );
    }

    #[Test]
    public function set_upserts_in_place_rather_than_stacking_duplicate_rows(): void
    {
        $firstId  = $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'app.greeting', 'Hello');
        $secondId = $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'app.greeting', 'Howdy');

        $this->assertSame($firstId, $secondId, 'the second set updates the same row (same id)');
        $this->assertSame('Howdy', $this->t->get('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'app.greeting'), 'later write wins');

        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM translation WHERE locale = ? AND scope = ? AND scope_id = ? AND translation_key = ?',
            ['en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'app.greeting']
        );
        $this->assertSame(1, $count, 'upsert keeps exactly one row for the key');
    }

    #[Test]
    public function scope_and_locale_are_hard_boundaries(): void
    {
        $orgId = Tiger_Uuid::v7();

        // Same key, three different (locale, scope, scope_id) coordinates — three independent values.
        $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '',      'core.brand', 'Global EN');
        $this->t->set('es', Tiger_Model_Translation::SCOPE_GLOBAL, '',      'core.brand', 'Global ES');
        $this->t->set('en', Tiger_Model_Translation::SCOPE_ORG,    $orgId,  'core.brand', 'Org EN');

        $this->assertSame('Global EN', $this->t->get('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.brand'));
        $this->assertSame('Global ES', $this->t->get('es', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.brand'), 'a different locale is a different value');
        $this->assertSame('Org EN',    $this->t->get('en', Tiger_Model_Translation::SCOPE_ORG, $orgId, 'core.brand'), 'the org scope has its own override');

        // A different org has NO override for the key — scope isolation, not a leak of the global one.
        $this->assertNull($this->t->get('en', Tiger_Model_Translation::SCOPE_ORG, Tiger_Uuid::v7(), 'core.brand'), 'another org gets null, not the global value');
    }

    #[Test]
    public function get_for_locale_returns_only_the_scoped_map(): void
    {
        $orgId = Tiger_Uuid::v7();
        $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '',     'a.one', 'One');
        $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '',     'a.two', 'Two');
        $this->t->set('es', Tiger_Model_Translation::SCOPE_GLOBAL, '',     'a.one', 'Uno');   // other locale
        $this->t->set('en', Tiger_Model_Translation::SCOPE_ORG,    $orgId, 'a.one', 'OrgOne'); // other scope

        $map = $this->t->getForLocale('en', Tiger_Model_Translation::SCOPE_GLOBAL, '');

        // assertEquals (not assertSame): the map is an unordered key=>value set and getForLocale has no
        // ORDER BY, so row order is DB-defined and differs across engines/runs — only the pairs matter.
        $this->assertEquals(['a.one' => 'One', 'a.two' => 'Two'], $map, 'exactly the en/global overrides as a key=>value map — no other locale or scope');
    }

    #[Test]
    public function a_soft_deleted_override_no_longer_wins(): void
    {
        // get() builds on activeSelect(), so a soft-deleted override drops out and resolution falls
        // back to the file base (here: null in the model tier).
        $id = $this->t->set('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.temp', 'temporary');
        $this->assertSame('temporary', $this->t->get('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.temp'));

        $this->t->softDelete($this->db->quoteInto('translation_id = ?', $id));
        $this->assertNull(
            $this->t->get('en', Tiger_Model_Translation::SCOPE_GLOBAL, '', 'core.temp'),
            'a soft-deleted override is no longer returned'
        );
    }
}
