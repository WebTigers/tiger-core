<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Form_Contact — the add/edit form on the profile Contacts tab.
 *
 * A contact is a stub: a `type` (the channel — Phone / Email / … from the configurable list) and a
 * `value` (the number / address / handle), plus `is_primary`. `user_contact_id` is hidden — present
 * when editing an existing link, empty when adding. The type is validated against the configured set
 * in the service (dynamic list), so it stays a plain text element here.
 *
 * PHONE mapping (agent note): when type = phone the browser's intl-tel-input widget writes the
 * canonical E.164 number into `value` (e.g. +15551234567) and the picked country's ISO-3166 alpha-2
 * into `phone_country`. The service stores value on `contact.value` and the ISO on `contact.type`
 * (so edit can round-trip it). Email/Other put their datum straight in `value`; phone_country is
 * ignored for those.
 */
class Profile_Form_Contact extends Tiger_Form
{
    /**
     * @return array the Tiger_Form element definitions
     */
    protected function elements(): array
    {
        return [
            ['hidden', 'user_contact_id', ['filters' => ['StringTrim']]],
            ['text', 'type', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => ['class' => 'form-select'],
            ]],
            ['text', 'value', [
                'required'   => true,
                'filters'    => ['StringTrim'],
                'validators' => [['StringLength', true, ['min' => 1, 'max' => 191]]],
                'attribs'    => ['class' => 'form-control'],
            ]],
            ['checkbox', 'is_primary', []],
            // For phone contacts only: the ISO-3166 alpha-2 the intl-tel-input widget reported.
            ['hidden', 'phone_country', ['filters' => ['StringTrim']]],
        ];
    }
}
