<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Logs — read-only access to the application log (the Logs screen + the AI agent).
 *
 * Tiger_Log is write-only (a facade over pluggable sinks); this is the read side. When the sink is a
 * file/stream (`tiger.log.writer = stream|file` → `tiger.log.stream.path`), `search` tails the file,
 * parses the JSON-per-line records, filters by minimum level + free text, and returns the newest
 * first — bounded (a capped tail + a result limit) so a huge log never blows memory. On a non-file
 * sink (errorlog / cloudwatch / …) it reports where the logs actually go instead of guessing.
 *
 * Superadmin-only (acl.ini): log context can carry error detail / PII. Because `search` is a
 * read-verb, the agent's Forge auto-runs it (as the acting user) — so "check the logs and tell me
 * what threw" works for a superadmin with no approval step, and is denied for anyone lower.
 *
 * @api
 */
class System_Service_Logs extends Tiger_Service_Service
{
    const DEFAULT_LIMIT   = 200;
    const MAX_LIMIT       = 1000;
    const MAX_SCAN_BYTES  = 3145728;   // 3 MB tail — bounds memory on a large log

    /** Severity rank (higher = more severe) for the "this level and above" filter. */
    const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'NOTICE' => 2, 'WARN' => 3, 'WARNING' => 3,
                    'ERR' => 4, 'ERROR' => 4, 'CRIT' => 5, 'CRITICAL' => 5, 'ALERT' => 6, 'EMERG' => 7];

    /**
     * Tail + filter the application log. Read-class, so the agent auto-runs it.
     *
     * @param  array $params level (min severity, e.g. WARN), q (free text), limit (default 200, max 1000)
     * @return void
     */
    public function search(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }   // defense-in-depth; acl.ini gates to superadmin

        $cfg    = Zend_Registry::get('Zend_Config');
        $logCfg = ($cfg->tiger && $cfg->tiger->log) ? $cfg->tiger->log : null;
        $writer = $logCfg ? strtolower((string) $logCfg->get('writer')) : 'errorlog';

        $path = '';
        if (in_array($writer, ['stream', 'file'], true) && $logCfg->get('stream') && $logCfg->stream->get('path')) {
            $path = (string) $logCfg->stream->path;
        }
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            // Not a readable file sink — say where the logs go rather than show nothing.
            $this->_success([
                'available' => false,
                'sink'      => $writer,
                'entries'   => [],
                'levels'    => self::_levelList(),
            ], 'core.api.success');
            return;
        }

        $limit    = min(self::MAX_LIMIT, max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $minRank  = self::LEVELS[strtoupper(trim((string) ($params['level'] ?? '')))] ?? -1;
        $q        = trim((string) ($params['q'] ?? ''));

        $lines   = $this->_tail($path, self::MAX_SCAN_BYTES);
        $entries = [];
        for ($i = count($lines) - 1; $i >= 0 && count($entries) < $limit; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') { continue; }       // skip blanks / non-JSON markers
            $e = json_decode($line, true);
            if (!is_array($e)) { continue; }
            $lvl = strtoupper((string) ($e['level'] ?? ''));
            if ($minRank >= 0 && (self::LEVELS[$lvl] ?? 99) < $minRank) { continue; }
            if ($q !== '') {
                $hay = (string) ($e['msg'] ?? '') . ' ' . json_encode($e['context'] ?? []);
                if (stripos($hay, $q) === false) { continue; }
            }
            $entries[] = [
                'ts'      => (string) ($e['ts'] ?? ''),
                'level'   => $lvl,
                'msg'     => (string) ($e['msg'] ?? ''),
                'context' => $e['context'] ?? new stdClass(),
            ];
        }

        $this->_success([
            'available' => true,
            'sink'      => $writer,
            'path'      => $path,
            'count'     => count($entries),
            'entries'   => $entries,
            'levels'    => self::_levelList(),
        ], 'core.api.success');
    }

    /**
     * Read the last $maxBytes of a file as lines (drops the leading partial line after a mid-file seek).
     *
     * @param  string $path
     * @param  int    $maxBytes
     * @return array<int,string>
     */
    private function _tail($path, $maxBytes): array
    {
        $size = @filesize($path);
        $fh   = @fopen($path, 'rb');
        if (!$fh) { return []; }
        if ($size !== false && $size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh);   // discard the partial first line
        }
        $data = stream_get_contents($fh);
        fclose($fh);
        return $data === false ? [] : explode("\n", $data);
    }

    /** The distinct level labels (severity order) for the UI's filter dropdown. */
    private static function _levelList(): array
    {
        return ['DEBUG', 'INFO', 'NOTICE', 'WARN', 'ERR', 'CRIT'];
    }
}
