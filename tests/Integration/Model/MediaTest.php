<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Media_Storage;
use Tiger_Model_Media;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_Media — URL routing (the access-scoping invariant) and extension classification.
 *
 * The load-bearing security invariant: url() hands out a **direct/CDN URL only for PUBLIC objects**;
 * a PRIVATE object never yields a public direct URL — its url() is the ACL-checked streamer route
 * (`/media/file/serve/id/<id>`), so private bytes can't be reached without passing the access gate.
 * A regression here (a private file resolving to `/_media/…`) would be a real data leak.
 *
 * classify() gates uploads against the configured `media.allow.*` allowlists: known image/document
 * extensions classify + are allowed; a disallowed/unknown extension → KIND_OTHER, not allowed.
 *
 * Both paths read the `media` config, so we register a Zend_Config with a filesystem disk + allowlists
 * for the duration of the test (and reset the storage memo, since it caches adapters per disk name).
 */
#[CoversClass(Tiger_Model_Media::class)]
final class MediaTest extends IntegrationTestCase
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
                'disks' => [
                    'local' => [
                        'adapter'      => 'filesystem',
                        'public_url'   => '/_media',
                        'public_root'  => 'public/_media',
                        'private_root' => 'storage/media',
                    ],
                ],
                'allow' => [
                    'image'    => 'jpg,jpeg,png,gif,webp',
                    'document' => 'pdf,doc,docx,txt',
                    'video'    => 'mp4,webm',
                    'audio'    => 'mp3',
                    'archive'  => 'zip',
                ],
            ],
        ]));
        Tiger_Media_Storage::reset();
    }

    protected function tearDown(): void
    {
        // Restore the prior registry state so we don't leak config into sibling test files.
        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } else {
            Zend_Registry::set('Zend_Config', new Zend_Config([]));
        }
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
            'storage_key' => 'images/example.jpg',
            'filename'    => 'example.jpg',
        ], $overrides));
    }

    #[Test]
    public function a_public_file_resolves_to_a_direct_docroot_url(): void
    {
        $id  = $this->insertMedia(['visibility' => Tiger_Model_Media::VISIBILITY_PUBLIC, 'storage_key' => 'images/logo.png']);
        $row = $this->media->findById($id)->toArray();

        $url = $this->media->url($row);
        $this->assertSame('/_media/images/logo.png', $url, 'a public object gets a direct docroot URL');
    }

    #[Test]
    public function a_private_file_never_yields_a_public_direct_url(): void
    {
        $id  = $this->insertMedia(['visibility' => Tiger_Model_Media::VISIBILITY_PRIVATE, 'storage_key' => 'docs/secret.pdf', 'kind' => Tiger_Model_Media::KIND_PDF]);
        $row = $this->media->findById($id)->toArray();

        $url = $this->media->url($row);

        // The access-scoping invariant: it must be the ACL-checked streamer route, keyed by the id —
        // and it must NOT be a public direct URL that would bypass the gate.
        $this->assertSame('/media/file/serve/id/' . rawurlencode($id), $url);
        $this->assertStringStartsWith('/media/file/serve/', $url, 'private goes through the streamer route');
        $this->assertStringNotContainsString('/_media/', $url, 'private must never expose the public docroot path');
        $this->assertStringNotContainsString('secret.pdf', $url, 'the private storage key is not leaked in the URL');
    }

    #[Test]
    public function classify_allows_known_image_and_document_extensions(): void
    {
        $img = Tiger_Model_Media::classify('jpg');
        $this->assertSame(Tiger_Model_Media::KIND_IMAGE, $img['kind']);
        $this->assertTrue($img['allowed']);

        // Case-insensitive and a leading dot is tolerated.
        $this->assertSame(Tiger_Model_Media::KIND_IMAGE, Tiger_Model_Media::classify('PNG')['kind']);
        $this->assertTrue(Tiger_Model_Media::classify('.gif')['allowed']);

        // pdf is split out of documents (its own kind) but still allowed via the document allowlist.
        $pdf = Tiger_Model_Media::classify('pdf');
        $this->assertSame(Tiger_Model_Media::KIND_PDF, $pdf['kind']);
        $this->assertTrue($pdf['allowed']);

        $doc = Tiger_Model_Media::classify('docx');
        $this->assertSame(Tiger_Model_Media::KIND_DOCUMENT, $doc['kind']);
        $this->assertTrue($doc['allowed']);

        $zip = Tiger_Model_Media::classify('zip');
        $this->assertSame(Tiger_Model_Media::KIND_ARCHIVE, $zip['kind']);
        $this->assertTrue($zip['allowed']);
    }

    #[Test]
    public function classify_rejects_a_disallowed_or_unknown_extension_as_other(): void
    {
        foreach (['exe', 'php', 'sh', 'unknownext', ''] as $ext) {
            $c = Tiger_Model_Media::classify($ext);
            $this->assertSame(Tiger_Model_Media::KIND_OTHER, $c['kind'], "'{$ext}' is not on any allowlist → other");
            $this->assertFalse($c['allowed'], "'{$ext}' must not be allowed");
        }
    }
}
