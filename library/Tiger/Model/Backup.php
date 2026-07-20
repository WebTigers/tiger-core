<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Model_Backup — the catalog of backup archives (metadata; the bytes live on a disk).
 *
 * A row is written `running` when a backup starts and flipped to `ok`/`error` at finish, carrying
 * the `disk` + `storage_key` where its zip lives so the catalog is self-locating (no remote list).
 * `pinned` (set on manual backups) exempts a row from rolling retention — "manual remove only".
 *
 * @api
 */
class Tiger_Model_Backup extends Tiger_Model_Table
{
    protected $_name    = 'backup';
    protected $_primary = 'backup_id';

    /**
     * Open a backup record (outcome `running`).
     *
     * @param  string $filename   the archive filename
     * @param  string $disk        destination disk (local | a media disk name)
     * @param  array  $components  selected components
     * @param  string $source      manual|scheduled
     * @return string the new backup_id
     */
    public function begin($filename, $disk, array $components, $source)
    {
        return $this->insert([
            'filename'   => (string) $filename,
            'disk'       => (string) $disk,
            'components' => implode(',', $components),
            'source'     => $source === 'scheduled' ? 'scheduled' : 'manual',
            'pinned'     => $source === 'scheduled' ? 0 : 1,
            'outcome'    => 'running',
            'status'     => 'active',
        ]);
    }

    /**
     * Finish a backup record.
     *
     * @param  string  $id        backup_id
     * @param  string  $outcome   ok|error
     * @param  array   $fields    storage_key, size_bytes, checksum, manifest, duration_ms, error
     * @return void
     */
    public function finish($id, $outcome, array $fields = [])
    {
        $data = ['outcome' => $outcome === 'ok' ? 'ok' : 'error'];
        foreach (['storage_key', 'size_bytes', 'checksum', 'manifest', 'duration_ms', 'error'] as $k) {
            if (array_key_exists($k, $fields)) { $data[$k] = $fields[$k]; }
        }
        $this->update($data, $this->getAdapter()->quoteInto('backup_id = ?', $id));
    }

    /**
     * Recent backups (newest first), non-deleted.
     *
     * @param  int $limit
     * @return array
     */
    public function recent($limit = 50)
    {
        $select = $this->activeSelect()->order('created_at DESC')->limit((int) $limit);
        return $this->getAdapter()->fetchAll($select);
    }

    /**
     * The scheduled+ok backups eligible for pruning, oldest first (excludes pinned/manual).
     *
     * @return array
     */
    public function prunable()
    {
        $select = $this->activeSelect()
            ->where('source = ?', 'scheduled')
            ->where('pinned = ?', 0)
            ->where('outcome = ?', 'ok')
            ->order('created_at ASC');
        return $this->getAdapter()->fetchAll($select);
    }
}
