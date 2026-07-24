<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Cms;

use Cms_Form_MenuItem;
use Cms_Form_Page;
use Cms_Form_Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Zend_Registry;

/**
 * The three CMS admin forms — declarative Tiger_Form schemas the services validate before a write.
 *
 * These carry their own DEDICATED coverage: PHPUnit attributes a service/controller test's coverage
 * only to its `#[CoversClass]` targets, so a form CONSTRUCTED inside a service (Cms_Service_*::save)
 * isn't credited there — the form is its own contract and gets its own test. Each `elements()` runs a
 * live `Tiger_Model_Page` query to build its page/home-page dropdown, so a published page is seeded to
 * exercise that option-loading branch. `tiger.auth.stateless` is set so the CSRF-bearing forms (Page,
 * Settings) build without a session token (MenuItem disables CSRF outright — the reload-free builder).
 */
#[CoversClass(Cms_Form_Page::class)]
#[CoversClass(Cms_Form_MenuItem::class)]
#[CoversClass(Cms_Form_Settings::class)]
final class FormsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function seedPublishedPage(): void
    {
        (new Tiger_Model_Page())->insert([
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'title'  => 'Home',
            'slug'   => 'home',
            'page_key' => 'home',
            'body'   => '',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
        ]);
    }

    // ----- Cms_Form_Page ------------------------------------------------------------------------

    #[Test]
    public function page_form_declares_its_schema_and_requires_only_a_title(): void
    {
        $form = new Cms_Form_Page();
        foreach (['page_id', 'title', 'slug', 'page_key', 'type', 'format', 'status', 'locale', 'body', 'meta_description'] as $name) {
            $this->assertNotNull($form->getElement($name), "the $name element is declared");
        }
        $this->assertTrue($form->getElement('title')->isRequired(), 'title is the one hard-required field');
        $this->assertFalse($form->getElement('slug')->isRequired(), 'slug is optional (derived when blank)');
    }

    #[Test]
    public function page_form_validates_a_minimal_page_and_rejects_a_blank_title(): void
    {
        $form = new Cms_Form_Page();
        $this->assertTrue(
            $form->isValid(['title' => 'Hello', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en']),
            'a titled page validates'
        );

        $bad = new Cms_Form_Page();
        $this->assertFalse($bad->isValid(['title' => '', 'type' => 'page', 'format' => 'html', 'status' => 'draft', 'locale' => 'en']));
        $this->assertArrayHasKey('title', $bad->getMessages());
    }

    // ----- Cms_Form_MenuItem --------------------------------------------------------------------

    #[Test]
    public function menu_item_form_disables_csrf_requires_a_label_and_lists_linkable_pages(): void
    {
        $this->seedPublishedPage();   // → the page_key dropdown option-loading branch runs
        $form = new Cms_Form_MenuItem();

        $this->assertNull($form->getElement('_csrf'), 'the menu builder form carries no CSRF token');
        $this->assertTrue($form->getElement('label')->isRequired(), 'label is required');
        foreach (['url', 'icon', 'css_class', 'link_target', 'resource', 'privilege', 'status'] as $name) {
            $this->assertNotNull($form->getElement($name), "the $name element is declared");
        }
        $options = $form->getElement('page_key')->getMultiOptions();
        $this->assertArrayHasKey('home', $options, 'the seeded published page is offered as a link target');
    }

    #[Test]
    public function menu_item_form_rejects_a_blank_label(): void
    {
        $form = new Cms_Form_MenuItem();
        $this->assertFalse($form->isValid(['label' => '']));
        $this->assertArrayHasKey('label', $form->getMessages());
    }

    // ----- Cms_Form_Settings --------------------------------------------------------------------

    #[Test]
    public function settings_form_requires_a_site_name_and_lists_published_pages_as_home_options(): void
    {
        $this->seedPublishedPage();
        $form = new Cms_Form_Settings();

        $this->assertTrue($form->getElement('site_name')->isRequired());
        $home = $form->getElement('home_page')->getMultiOptions();
        $this->assertArrayHasKey('', $home, 'the built-in landing is the empty option');
        $this->assertContains('Home (en)', $home, 'a published page is a home-page choice');

        $this->assertTrue($form->isValid(['site_name' => 'My Site', 'home_page' => '']));
        $bad = new Cms_Form_Settings();
        $this->assertFalse($bad->isValid(['site_name' => '', 'home_page' => '']));
        $this->assertArrayHasKey('site_name', $bad->getMessages());
    }
}
