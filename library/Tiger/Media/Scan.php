<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Scan — the upload scanning orchestrator.
 *
 * Runs the config-gated pre-store scanners (virus, then AI image moderation) and reports a
 * verdict the upload service acts on. Videos are handled asynchronously (they're stored
 * `in_review` + private and a job is submitted; a webhook resolves them) — see videoReview().
 *
 * Policy: an INFECTED / REJECTED verdict blocks the upload with a message. A scanner ERROR
 * is **fail-open** (store, but leave `scan_status = skipped` and log) so a clamd/Rekognition
 * hiccup can't halt all uploads; tighten per deploy if you need fail-closed.
 *
 * All scanners default OFF (`media.scan.*`), so with nothing configured this is a no-op
 * that returns `{ok:true, status:'skipped'}`.
 *
 * @api
 */
class Tiger_Media_Scan
{
    /**
     * Pre-store verdict for a file.
     *
     * @return array{ok:bool, status:string, message:?string, meta:array}
     *         ok=false → reject (message is an i18n key); status maps to `media.scan_status`.
     */
    public function preStore(string $path, ?string $mime, string $kind): array
    {
        $meta   = [];
        $status = Tiger_Model_Media::SCAN_SKIPPED;

        if ($this->_on('clamav')) {
            $r = (new Tiger_Media_Scanner_ClamAv())->scan($path, $mime);
            $meta['clamav'] = $r;
            if ($r['status'] === 'infected') {
                return ['ok' => false, 'status' => Tiger_Model_Media::SCAN_INFECTED, 'message' => 'media.error.infected', 'meta' => $meta];
            }
            if ($r['status'] === 'clean') {
                $status = Tiger_Model_Media::SCAN_CLEAN;
            } elseif ($r['status'] === 'error') {
                $this->_log('clamav scan error: ' . ($r['reason'] ?? ''));   // fail-open
            }
        }

        if ($this->_on('image') && $kind === Tiger_Model_Media::KIND_IMAGE) {
            $r = (new Tiger_Media_Scanner_Rekognition($this->_threshold(), $this->_region()))->scan($path, $mime);
            $meta['image'] = $r;
            if ($r['status'] === 'rejected') {
                return ['ok' => false, 'status' => Tiger_Model_Media::SCAN_REJECTED, 'message' => 'media.error.moderation', 'meta' => $meta];
            }
            if ($r['status'] === 'clean') {
                $status = Tiger_Model_Media::SCAN_CLEAN;
            } elseif ($r['status'] === 'error') {
                $this->_log('rekognition image error: ' . ($r['reason'] ?? ''));   // fail-open
            }
        }

        return ['ok' => true, 'status' => $status, 'message' => null, 'meta' => $meta];
    }

    /** Should a video be held for async AI review (stored private + in_review)? */
    public function videoReview(): bool
    {
        return $this->_on('video');
    }

    protected function _on(string $key): bool
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $scan = ($cfg && $cfg->get('media') && $cfg->media->get('scan')) ? $cfg->media->scan : null;
        return $scan ? ((int) $scan->get($key) === 1) : false;
    }

    protected function _threshold(): float
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $scan = ($cfg && $cfg->get('media') && $cfg->media->get('scan')) ? $cfg->media->scan : null;
        $t = $scan ? (float) $scan->get('image_threshold') : 0;
        return $t > 0 ? $t : 80.0;
    }

    protected function _region(): string
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $aws = $cfg ? $cfg->get('tiger') : null;
        return 'us-east-1';
    }

    protected function _log(string $message): void
    {
        if (class_exists('Tiger_Log')) {
            try { Tiger_Log::warn($message); return; } catch (Throwable $e) {}
        }
        error_log('Tiger_Media_Scan: ' . $message);
    }
}
