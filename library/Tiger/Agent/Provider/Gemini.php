<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Provider_Gemini — Google Gemini via the Generative Language API (Google AI Studio key).
 *
 * The one common provider that ISN'T OpenAI-shaped: the system prompt is a top-level
 * `systemInstruction`, turns live in `contents` with role `model` (not `assistant`), and text is
 * nested under `parts`. Auth is an `x-goog-api-key` header. Gemini's free tier makes it a popular
 * BYO choice (mind that the free tier may train on inputs — see the settings note). Single cURL, no
 * SDK.
 *
 * @api
 */
class Tiger_Agent_Provider_Gemini implements Tiger_Agent_Provider_Adapter
{
    const BASE        = 'https://generativelanguage.googleapis.com/v1beta';
    const MAX_TOKENS  = 4096;
    const TIMEOUT     = 120;

    /**
     * Run one completion against generateContent.
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
        $model   = ltrim((string) $model, '/');
        if (strpos($model, 'models/') === 0) { $model = substr($model, 7); }
        $payload = ['contents' => $this->_contents($messages)];
        if ((string) $system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => (string) $system]]];
        }
        $payload['generationConfig'] = ['maxOutputTokens' => self::MAX_TOKENS];

        $body = $this->_post(self::BASE . '/models/' . rawurlencode($model) . ':generateContent', $payload, $apiKey);

        $text = '';
        foreach ((array) ($body['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string) ($part['text'] ?? '');
        }
        $u = (array) ($body['usageMetadata'] ?? []);
        return [
            'text'  => $text,
            'usage' => [
                'input'  => (int) ($u['promptTokenCount'] ?? 0),
                'output' => (int) ($u['candidatesTokenCount'] ?? 0),
            ],
        ];
    }

    /**
     * Live model list (GET /models), filtered to those that support generateContent; else static.
     *
     * @param  string $apiKey
     * @return array<int,array{id:string,label:string}>
     */
    public function models($apiKey = '')
    {
        if ($apiKey !== '') {
            try {
                $ch = curl_init(self::BASE . '/models?pageSize=200');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $apiKey],
                ]);
                $raw  = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                $body = json_decode((string) $raw, true);
                if ($code === 200 && !empty($body['models'])) {
                    $out = [];
                    foreach ($body['models'] as $m) {
                        $methods = (array) ($m['supportedGenerationMethods'] ?? []);
                        if (!in_array('generateContent', $methods, true)) { continue; }
                        $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                        if ($id !== '') { $out[] = ['id' => $id, 'label' => (string) ($m['displayName'] ?? $id)]; }
                    }
                    if ($out) { return $out; }
                }
            } catch (Throwable $e) { /* fall through to static */ }
        }
        $out = [];
        foreach (Tiger_Agent_Provider_Factory::staticModels('gemini') as $id) {
            $out[] = ['id' => $id, 'label' => $id];
        }
        return $out;
    }

    /** Map neutral turns to Gemini `contents`: role model|user, text under parts, first turn = user. */
    protected function _contents(array $messages)
    {
        $out = [];
        foreach ($messages as $m) {
            $content = (string) ($m['content'] ?? '');
            $role    = (($m['role'] ?? 'user') === 'assistant') ? 'model' : 'user';
            $images  = ($role === 'user') ? (array) ($m['images'] ?? []) : [];   // images only on user turns
            if ($content === '' && !$images) { continue; }

            $parts = [];
            if ($content !== '') { $parts[] = ['text' => $content]; }
            foreach ($images as $img) {
                $data = (string) ($img['data'] ?? '');
                if ($data === '') { continue; }
                $parts[] = ['inlineData' => ['mimeType' => (string) ($img['mime'] ?? 'image/png'), 'data' => $data]];
            }
            if ($out && $out[count($out) - 1]['role'] === $role) {
                $out[count($out) - 1]['parts'] = array_merge($out[count($out) - 1]['parts'], $parts);
            } else {
                $out[] = ['role' => $role, 'parts' => $parts];
            }
        }
        if ($out && $out[0]['role'] !== 'user') {
            array_unshift($out, ['role' => 'user', 'parts' => [['text' => '(continue)']]]);
        }
        return $out;
    }

    /**
     * POST JSON + the API-key header, decode, translate errors to one RuntimeException.
     *
     * @param  string $url
     * @param  array  $payload
     * @param  string $apiKey
     * @return array
     * @throws RuntimeException
     */
    protected function _post($url, array $payload, $apiKey)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
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
            throw new RuntimeException('AI provider error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        return $body;
    }
}
