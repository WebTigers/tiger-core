<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Service_Contact — the /api service behind the profile Contacts tab.
 *
 * Self-service and STRICTLY self-scoped ($this->_user_id) — the user_id is never taken from the
 * payload, so a `user` can only touch their own links. A contact is a channel (`type`, validated
 * against the configurable Tiger_Profile_Types list) + a `value`; `is_primary` is single-per-
 * collection. Every response carries the refreshed contacts list and a rotated CSRF token.
 *
 * @api
 */
class Profile_Service_Contact extends Profile_Service_Base
{
    /**
     * Create or update one of the current user's contacts (edit when user_contact_id is present).
     *
     * @param  array $params type, value, is_primary, user_contact_id (blank = create)
     * @return void
     */
    public function save(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Profile_Form_Contact();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }

        $types = Tiger_Profile_Types::contact();
        $type  = strtolower(trim((string) $form->getValue('type')));
        if (!array_key_exists($type, $types)) { $this->_error('profile.contact.bad_type', ['field' => 'type']); return; }
        $value   = trim((string) $form->getValue('value'));
        $primary = (bool) $form->getValue('is_primary');
        $ucId    = trim((string) $form->getValue('user_contact_id'));

        // Phone: the browser sends canonical E.164 in `value` (intl-tel-input getNumber()) plus the
        // picked ISO-3166 country in `phone_country`. Validate the E.164 shape and stash the ISO on
        // contact.type so an edit can re-seed the widget's country.
        $contactType = null;
        if ($type === 'phone') {
            if (!preg_match('/^\+[1-9]\d{6,14}$/', $value)) {
                $this->_error('profile.contact.bad_phone', ['field' => 'value']); return;
            }
            $contactType = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $form->getValue('phone_country'))) ?: null;
        }

        try {
            $this->_transaction(function () use ($userId, $type, $contactType, $value, $primary, $ucId) {
                $link = new Tiger_Model_UserContact();
                if ($ucId !== '') {
                    $row = $link->findById($ucId);
                    if (!$row || (string) $row->user_id !== $userId) {
                        throw new RuntimeException('Not your contact.');
                    }
                    (new Tiger_Model_Contact())->update(
                        ['kind' => $type, 'type' => $contactType, 'value' => $value],
                        $link->getAdapter()->quoteInto('contact_id = ?', (string) $row->contact_id)
                    );
                    $link->update(['is_primary' => $primary ? 1 : 0], $link->getAdapter()->quoteInto('user_contact_id = ?', $ucId));
                    $keepId = $ucId;
                } else {
                    $contactId = (new Tiger_Model_Contact())->insert(['kind' => $type, 'type' => $contactType, 'value' => $value]);
                    $keepId    = $link->insert(['user_id' => $userId, 'contact_id' => $contactId, 'is_primary' => $primary ? 1 : 0]);
                }
                if ($primary) {
                    $this->_soloPrimary($link, 'user_contact_id', $userId, (string) $keepId);
                }
            });
            $this->_ok($userId, 'profile.contact.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Delete one of the current user's contacts (unlink + soft-delete the channel).
     *
     * @param  array $params user_contact_id
     * @return void
     */
    public function delete(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') { $this->_error('core.api.error.not_allowed'); return; }
        if (!$this->_validCsrf(Profile_Form_Contact::class, $params)) { $this->_error('core.api.error.csrf'); return; }
        $ucId = trim((string) ($params['user_contact_id'] ?? ''));

        try {
            $this->_transaction(function () use ($userId, $ucId) {
                $link = new Tiger_Model_UserContact();
                $row  = $ucId !== '' ? $link->findById($ucId) : null;
                if (!$row || (string) $row->user_id !== $userId) {
                    throw new RuntimeException('Not your contact.');
                }
                $link->softDelete($link->getAdapter()->quoteInto('user_contact_id = ?', $ucId));
                $c = new Tiger_Model_Contact();
                $c->softDelete($c->getAdapter()->quoteInto('contact_id = ?', (string) $row->contact_id));
            });
            $this->_ok($userId, 'profile.contact.deleted');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Success envelope: the refreshed list + a rotated CSRF token for the reused form.
     *
     * @param  string $userId
     * @param  string $messageKey
     * @return void
     */
    protected function _ok($userId, $messageKey)
    {
        $this->_success(
            [
                'contacts' => (new Tiger_Model_UserContact())->withContact($userId),
                '_csrf'    => $this->_freshToken(Profile_Form_Contact::class),
            ],
            $messageKey
        );
    }
}
