<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Access_Form_User — create/edit a user identity.
 *
 * The user is deliberately thin: email (canonical login id, unique), an optional
 * username (unique), and a lifecycle status. Profile fields belong to an Account
 * module; role belongs to org membership (managed in the org context) — neither is
 * here. Uniqueness is enforced by Access_Service_User::save (friendly errors) on top
 * of the DB unique indexes.
 *
 * @api
 */
class Access_Form_User extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        // Locale options from config (tiger.i18n.locales); timezones from PHP. Both allow '' = unset.
        $cfg   = Zend_Registry::get('Zend_Config');
        $i18n  = ($cfg->tiger && $cfg->tiger->i18n) ? (string) $cfg->tiger->i18n->get('locales') : 'en';
        $langs = array_values(array_filter(array_map('trim', explode(',', $i18n)))) ?: ['en'];
        $names = ['en' => 'English', 'es' => 'Español'];
        $localeOpts = ['' => '—'];
        foreach ($langs as $c) { $localeOpts[$c] = $names[$c] ?? strtoupper($c); }
        // Rich, searchable timezone labels ("America/New_York (EST, UTC-05:00)") — the view enhances
        // this select into a filter-as-you-type combobox (data-tiger-combo), findable by city,
        // abbreviation, or offset.
        $tzOpts = ['' => '—'] + Tiger_I18n_Timezone::options();

        return [
            ['hidden', 'user_id', []],

            ['text', 'email', [
                'required'   => true,
                'filters'    => ['StringTrim', 'StringToLower'],
                'validators' => [['EmailAddress']],
                'attribs'    => array_merge($control, ['id' => 'access-email', 'maxlength' => 191]),
            ]],

            ['text', 'username', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'access-username', 'maxlength' => 64]),
            ]],

            ['select', 'locale', [
                'multiOptions' => $localeOpts,
                'attribs'      => array_merge($select, ['id' => 'access-locale']),
            ]],

            ['select', 'timezone', [
                'multiOptions' => $tzOpts,
                'attribs'      => array_merge($select, ['id' => 'access-timezone', 'data-tiger-combo' => '1',
                    'data-placeholder' => 'Search by city, abbreviation (EST), or offset (-05:00)…']),
            ]],

            // Admin set/reset — optional. Blank leaves the password unchanged; when set it's applied
            // outright (no current-password step — admin authority), policy-validated (no reuse scope).
            ['password', 'new_password', [
                'required'   => false,
                'validators' => [new Tiger_Validate_Password()],
                'attribs'    => array_merge($control, ['id' => 'access-new-password', 'autocomplete' => 'new-password']),
            ]],

            ['select', 'status', [
                'multiOptions' => ['active' => 'Active', 'suspended' => 'Suspended'],
                'value'        => 'active',
                'attribs'      => array_merge($select, ['id' => 'access-status']),
            ]],
        ];
    }
}
