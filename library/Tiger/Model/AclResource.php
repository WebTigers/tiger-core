<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * AclResource — runtime resource rows (DB layer). Read by Tiger_Acl_Acl on top of
 * the code-shipped ini resources. See migration 0007.
 *
 * @api
 */
class Tiger_Model_AclResource extends Tiger_Model_Table
{
    protected $_name    = 'acl_resource';
    protected $_primary = 'acl_resource_id';

    /** All active resources for the ACL loader. */
    public function getResourceList()
    {
        return $this->fetchAll($this->activeSelect());
    }
}
