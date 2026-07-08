<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Log — the platform logging facade.
 *
 * A static facade over Zend_Log (the writers + JSON formatter live in TigerZF; this
 * is the Tiger-platform glue). It gives every subsystem one call —
 *
 *   Tiger_Log::info('intake started', ['chat_id' => $id]);
 *   Tiger_Log::warn('otp mismatch', ['identifier' => $email, 'attempts' => 3]);
 *   try { … } catch (Throwable $e) {
 *       Tiger_Log::error('login failed', ['err' => $e->getMessage()]);
 *   }
 *
 * What the facade adds on top of raw Zend_Log:
 *   - PLUGGABLE SINK. The writer is chosen by `tiger.log.writer` (see core.ini):
 *     null | errorlog | stderr | stream | syslog | cloudwatch | gcp | azure, or a
 *     comma-list to fan out, or any Zend_Log_Writer_* class name. Unknown/missing
 *     (e.g. a cloud writer whose SDK isn't installed) logs one warning and falls
 *     back to errorlog — logging never dies.
 *   - CONFIG-DRIVEN LEVEL. `tiger.log.min_level` (debug|info|notice|warn|error|
 *     crit) — changeable per env or in the config table with no deploy.
 *   - ENRICHMENT. Every line auto-carries a per-request `request_id` and, when an
 *     identity exists, `user_id` / `org_id` / `role` — so one request (or one
 *     tenant's activity) is traceable across lines.
 *   - NEVER THROWS. A logging failure must not raise a second exception on a
 *     caller's error path; it degrades to a raw error_log() line.
 *
 * @api
 */
class Tiger_Log
{
    /** @var Zend_Log|null memoized logger for the request */
    private static $_log = null;

    /** @var string|null per-request correlation id */
    private static $_requestId = null;

    // -- severity helpers (thin wrappers over Zend_Log priorities) -------------

    public static function critical($message, array $context = [])
    {
        self::_emit(Zend_Log::CRIT, $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::_emit(Zend_Log::ERR, $message, $context);
    }

    public static function warn($message, array $context = [])
    {
        self::_emit(Zend_Log::WARN, $message, $context);
    }

    public static function info($message, array $context = [])
    {
        self::_emit(Zend_Log::INFO, $message, $context);
    }

    public static function debug($message, array $context = [])
    {
        self::_emit(Zend_Log::DEBUG, $message, $context);
    }

    /** Forget the memoized logger — call after changing config at runtime, or between tests. */
    public static function reset()
    {
        self::$_log       = null;
        self::$_requestId = null;
    }

    // -- internals -------------------------------------------------------------

    private static function _emit($priority, $message, array $context)
    {
        try {
            self::_logger()->log((string) $message, $priority, [
                'context' => self::_enrich($context),
                'channel' => 'app',
            ]);
        } catch (Throwable $e) {
            // Logging must never throw into the caller. Keep the line, lose the frills.
            error_log('[tiger_log_fallback] ' . $message . ' :: ' . $e->getMessage());
        }
    }

    private static function _logger()
    {
        if (self::$_log === null) {
            $log = new Zend_Log();
            foreach (self::_buildWriters() as $writer) {
                $log->addWriter($writer);
            }
            // Priority filter: accept events at or above (numerically <=) the floor.
            $log->addFilter(new Zend_Log_Filter_Priority(self::_minPriority()));
            self::$_log = $log;
        }
        return self::$_log;
    }

    /**
     * Build the configured writer(s). `tiger.log.writer` may name one sink, a
     * comma-separated list (fan-out), or a Zend_Log_Writer_* class. Any writer
     * that can't be constructed (missing SDK/key) is skipped with a warning; if
     * that leaves none, we guarantee errorlog so logs are never silently dropped.
     */
    private static function _buildWriters()
    {
        $cfg  = self::_config();
        $spec = ($cfg && $cfg->get('writer')) ? (string) $cfg->writer : 'errorlog';

        $writers = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $name) {
            try {
                $writers[] = self::_writer($name, $cfg);
            } catch (Throwable $e) {
                error_log('[tiger_log] writer "' . $name . '" unavailable: ' . $e->getMessage() . ' — falling back to errorlog');
            }
        }
        if (!$writers) {
            $writers[] = new Zend_Log_Writer_ErrorLog();
        }
        return $writers;
    }

    /** Map a writer name (+ its config sub-node) to a concrete Zend_Log writer. */
    private static function _writer($name, $cfg)
    {
        switch (strtolower($name)) {
            case 'null':
                return new Zend_Log_Writer_Null();

            case 'errorlog':
                return new Zend_Log_Writer_ErrorLog();

            case 'stderr':
                return self::_stream('php://stderr');

            case 'stdout':
                return self::_stream('php://stdout');

            case 'stream':
            case 'file':
                $path = ($cfg && $cfg->get('stream') && $cfg->stream->get('path'))
                    ? (string) $cfg->stream->path : 'php://stderr';
                return self::_stream($path);

            case 'syslog':
                $w = new Zend_Log_Writer_Syslog(['application' => 'tiger']);
                return $w;

            case 'cloudwatch':
                return new Zend_Log_Writer_Cloudwatch($cfg ? $cfg->get('cloudwatch') : null);

            case 'gcp':
            case 'stackdriver':
            case 'googlecloud':
                return new Zend_Log_Writer_Googlecloud($cfg ? $cfg->get('gcp') : null);

            case 'azure':
            case 'appinsights':
            case 'azuremonitor':
                return new Zend_Log_Writer_Azuremonitor($cfg ? $cfg->get('azure') : null);

            default:
                // Full pluggability: any Zend_Log_Writer_* class name works.
                if (class_exists($name) && is_subclass_of($name, 'Zend_Log_Writer_Abstract')) {
                    return new $name();
                }
                throw new RuntimeException('unknown log writer: ' . $name);
        }
    }

    /** A JSON-formatted stream writer (php://stderr, a file path, …). */
    private static function _stream($target)
    {
        $w = new Zend_Log_Writer_Stream($target);
        $w->setFormatter(new Zend_Log_Formatter_Json());
        return $w;
    }

    /** The `tiger.log` config node from the resolved registry config, or null. */
    private static function _config()
    {
        try {
            if (Zend_Registry::isRegistered('Zend_Config')) {
                $log = Zend_Registry::get('Zend_Config')->get('tiger');
                if ($log instanceof Zend_Config) {
                    return $log->get('log');
                }
            }
        } catch (Throwable $e) { /* pre-config bootstrap — use defaults */ }
        return null;
    }

    /**
     * Resolve the minimum priority from tiger.log.min_level, defaulting to INFO so
     * DEBUG is muted unless explicitly enabled. Safe before the config is up.
     */
    private static function _minPriority()
    {
        static $map = [
            'debug'  => Zend_Log::DEBUG,  'info'    => Zend_Log::INFO,
            'notice' => Zend_Log::NOTICE, 'warn'    => Zend_Log::WARN,
            'warning'=> Zend_Log::WARN,   'error'   => Zend_Log::ERR,
            'err'    => Zend_Log::ERR,    'crit'    => Zend_Log::CRIT,
            'critical' => Zend_Log::CRIT,
        ];

        $name = 'info';
        $cfg  = self::_config();
        if ($cfg && $cfg->get('min_level')) {
            $name = strtolower((string) $cfg->min_level);
        }
        return isset($map[$name]) ? $map[$name] : Zend_Log::INFO;
    }

    /** Merge caller context over the auto base (request_id + identity). Caller wins. */
    private static function _enrich(array $context)
    {
        $base = ['request_id' => self::_requestId()];

        try {
            if (class_exists('Zend_Auth', false) && Zend_Auth::getInstance()->hasIdentity()) {
                $identity = Zend_Auth::getInstance()->getIdentity();
                if (!empty($identity->user_id)) { $base['user_id'] = $identity->user_id; }
                if (!empty($identity->org_id))  { $base['org_id']  = $identity->org_id; }
                if (!empty($identity->role))    { $base['role']    = $identity->role; }
            }
        } catch (Throwable $e) { /* CLI / early bootstrap — no identity, fine */ }

        return $context + $base;
    }

    private static function _requestId()
    {
        if (self::$_requestId === null) {
            try {
                self::$_requestId = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                self::$_requestId = uniqid('', true);
            }
        }
        return self::$_requestId;
    }
}
