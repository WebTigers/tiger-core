<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use Agent_Model_Attachment;
use Agent_Service_Agent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Crypto;
use Tiger_Model_AgentConversation;
use Tiger_Model_AgentMessage;
use Tiger_Model_AgentRun;
use Zend_Config;
use Zend_Registry;

/**
 * A subclass that exposes the protected turn helpers so they can be exercised without the live model
 * call (which the turn dispatch requires and this harness cannot stub — no seam past Factory::make).
 * Constructed with an empty message so the base constructor dispatches nothing.
 */
final class ExposedAgentService extends Agent_Service_Agent
{
    public function ctx(array $p): array { return $this->_context($p); }
    public function take(array $p): array { return $this->_takeAttachments($p); }
    public function mime(string $tmp, string $ext): string { return $this->_mime($tmp, $ext); }
    public function dom($raw): string { return $this->_domFeedback($raw); }
    public function resolve(string $id) { return $this->_resolveConversation($id); }
    public function role(): string { return $this->_role(); }
}

/**
 * Agent_Service_Agent — the aside's turn-engine `/api` service (Wave 6).
 *
 * Covered hermetically: the `_ready` gate (deny-by-default for a role below `manager`; the
 * unconfigured error when the feature is off / no key), form validation, `approve` end to end (run
 * lookup + owner scoping, ledger decode + index parsing, the Forge-gated execution loop, status
 * computation, the assistant-message meta re-sync), `conversations` + `history` (owner-scoped reads,
 * the rotated-CSRF success envelope), `uploadFile`'s guard, and the turn helpers via a subclass
 * (`_context` sanitize, `_takeAttachments` owner scoping, `_mime`, `_domFeedback`, `_resolveConversation`).
 *
 * BOUNDARY (see WAVE6-FINDINGS-agentmod.md): a SUCCESSFUL turn (`send` / `resume`) runs
 * Tiger_Agent_Loop → a live provider HTTP call, and the Loop builds its adapter through
 * Tiger_Agent_Provider_Factory::make() with no injection seam — so the turn itself is not driven here
 * (mirroring ForgeAclTest, which likewise treats the live dispatch as the boundary). `approve`'s
 * best-effort follow-up turn is kept off the network by pointing the run at a dangling conversation.
 */
#[CoversClass(Agent_Service_Agent::class)]
final class AgentServiceTest extends IntegrationTestCase
{
    use EnsuresAttachmentTable;

    private const CRYPTO_KEY = 'MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttachmentTable();
        Zend_Registry::set('tiger.auth.stateless', true);   // CSRF-immune API path (no session in CLI)
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::dropAttachmentTable();   // never leave the side-loaded table for InstallerLifecycleTest
        parent::tearDownAfterClass();
    }

    /** Switch the agent ON + connected (feature flag + an encrypted BYO key crypto can read). */
    private function enableAgent(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['crypto' => ['key' => self::CRYPTO_KEY]]], true));
        $enc = Tiger_Crypto::encrypt('sk-test-key');
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => [
                'crypto' => ['key' => self::CRYPTO_KEY],
                'agent'  => ['enabled' => '1', 'api_key_enc' => $enc, 'mode_max' => 'auto'],
            ],
        ], true));
    }

    /** Dispatch the real service and hand back the response object. */
    private function call(string $action, array $params = []): object
    {
        return (new Agent_Service_Agent(['action' => $action] + $params))->getResponse();
    }

    // ===== the _ready gate ========================================================================

    #[Test]
    public function a_role_below_manager_is_denied_every_action(): void
    {
        $this->enableAgent();
        foreach (['guest', 'user'] as $role) {
            $this->loginAs($role);
            foreach (['send', 'approve', 'resume', 'conversations', 'history', 'uploadFile'] as $action) {
                $res = $this->call($action);
                $this->assertSame(0, (int) $res->result, "{$role} denied on {$action}");
                $this->assertStringContainsString('not_allowed', json_encode($res->messages), "{$role}/{$action} denial");
            }
        }
    }

    #[Test]
    public function an_allowed_role_is_told_the_agent_is_unconfigured_when_off(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config([], true));   // feature explicitly OFF (no leaked enable)
        $this->loginAs('manager');   // may chat, but the feature is off / no key
        $res = $this->call('conversations');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('unconfigured', json_encode($res->messages));
    }

    // ===== send / resume — the validation surface (the turn itself is the live boundary) ==========

    #[Test]
    public function send_rejects_an_empty_message_with_form_errors(): void
    {
        $this->enableAgent();
        $this->loginAs('manager');
        $res = $this->call('send', ['message' => '   ']);   // trims to empty → the required validator fires
        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('message', (array) $res->form);
    }

    #[Test]
    public function resume_requires_a_conversation_id_then_scopes_to_the_owner(): void
    {
        $this->enableAgent();
        $this->loginAs('manager');

        $missing = $this->call('resume');            // no conversation_id → form error
        $this->assertSame(0, (int) $missing->result);
        $this->assertArrayHasKey('conversation_id', (array) $missing->form);

        $notMine = $this->call('resume', ['conversation_id' => 'not-a-real-conversation', 'results' => '[]']);
        $this->assertSame(0, (int) $notMine->result, 'a conversation the caller does not own is refused');
        $this->assertStringContainsString('run_missing', json_encode($notMine->messages));
    }

    // ===== conversations + history (owner-scoped reads + rotated-CSRF envelope) ====================

    #[Test]
    public function conversations_lists_the_callers_threads_newest_first_with_a_fresh_csrf(): void
    {
        $this->enableAgent();
        $this->loginAs('manager');   // user-manager / org-test
        $conv = new Tiger_Model_AgentConversation();
        $conv->start('org-test', 'user-manager', 'First thread',  'anthropic', 'claude-sonnet-5');
        $conv->start('org-test', 'user-manager', '',              'anthropic', 'claude-sonnet-5');   // titleless
        $conv->start('org-test', 'user-other',   'Someone else',  'anthropic', 'claude-sonnet-5');   // another owner

        $res = $this->call('conversations');
        $this->assertSame(1, (int) $res->result);
        $titles = array_column($res->data['conversations'], 'title');
        $this->assertContains('First thread', $titles);
        $this->assertContains('New chat', $titles, 'a titleless thread renders as "New chat"');
        $this->assertNotContains('Someone else', $titles, "another user's thread never appears");
        $this->assertArrayHasKey('_csrf', $res->data, 'every success rotates a fresh Agent CSRF token');
    }

    #[Test]
    public function history_returns_a_transcript_for_an_owned_thread_and_refuses_others(): void
    {
        $this->enableAgent();
        $this->loginAs('manager');

        $conv = new Tiger_Model_AgentConversation();
        $id   = $conv->start('org-test', 'user-manager', 'Chat', 'anthropic', 'claude-sonnet-5');
        $msg  = new Tiger_Model_AgentMessage();
        $msg->append($id, Tiger_Model_AgentMessage::ROLE_USER, 'hello');
        $msg->append($id, Tiger_Model_AgentMessage::ROLE_ASSISTANT, 'hi there', ['done' => true]);

        $res = $this->call('history', ['conversation_id' => $id]);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame($id, $res->data['conversation_id']);
        $this->assertCount(2, $res->data['messages']);
        $this->assertSame('user', $res->data['messages'][0]['role']);
        $this->assertSame('hi there', $res->data['messages'][1]['content']);
        $this->assertIsArray($res->data['messages'][1]['meta'], 'the assistant meta decodes back to an array');

        $notMine = $this->call('history', ['conversation_id' => 'nope']);
        $this->assertSame(0, (int) $notMine->result);
        $this->assertStringContainsString('run_missing', json_encode($notMine->messages));
    }

    // ===== uploadFile — the guard (the store seam is the CLI boundary; see findings) ==============

    #[Test]
    public function uploadFile_fails_cleanly_when_no_file_was_posted(): void
    {
        $this->enableAgent();
        $this->loginAs('manager');
        // is_uploaded_file() is false for anything not arriving via a real HTTP multipart POST, so the
        // CLI path always takes the guard — the store seam itself is covered by AttachmentModelTest.
        $res = $this->call('uploadFile');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('agent.file.failed', json_encode($res->messages));
    }

    // ===== approve — end to end, kept off the network =============================================

    /** Insert a run owned by $userId whose conversation_id is DANGLING (so the best-effort follow-up
     *  turn short-circuits to null before any provider call), carrying the given action ledger. */
    private function seedRun(string $userId, array $ledger): string
    {
        return (new Tiger_Model_AgentRun())->insert([
            'conversation_id' => 'dangling-conversation-' . substr(md5($userId . microtime()), 0, 8),
            'user_id'         => $userId,
            'status'          => Tiger_Model_AgentRun::STATUS_BLOCKED,
            'steps'           => 1,
            'actions'         => json_encode($ledger),
        ]);
    }

    #[Test]
    public function approve_requires_a_run_id_and_a_real_owned_run(): void
    {
        $this->enableAgent();
        $this->loginAs('admin');

        $noId = $this->call('approve');   // form: run_id required
        $this->assertSame(0, (int) $noId->result);
        $this->assertArrayHasKey('run_id', (array) $noId->form);

        $missing = $this->call('approve', ['run_id' => 'does-not-exist']);
        $this->assertSame(0, (int) $missing->result);
        $this->assertStringContainsString('run_missing', json_encode($missing->messages));
    }

    #[Test]
    public function approve_treats_a_run_with_a_non_array_ledger_as_missing(): void
    {
        $this->enableAgent();
        $this->loginAs('admin');
        $runId = (new Tiger_Model_AgentRun())->insert([
            'conversation_id' => 'dangling', 'user_id' => 'user-admin',
            'status' => Tiger_Model_AgentRun::STATUS_BLOCKED, 'steps' => 1, 'actions' => null,
        ]);
        $res = $this->call('approve', ['run_id' => $runId, 'all' => '1']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('run_missing', json_encode($res->messages));
    }

    #[Test]
    public function approve_with_nothing_targeted_leaves_the_proposal_blocked(): void
    {
        $this->enableAgent();
        $this->loginAs('admin');
        $ledger = [[
            'type' => 'file', 'status' => 'proposed', 'summary' => 'Write demo/x.phtml',
            'action' => ['type' => 'file', 'path' => 'demo/x.phtml', 'contents' => '<h1>x</h1>', 'reason' => 'r'],
        ]];
        $runId = $this->seedRun('user-admin', $ledger);

        // A JSON-array index that matches nothing → the proposal is never executed and stays blocked.
        $res = $this->call('approve', ['run_id' => $runId, 'indexes' => '[9]']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame(Tiger_Model_AgentRun::STATUS_BLOCKED, $res->data['status']);
        $this->assertSame('proposed', $res->data['actions'][0]['status'], 'the untargeted action is untouched');
        $this->assertNull($res->data['follow'], 'no follow-up turn when nothing executed');
    }

    #[Test]
    public function approve_runs_the_targeted_action_through_the_forge_and_resyncs_the_message(): void
    {
        $this->enableAgent();
        $this->loginAs('admin');   // admin lacks the superadmin `file` tier → the Forge returns 'denied'

        $ledger = [[
            'type' => 'file', 'status' => 'proposed', 'summary' => 'Write demo/x.phtml',
            'action' => ['type' => 'file', 'path' => 'demo/x.phtml', 'contents' => '<h1>x</h1>', 'reason' => 'r'],
        ]];
        $runId = $this->seedRun('user-admin', $ledger);

        // Mirror the assistant turn's chip meta, so the re-sync (meta.status) has a row to update.
        $convId = (string) (new Tiger_Model_AgentRun())->ownedById($runId, 'user-admin')->conversation_id;
        $msg    = new Tiger_Model_AgentMessage();
        $msgId  = $msg->append($convId, Tiger_Model_AgentMessage::ROLE_ASSISTANT, 'proposing', ['actions' => $ledger, 'status' => 'blocked'], $runId);

        $res = $this->call('approve', ['run_id' => $runId, 'all' => '1']);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame('denied', $res->data['actions'][0]['status'], 'the Forge ran the action and gated it by ACL');
        $this->assertSame(Tiger_Model_AgentRun::STATUS_OK, $res->data['status'], 'a denied (not errored/blocked) action → ok');

        // The run ledger + the mirrored message meta were both rewritten to the executed outcome.
        $run = (new Tiger_Model_AgentRun())->ownedById($runId, 'user-admin');
        $this->assertStringContainsString('denied', (string) $run->actions);

        $metaRow = $this->db->fetchOne('SELECT meta FROM agent_message WHERE message_id = ?', [$msgId]);
        $meta    = json_decode((string) $metaRow, true);
        $this->assertSame(Tiger_Model_AgentRun::STATUS_OK, $meta['status'], 'the assistant chip meta re-synced to ok');
    }

    // ===== the turn helpers (exposed subclass, no live model) =====================================

    private function exposed(): ExposedAgentService
    {
        $this->enableAgent();
        return new ExposedAgentService([]);   // empty message → the base constructor dispatches nothing
    }

    #[Test]
    public function context_sanitizes_the_path_and_the_declared_editable_targets(): void
    {
        $this->loginAs('manager');
        $svc = $this->exposed();

        $ctx = $svc->ctx(['context' => json_encode(['path' => '/admin/foo', 'targets' => [
            ['name' => 'ti tle!!', 'label' => 'The Title', 'kind' => 'html'],   // name sanitized, kind kept
            ['label' => 'no name'],                                             // dropped (no name)
            ['name' => 'body', 'kind' => 'weird'],                             // unknown kind → text
        ]])]);

        $this->assertSame('/admin/foo', $ctx['path']);
        $this->assertCount(2, $ctx['targets']);
        $this->assertSame('title', $ctx['targets'][0]['name'], 'spaces + punctuation stripped from the target name');
        $this->assertSame('html', $ctx['targets'][0]['kind']);
        $this->assertSame('text', $ctx['targets'][1]['kind'], 'an unknown kind falls back to text');
    }

    #[Test]
    public function context_rejects_a_non_path_and_accepts_an_array_envelope(): void
    {
        $this->loginAs('manager');
        $svc = $this->exposed();
        // A path with a query string doesn't match the strict pattern → dropped to ''.
        $this->assertSame('', $svc->ctx(['context' => ['path' => '/x?y=1']])['path']);
        $this->assertSame([], $svc->ctx([])['targets'], 'no context → empty targets');
    }

    #[Test]
    public function takeAttachments_resolves_only_the_callers_pending_ids(): void
    {
        $this->loginAs('manager');
        $svc = $this->exposed();

        $am   = new Agent_Model_Attachment();
        $mine = $am->insert(['conversation_id' => null, 'message_id' => null, 'user_id' => 'user-manager',
            'org_id' => 'org-test', 'disk' => 'local', 'filename' => 'a.txt', 'mime_type' => 'text/plain',
            'file_size' => 3, 'kind' => 'file', 'storage_key' => 'agent/x/a.txt', 'extract' => 'body text']);
        $theirs = $am->insert(['conversation_id' => null, 'message_id' => null, 'user_id' => 'user-other',
            'org_id' => 'org-test', 'disk' => 'local', 'filename' => 'b.txt', 'mime_type' => 'text/plain',
            'file_size' => 3, 'kind' => 'file']);

        $out = $svc->take(['attachment_ids' => "{$mine}, {$theirs}"]);
        $this->assertSame([$mine], $out['ids'], 'only my pending row resolves');
        $this->assertCount(1, $out['loop']);
        $this->assertSame('a.txt', $out['loop'][0]['filename']);
        $this->assertSame('body text', $out['loop'][0]['extract']);

        $this->assertSame(['ids' => [], 'loop' => []], $svc->take(['attachment_ids' => '  ']), 'blank → nothing');
    }

    #[Test]
    public function mime_detects_from_content_then_falls_back_to_the_extension_map(): void
    {
        $this->loginAs('manager');
        $svc = $this->exposed();

        $tmp = tempnam(sys_get_temp_dir(), 'agmime');
        file_put_contents($tmp, "plain text content\n");
        $this->assertSame('text/plain', $svc->mime($tmp, 'txt'), 'finfo detects text/plain');
        @unlink($tmp);

        // A path finfo can't read + a known extension → the extension map answers.
        $this->assertSame('application/json', $svc->mime('/no/such/file', 'json'));
        $this->assertSame('application/octet-stream', $svc->mime('/no/such/file', 'unknownext'));
    }

    #[Test]
    public function domFeedback_formats_reads_writes_and_failures(): void
    {
        $this->loginAs('manager');
        $svc = $this->exposed();

        $out = $svc->dom(json_encode([
            ['target' => 'headline', 'ok' => 1, 'kind' => 'text', 'content' => 'current copy'],
            ['target' => 'missing',  'ok' => 0, 'error' => 'not on the page'],
            ['target' => 'body',     'ok' => 1],   // no content key → an editor write
        ]));

        $this->assertStringContainsString('current content', $out);
        $this->assertStringContainsString('current copy', $out);
        $this->assertStringContainsString('FAILED — not on the page', $out);
        $this->assertStringContainsString('updated in the editor', $out);

        $this->assertStringContainsString('(none)', $svc->dom('not-json'), 'unparseable results degrade gracefully');
    }

    #[Test]
    public function resolveConversation_loads_an_owned_thread_else_starts_a_fresh_one(): void
    {
        $this->loginAs('manager');
        $svc = $this->exposed();

        $fresh = $svc->resolve('');
        $id    = (string) $fresh->conversation_id;
        $this->assertNotSame('', $id, 'an empty id starts a new thread');

        $again = $svc->resolve($id);
        $this->assertSame($id, (string) $again->conversation_id, 'an owned id loads the same thread');

        $unowned = $svc->resolve('someone-elses-id');
        $this->assertNotSame('someone-elses-id', (string) $unowned->conversation_id, 'an un-owned id starts a new thread, never leaks');

        $this->assertSame('manager', $svc->role(), 'the acting role comes from the identity');
    }
}
