<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Validate_Password — a form validator that runs the platform password policy.
 *
 * Wraps Tiger_Policy_Password so a password field validates the SAME rules everywhere —
 * at submit, and (via convenience validation) on blur. The first policy violation becomes
 * the field's message, so the user sees exactly what to fix. Pair it in the UI with the
 * live strength meter (tiger.password-strength.js), but this is the authority.
 *
 * @api
 */
class Tiger_Validate_Password extends Zend_Validate_Abstract
{
    const WEAK = 'passwordWeak';

    protected $_messageTemplates = [self::WEAK => "That password doesn't meet the policy."];

    /** @var string|null when set, enables reuse-prevention against this user's history */
    protected $_userId;

    public function __construct($userId = null)
    {
        $this->_userId = $userId ?: null;
    }

    public function isValid($value)
    {
        $value = (string) $value;
        $this->_setValue($value);

        $violations = (new Tiger_Policy_Password())->validate($value, $this->_userId);
        if (!$violations) {
            return true;
        }
        // Surface the first, most-relevant violation as the field message.
        $this->_messageTemplates[self::WEAK] = (string) $violations[0];
        $this->_error(self::WEAK);
        return false;
    }
}
