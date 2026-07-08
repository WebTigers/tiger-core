<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Service_Validate — convenience validation, over /api.
 *
 * Convenience validation (TigerValidateJS) runs a form's REAL server-side validators one
 * field at a time, on blur, BEFORE submit — so every value is known-good before the user
 * clicks the button, and a bad one shows its message inline while it's easy to fix.
 *
 * It's a first-party CORE service reachable at `module=tiger & service=validate` (the
 * reserved-module guard is disabled — the ACL is the gate). It carries exactly ONE public
 * `allow guest` rule in core acl.ini; every other Tiger_Service_* has no rule and is
 * deny-by-default, so nothing kernel-internal is exposed by this being reachable.
 *
 * The message names the form to check (its module + form name travel as params, distinct
 * from the routing `module=tiger`):
 *   module=tiger  service=validate  method=field
 *   form_module=<module>  form=<FormName>  field=<element>  value=<value>  [+ other fields]
 * Returns `{ valid, message }` in the success envelope's `data`. Always succeeds at the
 * service level (result=1); field validity is in the payload. Unknown form → valid (never
 * block the UI — submit-time isValid() stays authoritative). Only instantiates real
 * Tiger_Form subclasses (sanitized names + subclass check), and building a form is
 * read-only, so there's no mutation surface here.
 *
 * @api
 */
class Tiger_Service_Validate extends Tiger_Service_Service
{
    public function field(array $params): void
    {
        $formModule = preg_replace('/[^a-zA-Z]/', '', (string) ($params['form_module'] ?? ''));
        $formName   = preg_replace('/[^a-zA-Z]/', '', (string) ($params['form'] ?? ''));
        $class      = ucfirst($formModule) . '_Form_' . ucfirst($formName);

        if ($formModule === '' || $formName === '' || !class_exists($class, true) || !is_subclass_of($class, 'Tiger_Form')) {
            $this->_success(['valid' => true, 'message' => '']);
            return;
        }

        $form = new $class();
        $this->_success($form->convenienceValidate(
            (string) ($params['field'] ?? ''),
            $params['value'] ?? '',
            $params
        ));
    }
}
