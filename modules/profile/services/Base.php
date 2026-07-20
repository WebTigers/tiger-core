<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Service_Base — shared plumbing for the AJAX-reused profile collection services
 * (Contacts, Addresses). Two concerns those tabs both have:
 *
 *  - CSRF rotation. A Zend hash token hop-expires after ~1 use, but these tabs add/edit/delete
 *    many times from one page load. So every response hands back a FRESH token for the tab's form
 *    (`_freshToken`) and the client swaps it in for the next call — the same rotation that keeps the
 *    agent aside alive.
 *  - Solo primary. `is_primary` is single-per-collection: setting one clears it on the user's other
 *    links (`_soloPrimary`), so there's never more than one primary contact / primary address.
 *
 * @api
 */
abstract class Profile_Service_Base extends Tiger_Service_Service
{
    /**
     * Mint (and store in the session) a fresh CSRF token for the given Tiger_Form subclass, so an
     * AJAX-reused form's token doesn't hop-expire mid-session.
     *
     * @param  string $formClass a Tiger_Form subclass name
     * @return string the fresh token, or '' if it couldn't be minted
     */
    protected function _freshToken($formClass)
    {
        try {
            $el = (new $formClass())->getElement('_csrf');
            $el->setView(new Zend_View());
            $el->render();
            return (string) $el->getValue();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Validate the CSRF token standalone (for mutations without a full form, e.g. delete). Uses the
     * given form's `_csrf` element, so it accepts the same rotated token the tab's save mints. Returns
     * true if CSRF isn't in play (stateless token requests have no `_csrf` element).
     *
     * @param  string $formClass a Tiger_Form subclass name
     * @param  array  $params    the request params (reads `_csrf`)
     * @return bool
     */
    protected function _validCsrf($formClass, array $params)
    {
        try {
            $hash = (new $formClass())->getElement('_csrf');
            if (!$hash) { return true; }
            return (bool) $hash->isValid((string) ($params['_csrf'] ?? ''));
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Enforce a single primary within a user's collection: clear is_primary on every link but $keepId.
     *
     * @param  Tiger_Model_Table $linkModel the user_contact / user_address model
     * @param  string            $pkColumn  its primary-key column
     * @param  string            $userId    the owner
     * @param  string            $keepId    the link that stays primary
     * @return void
     */
    protected function _soloPrimary($linkModel, $pkColumn, $userId, $keepId)
    {
        $db = $linkModel->getAdapter();
        $linkModel->update(
            ['is_primary' => 0],
            $db->quoteInto('user_id = ?', (string) $userId) . ' AND ' . $db->quoteInto($pkColumn . ' <> ?', (string) $keepId)
        );
    }
}
