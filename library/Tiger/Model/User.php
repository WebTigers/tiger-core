<?php
/**
 * User — a person / identity. Deliberately THIN.
 *
 * A User row is the bare minimum to authenticate and be referenced: id, email,
 * optional username, a password hash, and status. That's it. NO profile fields,
 * NO org, NO role — those don't belong on the user:
 *
 *   - Profile/richness (name, avatar, phone, preferences) belongs to an Account
 *     MODULE that extends User via its own FK-linked table. Keeping it out of core
 *     means the platform can be updated without colliding with app-specific
 *     profile shapes.
 *   - A user's relationship to a tenant — and their ROLE — lives on org_user
 *     (Tiger_Model_OrgUser), because a user can belong to many orgs with a
 *     different role in each. Putting a role on the user would force a single
 *     global role and break multi-tenancy.
 *
 * password_hash is nullable: SSO-only / invited-but-not-yet-activated users have
 * no local password.
 *
 * @api
 */
class Tiger_Model_User extends Tiger_Model_Table
{
    protected $_name    = 'user';
    protected $_primary = 'user_id';

    /**
     * Find a user by email (the canonical login identifier; unique).
     *
     * @param  string $email
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function findByEmail($email)
    {
        return $this->fetchRow($this->activeSelect()->where('email = ?', $email)) ?: null;
    }

    /**
     * Hash a plaintext password for storage. Uses PASSWORD_DEFAULT (bcrypt today,
     * upgraded by PHP over time) — verification lives in the auth service, not here,
     * so the model stays a pure data gateway.
     *
     * @param  string $plain
     * @return string
     */
    public static function hashPassword($plain)
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}
