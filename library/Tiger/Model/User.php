<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * User — a person / identity. Deliberately THIN.
 *
 * A User row is PURE IDENTITY: id, email, optional username, and status. That's it.
 * NO credentials, NO profile, NO org, NO role — none of those belong on the user:
 *
 *   - CREDENTIALS (password, SMS/phone, TOTP, passkeys, SSO) live in
 *     `user_credential` (Tiger_Model_UserCredential), 1-to-many, because auth is
 *     multi-factor and a credential is not identity.
 *   - Profile/richness (name, avatar, phone-as-contact, preferences) belongs to an
 *     Account MODULE that extends User via its own FK-linked table, so the platform
 *     updates without colliding with app-specific profile shapes.
 *   - A user's relationship to a tenant — and their ROLE — lives on org_user
 *     (Tiger_Model_OrgUser), because a user can belong to many orgs with a different
 *     role in each. A role on the user would force one global role and break
 *     multi-tenancy.
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
     * Resolve a login IDENTIFIER to a user — the one entry point every login flow uses.
     * An identifier is what you claim to be; the CREDENTIAL (password / code / passkey)
     * that proves it is verified separately, afterward.
     *
     * Resolution order, first hit wins:
     *   1. email    — the canonical identity column (unique)
     *   2. username — identity column (unique)
     *   3. a verified factor `identifier` in user_credential — the (type, identifier)
     *      reverse-lookup the schema was built for: phone (sms, E.164), oauth subject,
     *      webauthn cred-id. Singleton factors (password/totp) carry identifier='' and
     *      never match here, and a pending/unverified factor must not resolve identity.
     *
     * Email-first keeps things deterministic if a username ever equals another user's
     * email local-part. Phone/oauth/passkey login all fall out of step 3 for free once
     * those factors exist (phone-number normalization lands with the SMS factor).
     *
     * @param  string $identifier email | username | a verified factor identifier
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function findByIdentifier($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        // 1 + 2: identity columns on the user row.
        $user = $this->findByEmail($identifier)
            ?: ($this->fetchRow($this->activeSelect()->where('username = ?', $identifier)) ?: null);
        if ($user) {
            return $user;
        }

        // 3: a verified factor identifier (phone / oauth subject / webauthn cred-id).
        $db  = $this->getAdapter();
        $uid = $db->fetchOne(
            $db->select()
               ->from('user_credential', ['user_id'])
               ->where('identifier = ?', $identifier)
               ->where("identifier <> ''")           // exclude singleton factors (password/totp)
               ->where('status = ?', 'active')
               ->where('deleted = ?', 0)
               ->where('verified_at IS NOT NULL')     // pending factors don't resolve identity
               ->limit(1)
        );
        return $uid ? $this->findById($uid) : null;
    }

    /**
     * DataTables data for the Users admin: identity + a membership summary (org count
     * and the distinct roles held, via org_user). Owns the query; the service handles
     * presentation + ACL. Returns total (scoped), filtered (scoped + search), and one
     * page of rows.
     *
     * @param array{search?:string,status?:string,orderCol?:int,orderDir?:string,offset?:int,limit?:int} $opts
     * @return array{total:int,filtered:int,rows:array}
     */
    public function datatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $status = (string) ($opts['status'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 25));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $orderCols = [0 => 'u.email', 1 => 'u.username', 3 => 'org_count', 4 => 'u.status', 5 => 'u.created_at'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'ASC') ? 'ASC' : 'DESC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'u.created_at DESC';

        $scope = function ($sel) use ($status) {
            $sel->where('u.deleted = 0');
            if ($status !== '') { $sel->where('u.status = ?', $status); }
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $parts = [];
            foreach (['u.email', 'u.username'] as $c) { $parts[] = $db->quoteInto("$c LIKE ?", $like); }
            $sel->where('(' . implode(' OR ', $parts) . ')');
        };

        $totalSel = $db->select()->from(['u' => $this->_name], ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($totalSel);
        $total = (int) $db->fetchOne($totalSel);

        $filteredSel = $db->select()->from(['u' => $this->_name], ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($filteredSel); $searchFn($filteredSel);
        $filtered = (int) $db->fetchOne($filteredSel);

        $pageSel = $db->select()
            ->from(['u' => $this->_name], ['user_id', 'email', 'username', 'status', 'created_at'])
            ->joinLeft(['ou' => 'org_user'], 'ou.user_id = u.user_id AND ou.deleted = 0', [
                'org_count'  => new Zend_Db_Expr('COUNT(DISTINCT ou.org_id)'),
                'role_names' => new Zend_Db_Expr("GROUP_CONCAT(DISTINCT ou.role ORDER BY ou.role SEPARATOR ', ')"),
            ])
            ->group('u.user_id')
            ->order(new Zend_Db_Expr($orderSql))
            ->limit($limit, $offset);
        $scope($pageSel); $searchFn($pageSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($pageSel)];
    }

    /** Is $value already used in $col (email|username) by a different, non-deleted user? */
    public function isTaken($col, $value, $excludeId = null)
    {
        if (!in_array($col, ['email', 'username'], true)) {
            return false;
        }
        $sel = $this->activeSelect()->where("$col = ?", (string) $value);
        if ($excludeId) { $sel->where('user_id != ?', (string) $excludeId); }
        return (bool) $this->fetchRow($sel);
    }
}
