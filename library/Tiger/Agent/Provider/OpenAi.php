<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Provider_OpenAi — OpenAI (GPT) via the chat/completions API. @api
 */
class Tiger_Agent_Provider_OpenAi extends Tiger_Agent_Provider_OpenAiCompatible
{
    protected function _base()        { return 'https://api.openai.com/v1'; }
    protected function _providerKey() { return 'openai'; }

    /** OpenAI's gpt-5 / o-series reject `max_tokens` and require `max_completion_tokens`. */
    protected function _maxTokensField() { return 'max_completion_tokens'; }
}
