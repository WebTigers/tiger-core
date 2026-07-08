<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Config — the runtime config override layer (see migration 0009).
 *
 * Read by Tiger_Application_Bootstrap::_initConfigs, which folds the rows onto the
 * ini config cascade (global first, then the current org). Values are dot-notation
 * keys (`tiger.skin`) mapped into the nested Zend_Config. This is also the per-org
 * theming resolver — an org row `tiger.skin` reskins that org.
 *
 * @api
 */
class Tiger_Model_Config extends Tiger_Model_Table
{
    protected $_name    = 'config';
    protected $_primary = 'config_id';

    const SCOPE_GLOBAL = 'global';
    const SCOPE_ORG    = 'org';
    const SCOPE_USER   = 'user';

    /**
     * Active config rows for a scope (+ optional scope id). Global uses scope_id ''.
     *
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function getForScope($scope, $scopeId = '')
    {
        return $this->fetchAll(
            $this->activeSelect()
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', (string) $scopeId)
        );
    }

    /** A single config value, or null. */
    public function get($scope, $scopeId, $key)
    {
        $row = $this->fetchRow(
            $this->activeSelect()
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', (string) $scopeId)
                ->where('config_key = ?', $key)
        );
        return $row ? $row->config_value : null;
    }

    /**
     * Upsert a config value for a scope. Returns the config_id.
     *
     * @return string
     */
    public function set($scope, $scopeId, $key, $value)
    {
        $existing = $this->fetchRow(
            $this->activeSelect()
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', (string) $scopeId)
                ->where('config_key = ?', $key)
        );
        if ($existing) {
            $this->update(
                ['config_value' => $value],
                $this->getAdapter()->quoteInto('config_id = ?', $existing->config_id)
            );
            return $existing->config_id;
        }
        return $this->insert([
            'scope'        => $scope,
            'scope_id'     => (string) $scopeId,
            'config_key'   => $key,
            'config_value' => $value,
        ]);
    }
}
