<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Base table-gateway for Tiger models.
 *
 * Extends ZF1's Zend_Db_Table_Abstract (Table Data Gateway) and maintains the
 * standard boilerplate every Tiger domain table carries — automatically, and only
 * when the column actually exists (so the base is safe for tables that omit some):
 *
 *   1. UUID primary keys, generated in PHP on insert (v7 by default — see
 *      Tiger_Uuid for why; set $_uuidVersion = 4 for opaque-timing tables).
 *   2. created_at / updated_at timestamps.
 *   3. created_by / updated_by actor stamps — the user_id from the current actor
 *      (see setActor()/actor()); NULL when there's no actor (system/genesis/CLI).
 *   4. deleted soft-delete flag — softDelete()/restore() flip it, and reads exclude
 *      deleted rows by default (findById, activeSelect).
 *
 * Because the PK is a client-generated UUID (not auto-increment), insert() is
 * overridden to (a) mint the id and (b) return the UUID string — ZF1's default
 * returns lastInsertId(), meaningless for a string PK.
 *
 * CONVENTION: Tiger domain tables carry status, deleted, created_by, updated_by,
 * created_at, updated_at. Audit TRAILS (change history) are an app concern, not
 * core's — an app adds audit tables if it needs them.
 *
 * Subclasses declare $_name and $_primary and (optionally) domain helper methods
 * that build on activeSelect() so soft-deleted rows stay hidden. Metadata is
 * lazy-loaded on first use, so this class is cheap to load without a DB.
 *
 * @api
 */
abstract class Tiger_Model_Table extends Zend_Db_Table_Abstract
{
    /**
     * UUID version for this table's primary key. 7 (time-ordered) is the default
     * and correct for entities; override to 4 for tables whose ids must not leak
     * creation time (tokens, secrets).
     *
     * @var int
     */
    protected $_uuidVersion = 7;

    /** Cached column list for this table (populated lazily). @var string[]|null */
    private $_colsCache = null;

    /**
     * The "current actor" — the user_id credited in created_by / updated_by,
     * request-wide (hence static). The auth layer calls setActor() after login;
     * CLI / system inserts leave it null, so those rows get created_by = NULL
     * (= system/genesis).
     *
     * @var string|null
     */
    private static $_actor = null;

    /**
     * Set the current actor (a user_id). Auth calls this on login.
     *
     * @param  string|null $userId the acting user's id, or null for system/CLI
     * @return void
     */
    public static function setActor($userId)
    {
        self::$_actor = $userId;
    }

    /**
     * The current actor's user_id, or null in a system/CLI context.
     *
     * @return string|null the acting user's id, or null
     */
    public static function actor()
    {
        return self::$_actor;
    }

    /**
     * The "current org" — the tenant an insert is credited to, stamped into `org_id` the same way the
     * actor is stamped into created_by/updated_by. Request-wide (static). The auth layer calls setOrg()
     * per request with the user's active org; null leaves org_id at its column default ('' = platform/
     * global — a system row, a shipped translation). The multi-site module overrides this per domain.
     *
     * @var string|null
     */
    private static $_org = null;

    /**
     * Set the current org (an org_id). Auth calls this per request from the active membership; a
     * multi-site module overrides it from the request host. An explicitly passed org_id always wins.
     *
     * @param  string|null $orgId the acting org's id, or null for platform/global scope
     * @return void
     */
    public static function setOrg($orgId)
    {
        self::$_org = $orgId;
    }

    /**
     * The current org_id, or null in a platform/global context.
     *
     * @return string|null the acting org's id, or null
     */
    public static function org()
    {
        return self::$_org;
    }

    /**
     * Insert a row: mint the UUID PK, stamp created_at/updated_at and (if an actor
     * is set) created_by/updated_by. Any of these you pass explicitly wins.
     *
     * @param  array $data column => value (omit the PK — we generate it)
     * @return string the generated UUID primary key
     */
    public function insert(array $data)
    {
        $pk = $this->_primaryColumn();
        if (empty($data[$pk])) {
            $data[$pk] = ($this->_uuidVersion === 4) ? Tiger_Uuid::v4() : Tiger_Uuid::v7();
        }

        $now = $this->_now();
        if ($this->_hasColumn('created_at') && empty($data['created_at'])) {
            $data['created_at'] = $now;
        }
        if ($this->_hasColumn('updated_at') && !array_key_exists('updated_at', $data)) {
            $data['updated_at'] = $now;
        }

        $actor = self::$_actor;
        if ($actor !== null) {
            if ($this->_hasColumn('created_by') && !array_key_exists('created_by', $data)) {
                $data['created_by'] = $actor;
            }
            if ($this->_hasColumn('updated_by') && !array_key_exists('updated_by', $data)) {
                $data['updated_by'] = $actor;
            }
        }

        // Tenant stamp: a row on an org_id table is credited to the current org, so content is owned
        // rather than left at the '' default. Only when an org is set (an authenticated request) and the
        // caller didn't pass org_id explicitly — system/global writes (no org) keep the column default.
        if (self::$_org !== null && $this->_hasColumn('org_id') && !array_key_exists('org_id', $data)) {
            $data['org_id'] = self::$_org;
        }

        parent::insert($data);

        // Return the UUID we generated — NOT lastInsertId(), which is empty for a
        // client-generated string PK.
        return $data[$pk];
    }

    /**
     * Update rows: refresh updated_at and (if an actor is set) updated_by.
     *
     * @param  array        $data
     * @param  array|string $where
     * @return int affected rows
     */
    public function update(array $data, $where)
    {
        if ($this->_hasColumn('updated_at')) {
            $data['updated_at'] = $this->_now();
        }
        $actor = self::$_actor;
        if ($actor !== null && $this->_hasColumn('updated_by') && !array_key_exists('updated_by', $data)) {
            $data['updated_by'] = $actor;
        }
        return parent::update($data, $where);
    }

    /**
     * Soft-delete: flip `deleted` to 1 (refreshing updated_at/updated_by) instead
     * of removing the row. Falls back to a hard delete if the table has no
     * `deleted` column, so the call still means "gone." Use delete() for a
     * deliberate hard delete.
     *
     * @param  array|string $where
     * @return int affected rows
     */
    public function softDelete($where)
    {
        if (!$this->_hasColumn('deleted')) {
            return $this->delete($where);
        }
        return $this->update(['deleted' => 1], $where);
    }

    /**
     * Reverse a soft delete: set `deleted` back to 0.
     *
     * @param  array|string $where
     * @return int affected rows
     */
    public function restore($where)
    {
        return $this->update(['deleted' => 0], $where);
    }

    /**
     * Fetch a single row by primary key, or null. Respects soft-delete: a deleted
     * row returns null unless $includeDeleted is true.
     *
     * @param  string $id
     * @param  bool   $includeDeleted
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function findById($id, $includeDeleted = false)
    {
        $row = $this->find($id)->current();
        if (!$row) {
            return null;
        }
        if (!$includeDeleted && $this->_hasColumn('deleted') && (int) $row->deleted === 1) {
            return null;
        }
        return $row;
    }

    /**
     * A select() pre-scoped to non-deleted rows (when the table has `deleted`).
     * Domain finders should build on THIS, not select(), so soft-deleted rows stay
     * hidden by default.
     *
     * @return Zend_Db_Table_Select
     */
    public function activeSelect()
    {
        $select = $this->select();
        if ($this->_hasColumn('deleted')) {
            $select->where($this->_name . '.deleted = ?', 0);
        }
        return $select;
    }

    /** The (single) primary-key column name. */
    protected function _primaryColumn()
    {
        // Zend stores _primary as a 1-indexed array; we assume single-column PKs.
        $primary = (array) $this->_primary;
        return reset($primary);
    }

    /** Does this table have the given column? (Cached.) */
    protected function _hasColumn($column)
    {
        if ($this->_colsCache === null) {
            $this->_colsCache = $this->info(self::COLS);
        }
        return in_array($column, $this->_colsCache, true);
    }

    /** Current timestamp in MySQL DATETIME format (server-local; write UTC upstream if desired). */
    protected function _now()
    {
        return date('Y-m-d H:i:s');
    }
}
