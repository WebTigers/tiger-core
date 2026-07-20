<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Agent_Form_Send — validates an aside turn (the human's message + CSRF).
 *
 * CSRF stays ON for the outer aside POST (the boundary the browser crosses). The Forge only
 * flips the request stateless AFTER this validates, so downstream service forms skip their
 * token on the same request (Tiger_Form §82) without weakening this entry point.
 *
 * @api
 */
class Agent_Form_Send extends Tiger_Form
{
    /** All agent /api endpoints (send/approve/resume) share ONE CSRF token — the aside renders just one. */
    protected function csrfSalt(): string { return 'Agent'; }

    protected function elements(): array
    {
        return [
            ['textarea', 'message', [
                'required'   => true,
                'filters'    => ['StringTrim'],
                'validators' => [['StringLength', false, [1, 8000]]],
                'attribs'    => ['class' => 'form-control'],
            ]],
        ];
    }
}
