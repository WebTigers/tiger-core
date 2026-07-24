<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_AgentConversation;
use Tiger_Model_AgentMessage;
use Tiger_Model_AgentRun;
use Tiger_Uuid;

/**
 * The TigerAgent persistence spine (migrations 0034-0036): a conversation is org-scoped and
 * OWNER-scoped (a thread can't be read across accounts), messages are the durable transcript,
 * and a run is the audit record of one turn — opened `ok`, finalized with its action ledger,
 * token usage, and any error.
 *
 * The load-bearing invariants: ownership scoping (ownedById refuses another user's row), the
 * transcript reads oldest-first (tail-limited), and finish() persists the JSON action ledger +
 * usage without re-calling the model.
 */
#[CoversClass(Tiger_Model_AgentConversation::class)]
#[CoversClass(Tiger_Model_AgentMessage::class)]
#[CoversClass(Tiger_Model_AgentRun::class)]
final class AgentModelsTest extends IntegrationTestCase
{
    #[Test]
    public function start_recent_and_owner_scoped_load_of_a_conversation(): void
    {
        $conv  = new Tiger_Model_AgentConversation();
        $orgId = Tiger_Uuid::v7();
        $me    = Tiger_Uuid::v7();
        $other = Tiger_Uuid::v7();

        $a = $conv->start($orgId, $me, 'First thread', 'anthropic', 'claude-opus');
        $b = $conv->start($orgId, $me, str_repeat('x', 300), 'openai', 'gpt-5');   // title clamps to 191
        $conv->start($orgId, $other, 'Not mine', 'anthropic', 'claude');

        $mine = $conv->recentForUser($orgId, $me, 20);
        $this->assertCount(2, $mine, 'recentForUser is scoped to the owner');
        $ids = array_column($mine, 'conversation_id');
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);

        $titleB = $conv->findById($b)->title;
        $this->assertSame(191, mb_strlen($titleB), 'the title is clamped to 191 chars');

        $this->assertNotNull($conv->ownedById($a, $me), 'the owner can load their thread');
        $this->assertNull($conv->ownedById($a, $other), 'a foreign user cannot load it');
    }

    #[Test]
    public function recent_for_user_without_an_org_matches_across_orgs(): void
    {
        $conv = new Tiger_Model_AgentConversation();
        $me   = Tiger_Uuid::v7();
        $conv->start(Tiger_Uuid::v7(), $me, 'A', 'anthropic', 'm');
        $conv->start(Tiger_Uuid::v7(), $me, 'B', 'anthropic', 'm');

        // A blank org means "don't filter by org" — both threads come back.
        $this->assertCount(2, $conv->recentForUser('', $me, 20));
    }

    #[Test]
    public function messages_append_and_transcript_reads_oldest_first(): void
    {
        $conv = new Tiger_Model_AgentConversation();
        $msg  = new Tiger_Model_AgentMessage();
        $me   = Tiger_Uuid::v7();
        $cid  = $conv->start(Tiger_Uuid::v7(), $me, 'T', 'anthropic', 'claude');

        $msg->append($cid, Tiger_Model_AgentMessage::ROLE_USER, 'hello');
        $runId = Tiger_Uuid::v7();
        $asst  = $msg->append($cid, Tiger_Model_AgentMessage::ROLE_ASSISTANT, 'hi there', ['actions' => [['type' => 'navigate']]], $runId);

        $rows = $msg->transcript($cid, 100);
        $this->assertCount(2, $rows);
        $this->assertSame('hello', $rows[0]['content'], 'oldest message first');
        $this->assertSame('hi there', $rows[1]['content']);
        $this->assertSame($runId, $rows[1]['run_id'], 'the producing run id rides along');

        // The structured sidecar round-trips as JSON.
        $meta = json_decode((string) $msg->findById($asst)->meta, true);
        $this->assertSame('navigate', $meta['actions'][0]['type']);
    }

    #[Test]
    public function a_bare_user_message_has_a_null_run_id_and_null_meta(): void
    {
        $conv = new Tiger_Model_AgentConversation();
        $msg  = new Tiger_Model_AgentMessage();
        $cid  = $conv->start('', Tiger_Uuid::v7(), 'T', 'anthropic', 'claude');

        $id  = $msg->append($cid, Tiger_Model_AgentMessage::ROLE_USER, 'just asking');
        $row = $msg->findById($id);
        $this->assertNull($row->run_id, 'a bare user message stores no run id');
        $this->assertNull($row->meta, 'no meta means a NULL column, not "null"');
    }

    #[Test]
    public function transcript_tail_limit_returns_the_newest_but_still_oldest_first(): void
    {
        $conv = new Tiger_Model_AgentConversation();
        $msg  = new Tiger_Model_AgentMessage();
        $cid  = $conv->start('', Tiger_Uuid::v7(), 'T', 'anthropic', 'claude');

        // Back-date each so created_at strictly increases (sub-second inserts would tie otherwise).
        $t = time() - 40;
        foreach (['m1', 'm2', 'm3', 'm4'] as $i => $c) {
            $id = $msg->append($cid, Tiger_Model_AgentMessage::ROLE_USER, $c);
            $this->db->update('agent_message', ['created_at' => date('Y-m-d H:i:s', $t + $i * 10)], $this->db->quoteInto('message_id = ?', $id));
        }
        $tail = $msg->transcript($cid, 2);
        $this->assertSame(['m3', 'm4'], array_column($tail, 'content'), 'the newest 2, re-ordered oldest-first');
    }

    #[Test]
    public function a_run_opens_ok_then_finish_records_the_ledger_usage_and_status(): void
    {
        $run = new Tiger_Model_AgentRun();
        $me  = Tiger_Uuid::v7();
        $cid = Tiger_Uuid::v7();

        $id  = $run->open($cid, $me);
        $row = $run->findById($id);
        $this->assertSame(Tiger_Model_AgentRun::STATUS_OK, $row->status, 'a run opens ok');
        $this->assertSame(1, (int) $row->steps);

        $actions = [['type' => 'page.create', 'state' => 'proposed']];
        $run->finish($id, Tiger_Model_AgentRun::STATUS_BLOCKED, $actions, ['input' => 120, 'output' => 45], 'needs approval');

        $done = $run->findById($id);
        $this->assertSame(Tiger_Model_AgentRun::STATUS_BLOCKED, $done->status);
        $this->assertSame(120, (int) $done->input_tokens);
        $this->assertSame(45, (int) $done->output_tokens);
        $this->assertSame('needs approval', $done->error);
        $this->assertSame('page.create', json_decode((string) $done->actions, true)[0]['type']);
    }

    #[Test]
    public function finish_with_no_usage_or_error_leaves_those_columns_null(): void
    {
        $run = new Tiger_Model_AgentRun();
        $id  = $run->open(Tiger_Uuid::v7(), Tiger_Uuid::v7());
        $run->finish($id, Tiger_Model_AgentRun::STATUS_OK, [['type' => 'read', 'state' => 'done']]);

        $row = $run->findById($id);
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->error, 'an empty error string stores as NULL');
    }

    #[Test]
    public function a_run_is_owner_scoped(): void
    {
        $run = new Tiger_Model_AgentRun();
        $me  = Tiger_Uuid::v7();
        $id  = $run->open(Tiger_Uuid::v7(), $me);

        $this->assertNotNull($run->ownedById($id, $me));
        $this->assertNull($run->ownedById($id, Tiger_Uuid::v7()), 'another user cannot load the run');
    }
}
