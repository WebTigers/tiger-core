<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Form_Element_Recaptcha — a Google reCAPTCHA field for Tiger_Form.
 *
 * Declare it like any element:
 *
 *   protected function elements(): array
 *   {
 *       return [
 *           ['text', 'email', [...]],
 *           ['recaptcha', 'recaptcha', []],          // v2 checkbox
 *           // v3: ['recaptcha', 'recaptcha', ['action' => 'signup']],
 *       ];
 *   }
 *
 * It renders through Tiger_View_Helper_FormRecaptcha and self-attaches
 * Tiger_Validate_Recaptcha, which reads the widget's `g-recaptcha-response` token from
 * the form context — so the element name doesn't matter and the value is never a real
 * model field (setIgnore). It's not `required` (an empty token is handled by the
 * validator with a proper reCAPTCHA message), but `allowEmpty(false)` forces the
 * validator to run even when the field posts empty.
 *
 * @api
 */
class Tiger_Form_Element_Recaptcha extends Zend_Form_Element_Xhtml
{
    /** Rendered by $view->formRecaptcha(). */
    public $helper = 'formRecaptcha';

    public function init()
    {
        $options = [];
        if ($this->getAttrib('action') !== null) {
            $options['action'] = $this->getAttrib('action');   // v3 action, if set
        }

        $this->setRequired(false)      // emptiness is the validator's job (proper message)
             ->setAllowEmpty(false)    // ...so make validators run even on an empty post
             ->setIgnore(true)         // not a model value — excluded from getValues()
             ->addValidator(new Tiger_Validate_Recaptcha($options), true);
    }
}
