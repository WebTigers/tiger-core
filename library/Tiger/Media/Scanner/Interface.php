<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Scanner_Interface — a pluggable content scanner for uploads.
 *
 * Implementations examine a file before it's trusted: a virus scanner (ClamAV), an AI
 * moderation scanner (AWS Rekognition), etc. All are OPTIONAL and config-gated — the
 * upload pipeline (Tiger_Media_Scan) only runs the ones turned on.
 *
 * @api
 */
interface Tiger_Media_Scanner_Interface
{
    /**
     * Scan a file on disk. Never throws — transport/tooling failures come back as
     * `error` so the orchestrator can apply its fail-open/closed policy.
     *
     * @return array{status:string, reason:?string, meta:array}
     *         status: `clean` | `infected` | `rejected` (failed AI moderation) | `error`
     */
    public function scan(string $path, ?string $mime = null): array;
}
