<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_MessageObject;
use Tiger_Model_PageRedirect;
use Tiger_Model_ResponseObject;
use Tiger_Uuid;
use Zend_Registry;
use Zend_Translate;

/**
 * The /api response envelope (ResponseObject + MessageObject) and PageRedirect.
 *
 * ResponseObject is the fixed JSON shape every service returns; MessageObject treats its message as a
 * translation KEY — resolved in the current locale, falling back to the default language, and finally
 * to the raw string so plain text still works (the contract that keeps every API message localized
 * without the service thinking about it).
 *
 * PageRedirect 301s a retired slug to its replacement, tenant-scoped (a tenant row wins over global '')
 * — add/findFrom/clearFrom, the machinery Page::save() drives on a slug change.
 */
#[CoversClass(Tiger_Model_ResponseObject::class)]
#[CoversClass(Tiger_Model_MessageObject::class)]
#[CoversClass(Tiger_Model_PageRedirect::class)]
final class ResponseEnvelopeTest extends IntegrationTestCase
{
    private $priorTranslate = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorTranslate = Zend_Registry::isRegistered('Zend_Translate') ? Zend_Registry::get('Zend_Translate') : null;
    }

    protected function tearDown(): void
    {
        // Restore the registry EXACTLY: if there was no translator before, UNSET it — setting it to
        // null would leave it registered-as-null, and code guarded by isRegistered() would then call
        // a method on null (this leaked into SchemaServiceTest's menu→nav resolution).
        $reg = Zend_Registry::getInstance();
        if ($this->priorTranslate !== null) {
            Zend_Registry::set('Zend_Translate', $this->priorTranslate);
        } elseif ($reg->offsetExists('Zend_Translate')) {
            $reg->offsetUnset('Zend_Translate');
        }
        parent::tearDown();
    }

    #[Test]
    public function response_object_has_the_default_envelope_shape(): void
    {
        $r = new Tiger_Model_ResponseObject();
        $this->assertSame(0, $r->result, 'result defaults to failure (0)');
        $this->assertNull($r->data);
        $this->assertNull($r->redirect);
        $this->assertNull($r->form);
        $this->assertSame([], $r->messages, 'messages defaults to an empty list');
    }

    #[Test]
    public function message_object_falls_back_to_the_literal_text_when_no_translator_is_wired(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('Zend_Translate')) { $reg->offsetUnset('Zend_Translate'); }
        $m = new Tiger_Model_MessageObject('Saved successfully.', 'success', 'email');
        $this->assertSame('Saved successfully.', $m->message, 'plain text passes through untranslated');
        $this->assertSame('success', $m->class);
        $this->assertSame('email', $m->field);
    }

    #[Test]
    public function message_object_translates_a_known_key_in_the_current_locale(): void
    {
        $translate = new Zend_Translate([
            'adapter' => 'array',
            'content' => ['msg.saved' => 'Your changes were saved'],
            'locale'  => 'en',
        ]);
        Zend_Registry::set('Zend_Translate', $translate);

        $m = new Tiger_Model_MessageObject('msg.saved', 'info');
        $this->assertSame('Your changes were saved', $m->message, 'a translated key is resolved');
        $this->assertSame('info', $m->class);
        $this->assertNull($m->field, 'field defaults to null');
    }

    #[Test]
    public function page_redirect_add_find_and_clear_with_the_tenant_cascade(): void
    {
        $redirect = new Tiger_Model_PageRedirect();
        $orgA     = Tiger_Uuid::v7();

        // A global redirect and a tenant override for the same retired slug + locale.
        $redirect->add('old-home', 'home',    'en', '');
        $redirect->add('old-home', 'welcome', 'en', $orgA);

        // The tenant row wins over global for the owning org…
        $seenByA = $redirect->findFrom('old-home', 'en', $orgA);
        $this->assertNotNull($seenByA);
        $this->assertSame('welcome', $seenByA->to_slug, 'a tenant redirect overrides the global one');
        $this->assertSame(301, (int) $seenByA->code, 'the default code is a 301');

        // …and a foreign/blank org falls back to the global redirect.
        $seenGlobal = $redirect->findFrom('old-home', 'en', '');
        $this->assertSame('home', $seenGlobal->to_slug);

        // A different locale doesn't match.
        $this->assertNull($redirect->findFrom('old-home', 'fr', ''), 'the locale must match');

        // clearFrom removes exactly the (slug, locale, org) redirect.
        $n = $redirect->clearFrom('old-home', 'en', $orgA);
        $this->assertSame(1, $n, 'one tenant redirect was cleared');
        // With the tenant row gone, org A now cascades to the surviving global redirect.
        $afterClear = $redirect->findFrom('old-home', 'en', $orgA);
        $this->assertNotNull($afterClear, 'clearing the tenant redirect leaves global intact');
        $this->assertSame('home', $afterClear->to_slug, 'org A falls back to the global redirect');
        $this->assertSame('', $afterClear->org_id);
    }
}
