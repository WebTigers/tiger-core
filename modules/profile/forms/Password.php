<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Form_Password — the change-password form on the profile Security tab.
 *
 * The new password (validated against the platform policy AND the user's own history via
 * Tiger_Validate_Password scoped to the user id) and a confirm that must match. There is
 * DELIBERATELY no "current password" field: a user who forgot their password signs in with an
 * OTP code, so demanding the old password would lock them out of the very screen that fixes it.
 * A logged-in session is authority enough to set a new password (same as the admin reset path).
 */
class Profile_Form_Password extends Tiger_Form
{
    /** @var string|null the user whose history scopes reuse-prevention */
    protected $_userId;

    /**
     * @param  string|null $userId the acting user (enables password-reuse prevention)
     * @param  mixed       $options passed through to Zend_Form
     * @return void
     */
    public function __construct($userId = null, $options = null)
    {
        $this->_userId = $userId ?: null;   // set BEFORE parent ctor: init()->elements() reads it
        parent::__construct($options);
    }

    /**
     * The change-password element schema.
     *
     * @return array the Tiger_Form element definitions
     */
    protected function elements(): array
    {
        return [
            ['password', 'new_password', [
                'required'   => true,
                'validators' => [new Tiger_Validate_Password($this->_userId)],
                'attribs'    => ['class' => 'form-control', 'autocomplete' => 'new-password', 'data-tiger-strength' => '1', 'data-no-validate' => '1'],
            ]],
            ['password', 'confirm_password', [
                'required'   => true,
                'validators' => [['Identical', false, ['token' => 'new_password']]],
                'attribs'    => ['class' => 'form-control', 'autocomplete' => 'new-password'],
            ]],
        ];
    }
}
