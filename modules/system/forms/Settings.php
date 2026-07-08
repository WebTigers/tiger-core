<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Form_Settings — core platform settings: session lifetime + auto-logout.
 *
 * Values are stored in the `config` table (scope=global) by System_Service_Settings —
 * the live-override tier, no deploy. Session/security lives here (not CMS): it's a
 * platform concern. Room to grow (mail, logging, locale) as tabs on the System page.
 */
class System_Form_Settings extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];

        return [
            ['text', 'session_ttl', [
                'required'   => true,
                'filters'    => ['StringTrim'],
                'validators' => [['Digits'], ['GreaterThan', false, ['min' => 59]]],
                'attribs'    => array_merge($control, ['id' => 'set-session-ttl', 'inputmode' => 'numeric']),
            ]],
            ['checkbox', 'autologout_enabled', [
                'attribs' => ['id' => 'set-autologout-enabled', 'class' => 'form-check-input'],
            ]],
            ['text', 'autologout_seconds', [
                'required'   => true,
                'filters'    => ['StringTrim'],
                'validators' => [['Digits'], ['GreaterThan', false, ['min' => 29]]],
                'attribs'    => array_merge($control, ['id' => 'set-autologout-seconds', 'inputmode' => 'numeric']),
            ]],
            ['radio', 'autologout_action', [
                'multiOptions' => ['logout' => 'Full logout (end the session)', 'lock' => 'Lock screen (re-enter password)'],
                'value'        => 'logout',
                'separator'    => '',
                'attribs'      => ['class' => 'form-check-input'],
            ]],
        ];
    }
}
