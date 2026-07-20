<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Backup_Archive — a tiny zip read/write shim that works with or without ext-zip.
 *
 * Backups are always real `.zip` files (openable anywhere). ext-zip (`ZipArchive`) is preferred when
 * present, but Tiger targets cPanel/shared hosting where it isn't guaranteed — so this falls back to
 * `PharData` (ext-phar, effectively universal, needs only zlib for compression), which produces a
 * standard zip too. Callers never touch either class directly.
 *
 * @api
 */
class Tiger_Backup_Archive
{
    /** True if some archiver backend is available. */
    public static function available()
    {
        return class_exists('ZipArchive') || class_exists('PharData');
    }

    /**
     * Build a zip from a list of entries.
     *
     * @param  string $zipPath  the archive to create (overwrites)
     * @param  array  $entries  each ['name' => archiveName] with either 'file' => absPath or 'data' => bytes
     * @return void
     * @throws RuntimeException on failure / no backend
     */
    public static function build($zipPath, array $entries)
    {
        @unlink($zipPath);

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create archive ' . $zipPath);
            }
            foreach ($entries as $en) {
                if (isset($en['file'])) { $zip->addFile($en['file'], $en['name']); }
                else { $zip->addFromString($en['name'], (string) ($en['data'] ?? '')); }
            }
            if (!$zip->close()) { throw new RuntimeException('Failed to finalize archive.'); }
            return;
        }

        if (class_exists('PharData')) {
            $p = new PharData($zipPath, 0, null, Phar::ZIP);
            foreach ($entries as $en) {
                if (isset($en['file'])) { $p->addFile($en['file'], $en['name']); }
                else { $p->addFromString($en['name'], (string) ($en['data'] ?? '')); }
            }
            try { $p->compressFiles(Phar::GZ); } catch (Throwable $e) { /* store uncompressed if deflate unavailable */ }
            unset($p);   // flush to disk
            return;
        }

        throw new RuntimeException('No zip backend available (need ext-zip or ext-phar).');
    }

    /**
     * Extract an entire archive into a directory.
     *
     * @param  string $zipPath
     * @param  string $destDir
     * @return void
     * @throws RuntimeException on failure
     */
    public static function extract($zipPath, $destDir)
    {
        @is_dir($destDir) || @mkdir($destDir, 0775, true);

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) { throw new RuntimeException('Cannot open archive ' . $zipPath); }
            if (!$zip->extractTo($destDir)) { $zip->close(); throw new RuntimeException('Extract failed.'); }
            $zip->close();
            return;
        }
        if (class_exists('PharData')) {
            $p = new PharData($zipPath);
            $p->extractTo($destDir, null, true);   // overwrite
            return;
        }
        throw new RuntimeException('No zip backend available.');
    }

    /**
     * Read a single entry's bytes (e.g. manifest.json), or false if absent/unreadable.
     *
     * @param  string $zipPath
     * @param  string $name
     * @return string|false
     */
    public static function read($zipPath, $name)
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) { return false; }
            $data = $zip->getFromName($name);
            $zip->close();
            return $data;
        }
        if (class_exists('PharData')) {
            $stream = 'phar://' . $zipPath . '/' . $name;
            return is_file($stream) ? @file_get_contents($stream) : false;
        }
        return false;
    }
}
