<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Provider_Anthropic — the Anthropic Messages API adapter (the reference).
 *
 * The first provider adapter, and the one every other targets for parity. It maps Tiger's
 * neutral message list onto the Messages API (`system` is a top-level field, not a message;
 * roles alternate user/assistant), calls it with the org's BYO `x-api-key`, and returns the
 * concatenated text blocks + token usage. No SDK, no Composer dep — a single cURL POST, so
 * it works on stock cPanel PHP (DEPENDENCIES.md: keep the runtime deps tiny and pure).
 *
 * @api
 */
class Tiger_Agent_Provider_Anthropic implements Tiger_Agent_Provider_Adapter
{
    const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
    const ENDPOINT_MODELS = 'https://api.anthropic.com/v1/models';
    const API_VERSION     = '2023-06-01';
    const MAX_TOKENS      = 4096;
    const TIMEOUT         = 120;

    /**
     * Run one completion against the Anthropic Messages API.
     *
     * @param  string $system   the system prompt
     * @param  array  $messages neutral turns [{role, content}, …]
     * @param  string $model    the model id (e.g. claude-sonnet-5)
     * @param  string $apiKey   the org's BYO key
     * @return array{text:string,usage:array{input:int,output:int}}
     * @throws RuntimeException on transport or API error
     */
    public function complete($system, array $messages, $model, $apiKey)
    {
        $body = $this->_post($this->_payload($system, $messages, $model), $apiKey);

        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        // Total input = fresh + cache-write + cache-read (for the cost view). A cache HIT bills the
        // read tokens at ~10%, which is the whole point — the static system prompt isn't re-processed.
        $u = (array) ($body['usage'] ?? []);
        return [
            'text'  => $text,
            'usage' => [
                'input'  => (int) ($u['input_tokens'] ?? 0)
                          + (int) ($u['cache_read_input_tokens'] ?? 0)
                          + (int) ($u['cache_creation_input_tokens'] ?? 0),
                'output' => (int) ($u['output_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Build the request body. The system prompt (Tiger identity + the whole role-filtered /api
     * catalog + the read-tool docs) is LARGE and identical across a turn's multi-step loop and
     * across repeat turns — so it's marked with `cache_control: ephemeral`. Anthropic then reuses
     * that prefix for ~5 minutes at ~10% cost instead of re-billing + re-processing it on every
     * call. This is the main lever that keeps a long, multi-step conversation from paying full
     * freight for a context that barely changes (see TIGERAGENT.md §5b).
     *
     * @param  string $system   the system prompt
     * @param  array  $messages neutral turns
     * @param  string $model    the model id
     * @return array            the Messages API request body
     */
    protected function _payload($system, array $messages, $model)
    {
        return [
            'model'      => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => [[
                'type'          => 'text',
                'text'          => (string) $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages'   => $this->_mapMessages($messages),
        ];
    }

    /**
     * The Anthropic models — live from GET /v1/models when a key is given, else the curated static
     * fallback. Never throws (a failed fetch degrades to static).
     *
     * @param  string $apiKey optional BYO key
     * @return array<int,array{id:string,label:string}>
     */
    public function models($apiKey = '')
    {
        if ($apiKey !== '') {
            try {
                $ch = curl_init(self::ENDPOINT_MODELS . '?limit=100');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => ['x-api-key: ' . $apiKey, 'anthropic-version: ' . self::API_VERSION],
                ]);
                $raw  = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                $body = json_decode((string) $raw, true);
                if ($code === 200 && !empty($body['data'])) {
                    $out = [];
                    foreach ($body['data'] as $m) {
                        if (!empty($m['id'])) {
                            $out[] = ['id' => (string) $m['id'], 'label' => (string) ($m['display_name'] ?? $m['id'])];
                        }
                    }
                    if ($out) {
                        return $out;
                    }
                }
            } catch (Throwable $e) {
                // fall through to static
            }
        }
        $out = [];
        foreach (Tiger_Agent_Provider_Factory::staticModels('anthropic') as $id) {
            $out[] = ['id' => $id, 'label' => $id];
        }
        return $out;
    }

    /**
     * Collapse Tiger's neutral messages into the API's strict alternation. Consecutive
     * same-role turns are merged (the API rejects two `user` messages in a row), and any
     * non user/assistant role (a `tool` result we replayed) is folded in as a user turn.
     *
     * @param  array $messages neutral turns
     * @return array           Anthropic-shaped messages
     */
    protected function _mapMessages(array $messages)
    {
        $out = [];
        foreach ($messages as $m) {
            $role    = (($m['role'] ?? 'user') === 'assistant') ? 'assistant' : 'user';
            $content = (string) ($m['content'] ?? '');
            if ($content === '') {
                continue;
            }
            if ($out && $out[count($out) - 1]['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n" . $content;
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }
        // The API requires the first message to be a user turn.
        if ($out && $out[0]['role'] !== 'user') {
            array_unshift($out, ['role' => 'user', 'content' => '(continue)']);
        }
        return $out;
    }

    /**
     * POST the payload and decode the JSON body, translating transport + API errors into a
     * single RuntimeException the Loop can surface cleanly.
     *
     * @param  array  $payload the request body
     * @param  string $apiKey  the BYO key
     * @return array            the decoded response body
     * @throws RuntimeException
     */
    protected function _post(array $payload, $apiKey)
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach the AI provider: ' . $err);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new RuntimeException('The AI provider returned an unreadable response.');
        }
        if ($code < 200 || $code >= 300) {
            $msg = $body['error']['message'] ?? ('HTTP ' . $code);
            throw new RuntimeException('AI provider error: ' . $msg);
        }
        return $body;
    }
}
