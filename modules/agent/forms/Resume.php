<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Agent_Form_Resume — validates the client leg's callback (conversation + CSRF).
 *
 * The browser posts DOM results here to continue a turn (Agent_Service_Agent::resume). CSRF
 * stays on the outer aside POST; the Forge/Scout flip the request stateless only after this
 * validates.
 *
 * @api
 */
class Agent_Form_Resume extends Tiger_Form
{
    /** Shares the agent CSRF token (salt 'Agent') so the single rendered token validates here too. */
    protected function csrfSalt(): string { return 'Agent'; }

    protected function elements(): array
    {
        return [
            ['text', 'conversation_id', [
                'required'   => true,
                'filters'    => ['StringTrim'],
                'validators' => [['StringLength', false, [1, 36]]],
                'attribs'    => ['class' => 'form-control'],
            ]],
        ];
    }
}
