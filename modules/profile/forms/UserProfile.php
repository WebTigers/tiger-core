<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Form_UserProfile — the Basic-info tab of the self-service user profile.
 *
 * Only the fields the base surface owns: `username` (optional, unique — checked in the service),
 * plus the two i18n primitives that live on the identity row, `locale` and `timezone`. Their
 * membership (a supported language / a real IANA zone) is validated in Profile_Service_User, not
 * here, since the valid sets are dynamic — so these stay plain text elements (the view renders the
 * selects). Email is intentionally NOT here: it's the login identifier, so changing it needs a
 * verification flow that isn't part of the base surface yet.
 */
class Profile_Form_UserProfile extends Tiger_Form
{
    /**
     * The Basic-info element schema.
     *
     * @return array the Tiger_Form element definitions
     */
    protected function elements(): array
    {
        return [
            ['text', 'display_name', [
                'required'   => false,
                'filters'    => ['StringTrim'],
                'validators' => [['StringLength', false, [0, 120]]],
                'attribs'    => ['class' => 'form-control'],
            ]],
            ['text', 'username', [
                'required'   => false,
                'filters'    => ['StringTrim'],
                'validators' => [['StringLength', false, [0, 64]]],
                'attribs'    => ['class' => 'form-control'],
            ]],
            ['text', 'locale', [
                'required' => false,
                'filters'  => ['StringTrim'],
                'attribs'  => ['class' => 'form-select'],
            ]],
            ['text', 'timezone', [
                'required' => false,
                'filters'  => ['StringTrim'],
                'attribs'  => ['class' => 'form-select'],
            ]],
        ];
    }
}
