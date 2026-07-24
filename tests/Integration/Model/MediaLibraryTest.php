<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Media_Storage;
use Tiger_Model_Config;
use Tiger_Model_Media;
use Tiger_Uuid;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_Media, part 2 — the library QUERY surface + storage-key helpers (MediaTest covers the
 * url()/classify() access invariant; this covers the rest).
 *
 * search() is the Tiger_Search "media" provider seam and enforces the same visibility gate the URL
 * router does: public+clean to everyone, private+clean only to a signed-in caller in the OWNING org,
 * and unscanned/infected/rejected items are never surfaced. datatable() is the admin library grid
 * (kind filter + text search + paging). The storage helpers decide obfuscated-vs-readable keys, the
 * download filename, and the per-kind folder — the plumbing that keeps private bytes from leaking
 * anything about the original file.
 */
#[CoversClass(Tiger_Model_Media::class)]
final class MediaLibraryTest extends IntegrationTestCase
{
    private Tiger_Model_Media $media;
    private ?Zend_Config $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->media = new Tiger_Model_Media();

        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'media' => [
                'default_disk' => 'local',
                'disks' => ['local' => ['adapter' => 'filesystem', 'public_url' => '/_media', 'public_root' => 'public/_media', 'private_root' => 'storage/media']],
                'allow' => ['image' => 'jpg,png', 'document' => 'pdf', 'video' => 'mp4', 'audio' => 'mp3', 'archive' => 'zip'],
            ],
        ]));
        Tiger_Media_Storage::reset();
    }

    protected function tearDown(): void
    {
        Zend_Registry::set('Zend_Config', $this->priorConfig ?? new Zend_Config([]));
        Tiger_Media_Storage::reset();
        parent::tearDown();
    }

    private function insertMedia(array $overrides): string
    {
        return $this->media->insert(array_merge([
            'org_id'      => '',
            'disk'        => 'local',
            'visibility'  => Tiger_Model_Media::VISIBILITY_PUBLIC,
            'kind'        => Tiger_Model_Media::KIND_IMAGE,
            'storage_key' => 'images/' . bin2hex(random_bytes(6)) . '.jpg',
            'filename'    => 'file.jpg',
            'scan_status' => Tiger_Model_Media::SCAN_CLEAN,
        ], $overrides));
    }

    // ---- static helpers -----------------------------------------------------

    #[Test]
    public function kind_folder_maps_each_kind_and_defaults_to_files(): void
    {
        $this->assertSame('images', Tiger_Model_Media::kindFolder(Tiger_Model_Media::KIND_IMAGE));
        $this->assertSame('docs', Tiger_Model_Media::kindFolder(Tiger_Model_Media::KIND_PDF));
        $this->assertSame('docs', Tiger_Model_Media::kindFolder(Tiger_Model_Media::KIND_DOCUMENT));
        $this->assertSame('files', Tiger_Model_Media::kindFolder(Tiger_Model_Media::KIND_ARCHIVE));
        $this->assertSame('files', Tiger_Model_Media::kindFolder('bogus'), 'an unknown kind falls back to files');
    }

    #[Test]
    public function slugify_produces_a_safe_lowercase_token(): void
    {
        $this->assertSame('my-cool-file', Tiger_Model_Media::slugify('My Cool File!'));
        $this->assertSame('a-b', Tiger_Model_Media::slugify('  a...b  '));
        $this->assertSame('', Tiger_Model_Media::slugify('***'), 'nothing survivable → empty string');
    }

    #[Test]
    public function storage_base_is_random_when_obfuscated_and_readable_otherwise(): void
    {
        $obf = Tiger_Model_Media::storageBase('My Photo.JPG', true);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $obf, 'obfuscated = a bare 32-hex key');

        $readable = Tiger_Model_Media::storageBase('My Photo.JPG', false);
        $this->assertStringStartsWith('my-photo-', $readable, 'readable = the slug + a short random suffix');
        $this->assertMatchesRegularExpression('/^my-photo-[0-9a-f]{8}$/', $readable);

        // A filename with no slug-able characters still yields a random key.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Tiger_Model_Media::storageBase('***.jpg', false));
    }

    #[Test]
    public function is_obfuscated_key_recognises_bare_hex_but_not_a_readable_key(): void
    {
        $this->assertTrue(Tiger_Model_Media::isObfuscatedKey('images/' . str_repeat('a', 32) . '.jpg'));
        $this->assertFalse(Tiger_Model_Media::isObfuscatedKey('images/my-photo-1a2b3c4d.jpg'), 'a -8hex suffix is a readable key');
        $this->assertFalse(Tiger_Model_Media::isObfuscatedKey('images/logo.png'));
    }

    #[Test]
    public function download_name_hides_the_original_for_obfuscated_files_only(): void
    {
        $obf = ['storage_key' => 'docs/' . str_repeat('b', 32) . '.pdf', 'filename' => 'Secret Contract.pdf'];
        $this->assertSame(str_repeat('b', 32) . '.pdf', Tiger_Model_Media::downloadName($obf), 'obfuscated downloads under the random name');

        $readable = ['storage_key' => 'docs/report-1a2b3c4d.pdf', 'filename' => 'Q3 Report.pdf'];
        $this->assertSame('Q3 Report.pdf', Tiger_Model_Media::downloadName($readable), 'readable downloads under the original name');
    }

    #[Test]
    public function obfuscate_default_and_setting_scope(): void
    {
        $this->assertTrue(Tiger_Model_Media::obfuscateDefault(Tiger_Model_Media::VISIBILITY_PRIVATE), 'private defaults to obfuscated');
        $this->assertFalse(Tiger_Model_Media::obfuscateDefault(Tiger_Model_Media::VISIBILITY_PUBLIC), 'public defaults to readable');

        $this->assertSame([Tiger_Model_Config::SCOPE_GLOBAL, ''], Tiger_Model_Media::settingScope(''), 'no org → global scope');
        $org = Tiger_Uuid::v7();
        $this->assertSame([Tiger_Model_Config::SCOPE_ORG, $org], Tiger_Model_Media::settingScope($org), 'an org → org scope');
    }

    #[Test]
    public function obfuscate_enabled_falls_back_default_then_reads_config(): void
    {
        // Nothing stored → the built-in default per visibility.
        $this->assertTrue($this->media->obfuscateEnabled(Tiger_Model_Media::VISIBILITY_PRIVATE, ''));
        $this->assertFalse($this->media->obfuscateEnabled(Tiger_Model_Media::VISIBILITY_PUBLIC, ''));

        // A stored global override flips the public default on.
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', Tiger_Model_Media::CFG_OBFUSCATE . 'public', '1');
        $this->assertTrue($this->media->obfuscateEnabled(Tiger_Model_Media::VISIBILITY_PUBLIC, ''), 'a stored "1" enables obfuscation');
    }

    // ---- variants + url variant path ---------------------------------------

    #[Test]
    public function variants_decodes_json_and_thumb_url_prefers_the_thumbnail(): void
    {
        $variants = ['thumbnail' => ['key' => 'images/thumb_logo.png']];
        $id  = $this->insertMedia(['visibility' => Tiger_Model_Media::VISIBILITY_PUBLIC, 'storage_key' => 'images/logo.png', 'variants' => json_encode($variants)]);
        $row = $this->media->findById($id)->toArray();

        $this->assertSame($variants, $this->media->variants($row), 'the variants JSON decodes to an array');
        $this->assertSame('/_media/images/thumb_logo.png', $this->media->thumbUrl($row), 'thumbUrl serves the thumbnail variant');
        $this->assertSame('/_media/images/thumb_logo.png', $this->media->url($row, 'thumbnail'), 'url() resolves a named variant key');

        // An unknown variant falls back to the original.
        $this->assertSame('/_media/images/logo.png', $this->media->url($row, 'nope'));
    }

    #[Test]
    public function thumb_url_falls_back_to_the_original_when_there_is_no_thumbnail(): void
    {
        $id  = $this->insertMedia(['storage_key' => 'images/plain.jpg', 'variants' => null]);
        $row = $this->media->findById($id)->toArray();
        $this->assertSame('/_media/images/plain.jpg', $this->media->thumbUrl($row));
    }

    #[Test]
    public function url_of_a_row_with_no_storage_key_is_empty(): void
    {
        $this->assertSame('', $this->media->url(['visibility' => 'public', 'storage_key' => '']));
    }

    #[Test]
    public function a_private_variant_streams_through_the_acl_route_with_the_variant_segment(): void
    {
        $id  = $this->insertMedia([
            'visibility'  => Tiger_Model_Media::VISIBILITY_PRIVATE,
            'kind'        => Tiger_Model_Media::KIND_PDF,
            'storage_key' => 'docs/secret.pdf',
            'variants'    => json_encode(['preview' => ['key' => 'docs/secret_preview.png']]),
        ]);
        $row = $this->media->findById($id)->toArray();

        $url = $this->media->url($row, 'preview');
        $this->assertStringStartsWith('/media/file/serve/id/' . rawurlencode($id), $url, 'private variant goes through the gate');
        $this->assertStringEndsWith('/v/preview', $url, 'the variant segment rides on the route');
        $this->assertStringNotContainsString('secret', $url, 'no private key leaks in the URL');
    }

    // ---- search + datatable -------------------------------------------------

    #[Test]
    public function search_returns_nothing_for_a_blank_term(): void
    {
        $this->assertSame([], $this->media->search('   ', ['role' => 'user', 'orgId' => 'x']));
    }

    #[Test]
    public function search_surfaces_public_clean_media_to_a_guest_but_hides_private_and_infected(): void
    {
        $org = Tiger_Uuid::v7();
        $this->insertMedia(['visibility' => 'public',  'title' => 'Vacation Sunset', 'filename' => 'vacation-sunset.jpg', 'scan_status' => Tiger_Model_Media::SCAN_CLEAN]);
        $this->insertMedia(['visibility' => 'private', 'org_id' => $org, 'title' => 'Vacation Private', 'filename' => 'vacation-priv.jpg', 'scan_status' => Tiger_Model_Media::SCAN_CLEAN]);
        $this->insertMedia(['visibility' => 'public',  'title' => 'Vacation Infected', 'filename' => 'vacation-bad.jpg', 'scan_status' => Tiger_Model_Media::SCAN_INFECTED]);

        $guest = $this->media->search('vacation', ['role' => 'guest', 'orgId' => '']);
        $titles = array_column($guest, 'title');
        $this->assertContains('Vacation Sunset', $titles, 'public+clean is visible to a guest');
        $this->assertNotContains('Vacation Private', $titles, 'private is hidden from a guest');
        $this->assertNotContains('Vacation Infected', $titles, 'infected media is never surfaced');
    }

    #[Test]
    public function search_shows_a_signed_in_owner_their_own_private_media(): void
    {
        $org = Tiger_Uuid::v7();
        $this->insertMedia(['visibility' => 'private', 'org_id' => $org, 'title' => 'Manuscript Draft', 'filename' => 'manuscript.pdf', 'kind' => 'pdf', 'scan_status' => Tiger_Model_Media::SCAN_CLEAN]);
        $this->insertMedia(['visibility' => 'private', 'org_id' => Tiger_Uuid::v7(), 'title' => 'Manuscript Other', 'filename' => 'other.pdf', 'kind' => 'pdf', 'scan_status' => Tiger_Model_Media::SCAN_CLEAN]);

        $mine = $this->media->search('manuscript', ['role' => 'user', 'orgId' => $org]);
        $titles = array_column($mine, 'title');
        $this->assertContains('Manuscript Draft', $titles, 'the owning org sees its private media');
        $this->assertNotContains('Manuscript Other', $titles, 'another org\'s private media stays hidden');
    }

    #[Test]
    public function datatable_counts_filters_by_kind_and_searches(): void
    {
        $this->insertMedia(['kind' => 'image', 'title' => 'Logo Alpha', 'filename' => 'logo-alpha.png']);
        $this->insertMedia(['kind' => 'image', 'title' => 'Logo Beta',  'filename' => 'logo-beta.png']);
        $this->insertMedia(['kind' => 'pdf',   'title' => 'A Document', 'filename' => 'doc.pdf']);

        $all = $this->media->datatable(['limit' => 24]);
        $this->assertSame(3, $all['total']);

        $images = $this->media->datatable(['kind' => 'image', 'limit' => 24]);
        $this->assertSame(2, $images['filtered']);
        foreach ($images['rows'] as $r) { $this->assertSame('image', $r['kind']); }

        $search = $this->media->datatable(['search' => 'Alpha', 'limit' => 24]);
        $this->assertSame(1, $search['filtered']);
        $this->assertSame('Logo Alpha', $search['rows'][0]['title']);
    }
}
