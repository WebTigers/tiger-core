<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Service_Address — the /api service behind the profile Addresses tab.
 *
 * Self-service and STRICTLY self-scoped ($this->_user_id) — never takes a user_id from the payload.
 * The `type` (Home / Office / … from Tiger_Profile_Types) is stored on the LINK (user_address.label);
 * the location goes on the shared `address` row. `country` is validated against Tiger_I18n_Country.
 * `is_primary` is single-per-collection. Every response carries the refreshed list + a rotated token.
 *
 * @api
 */
class Profile_Service_Address extends Profile_Service_Base
{
    /**
     * Create or update one of the current user's addresses (edit when user_address_id is present).
     *
     * @param  array $params type, country, line1, line2, city, region, postal, is_primary, user_address_id
     * @return void
     */
    public function save(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Profile_Form_Address();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }

        $types = Tiger_Profile_Types::address();
        $type  = strtolower(trim((string) $form->getValue('type')));
        if (!array_key_exists($type, $types)) { $this->_error('profile.address.bad_type', ['field' => 'type']); return; }

        $country = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $form->getValue('country')));
        if (!in_array($country, Tiger_I18n_Country::codes(), true)) { $this->_error('profile.address.bad_country', ['field' => 'country']); return; }

        $loc = [
            'line1'     => trim((string) $form->getValue('line1')),
            'line2'     => trim((string) $form->getValue('line2')) ?: null,
            'city'      => trim((string) $form->getValue('city')),
            'region'    => trim((string) $form->getValue('region')) ?: null,
            'postal'    => trim((string) $form->getValue('postal')) ?: null,
            'country'   => $country,
            'latitude'  => $this->_coord($form->getValue('latitude'), 90.0),   // from the autocomplete pick
            'longitude' => $this->_coord($form->getValue('longitude'), 180.0),
        ];
        $primary = (bool) $form->getValue('is_primary');
        $uaId    = trim((string) $form->getValue('user_address_id'));

        try {
            $this->_transaction(function () use ($userId, $type, $loc, $primary, $uaId) {
                $link = new Tiger_Model_UserAddress();
                if ($uaId !== '') {
                    $row = $link->findById($uaId);
                    if (!$row || (string) $row->user_id !== $userId) {
                        throw new RuntimeException('Not your address.');
                    }
                    (new Tiger_Model_Address())->update($loc, $link->getAdapter()->quoteInto('address_id = ?', (string) $row->address_id));
                    $link->update(['label' => $type, 'is_primary' => $primary ? 1 : 0], $link->getAdapter()->quoteInto('user_address_id = ?', $uaId));
                    $keepId = $uaId;
                } else {
                    $addressId = (new Tiger_Model_Address())->create($loc);
                    $keepId    = $link->insert(['user_id' => $userId, 'address_id' => $addressId, 'label' => $type, 'is_primary' => $primary ? 1 : 0]);
                }
                if ($primary) {
                    $this->_soloPrimary($link, 'user_address_id', $userId, (string) $keepId);
                }
            });
            $this->_ok($userId, 'profile.address.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Delete one of the current user's addresses (unlink + soft-delete the location).
     *
     * @param  array $params user_address_id
     * @return void
     */
    public function delete(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') { $this->_error('core.api.error.not_allowed'); return; }
        if (!$this->_validCsrf(Profile_Form_Address::class, $params)) { $this->_error('core.api.error.csrf'); return; }
        $uaId = trim((string) ($params['user_address_id'] ?? ''));

        try {
            $this->_transaction(function () use ($userId, $uaId) {
                $link = new Tiger_Model_UserAddress();
                $row  = $uaId !== '' ? $link->findById($uaId) : null;
                if (!$row || (string) $row->user_id !== $userId) {
                    throw new RuntimeException('Not your address.');
                }
                $link->softDelete($link->getAdapter()->quoteInto('user_address_id = ?', $uaId));
                $a = new Tiger_Model_Address();
                $a->softDelete($a->getAdapter()->quoteInto('address_id = ?', (string) $row->address_id));
            });
            $this->_ok($userId, 'profile.address.deleted');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Normalize a coordinate: a numeric value within ±$max → float, anything else → null (so a blank
     * or out-of-range geocode stores as NULL rather than a bogus point).
     *
     * @param  mixed $v
     * @param  float $max the absolute bound (90 for latitude, 180 for longitude)
     * @return float|null
     */
    private function _coord($v, float $max): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) { return null; }
        $f = (float) $v;
        return ($f < -$max || $f > $max) ? null : $f;
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
                'addresses' => (new Tiger_Model_UserAddress())->withAddress($userId),
                '_csrf'     => $this->_freshToken(Profile_Form_Address::class),
            ],
            $messageKey
        );
    }
}
