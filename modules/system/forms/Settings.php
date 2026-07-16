<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Form_Settings — core platform settings: session lifetime + auto-logout.
 *
 * Values are stored in the `config` table (scope=global) by System_Service_Settings —
 * the live-override tier, no deploy. Session/security lives here (not CMS): it's a
 * platform concern. Room to grow (mail, logging, locale) as tabs on the System page.
 *
 * @api
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

            // reCAPTCHA tab — keys are optional (a keyless install just leaves the widget off). The
            // secret is a password field: blank = keep the current one (Tiger_Recaptcha::saveSettings).
            ['checkbox', 'recaptcha_enabled', [
                'attribs' => ['id' => 'set-rc-enabled', 'class' => 'form-check-input'],
            ]],
            ['select', 'recaptcha_version', [
                'multiOptions' => ['v2' => 'v2 — checkbox', 'v3' => 'v3 — invisible score'],
                'value'        => 'v2',
                'attribs'      => ['id' => 'set-rc-version', 'class' => 'form-select'],
            ]],
            ['text', 'recaptcha_site_key', [
                'required'   => false,
                'filters'    => ['StringTrim'],
                'attribs'    => array_merge($control, ['id' => 'set-rc-site', 'autocomplete' => 'off']),
            ]],
            ['password', 'recaptcha_secret_key', [
                'required'   => false,
                'filters'    => ['StringTrim'],
                'attribs'    => array_merge($control, ['id' => 'set-rc-secret', 'autocomplete' => 'new-password']),
            ]],
            ['text', 'recaptcha_min_score', [
                'required'   => false,
                'filters'    => ['StringTrim'],
                'validators' => [['Float'], ['Between', false, ['min' => 0, 'max' => 1, 'inclusive' => true]]],
                'attribs'    => array_merge($control, ['id' => 'set-rc-score', 'inputmode' => 'decimal']),
            ]],
            ['checkbox', 'recaptcha_fail_open', [
                'attribs' => ['id' => 'set-rc-failopen', 'class' => 'form-check-input'],
            ]],
            ['checkbox', 'recaptcha_hide_badge', [
                'attribs' => ['id' => 'set-rc-hidebadge', 'class' => 'form-check-input'],
            ]],
        ];
    }
}
