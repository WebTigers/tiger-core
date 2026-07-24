<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Media;
use Tiger_View_Helper_MediaField;
use Tiger_View_Helper_Menu;

/**
 * The two DB-backed view helpers.
 *
 *  - Tiger_View_Helper_Menu — a thin wrapper over Tiger_Menu::getHTML (`<?= $this->menu('primary') ?>`).
 *    `menu()` with no key returns the helper (fluent); a key with no menu rows renders ''.
 *  - Tiger_View_Helper_MediaField — a media-picker form field (hidden input + live preview + Choose/
 *    Clear buttons). An empty value renders the bare control; an existing image value server-renders an
 *    `<img>` thumbnail (resolved from the `media` row), a non-image renders a file-icon tile.
 *
 * Both reach the DB (menu tree / media row), hence integration. Neither uses the view object, so the
 * helpers are called directly.
 */
#[CoversClass(Tiger_View_Helper_Menu::class)]
#[CoversClass(Tiger_View_Helper_MediaField::class)]
final class ViewHelperTest extends IntegrationTestCase
{
    // ----- Menu -------------------------------------------------------------------------------

    #[Test]
    public function menu_with_no_key_returns_the_helper_for_fluent_access(): void
    {
        $h = new Tiger_View_Helper_Menu();
        $this->assertSame($h, $h->menu());
    }

    #[Test]
    public function menu_with_an_unknown_key_renders_nothing(): void
    {
        $h = new Tiger_View_Helper_Menu();
        $this->assertSame('', $h->menu('no-such-menu'), 'an empty tree yields no markup');
    }

    // ----- MediaField -------------------------------------------------------------------------

    #[Test]
    public function media_field_with_no_value_renders_the_bare_control(): void
    {
        $h    = new Tiger_View_Helper_MediaField();
        $html = $h->mediaField('hero_image', '', ['kind' => 'image', 'label' => 'Hero image']);

        $this->assertStringContainsString('data-media-field', $html);
        $this->assertStringContainsString('name="hero_image"', $html);
        $this->assertStringContainsString('id="hero_image"', $html);
        $this->assertStringContainsString('data-kind="image"', $html);
        $this->assertStringContainsString('Hero image', $html);
        // No value => the Clear button is hidden and there is no preview <img>.
        $this->assertStringContainsString('data-media-clear hidden', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function media_field_uses_a_custom_id_and_multiple_flag(): void
    {
        $h    = new Tiger_View_Helper_MediaField();
        $html = $h->mediaField('gallery', '', ['id' => 'gallery-picker', 'multiple' => true]);

        $this->assertStringContainsString('id="gallery-picker"', $html);
        $this->assertStringContainsString('data-multiple="1"', $html);
    }

    #[Test]
    public function media_field_server_renders_an_image_thumbnail_for_an_existing_value(): void
    {
        $mediaId = (new Tiger_Model_Media())->insert([
            'org_id'      => '',
            'disk'        => 'local',
            'storage_key' => 'uploads/hero.jpg',
            'visibility'  => 'public',
            'kind'        => 'image',
            'mime_type'   => 'image/jpeg',
            'filename'    => 'hero.jpg',
        ]);

        $html = (new Tiger_View_Helper_MediaField())->mediaField('hero_image', $mediaId);

        $this->assertStringContainsString('<img', $html, 'an image value previews as a thumbnail');
        $this->assertStringContainsString('value="' . $mediaId . '"', $html);
        // A value is present => the Clear button is NOT hidden.
        $this->assertStringNotContainsString('data-media-clear hidden', $html);
    }

    #[Test]
    public function media_field_renders_a_file_tile_for_a_non_image_value(): void
    {
        $mediaId = (new Tiger_Model_Media())->insert([
            'org_id'      => '',
            'disk'        => 'local',
            'storage_key' => 'uploads/manual.pdf',
            'visibility'  => 'public',
            'kind'        => 'document',
            'mime_type'   => 'application/pdf',
            'filename'    => 'manual.pdf',
        ]);

        $html = (new Tiger_View_Helper_MediaField())->mediaField('attachment', $mediaId);

        $this->assertStringContainsString('fa-file', $html, 'a non-image value shows a file icon');
        $this->assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function media_field_shows_no_preview_for_a_multiple_value(): void
    {
        // The server-rendered preview is only built for a single value; multiple defers to the JS picker.
        $mediaId = (new Tiger_Model_Media())->insert([
            'org_id' => '', 'disk' => 'local', 'storage_key' => 'x.jpg',
            'visibility' => 'public', 'kind' => 'image', 'filename' => 'x.jpg',
        ]);

        $html = (new Tiger_View_Helper_MediaField())->mediaField('imgs', $mediaId, ['multiple' => true]);
        $this->assertStringNotContainsString('<img', $html);
    }
}
