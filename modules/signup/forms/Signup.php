<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Signup_Form_Signup — the platform's reference form.
 *
 * This is the gold-standard example an AI agent (or a human) copies when building ANY Tiger
 * form: it exercises the full breadth of validation — required, length, regex, email,
 * InArray (selects), DB-uniqueness (Zend_Validate_Db_NoRecordExists on username + email),
 * and the platform password policy (Tiger_Validate_Password). Every field is validated the
 * same way twice: on blur via convenience validation (TigerValidateJS -> Tiger_Service_Validate
 * -> Tiger_Form::convenienceValidate, running exactly these validators one field at a time),
 * and again at submit via isValid(). Declare validators ONCE here; both paths use them.
 *
 * The view (views/scripts/index/index.phtml) owns all markup + layout; this class only
 * declares the elements + their rules.
 */
class Signup_Form_Signup extends Tiger_Form
{
    /** A small ISO-3166-1 alpha-2 set for the demo country picker. */
    private static $_countries = [
        '' => '— Select —', 'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
        'IE' => 'Ireland', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'DE' => 'Germany',
        'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
        'SE' => 'Sweden', 'NO' => 'Norway', 'JP' => 'Japan', 'MX' => 'Mexico', 'BR' => 'Brazil',
    ];

    private static $_phoneTypes = [
        'mobile' => 'Mobile', 'work' => 'Work', 'home' => 'Home', 'main' => 'Main', 'fax' => 'Fax',
    ];

    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        // DB-uniqueness — the NoDbRecordExists validators, with human messages.
        $uniqueUsername = new Zend_Validate_Db_NoRecordExists(['table' => 'user', 'field' => 'username']);
        $uniqueUsername->setMessage('That username is already taken.', Zend_Validate_Db_NoRecordExists::ERROR_RECORD_FOUND);
        $uniqueEmail = new Zend_Validate_Db_NoRecordExists(['table' => 'user', 'field' => 'email']);
        $uniqueEmail->setMessage('An account with that email already exists.', Zend_Validate_Db_NoRecordExists::ERROR_RECORD_FOUND);

        $countryCodes = array_values(array_filter(array_keys(self::$_countries)));   // no '' placeholder

        return [
            ['text', 'first_name', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [['StringLength', true, ['min' => 1, 'max' => 64]]],
                'attribs' => array_merge($control, ['id' => 'su-first', 'autocomplete' => 'given-name', 'placeholder' => 'Jane']),
            ]],
            ['text', 'last_name', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [['StringLength', true, ['min' => 1, 'max' => 64]]],
                'attribs' => array_merge($control, ['id' => 'su-last', 'autocomplete' => 'family-name', 'placeholder' => 'Doe']),
            ]],
            ['text', 'company', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [['StringLength', true, ['min' => 2, 'max' => 128]]],
                'attribs' => array_merge($control, ['id' => 'su-company', 'autocomplete' => 'organization', 'placeholder' => 'Acme Widgets, Inc.']),
            ]],

            ['text', 'username', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [
                    ['Regex', true, ['pattern' => '/^[a-zA-Z0-9._-]{3,32}$/', 'messages' => [Zend_Validate_Regex::NOT_MATCH => '3–32 letters, numbers, dot, dash or underscore.']]],
                    $uniqueUsername,
                ],
                'attribs' => array_merge($control, ['id' => 'su-username', 'autocomplete' => 'username', 'placeholder' => 'janedoe']),
            ]],
            ['password', 'password', [
                'required' => true,
                'validators' => [new Tiger_Validate_Password()],
                // data-tiger-strength -> the live meter owns the inline UX; data-no-validate ->
                // TigerValidateJS skips it (the meter handles blur), but the server policy above
                // still validates it at submit.
                'attribs' => array_merge($control, ['id' => 'su-password', 'autocomplete' => 'new-password', 'data-tiger-strength' => '1', 'data-no-validate' => '1']),
            ]],
            ['text', 'email', [
                'required' => true, 'filters' => ['StringTrim', 'StringToLower'],
                // Format-only email check — ALLOW_ALL skips the wonky TLD/DNS hostname list
                // (which rejects perfectly good addresses like *.test / new TLDs). Uniqueness
                // is the NoRecordExists below.
                'validators' => [['EmailAddress', true, ['allow' => Zend_Validate_Hostname::ALLOW_ALL]], $uniqueEmail],
                'attribs' => array_merge($control, ['id' => 'su-email', 'type' => 'email', 'autocomplete' => 'email', 'placeholder' => 'jane@acme.com']),
            ]],

            ['text', 'street', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [['StringLength', true, ['min' => 3, 'max' => 191]]],
                // Address autocomplete (Tiger Location Service): picking a suggestion fills
                // the street + the mapped city/region/postal/country fields.
                'attribs' => array_merge($control, [
                    'id' => 'su-street', 'autocomplete' => 'address-line1', 'placeholder' => '123 Main St',
                    'data-tiger-address' => '1',
                    'data-fill-city' => 'su-city', 'data-fill-region' => 'su-region',
                    'data-fill-postal' => 'su-postal', 'data-fill-country' => 'su-country',
                ]),
            ]],
            ['text', 'city', [
                'required' => true, 'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'su-city', 'autocomplete' => 'address-level2']),
            ]],
            ['text', 'region', [
                'required' => true, 'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'su-region', 'autocomplete' => 'address-level1', 'placeholder' => 'State / Province']),
            ]],
            ['text', 'postal', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [['Regex', true, ['pattern' => '/^[A-Za-z0-9 -]{3,12}$/', 'messages' => [Zend_Validate_Regex::NOT_MATCH => 'Enter a valid postal code.']]]],
                'attribs' => array_merge($control, ['id' => 'su-postal', 'autocomplete' => 'postal-code']),
            ]],
            ['select', 'country', [
                'required' => true, 'multiOptions' => self::$_countries,
                'validators' => [['InArray', true, ['haystack' => $countryCodes, 'messages' => [Zend_Validate_InArray::NOT_IN_ARRAY => 'Please choose a country.']]]],
                'attribs' => array_merge($select, ['id' => 'su-country', 'autocomplete' => 'country']),
            ]],

            ['select', 'phone_type', [
                'required' => true, 'multiOptions' => self::$_phoneTypes, 'value' => 'mobile',
                'validators' => [['InArray', true, ['haystack' => array_keys(self::$_phoneTypes)]]],
                'attribs' => array_merge($select, ['id' => 'su-phone-type']),
            ]],
            ['text', 'phone', [
                'required' => true, 'filters' => ['StringTrim'],
                'validators' => [['Regex', true, ['pattern' => '/^[0-9+().\- ]{7,20}$/', 'messages' => [Zend_Validate_Regex::NOT_MATCH => 'Enter a valid phone number.']]]],
                'attribs' => array_merge($control, ['id' => 'su-phone', 'type' => 'tel', 'autocomplete' => 'tel', 'placeholder' => '+1 555 123 4567']),
            ]],
        ];
    }
}
