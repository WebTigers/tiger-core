<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Address — a postal location, owner-agnostic.
 *
 * A reusable street/postal address that belongs to no one on its own: an org or user
 * points at it through a link table (Tiger_Model_OrgAddress / Tiger_Model_UserAddress),
 * and the relationship label lives on that link — never here. Keep this row THIN:
 * location fields plus an optional cached geocode, nothing owner- or role-specific.
 *
 * @api
 */
class Tiger_Model_Address extends Tiger_Model_Table
{
    protected $_name    = 'address';
    protected $_primary = 'address_id';

    /**
     * Insert an address and return its new id. Tiger_Model_Table mints the UUID PK.
     *
     * @param  array $data
     * @return string the new address_id
     */
    public function create(array $data): string
    {
        return $this->insert($data);
    }
}
