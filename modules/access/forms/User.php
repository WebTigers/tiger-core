<?php
/**
 * Access_Form_User — create/edit a user identity.
 *
 * The user is deliberately thin: email (canonical login id, unique), an optional
 * username (unique), and a lifecycle status. Profile fields belong to an Account
 * module; role belongs to org membership (managed in the org context) — neither is
 * here. Uniqueness is enforced by Access_Service_User::save (friendly errors) on top
 * of the DB unique indexes.
 */
class Access_Form_User extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

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

            ['select', 'status', [
                'multiOptions' => ['active' => 'Active', 'suspended' => 'Suspended'],
                'value'        => 'active',
                'attribs'      => array_merge($select, ['id' => 'access-status']),
            ]],
        ];
    }
}
