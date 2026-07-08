<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Scanner_ClamAv — virus scan via ClamAV.
 *
 * Prefers the daemon client `clamdscan` (the signature DB stays resident in clamd → fast;
 * `--fdpass` hands clamd the open descriptor so it can read the PHP upload tmp file
 * regardless of its user). If the daemon isn't reachable (clamdscan errors) or isn't
 * installed, it falls back to standalone `clamscan`, which reloads the ~108 MB signature DB on
 * every call — measured ~17s per scan and ~1 GB transient. That fallback is a degraded
 * last-resort (a scan that slow blocks the upload request); production should always run the
 * clamd daemon on a ≥4 GB host (it holds the DB resident → ~10-20 ms scans). The php-fpm user
 * needs membership in clamd's socket group and `--fdpass` reads the upload tmp file regardless
 * of owner — see MEDIA.md §4 for the daemon requirement + ops notes.
 *
 * @api
 */
class Tiger_Media_Scanner_ClamAv implements Tiger_Media_Scanner_Interface
{
    public function scan(string $path, ?string $mime = null): array
    {
        // Daemon client first; on a definite verdict (clean/infected) use it.
        $r = $this->_run('clamdscan', '--fdpass --no-summary', $path);
        if ($r !== null && $r['status'] !== 'error') {
            return $r;
        }
        // No daemon (or a scan error) -> standalone clamscan.
        $fallback = $this->_run('clamscan', '--no-summary', $path);
        if ($fallback !== null) {
            return $fallback;
        }
        return $r ?? ['status' => 'error', 'reason' => 'clamav not installed', 'meta' => []];
    }

    /** Run a ClamAV client; null if the binary is absent. Exit 0=clean, 1=infected, 2=error. */
    protected function _run(string $bin, string $flags, string $path): ?array
    {
        if (!$this->_which($bin)) {
            return null;
        }
        $out  = [];
        $code = 2;
        exec($bin . ' ' . $flags . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        $output = trim(implode("\n", $out));

        if ($code === 0) {
            return ['status' => 'clean', 'reason' => null, 'meta' => ['scanner' => $bin]];
        }
        if ($code === 1) {
            $sig = preg_match('/:\s*(.+?)\s+FOUND/', $output, $m) ? trim($m[1]) : 'malware';
            return ['status' => 'infected', 'reason' => $sig, 'meta' => ['scanner' => $bin, 'signature' => $sig]];
        }
        return ['status' => 'error', 'reason' => 'scan error', 'meta' => ['scanner' => $bin, 'output' => substr($output, 0, 300)]];
    }

    protected function _which(string $bin): bool
    {
        $w = [];
        $c = 1;
        exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $w, $c);
        return $c === 0 && !empty($w);
    }
}
