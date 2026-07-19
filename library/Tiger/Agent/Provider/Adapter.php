<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Provider_Adapter — the contract every AI provider adapter implements.
 *
 * "Multi-agent" in TigerAgent means multi-*provider*: Anthropic, OpenAI, a local Ollama —
 * each a thin adapter behind this one interface (TIGERAGENT.md §7). The adapter's only job
 * is transport: take a system prompt + a normalized message list, call the provider's API
 * with the org's BYO key, and hand back the model's raw text + token usage. It knows nothing
 * about the response contract, the Forge, or the ACL — the Loop owns all of that. Swapping
 * providers therefore changes the wire format only, never how Tiger reasons about a turn.
 *
 * Messages are the provider-neutral shape `[{role:'user'|'assistant', content:'…'}, …]`.
 *
 * @api
 */
interface Tiger_Agent_Provider_Adapter
{
    /**
     * Run one completion.
     *
     * @param  string $system   the system prompt (Tiger identity + tools + the JSON contract)
     * @param  array  $messages provider-neutral turns: [{role, content}, …], oldest first
     * @param  string $model    the model id to call
     * @param  string $apiKey   the org's BYO key (decrypted)
     * @return array{text:string,usage:array{input:int,output:int}}
     * @throws RuntimeException on a transport/API failure (the Loop turns it into a clean error)
     */
    public function complete($system, array $messages, $model, $apiKey);

    /**
     * The selectable models for this provider. With a key, fetch the LIVE list from the provider's
     * models API (vendors change models constantly, so this is the source of truth); without a key,
     * return the curated static fallback. Never throws — a failed fetch falls back to static.
     *
     * @param  string $apiKey optional BYO key for the live list
     * @return array<int,array{id:string,label:string}>
     */
    public function models($apiKey = '');
}
