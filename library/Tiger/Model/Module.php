<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Model_Module — the module lifecycle registry (see migration 0023).
 *
 * The source of truth for which modules are ACTIVE. `inactiveSlugs()` is the hot path the
 * boot-time gate (Tiger_Application_Resource_Modules) calls to strip deactivated modules from
 * the controller-directory map — it must stay a single cheap indexed query. Everything else
 * feeds the Modules admin + (later) the installer.
 *
 * @api
 */
class Tiger_Model_Module extends Tiger_Model_Table
{
    protected $_name    = 'module';
    protected $_primary = 'module_id';

    const SOURCE_REGISTRY   = 'registry';
    const SOURCE_URL        = 'url';
    const SOURCE_UPLOAD     = 'upload';
    const SOURCE_DISCOVERED = 'discovered';

    /**
     * Slugs of deactivated modules (active = 0). The gate's query — keep it lean.
     *
     * @return array<int,string> the slugs of inactive modules
     */
    public function inactiveSlugs()
    {
        $db = $this->getAdapter();
        return $db->fetchCol($db->select()->from($this->_name, ['slug'])->where('active = 0'));
    }

    /**
     * One row by slug, or null.
     *
     * @param string $slug the module slug
     * @return Zend_Db_Table_Row_Abstract|null the module row, or null if none
     */
    public function bySlug($slug)
    {
        return $this->fetchRow($this->select()->where('slug = ?', (string) $slug));
    }

    /**
     * All rows keyed by slug — for overlaying state onto discovered modules.
     *
     * @return array<string,Zend_Db_Table_Row_Abstract> module rows keyed by slug
     */
    public function bySlugMap()
    {
        $out = [];
        foreach ($this->fetchAll($this->select()) as $r) {
            $out[$r->slug] = $r;
        }
        return $out;
    }

    /**
     * Set a module's active state (upsert). A discovered module gets a row the first time it's
     * toggled; an installer-managed row keeps its provenance. Returns the row id.
     *
     * @param string $slug   the module slug
     * @param bool   $active the desired active state
     * @param array  $meta   optional provenance for a new row (source, name, version)
     * @return string the module_id
     */
    public function setActive($slug, $active, array $meta = [])
    {
        $row  = $this->bySlug($slug);
        $data = [
            'active' => $active ? 1 : 0,
            'status' => $active ? 'active' : 'inactive',
        ];
        if ($row) {
            $this->update($data, $this->getAdapter()->quoteInto('slug = ?', (string) $slug));
            return $row->module_id;
        }
        $data['slug']    = (string) $slug;
        $data['source']  = $meta['source']  ?? self::SOURCE_DISCOVERED;
        $data['name']    = $meta['name']    ?? null;
        $data['version'] = $meta['version'] ?? null;
        return $this->insert($data);
    }

    /**
     * Record an installed (or updated) module with full provenance + active. Returns the id.
     *
     * @param string $slug the module slug
     * @param array  $meta provenance (name, version, repository, ref, source)
     * @return string the module_id
     */
    public function install($slug, array $meta)
    {
        $data = [
            'name'       => $meta['name']       ?? null,
            'version'    => $meta['version']    ?? null,
            'repository' => $meta['repository'] ?? null,
            'ref'        => $meta['ref']        ?? null,
            'source'     => $meta['source']     ?? self::SOURCE_URL,
            'active'     => 1,
            'status'     => 'active',
        ];
        $row = $this->bySlug($slug);
        if ($row) {
            $this->update($data, $this->getAdapter()->quoteInto('slug = ?', (string) $slug));
            return $row->module_id;
        }
        $data['slug'] = (string) $slug;
        return $this->insert($data);
    }

    /**
     * Drop a module's registry row (uninstall).
     *
     * @param string $slug the module slug
     * @return int the number of rows deleted
     */
    public function uninstall($slug)
    {
        return $this->delete($this->getAdapter()->quoteInto('slug = ?', (string) $slug));
    }
}
