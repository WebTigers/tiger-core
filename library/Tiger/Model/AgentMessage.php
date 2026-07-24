<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Model_AgentMessage — one message in an agent conversation (see migration 0035).
 *
 * The durable transcript that mirrors the in-session working copy (TIGERAGENT.md §5): the
 * live turn runs in the request and is held in session for snappy re-render, but every
 * message is also written here so history survives a new session or a second device. The
 * structured payload from an assistant turn (parsed actions + navigate + done) rides in
 * `meta` as JSON so an old turn's action chips re-render on reload without re-execution.
 *
 * @api
 */
class Tiger_Model_AgentMessage extends Tiger_Model_Table
{
    protected $_name    = 'agent_message';
    protected $_primary = 'message_id';

    const ROLE_USER      = 'user';
    const ROLE_ASSISTANT = 'assistant';
    const ROLE_TOOL      = 'tool';
    const ROLE_SYSTEM    = 'system';

    /**
     * Append a message to a conversation and return its id.
     *
     * @param  string     $conversationId the thread
     * @param  string     $role           one of the ROLE_* constants
     * @param  string     $content        the prose
     * @param  array|null $meta           optional structured sidecar (encoded to JSON)
     * @param  string     $runId          the turn that produced it ('' for a bare user message)
     * @return string                     the new message_id
     */
    public function append($conversationId, $role, $content, ?array $meta = null, $runId = '')
    {
        return $this->insert([
            'conversation_id' => (string) $conversationId,
            'run_id'          => ($runId !== '' ? (string) $runId : null),
            'role'            => (string) $role,
            'content'         => (string) $content,
            'meta'            => ($meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null),
        ]);
    }

    /**
     * The transcript of a conversation, oldest first.
     *
     * @param  string $conversationId the thread
     * @param  int    $limit          max messages (the tail is what matters for context)
     * @return array                  plain rows (assoc), oldest first
     */
    public function transcript($conversationId, $limit = 100)
    {
        // Pull the newest N, then reverse to oldest-first so context reads in order.
        $rows = $this->fetchAll(
            $this->activeSelect()
                ->where('conversation_id = ?', (string) $conversationId)
                ->order('created_at DESC')
                ->order('message_id DESC')
                ->limit(max(1, (int) $limit))
        )->toArray();
        return array_reverse($rows);
    }
}
