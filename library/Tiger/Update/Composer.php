<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Update_Composer — run `composer update <package>` IN-PROCESS, for hosts where Composer
 * genuinely runs (a binary + proc_open/exec not disabled + a writable vendor/ — see
 * Tiger_Vendor_Environment). This is the shell / VPS / dev-box counterpart to the no-shell vendored-
 * ZIP swap (Tiger_Update_Core): where Composer works, USE it; where it can't, the ZIP swap covers the
 * CMS user on shared hosting. Either way the Updates page APPLIES the update rather than merely
 * advising it.
 *
 * Never throws — returns {ok, version?, log:[{step,ok,detail}]}. The current PHP process keeps the
 * OLD classes it already loaded; the new code serves the NEXT request. So the version check re-reads
 * Version.php from DISK (not the loaded constant).
 *
 * @api
 */
class Tiger_Update_Composer
{
    const TIMEOUT = 600;   // composer update can be slow on a cold cache

    /** Can a composer-driven update run here? (a runnable binary + proc_open + writable vendor/). */
    public static function possible()
    {
        return function_exists('proc_open')
            && Tiger_Vendor_Environment::composerBinary() !== null
            && Tiger_Vendor_Environment::vendorWritable();
    }

    /**
     * Run `composer update <package> --with-all-dependencies` in the app root and verify the result.
     *
     * @param  array $opts {package: string, target?: string}
     * @return array {ok, version?, log}
     */
    public static function update(array $opts)
    {
        $log = [];
        $add = static function ($step, $ok, $detail) use (&$log) {
            $log[] = ['step' => $step, 'ok' => (bool) $ok, 'detail' => $detail];
            if (class_exists('Tiger_Log')) {
                Tiger_Log::info('update.composer', ['step' => $step, 'ok' => (bool) $ok, 'detail' => $detail]);
            }
        };
        $fail = static function ($detail) use (&$log, $add) {
            $add('error', false, $detail);
            return ['ok' => false, 'log' => $log];
        };

        $package = (string) ($opts['package'] ?? '');
        if ($package === '') { return $fail('No package to update.'); }

        $binary = Tiger_Vendor_Environment::composerBinary();
        if ($binary === null || !function_exists('proc_open')) {
            return $fail('Composer is not runnable here (no binary, or proc_open disabled).');
        }
        $root = Tiger_Vendor_Environment::appRoot();
        if (!is_file($root . '/composer.json')) {
            return $fail('No composer.json at ' . $root . ' — not a Composer-managed install.');
        }
        if (!Tiger_Vendor_Environment::vendorWritable()) {
            return $fail('vendor/ is not writable.');
        }
        $add('preflight', true, 'Composer runnable, composer.json present, vendor/ writable.');

        $verFile = $root . '/vendor/' . $package . '/library/Tiger/Version.php';   // tiger-core layout
        $before  = self::_versionIn($verFile);

        // A writable HOME/COMPOSER_HOME (web users often have none), unbounded memory, no TTY.
        $composerHome = $root . '/var/composer-home';
        @mkdir($composerHome, 0775, true);
        @putenv('HOME=' . $composerHome);
        @putenv('COMPOSER_HOME=' . $composerHome);
        @putenv('COMPOSER_MEMORY_LIMIT=-1');
        @putenv('COMPOSER_NO_INTERACTION=1');
        @set_time_limit(0);
        // Managed hosting often has a vendor tree owned by a user other than the web user; git then
        // aborts with "dubious ownership" (Composer survives it, but it looks alarming in the operator's
        // log). Seed our HOME's gitconfig to trust any path so the update log stays clean.
        @file_put_contents($composerHome . '/.gitconfig', "[safe]\n\tdirectory = *\n");

        // --with-all-dependencies so a required tigerzf/polyfill bump comes along; --no-dev for a
        // production-shaped tree; --no-scripts so a post-update hook can't fail the update mid-request.
        $cmd = $binary . ' update ' . escapeshellarg($package)
             . ' --with-all-dependencies --no-dev --no-interaction --no-progress --no-scripts --no-ansi 2>&1';
        list($code, $out) = self::_run($cmd, $root, self::TIMEOUT);
        $tail = self::_tail($out, 4000);

        if ($code !== 0) {
            return $fail("Composer exited with code {$code}." . ($tail !== '' ? "\n" . $tail : ''));
        }
        $add('composer', true, 'composer update ' . $package . ' finished.' . ($tail !== '' ? "\n" . $tail : ''));

        // Re-read from disk — the running process still holds the old Version constant.
        $after = self::_versionIn($verFile);
        $add('done', true, ($after !== null
                ? 'Now at ' . $after . ($before !== null && $before !== $after ? ' (was ' . $before . ')' : '')
                : 'Composer reported success')
            . '. The new code serves the next request.');
        return ['ok' => true, 'version' => $after, 'log' => $log];
    }

    // ---- helpers ---------------------------------------------------------------

    /** Run a command, capturing merged output, with a wall-clock timeout. Returns [exitCode, output]. */
    protected static function _run($cmd, $cwd, $timeout)
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, $cwd, null);
        if (!is_resource($proc)) { return [1, 'Could not start Composer (proc_open failed).']; }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out    = '';
        $start  = time();
        $status = ['running' => true, 'exitcode' => -1];
        while (true) {
            $out   .= (string) stream_get_contents($pipes[1]);
            $out   .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (empty($status['running'])) { break; }
            if (time() - $start > $timeout) { @proc_terminate($proc); $out .= "\n[timed out after {$timeout}s]"; break; }
            usleep(200000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $closeCode = proc_close($proc);
        $code = (isset($status['exitcode']) && $status['exitcode'] >= 0) ? $status['exitcode'] : $closeCode;
        return [$code, $out];
    }

    protected static function _versionIn($file)
    {
        if (!is_file($file)) { return null; }
        return preg_match('/VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', (string) @file_get_contents($file), $m) ? $m[1] : null;
    }

    protected static function _tail($s, $max)
    {
        $s = trim((string) $s);
        return strlen($s) > $max ? '…' . substr($s, -$max) : $s;
    }
}
