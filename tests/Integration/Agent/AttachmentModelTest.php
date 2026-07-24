<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use Agent_Model_Attachment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use ZipArchive;

/**
 * Agent_Model_Attachment — the DB-backed owner-scoping queries + the document text extractors (Wave 6).
 *
 * The static classification/extraction helpers (kindFor / accepted / extractText for text+html+cap)
 * are already covered by tests/Unit/Agent/AttachmentTest — this adds the parts that need a real DB or
 * a real container file: `pendingForUser` and `linkToMessage` (the owner-scoped, still-pending guards
 * that stop one user attaching another's upload to a turn), and the DOCX / PDF extraction paths
 * (`_docxText`, `_pdfText`) exercised against a genuine minimal container.
 *
 * The `agent_attachment` table ships as a MODULE migration the harness doesn't scan, so we create it
 * on a side connection (see EnsuresAttachmentTable).
 */
#[CoversClass(Agent_Model_Attachment::class)]
final class AttachmentModelTest extends IntegrationTestCase
{
    use EnsuresAttachmentTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttachmentTable();
    }

    public static function tearDownAfterClass(): void
    {
        self::dropAttachmentTable();   // never leave the side-loaded table for InstallerLifecycleTest
        parent::tearDownAfterClass();
    }

    /** Insert a pending (unlinked) attachment row and return its id. */
    private function seed(string $userId, string $orgId, array $over = []): string
    {
        return (new Agent_Model_Attachment())->insert($over + [
            'conversation_id' => null,
            'message_id'      => null,
            'user_id'         => $userId,
            'org_id'          => $orgId,
            'disk'            => 'local',
            'filename'        => 'notes.txt',
            'mime_type'       => 'text/plain',
            'file_size'       => 12,
            'kind'            => 'file',
        ]);
    }

    // ----- pendingForUser (owner scoping + still-pending guard) ------------------------------------

    #[Test]
    public function pendingForUser_returns_only_the_callers_own_unlinked_rows(): void
    {
        $mine    = $this->seed('user-a', 'org-1');
        $theirs  = $this->seed('user-b', 'org-1');
        $linked  = $this->seed('user-a', 'org-1');
        // Mark one of mine as already sent (message_id set) → it must NOT come back.
        (new Agent_Model_Attachment())->update(['message_id' => 'msg-1'], "attachment_id = '{$linked}'");

        $rows = (new Agent_Model_Attachment())->pendingForUser([$mine, $theirs, $linked], 'user-a');
        $ids  = array_column($rows, 'attachment_id');

        $this->assertContains($mine, $ids, 'my still-pending row resolves');
        $this->assertNotContains($theirs, $ids, "another user's row is dropped");
        $this->assertNotContains($linked, $ids, 'an already-sent (linked) row is dropped');
    }

    #[Test]
    public function pendingForUser_with_no_ids_returns_empty_without_a_query(): void
    {
        $this->assertSame([], (new Agent_Model_Attachment())->pendingForUser([], 'user-a'));
        $this->assertSame([], (new Agent_Model_Attachment())->pendingForUser(['', null], 'user-a'));
    }

    // ----- linkToMessage (owner-scoped, only still-pending) ---------------------------------------

    #[Test]
    public function linkToMessage_binds_only_owned_pending_rows_and_reports_the_count(): void
    {
        $mine   = $this->seed('user-a', 'org-1');
        $theirs = $this->seed('user-b', 'org-1');

        $n = (new Agent_Model_Attachment())->linkToMessage([$mine, $theirs], 'conv-9', 'msg-9', 'user-a');
        $this->assertSame(1, $n, 'only my row is linked (the other owner is never touched)');

        $row = $this->db->fetchRow('SELECT conversation_id, message_id FROM agent_attachment WHERE attachment_id = ?', [$mine]);
        $this->assertSame('conv-9', $row['conversation_id']);
        $this->assertSame('msg-9', $row['message_id']);

        $other = $this->db->fetchRow('SELECT message_id FROM agent_attachment WHERE attachment_id = ?', [$theirs]);
        $this->assertNull($other['message_id'], "the other user's row is left pending");
    }

    #[Test]
    public function linkToMessage_with_no_ids_is_a_noop(): void
    {
        $this->assertSame(0, (new Agent_Model_Attachment())->linkToMessage([], 'c', 'm', 'user-a'));
    }

    // ----- document extraction (real containers) --------------------------------------------------

    #[Test]
    public function extractText_pulls_readable_text_from_a_real_docx(): void
    {
        if (!class_exists('ZipArchive')) { $this->markTestSkipped('ZipArchive not available'); }

        $tmp = tempnam(sys_get_temp_dir(), 'agtdocx') . '.docx';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
            . '<w:p><w:r><w:t>Hello</w:t></w:r><w:r><w:t> Tiger</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Second line</w:t></w:r></w:p>'
            . '</w:body></w:document>'
        );
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        $out = Agent_Model_Attachment::extractText('brief.docx', 'application/octet-stream', $bytes);
        $this->assertStringContainsString('Hello Tiger', $out);
        $this->assertStringContainsString('Second line', $out);
    }

    #[Test]
    public function extractText_scans_shown_strings_from_a_minimal_pdf(): void
    {
        // A tiny uncompressed PDF content stream with a single Tj text-show operator.
        $pdf = "%PDF-1.4\n"
             . "stream\n"
             . "BT /F1 12 Tf (Hello from a PDF) Tj ET\n"
             . "endstream\n"
             . "%%EOF";

        $out = Agent_Model_Attachment::extractText('paper.pdf', 'application/pdf', $pdf);
        $this->assertIsString($out);
        $this->assertStringContainsString('Hello from a PDF', $out);
    }

    #[Test]
    public function extractText_returns_null_for_a_store_only_binary_type(): void
    {
        // An epub is stored for the agent to ACT on, never parsed as text.
        $this->assertNull(Agent_Model_Attachment::extractText('novel.epub', 'application/epub+zip', 'PK\x03\x04'));
    }
}
