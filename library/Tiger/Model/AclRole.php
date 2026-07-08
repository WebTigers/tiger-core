<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * AclRole — runtime role rows (the DB layer of the role graph). Read by
 * Tiger_Acl_Acl on top of the code-shipped ini roles. See migration 0006.
 *
 * @api
 */
class Tiger_Model_AclRole extends Tiger_Model_Table
{
    protected $_name    = 'acl_role';
    protected $_primary = 'acl_role_id';

    /** All active roles (role + comma-sep parent_role) for the ACL loader. */
    public function getRoleList()
    {
        return $this->fetchAll($this->activeSelect());
    }
}
