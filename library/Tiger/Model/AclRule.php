<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * AclRule — runtime allow/deny rules (DB layer, loaded LAST so DB wins). Read by
 * Tiger_Acl_Acl. See migration 0008.
 *
 * @api
 */
class Tiger_Model_AclRule extends Tiger_Model_Table
{
    protected $_name    = 'acl_rule';
    protected $_primary = 'acl_rule_id';

    /** All active rules for the ACL loader. */
    public function getRuleList()
    {
        return $this->fetchAll($this->activeSelect());
    }
}
