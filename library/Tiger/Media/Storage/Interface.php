<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Storage_Interface — the pluggable storage backend for media bytes.
 *
 * The `media` table holds metadata; the actual bytes live behind one of these adapters
 * (filesystem / S3 / GCS / Azure), selected per-file by the row's `disk`. `visibility`
 * (public|private) is passed to every locating call because it can change WHERE/HOW a
 * backend stores or serves an object (a filesystem adapter keeps public files under the
 * docroot and private files outside it; S3 sets the object ACL). Keys are adapter-relative,
 * tenant-namespaced paths built by the upload service — `<org_id>/<kind-folder>/<rand>.<ext>`
 * (e.g. `3f2504e0-…/images/ab12….jpg`); the adapter prepends the visibility root
 * (`public/` | `private/`). The immutable `<org_id>` segment keeps tenants' bytes separated.
 *
 * @api
 */
interface Tiger_Media_Storage_Interface
{
    /** Store bytes from a source file path (e.g. an upload tmp file). */
    public function put($key, $sourcePath, $visibility, $mime = null);

    /** Store raw bytes (e.g. a generated thumbnail held in memory). */
    public function write($key, $bytes, $visibility, $mime = null);

    /** Read all bytes. */
    public function get($key, $visibility);

    /** Open a read stream (for large files — avoids loading them fully into memory). */
    public function stream($key, $visibility);

    /** Remove the object (idempotent — missing is not an error). */
    public function delete($key, $visibility);

    /** Does the object exist? */
    public function exists($key, $visibility);

    /** Size in bytes (0 if missing). */
    public function size($key, $visibility);

    /**
     * A directly-usable URL, or '' when the caller must serve it itself. Public objects
     * always yield a URL (direct path / CDN); private objects yield a signed URL when the
     * backend supports one (S3 presign), else '' — the media layer then serves it through
     * the ACL-checked streamer route (/media/file/<id>).
     *
     * @param int|null $ttl seconds for a signed URL (backend default when null)
     */
    public function url($key, $visibility, $ttl = null);
}
