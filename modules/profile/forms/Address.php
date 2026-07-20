<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Form_Address — the add/edit form on the profile Addresses tab.
 *
 * The location fields (line1/line2/city/region/postal/country) plus a `type` (Home / Office / … from
 * the configurable list) and `is_primary`. `user_address_id` is hidden — present when editing. The
 * `type` and `country` are validated against dynamic sets in the service (config types;
 * Tiger_I18n_Country codes), so they stay plain text elements here — the view renders the selects.
 *
 * Agent note: the address flow is country → autocomplete (tiger.address.js posts `country` + `q` to
 * Tiger_Service_Location::suggest) → the street/city/region/postal fields autofill → save. The `type`
 * is stored on the LINK (user_address.label), the location on the shared `address` row.
 */
class Profile_Form_Address extends Tiger_Form
{
    /**
     * @return array the Tiger_Form element definitions
     */
    protected function elements(): array
    {
        $line = ['filters' => ['StringTrim'], 'attribs' => ['class' => 'form-control']];
        return [
            ['hidden', 'user_address_id', ['filters' => ['StringTrim']]],
            ['text', 'type',    ['required' => true, 'filters' => ['StringTrim'], 'attribs' => ['class' => 'form-select']]],
            ['text', 'country', ['required' => true, 'filters' => ['StringTrim'], 'attribs' => ['class' => 'form-select']]],
            ['text', 'line1',   ['required' => true, 'filters' => ['StringTrim'], 'validators' => [['StringLength', true, ['min' => 1, 'max' => 191]]], 'attribs' => ['class' => 'form-control']]],
            ['text', 'line2',   $line],
            ['text', 'city',    ['required' => true] + $line],
            ['text', 'region',  $line],
            ['text', 'postal',  $line],
            // Cached geocode from the autocomplete pick (optional; the service range-checks + nulls it).
            ['hidden', 'latitude',  ['filters' => ['StringTrim']]],
            ['hidden', 'longitude', ['filters' => ['StringTrim']]],
            ['checkbox', 'is_primary', []],
        ];
    }
}
