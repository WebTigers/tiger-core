<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Agent_Service_Agent — the /api service the aside talks to (TIGERAGENT.md §4, §5).
 *
 * This is the browser-facing half of a turn: the aside POSTs a message here, the service
 * resolves/creates the conversation, runs one Tiger_Agent_Loop turn as the signed-in user,
 * and returns the structured result (say + the action ledger + navigate + done). Writes the
 * model proposes come back `proposed`; the human approves them with `approve`, which executes
 * just those actions through the Forge — no second model call. Everything is owner-scoped, so
 * a user can only ever see and drive their own conversations.
 *
 * @api
 */
class Agent_Service_Agent extends Tiger_Service_Service
{
    /**
     * Run a turn: send a message and get the agent's response + any proposed actions.
     *
     * @param  array $params conversation_id (optional), message, context (JSON, optional)
     * @return void
     */
    public function send(array $params): void
    {
        if (!$this->_ready()) { return; }

        $form = new Agent_Form_Send();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }

        $text = trim((string) $form->getValue('message'));
        if ($text === '') { $this->_error('agent.error.empty'); return; }

        try {
            $conversation = $this->_resolveConversation((string) ($params['conversation_id'] ?? ''));
            $context      = $this->_context($params);
            $mode         = Tiger_Agent::clampMode((string) ($params['mode'] ?? 'ask'));

            $loop   = new Tiger_Agent_Loop($this->_role(), (string) $this->_user_id, (string) $this->_org_id);
            $result = $loop->run($conversation, $text, $context, $mode);

            $this->_success(array_merge($result, [
                'conversation_id' => (string) $conversation->conversation_id,
            ]), 'agent.turn.ok');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'agent.error.provider');
        }
    }

    /**
     * Approve (and run) proposed write actions from an earlier turn. Executes only the named
     * ledger entries through the Forge, re-checking the ACL, and rewrites the run ledger.
     *
     * @param  array $params run_id, and `indexes` (array of ledger positions) or `all`
     * @return void
     */
    public function approve(array $params): void
    {
        if (!$this->_ready()) { return; }

        $form = new Agent_Form_Approve();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }

        $runId = (string) $params['run_id'];
        $runs  = new Tiger_Model_AgentRun();
        $run   = $runs->ownedById($runId, (string) $this->_user_id);
        if (!$run) { $this->_error('agent.error.run_missing'); return; }

        $ledger = json_decode((string) $run->actions, true);
        if (!is_array($ledger)) { $this->_error('agent.error.run_missing'); return; }

        $wantAll = !empty($params['all']);
        $rawIdx  = $params['indexes'] ?? [];
        if (is_string($rawIdx)) {
            $decoded = json_decode($rawIdx, true);
            $rawIdx  = is_array($decoded) ? $decoded : array_filter(explode(',', $rawIdx), 'strlen');
        }
        $indexes = array_map('intval', (array) $rawIdx);

        $mode = Tiger_Agent::clampMode((string) ($params['mode'] ?? 'ask'));

        try {
            $forge    = new Tiger_Agent_Forge($this->_role());
            $executed = [];
            $blocked  = false;
            $errored  = false;

            foreach ($ledger as $i => $entry) {
                $isTarget = $wantAll || in_array((int) $i, $indexes, true);
                if (!$isTarget || ($entry['status'] ?? '') !== 'proposed') {
                    if (($entry['status'] ?? '') === 'proposed') { $blocked = true; }
                    continue;
                }
                $action = (array) ($entry['action'] ?? []);
                $action['approved'] = true;
                $result = $forge->execute($action);
                unset($result['feedback']);
                $ledger[$i] = $result;
                $executed[] = $result;
                if (($result['status'] ?? '') === 'error')    { $errored = true; }
                if (($result['status'] ?? '') === 'proposed') { $blocked = true; }
            }

            $status = $blocked ? Tiger_Model_AgentRun::STATUS_BLOCKED
                    : ($errored ? Tiger_Model_AgentRun::STATUS_PARTIAL : Tiger_Model_AgentRun::STATUS_OK);
            $runs->finish($runId, $status, $ledger);
            $this->_syncMessageMeta($runId, $ledger, $status);

            // Report back: hand the AI what just ran so it gives its closing word — or takes the
            // next step (which may itself propose more, continuing the conversation naturally).
            $follow = $this->_followUp($run, $executed, $params, $mode);

            $this->_success(['run_id' => $runId, 'actions' => $ledger, 'status' => $status, 'follow' => $follow], 'agent.approve.ok');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * The current user's recent conversations (for the aside's thread switcher).
     *
     * @param  array $params unused
     * @return void
     */
    public function conversations(array $params): void
    {
        if (!$this->_ready()) { return; }
        $rows = (new Tiger_Model_AgentConversation())->recentForUser((string) $this->_org_id, (string) $this->_user_id, 20);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = [
                'conversation_id' => $r['conversation_id'],
                'title'           => $r['title'] !== '' ? $r['title'] : 'New chat',
                'updated'         => substr((string) ($r['updated_at'] ?: $r['created_at']), 0, 16),
            ];
        }
        $this->_success(['conversations' => $out], 'core.api.success');
    }

    /**
     * The transcript of one conversation (owner-scoped), for the aside to render on load.
     *
     * @param  array $params conversation_id
     * @return void
     */
    public function history(array $params): void
    {
        if (!$this->_ready()) { return; }
        $id   = (string) ($params['conversation_id'] ?? '');
        $conv = (new Tiger_Model_AgentConversation())->ownedById($id, (string) $this->_user_id);
        if (!$conv) { $this->_error('agent.error.run_missing'); return; }

        $rows = (new Tiger_Model_AgentMessage())->transcript($id, 200);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = [
                'role'    => $r['role'],
                'content' => (string) $r['content'],
                'meta'    => $r['meta'] ? json_decode((string) $r['meta'], true) : null,
                'at'      => substr((string) $r['created_at'], 0, 16),
            ];
        }
        $this->_success(['conversation_id' => $id, 'messages' => $out], 'core.api.success');
    }

    // ----- helpers -----------------------------------------------------------

    /** The agent must be enabled, connected, and the caller allowed. */
    protected function _ready(): bool
    {
        if (!$this->_isAdmin(Tiger_Agent::RESOURCE_CHAT, 'send')) {
            $this->_error('core.api.error.not_allowed');
            return false;
        }
        if (!Tiger_Agent::isEnabled() || !Tiger_Agent::isConnected()) {
            $this->_error('agent.error.unconfigured');
            return false;
        }
        return true;
    }

    /** The acting role from the authenticated identity. */
    protected function _role(): string
    {
        return (string) ($this->_identity->role ?? 'guest');
    }

    /**
     * Load the requested conversation (owner-scoped) or start a fresh one.
     *
     * @param  string $id the requested conversation_id ('' to start new)
     * @return Zend_Db_Table_Row_Abstract
     */
    protected function _resolveConversation($id)
    {
        $model = new Tiger_Model_AgentConversation();
        if ($id !== '') {
            $row = $model->ownedById($id, (string) $this->_user_id);
            if ($row) { return $row; }
        }
        $newId = $model->start((string) $this->_org_id, (string) $this->_user_id, '', Tiger_Agent::provider(), Tiger_Agent::model());
        return $model->ownedById($newId, (string) $this->_user_id);
    }

    /**
     * Sanitize the client-supplied context envelope (current path, page id, selection).
     *
     * @param  array $params the request
     * @return array         a small, safe context block
     */
    protected function _context(array $params): array
    {
        $raw = $params['context'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $path = (string) ($raw['path'] ?? '');

        // The page's declared editable targets (data-agent-target) — sanitized names + kinds.
        $targets = [];
        foreach ((array) ($raw['targets'] ?? []) as $t) {
            if (!is_array($t) || empty($t['name'])) { continue; }
            $targets[] = [
                'name'  => preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string) $t['name']),
                'label' => mb_substr((string) ($t['label'] ?? ''), 0, 80),
                'kind'  => in_array(($t['kind'] ?? ''), ['text', 'html', 'code'], true) ? (string) $t['kind'] : 'text',
            ];
            if (count($targets) >= 40) { break; }
        }

        return [
            'path'    => (preg_match('#^/[\w\-/.]*$#', $path) ? $path : ''),
            'targets' => $targets,
        ];
    }

    /**
     * Resume a turn with the browser's DOM results (the client leg of the loop, TIGERAGENT.md §5c):
     * tiger.agent.js executed a dom.read/dom.write and posts the outcome here; we feed it to the
     * model so it can continue (e.g. read → rewrite).
     *
     * @param  array $params conversation_id, results (JSON), context, mode
     * @return void
     */
    public function resume(array $params): void
    {
        if (!$this->_ready()) { return; }

        $form = new Agent_Form_Resume();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }

        $conv = (new Tiger_Model_AgentConversation())->ownedById((string) $params['conversation_id'], (string) $this->_user_id);
        if (!$conv) { $this->_error('agent.error.run_missing'); return; }

        $mode     = Tiger_Agent::clampMode((string) ($params['mode'] ?? 'ask'));
        $context  = $this->_context($params);
        $feedback = $this->_domFeedback($params['results'] ?? '');

        try {
            $loop   = new Tiger_Agent_Loop($this->_role(), (string) $this->_user_id, (string) $this->_org_id);
            $result = $loop->followUp($conv, $feedback, $context, $mode);
            $this->_success(array_merge($result, ['conversation_id' => (string) $conv->conversation_id]), 'agent.turn.ok');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'agent.error.provider');
        }
    }

    /**
     * Turn the browser's DOM results into the model-facing feedback text.
     *
     * @param  mixed $raw the posted results (JSON string or array)
     * @return string
     */
    protected function _domFeedback($raw): string
    {
        $results = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($results)) { return "[DOM results]\n(none)"; }

        $lines = [];
        foreach ($results as $r) {
            if (!is_array($r)) { continue; }
            $target = (string) ($r['target'] ?? '?');
            if (empty($r['ok'])) {
                $lines[] = 'target "' . $target . '": FAILED — ' . (string) ($r['error'] ?? 'not found on the page');
                continue;
            }
            if (array_key_exists('content', $r)) {
                $content = (string) $r['content'];
                if (mb_strlen($content) > 20000) { $content = mb_substr($content, 0, 20000) . "\n…(truncated)"; }
                $lines[] = 'target "' . $target . '" (' . (string) ($r['kind'] ?? 'text') . ") current content:\n```\n" . $content . "\n```";
            } else {
                $lines[] = 'target "' . $target . '": updated in the editor.';
            }
        }
        return "[DOM results — act on these, then continue or finish]\n\n" . implode("\n\n", $lines);
    }

    /**
     * Run a follow-up turn after an approval: tell the model what executed so it can report to
     * the user (or continue). Best-effort — the approval already succeeded, so a provider hiccup
     * here just means no closing message.
     *
     * @param  Zend_Db_Table_Row_Abstract $run      the approved run
     * @param  array                      $executed the freshly executed ledger entries
     * @param  array                      $params   the request (for context + mode)
     * @param  string                     $mode     the effective mode
     * @return array|null                           the follow-up turn result, or null
     */
    protected function _followUp($run, array $executed, array $params, $mode)
    {
        if (!$executed) { return null; }
        $lines = array_map(static function ($e) {
            return '- [' . ($e['status'] ?? '') . '] ' . ($e['summary'] ?? '');
        }, $executed);
        $feedback = "[the user approved — these just ran]\n" . implode("\n", $lines)
                  . "\nTell the user what happened. If more steps remain, take them; otherwise set done:true.";

        try {
            $conv = (new Tiger_Model_AgentConversation())->ownedById((string) $run->conversation_id, (string) $this->_user_id);
            if (!$conv) { return null; }
            $loop = new Tiger_Agent_Loop($this->_role(), (string) $this->_user_id, (string) $this->_org_id);
            return $loop->followUp($conv, $feedback, $this->_context($params), $mode);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * After an approval, mirror the updated ledger into the assistant message's meta so a
     * reload re-renders the now-executed chips (not the stale `proposed` ones).
     *
     * @param  string $runId  the run
     * @param  array  $ledger the updated action ledger
     * @param  string $status the run status
     * @return void
     */
    protected function _syncMessageMeta($runId, array $ledger, $status): void
    {
        try {
            $m   = new Tiger_Model_AgentMessage();
            $row = $m->fetchRow($m->activeSelect()->where('run_id = ?', $runId)->where('role = ?', Tiger_Model_AgentMessage::ROLE_ASSISTANT));
            if (!$row) { return; }
            $meta = json_decode((string) $row->meta, true) ?: [];
            $meta['actions'] = $ledger;
            $meta['status']  = $status;
            $m->update(
                ['meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                $m->getAdapter()->quoteInto('message_id = ?', $row->message_id)
            );
        } catch (Throwable $e) { /* cosmetic re-render sync — never fail approval over it */ }
    }

    /**
     * Stamp a FRESH CSRF token onto every success response. The agent is a long-lived AJAX panel that
     * makes many /api calls from one page, but a Zend hash token expires by hops after ~1 use — so a
     * single rendered token can't carry a whole conversation. Rotating one per response (the client
     * adopts it for the next call, see tiger.agent.js) keeps the panel alive without a reload.
     *
     * @param  mixed       $data
     * @param  string      $message
     * @param  string|null $redirect
     * @return void
     */
    protected function _success($data = null, $message = 'core.api.success', $redirect = null)
    {
        if (is_array($data)) { $data['_csrf'] = $this->_freshToken(); }
        parent::_success($data, $message, $redirect);
    }

    /**
     * Mint (and rotate into the session) a fresh shared-salt 'Agent' CSRF token. Rendering a new
     * Agent_Form_Send hash element generates a new hash, stores it under the shared salt, and resets
     * its hop expiry — so the value returned validates on the next send / approve / resume.
     *
     * @return string the fresh token, or '' if it couldn't be minted
     */
    protected function _freshToken(): string
    {
        try {
            $el = (new Agent_Form_Send())->getElement('_csrf');
            $el->setView(new Zend_View());
            $el->render();
            return (string) $el->getValue();
        } catch (Throwable $e) {
            return '';
        }
    }
}
