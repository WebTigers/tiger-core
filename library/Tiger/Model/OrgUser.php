<?php
/**
 * OrgUser — MEMBERSHIP. The most important table in the substrate.
 *
 * A row here says "user U belongs to org O, with role R." It is simultaneously:
 *
 *   1. THE TENANCY BOUNDARY. What stops a user from acting inside an org is the
 *      ABSENCE of an org_user row linking them to it. Cross-tenant denial is
 *      therefore structural — a missing row — not a code check someone can forget
 *      to write. Every tenant-scoped query should be gated on "does an (org_id,
 *      user_id) membership exist?"
 *
 *   2. THE ROLE CARRIER. The role lives HERE, per-membership — not on the user —
 *      so the same user can be `admin` in one org and `viewer` in another. This is
 *      the core of Tiger's multi-tenant ACL (Tiger's key evolution over a single
 *      global role per user). The ACL engine reads the role from the current
 *      org_user row for the acting user + current org.
 *
 * A user has at most ONE membership per org (unique org_id + user_id). `status`
 * distinguishes active / invited / suspended memberships without deleting the row.
 *
 * @api
 */
class Tiger_Model_OrgUser extends Tiger_Model_Table
{
    protected $_name    = 'org_user';
    protected $_primary = 'org_user_id';

    /**
     * The membership row for a (org, user) pair, or null if none exists.
     *
     * A null return IS the cross-tenant denial signal — callers should treat "no
     * membership" as "not a member of this tenant, deny."
     *
     * @param  string $orgId
     * @param  string $userId
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function membership($orgId, $userId)
    {
        return $this->fetchRow(
            $this->activeSelect()->where('org_id = ?', $orgId)->where('user_id = ?', $userId)
        ) ?: null;
    }

    /**
     * The role a user holds in an org, or null if they are not a member.
     *
     * @param  string $orgId
     * @param  string $userId
     * @return string|null
     */
    public function roleOf($orgId, $userId)
    {
        $row = $this->membership($orgId, $userId);
        return $row ? $row->role : null;
    }

    /**
     * All memberships for a user (i.e. every org they belong to). Useful for an
     * org switcher / "your organizations" list.
     *
     * @param  string $userId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function orgsForUser($userId)
    {
        return $this->fetchAll($this->activeSelect()->where('user_id = ?', $userId));
    }

    /**
     * All memberships in an org (i.e. its members). Useful for a team/members list.
     *
     * @param  string $orgId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function usersInOrg($orgId)
    {
        return $this->fetchAll($this->activeSelect()->where('org_id = ?', $orgId));
    }
}
