<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * PageVersion — append-only page history (see migration 0015).
 *
 * Tiger_Model_Page::save() snapshots the saved content here as an incrementing
 * `version` on every save (so `page_version[max]` mirrors the live page), and
 * restore() writes a chosen version back onto the page. Immutable: no update / no
 * soft-delete.
 *
 * @api
 */
class Tiger_Model_PageVersion extends Tiger_Model_Table
{
    protected $_name    = 'page_version';
    protected $_primary = 'page_version_id';

    /** The next version number for a page (1-based). */
    public function nextVersion($pageId)
    {
        $db  = $this->getAdapter();
        $max = (int) $db->fetchOne(
            $db->select()
                ->from($this->_name, ['m' => new Zend_Db_Expr('MAX(version)')])
                ->where('page_id = ?', (string) $pageId)
        );
        return $max + 1;
    }

    /** Snapshot a page's content as a new version. Returns the version number. */
    public function snapshot($pageId, array $fields)
    {
        $version = $this->nextVersion($pageId);
        $this->insert([
            'page_id' => (string) $pageId,
            'version' => $version,
            'title'   => $fields['title']  ?? null,
            'body'    => $fields['body']   ?? null,
            'format'  => $fields['format'] ?? 'html',
            'meta'    => $fields['meta']   ?? null,
            'status'  => $fields['status'] ?? null,
        ]);
        return $version;
    }

    /** A page's versions, newest first. */
    public function recentForPage($pageId, $limit = 50)
    {
        return $this->fetchAll(
            $this->select()->where('page_id = ?', (string) $pageId)->order('version DESC')->limit((int) $limit)
        );
    }

    /** One version of a page, or null. */
    public function get($pageId, $version)
    {
        return $this->fetchRow(
            $this->select()->where('page_id = ?', (string) $pageId)->where('version = ?', (int) $version)
        );
    }
}
