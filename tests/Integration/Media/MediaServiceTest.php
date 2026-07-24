<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Media;

use Media_Service_Media;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Media_Storage;
use Tiger_Model_Media;
use Zend_Config;
use Zend_Registry;

/**
 * Media_Service_Media — the /api service behind the admin Media Library (upload / datatable / update /
 * delete). Wave-4 coverage of the reachable surface: the deny-by-default ACL gate, the DataTables
 * envelope (search / kind filter / paging + the server-computed `can_delete` flag), the editorial
 * `update` (fields + visibility normalization, invalid-id + empty-payload rejects), and the soft-delete
 * (row flagged + stored bytes AND variant bytes removed from the disk).
 *
 * The `upload()` HAPPY path is NOT reachable from CLI: PHP's `is_uploaded_file()` only trusts a file
 * that arrived over a real multipart POST, so a synthesized `$_FILES` entry is always refused. We cover
 * its two reachable branches (ACL deny + the no-file error) and note the rest in WAVE4-FINDINGS-mediaan.md.
 */
#[CoversClass(Media_Service_Media::class)]
final class MediaServiceTest extends IntegrationTestCase
{
    private string $publicRoot;
    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);

        $this->publicRoot  = sys_get_temp_dir() . '/tiger-w4-media/pub-' . uniqid();
        $this->privateRoot = sys_get_temp_dir() . '/tiger-w4-media/priv-' . uniqid();
        @mkdir($this->publicRoot, 0777, true);
        @mkdir($this->privateRoot, 0777, true);

        Zend_Registry::set('Zend_Config', new Zend_Config([
            'media' => [
                'default_disk' => 'local',
                'max_upload'   => 52428800,
                'disks'        => [
                    'local' => [
                        'adapter'      => 'filesystem',
                        'public_root'  => $this->publicRoot,
                        'private_root' => $this->privateRoot,
                        'public_url'   => '/_media',
                    ],
                ],
            ],
        ], true));
        Tiger_Media_Storage::reset();
    }

    protected function tearDown(): void
    {
        Tiger_Media_Storage::reset();
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    /** Dispatch the service with an action + payload and hand back the response. */
    private function call(string $action, array $params = []): object
    {
        return (new Media_Service_Media(['action' => $action] + $params))->getResponse();
    }

    /** Insert a media row directly (the storage/upload seam is exercised via the disk adapter). */
    private function seed(array $overrides = []): string
    {
        return (new Tiger_Model_Media())->insert($overrides + [
            'org_id'      => 'org-test',
            'disk'        => 'local',
            'storage_key' => 'org-test/documents/' . uniqid() . '.txt',
            'visibility'  => Tiger_Model_Media::VISIBILITY_PUBLIC,
            'kind'        => Tiger_Model_Media::KIND_DOCUMENT,
            'mime_type'   => 'text/plain',
            'extension'   => 'txt',
            'file_size'   => 12,
            'filename'    => 'seed.txt',
            'title'       => 'Seed',
        ]);
    }

    // ----- ACL gate -----------------------------------------------------------------------------

    #[Test]
    public function guest_is_denied_every_action(): void
    {
        $this->login('anon', 'org-test', 'guest');
        foreach (['upload', 'datatable', 'update', 'delete'] as $action) {
            $res = $this->call($action);
            $this->assertSame(0, (int) $res->result, "guest denied on {$action}");
            $this->assertStringContainsString('not_allowed', json_encode($res->messages), "ACL denial on {$action}");
        }
    }

    #[Test]
    public function a_plain_user_is_denied(): void
    {
        $this->loginAs('user');
        $res = $this->call('datatable', ['draw' => 1]);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    // ----- datatable ----------------------------------------------------------------------------

    #[Test]
    public function datatable_returns_the_envelope_with_a_delete_flag(): void
    {
        $this->loginAs('admin');
        $this->seed(['filename' => 'grid-one.txt', 'title' => 'Grid One']);

        $res  = $this->call('datatable', ['draw' => 5, 'start' => 0, 'length' => 25]);
        $this->assertSame(1, (int) $res->result);
        $data = $res->data;

        $this->assertSame(5, $data['draw']);
        $this->assertArrayHasKey('recordsTotal', $data);
        $this->assertArrayHasKey('recordsFiltered', $data);
        $this->assertGreaterThanOrEqual(1, $data['recordsTotal']);
        $this->assertNotEmpty($data['data']);

        $row = $data['data'][0];
        $this->assertArrayHasKey('media_id', $row);
        $this->assertArrayHasKey('can_delete', $row);
        $this->assertArrayHasKey('url', $row, 'the presenter adds a URL');
        $this->assertArrayHasKey('thumb', $row);
        $this->assertTrue($row['can_delete'], 'admin has the delete privilege on the resource');
    }

    #[Test]
    public function datatable_search_narrows_the_filtered_count(): void
    {
        $this->loginAs('admin');
        $this->seed(['filename' => 'needle-unique-doc.txt', 'title' => 'Needle']);
        $this->seed(['filename' => 'haystack-a.txt']);
        $this->seed(['filename' => 'haystack-b.txt']);

        $res  = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => 'needle-unique-doc']);
        $data = $res->data;

        $this->assertSame(1, $data['recordsFiltered'], 'search narrows to the one match');
        $this->assertGreaterThanOrEqual(3, $data['recordsTotal'], 'total is the unfiltered set');
        $this->assertSame('needle-unique-doc.txt', $data['data'][0]['filename']);
    }

    #[Test]
    public function datatable_kind_filter_limits_to_the_requested_kind(): void
    {
        $this->loginAs('admin');
        $this->seed(['kind' => Tiger_Model_Media::KIND_IMAGE, 'filename' => 'pic.png', 'extension' => 'png', 'mime_type' => 'image/png']);
        $this->seed(['kind' => Tiger_Model_Media::KIND_DOCUMENT, 'filename' => 'doc.txt']);

        $res  = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'kind' => Tiger_Model_Media::KIND_IMAGE]);
        foreach ($res->data['data'] as $row) {
            $this->assertSame(Tiger_Model_Media::KIND_IMAGE, $row['kind'], 'only images pass the kind filter');
        }
        $this->assertGreaterThanOrEqual(1, count($res->data['data']));
    }

    #[Test]
    public function datatable_paging_limits_rows_without_shrinking_total(): void
    {
        $this->loginAs('admin');
        for ($i = 0; $i < 3; $i++) { $this->seed(['filename' => "page{$i}.txt"]); }

        $res  = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 1]);
        $this->assertCount(1, $res->data['data'], 'length=1 returns a single row');
        $this->assertGreaterThanOrEqual(3, $res->data['recordsTotal']);
    }

    #[Test]
    public function datatable_ignores_an_unknown_kind_filter(): void
    {
        $this->loginAs('admin');
        $this->seed(['filename' => 'anything.txt']);
        // 'bogus' isn't a real kind, so the service drops the filter and returns the unfiltered set.
        $res = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'kind' => 'bogus']);
        $this->assertSame(1, (int) $res->result);
        $this->assertGreaterThanOrEqual(1, $res->data['recordsTotal']);
    }

    // ----- update -------------------------------------------------------------------------------

    #[Test]
    public function update_edits_editorial_fields(): void
    {
        $this->loginAs('admin');
        $id = $this->seed();

        $res = $this->call('update', [
            'media_id' => $id,
            'title'    => '  New Title  ',
            'caption'  => 'A caption',
            'alt_text' => 'alt words',
        ]);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('New Title', $res->data['media']['title'], 'trimmed on save');

        $row = (new Tiger_Model_Media())->findById($id);
        $this->assertSame('New Title', $row->title);
        $this->assertSame('A caption', $row->caption);
        $this->assertSame('alt words', $row->alt_text);
    }

    #[Test]
    public function update_normalizes_visibility_to_the_allowed_pair(): void
    {
        $this->loginAs('admin');
        $id = $this->seed(['visibility' => Tiger_Model_Media::VISIBILITY_PUBLIC]);

        $res = $this->call('update', ['media_id' => $id, 'visibility' => 'nonsense']);
        $this->assertSame(1, (int) $res->result);
        // Anything that isn't the private sentinel collapses to public.
        $this->assertSame(Tiger_Model_Media::VISIBILITY_PUBLIC, (new Tiger_Model_Media())->findById($id)->visibility);

        $res2 = $this->call('update', ['media_id' => $id, 'visibility' => Tiger_Model_Media::VISIBILITY_PRIVATE]);
        $this->assertSame(1, (int) $res2->result);
        $this->assertSame(Tiger_Model_Media::VISIBILITY_PRIVATE, (new Tiger_Model_Media())->findById($id)->visibility);
    }

    #[Test]
    public function update_with_no_editable_fields_is_an_error(): void
    {
        $this->loginAs('admin');
        $id = $this->seed();
        $res = $this->call('update', ['media_id' => $id]);   // nothing to change
        $this->assertSame(0, (int) $res->result, 'an empty edit payload is rejected');
    }

    #[Test]
    public function update_rejects_a_missing_or_unknown_id(): void
    {
        $this->loginAs('admin');
        $this->assertSame(0, (int) $this->call('update', ['title' => 'x'])->result, 'no media_id');
        $this->assertSame(0, (int) $this->call('update', ['media_id' => 'does-not-exist', 'title' => 'x'])->result);
    }

    // ----- delete (soft-delete + byte removal) --------------------------------------------------

    #[Test]
    public function delete_soft_deletes_the_row_and_removes_the_bytes(): void
    {
        $this->loginAs('admin');
        $key = 'org-test/documents/del-' . uniqid() . '.txt';
        $id  = $this->seed(['storage_key' => $key]);

        // Lay down real bytes on the disk so the delete has something to remove.
        $disk = Tiger_Media_Storage::disk('local');
        $disk->write($key, 'the bytes', Tiger_Model_Media::VISIBILITY_PUBLIC);
        $this->assertTrue($disk->exists($key, Tiger_Model_Media::VISIBILITY_PUBLIC), 'file present before delete');

        $res = $this->call('delete', ['media_id' => $id]);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame($id, $res->data['media_id']);

        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM media WHERE media_id = ?', [$id]), 'row soft-deleted');
        $this->assertNull((new Tiger_Model_Media())->findById($id), 'findById excludes the soft-deleted row');
        $this->assertFalse($disk->exists($key, Tiger_Model_Media::VISIBILITY_PUBLIC), 'stored bytes removed');
    }

    #[Test]
    public function delete_also_removes_variant_bytes(): void
    {
        $this->loginAs('admin');
        $key   = 'org-test/image/orig-' . uniqid() . '.png';
        $vKey  = 'org-test/image/orig.thumbnail.png';
        $id = $this->seed([
            'storage_key' => $key,
            'kind'        => Tiger_Model_Media::KIND_IMAGE,
            'extension'   => 'png',
            'mime_type'   => 'image/png',
            'variants'    => json_encode(['thumbnail' => ['key' => $vKey, 'w' => 50, 'h' => 50]]),
        ]);

        $disk = Tiger_Media_Storage::disk('local');
        $disk->write($key,  'orig', Tiger_Model_Media::VISIBILITY_PUBLIC);
        $disk->write($vKey, 'thumb', Tiger_Model_Media::VISIBILITY_PUBLIC);

        $this->assertSame(1, (int) $this->call('delete', ['media_id' => $id])->result);
        $this->assertFalse($disk->exists($key,  Tiger_Model_Media::VISIBILITY_PUBLIC), 'original removed');
        $this->assertFalse($disk->exists($vKey, Tiger_Model_Media::VISIBILITY_PUBLIC), 'variant removed');
    }

    #[Test]
    public function delete_still_soft_deletes_when_the_bytes_are_already_gone(): void
    {
        $this->loginAs('admin');
        $id = $this->seed();   // no bytes written — the disk delete is a no-op, the row still flips
        $this->assertSame(1, (int) $this->call('delete', ['media_id' => $id])->result);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM media WHERE media_id = ?', [$id]));
    }

    #[Test]
    public function delete_rejects_a_missing_or_unknown_id(): void
    {
        $this->loginAs('admin');
        $this->assertSame(0, (int) $this->call('delete', [])->result, 'no media_id');
        $this->assertSame(0, (int) $this->call('delete', ['media_id' => 'nope'])->result);
    }

    // ----- upload (only the CLI-reachable branches) ---------------------------------------------

    #[Test]
    public function upload_is_denied_for_a_guest(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call('upload');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function upload_errors_when_no_file_is_present(): void
    {
        $this->loginAs('admin');
        unset($_FILES['file']);
        $res = $this->call('upload');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('media.error.upload', json_encode($res->messages), 'no $_FILES → upload error');
    }

    #[Test]
    public function upload_errors_on_a_non_uploaded_tmp_file(): void
    {
        $this->loginAs('admin');
        // A synthesized $_FILES entry: is_uploaded_file() refuses it (not a real POST upload), so the
        // service returns the same upload error — proving the guard. See the findings note.
        $tmp = tempnam(sys_get_temp_dir(), 'w4up');
        file_put_contents($tmp, 'bytes');
        $_FILES['file'] = ['name' => 'x.txt', 'tmp_name' => $tmp, 'size' => 5, 'error' => UPLOAD_ERR_OK];
        try {
            $res = $this->call('upload');
            $this->assertSame(0, (int) $res->result);
            $this->assertStringContainsString('media.error.upload', json_encode($res->messages));
        } finally {
            unset($_FILES['file']);
            @unlink($tmp);
        }
    }
}
