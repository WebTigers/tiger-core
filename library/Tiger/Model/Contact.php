<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Contact — a point of contact (phone / email / other), owner-agnostic.
 *
 * A single reusable contact channel that belongs to no one on its own: an org or user
 * points at it through a link table (Tiger_Model_OrgContact / Tiger_Model_UserContact),
 * and the relationship label lives on that link — never here. This is contact-as-DATA
 * (how to reach someone), NOT the `sms` auth factor (that lives in user_credential).
 *
 * @api
 */
class Tiger_Model_Contact extends Tiger_Model_Table
{
    protected $_name    = 'contact';
    protected $_primary = 'contact_id';
}
