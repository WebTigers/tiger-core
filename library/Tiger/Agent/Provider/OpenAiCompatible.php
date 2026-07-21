<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Provider_OpenAiCompatible — the base adapter for every provider that speaks the OpenAI
 * `/chat/completions` wire format.
 *
 * That's most of the market: OpenAI itself, xAI (Grok), Groq, Mistral, DeepSeek, OpenRouter, Together,
 * Fireworks, and a local Ollama all accept the same request (`Authorization: Bearer`, a `messages`
 * array with a leading `system` turn) and return the same `choices[0].message.content` + `usage`
 * shape. So a concrete provider is a few lines — the base URL, a label, and a curated model fallback;
 * this class does the transport. Single cURL POST, no SDK (works on stock cPanel PHP).
 *
 * @api
 */
abstract class Tiger_Agent_Provider_OpenAiCompatible implements Tiger_Agent_Provider_Adapter
{
    const MAX_TOKENS = 4096;
    const TIMEOUT    = 120;

    /** The API base, no trailing slash (e.g. https://api.openai.com/v1). */
    abstract protected function _base();

    /** The provider's roster key (for the static model fallback via the Factory). */
    abstract protected function _providerKey();

    /** Request headers; override to add provider extras (e.g. OpenRouter attribution). */
    protected function _headers($apiKey)
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    }

    /** The output-token limit field. Most OpenAI-compatible APIs use `max_tokens`; OpenAI's own newer
     *  models (gpt-5 / o-series) reject it and require `max_completion_tokens` — overridden per adapter. */
    protected function _maxTokensField()
    {
        return 'max_tokens';
    }

    /**
     * Run one completion against a chat/completions endpoint.
     *
     * @param  string $system
     * @param  array  $messages provider-neutral [{role, content}, …]
     * @param  string $model
     * @param  string $apiKey
     * @return array{text:string,usage:array{input:int,output:int}}
     * @throws RuntimeException
     */
    public function complete($system, array $messages, $model, $apiKey)
    {
        $turns = [];
        if ((string) $system !== '') { $turns[] = ['role' => 'system', 'content' => (string) $system]; }
        foreach ($messages as $m) {
            $content = (string) ($m['content'] ?? '');
            $role    = (($m['role'] ?? 'user') === 'assistant') ? 'assistant' : 'user';
            $images  = ($role === 'user') ? (array) ($m['images'] ?? []) : [];   // images only on user turns
            if ($content === '' && !$images) { continue; }

            if ($images) {
                // Multimodal turn: content becomes an array of parts (OpenAI vision wire format).
                $parts = [];
                if ($content !== '') { $parts[] = ['type' => 'text', 'text' => $content]; }
                foreach ($images as $img) {
                    $data = (string) ($img['data'] ?? '');
                    if ($data === '') { continue; }
                    $mime = (string) ($img['mime'] ?? 'image/png');
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $data]];
                }
                $turns[] = ['role' => $role, 'content' => $parts];
            } else {
                $turns[] = ['role' => $role, 'content' => $content];
            }
        }

        $body = $this->_post($this->_base() . '/chat/completions', [
            'model'                  => $model,
            'messages'               => $turns,
            $this->_maxTokensField() => self::MAX_TOKENS,
        ], $this->_headers($apiKey));

        $text = (string) ($body['choices'][0]['message']['content'] ?? '');
        $u    = (array) ($body['usage'] ?? []);
        return [
            'text'  => $text,
            'usage' => [
                'input'  => (int) ($u['prompt_tokens'] ?? 0),
                'output' => (int) ($u['completion_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Live model list from GET {base}/models (the OpenAI shape), else the curated static fallback.
     *
     * @param  string $apiKey
     * @return array<int,array{id:string,label:string}>
     */
    public function models($apiKey = '')
    {
        if ($apiKey !== '') {
            try {
                $ch = curl_init($this->_base() . '/models');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => $this->_headers($apiKey),
                ]);
                $raw  = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                $body = json_decode((string) $raw, true);
                if ($code === 200 && !empty($body['data'])) {
                    $out = [];
                    foreach ($body['data'] as $m) {
                        if (!empty($m['id'])) { $out[] = ['id' => (string) $m['id'], 'label' => (string) $m['id']]; }
                    }
                    if ($out) {
                        usort($out, static function ($a, $b) { return strcmp($a['id'], $b['id']); });
                        return $out;
                    }
                }
            } catch (Throwable $e) { /* fall through to static */ }
        }
        $out = [];
        foreach (Tiger_Agent_Provider_Factory::staticModels($this->_providerKey()) as $id) {
            $out[] = ['id' => $id, 'label' => $id];
        }
        return $out;
    }

    /**
     * POST JSON and decode, turning transport + API errors into one RuntimeException.
     *
     * @param  string $url
     * @param  array  $payload
     * @param  array  $headers
     * @return array
     * @throws RuntimeException
     */
    protected function _post($url, array $payload, array $headers)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
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
            $msg = $body['error']['message'] ?? ($body['error'] ?? ('HTTP ' . $code));
            throw new RuntimeException('AI provider error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        return $body;
    }
}
