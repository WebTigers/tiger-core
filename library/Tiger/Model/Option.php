<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Option — the LAZY, on-demand scoped key/value store (see migration 0031).
 *
 * The sibling of Tiger_Model_Config, and the other half of the config-discipline split:
 * `config` is EAGER (every row folded into the Zend_Config cascade on every request);
 * `option` is LAZY (a row is read only when its owner asks). Reach for this — never the
 * `config` tier — for on-demand per-user / per-entity state: a user's dashboard widget
 * layout, dismissed notices, a wizard's saved progress. If a value should influence the
 * request-wide config cascade, it belongs in `config`; if it's private state read only by
 * the feature that wrote it, it belongs here.
 *
 * Same (scope, scope_id, key) shape as config — this is a disciplined store, not a
 * wp_options grab-bag. Values are strings; use getJson()/setJson() for structured values.
 *
 * @api
 */
class Tiger_Model_Option extends Tiger_Model_Table
{
    protected $_name    = 'option';
    protected $_primary = 'option_id';

    const SCOPE_GLOBAL = 'global';
    const SCOPE_ORG    = 'org';
    const SCOPE_USER   = 'user';

    /**
     * Fetch a single option value, or null.
     *
     * @param  string $scope   the scope (global/org/user)
     * @param  string $scopeId the scope id ('' for global)
     * @param  string $key     the dot-notation option key
     * @return string|null the value, or null when unset
     */
    public function get($scope, $scopeId, $key)
    {
        $row = $this->fetchRow(
            $this->activeSelect()
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', (string) $scopeId)
                ->where('option_key = ?', $key)
        );
        return $row ? $row->option_value : null;
    }

    /**
     * Upsert an option value for a scope. Returns the option_id.
     *
     * @param  string $scope   the scope (global/org/user)
     * @param  string $scopeId the scope id ('' for global)
     * @param  string $key     the dot-notation option key
     * @param  string $value   the value to store
     * @return string the option_id
     */
    public function set($scope, $scopeId, $key, $value)
    {
        $existing = $this->fetchRow(
            $this->activeSelect()
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', (string) $scopeId)
                ->where('option_key = ?', $key)
        );
        if ($existing) {
            $this->update(
                ['option_value' => $value],
                $this->getAdapter()->quoteInto('option_id = ?', $existing->option_id)
            );
            return $existing->option_id;
        }
        return $this->insert([
            'scope'        => $scope,
            'scope_id'     => (string) $scopeId,
            'option_key'   => $key,
            'option_value' => $value,
        ]);
    }

    /**
     * Fetch a JSON option decoded to an array, or $default when unset/unparseable.
     *
     * @param  string $scope
     * @param  string $scopeId
     * @param  string $key
     * @param  mixed  $default returned when the key is missing or the value isn't valid JSON
     * @return mixed
     */
    public function getJson($scope, $scopeId, $key, $default = null)
    {
        $raw = $this->get($scope, $scopeId, $key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /**
     * Upsert a structured value as JSON. Returns the option_id.
     *
     * @param  string $scope
     * @param  string $scopeId
     * @param  string $key
     * @param  mixed  $value any JSON-encodable value
     * @return string the option_id
     */
    public function setJson($scope, $scopeId, $key, $value)
    {
        return $this->set($scope, $scopeId, $key, (string) json_encode($value));
    }

    /**
     * Remove an option (soft-delete) for a scope. No-op if it doesn't exist.
     *
     * @param  string $scope
     * @param  string $scopeId
     * @param  string $key
     * @return void
     */
    public function forget($scope, $scopeId, $key)
    {
        $row = $this->fetchRow(
            $this->activeSelect()
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', (string) $scopeId)
                ->where('option_key = ?', $key)
        );
        if ($row) {
            $this->softDelete($this->getAdapter()->quoteInto('option_id = ?', $row->option_id));
        }
    }
}
