<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Model_ResponseObject — the standard API response envelope.
 *
 * The heart of the TIGER webservice contract (see the "REST is Dying" article):
 * every /api response is THIS object, serialized as JSON, so the client always
 * finds the same shape. Ported from AskLevi's Levi_Model_ResponseObject.
 *
 *   { "result": 1, "data": null, "messages": [] }   // minimum
 *
 * `result` is 1 (success) / 0 (failure). Services add only what they need —
 * `redirect`, `form` (Zend_Form field errors), or a richer `data` payload.
 * Special-purpose consumers (DataTables, Select2) can return their own shapes;
 * this is the default contract.
 *
 * @api
 */
class Tiger_Model_ResponseObject
{
    /** @var int 1 = success, 0 = failure */
    public $result = 0;

    /** @var mixed service payload (object or array) */
    public $data = null;

    /** @var string|null client-side redirect target */
    public $redirect = null;

    /** @var array|null keyed form-field errors (Zend_Form::getMessages()) */
    public $form = null;

    /** @var Tiger_Model_MessageObject[] */
    public $messages = [];
}
