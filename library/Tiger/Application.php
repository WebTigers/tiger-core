<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger front door.
 *
 * The app's public/index.php is a 3-line shim that hands the project root to
 * this class. Everything here is Tiger-owned entry plumbing — proxy/ALB header
 * normalization, path constants, autoload/include paths, the config cascade,
 * and the guarded dispatch. Apps customize via an optional `custom.php` hook,
 * NOT by editing this file (it lives in vendor/ and updates replace it).
 *
 * @api
 */
class Tiger_Application
{
    /** @var string absolute project root (dir containing public/, application/, vendor/) */
    protected $root;

    /**
     * Capture the project root (the shim hands it in from public/index.php).
     *
     * @param  string $root absolute project root (dir containing public/, application/, vendor/)
     * @return void
     */
    public function __construct($root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Boot the application and dispatch the request, failing safe on any error.
     *
     * @return void
     */
    public function run()
    {
        if ($this->_updateInProgress()) {
            return;   // a core self-update is swapping vendor/ — serve a brief maintenance page
        }
        try {
            $this->boot()->run();
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    /**
     * Serve a 503 "Updating…" flash while a core self-update swaps `vendor/` (the flag written by
     * Tiger_Update_Core). Auto-expires after 120s so a crashed update can never wedge the site.
     *
     * @return bool true if the maintenance page was served (skip dispatch)
     */
    protected function _updateInProgress()
    {
        if (!defined('APPLICATION_ROOT')) {
            return false;
        }
        $flag = APPLICATION_ROOT . '/var/update/.maintenance';
        if (!is_file($flag) || (time() - (int) @filemtime($flag)) > 120) {
            return false;
        }
        if (!headers_sent()) {
            header('HTTP/1.1 503 Service Unavailable');
            header('Retry-After: 20');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!doctype html><meta charset="utf-8"><title>Updating…</title>'
           . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:30rem;margin:14vh auto;text-align:center;padding:0 1rem">'
           . '<h1 style="font-size:1.4rem;margin-bottom:.5rem">Updating…</h1>'
           . '<p style="color:#555">This site is applying an update and will be back in a few moments. '
           . 'Please refresh shortly.</p></div>';
        return true;
    }

    /**
     * Prepare the environment and bootstrap the app **without dispatching**.
     *
     * Shared by run() (web) and the console (bin/tiger), so CLI commands get the
     * identical constants, config cascade, and DB adapter as a web request — no
     * second, drifting bootstrap path. Returns the bootstrapped Zend_Application;
     * call ->run() on it to dispatch (web only).
     *
     * @return Zend_Application
     */
    public function boot()
    {
        $this->normalizeProxy();   // no-op under CLI (no X-Forwarded-* headers present)
        $this->defineConstants();
        $this->setIncludePath();
        $this->loadCustomHook();

        $application = new Zend_Application(APPLICATION_ENV, $this->buildConfig());
        return $application->bootstrap();   // runs _init* (incl. _initDb); does NOT dispatch
    }

    /**
     * Behind an ALB / reverse proxy, TLS is terminated upstream and the real
     * client IP + scheme arrive as X-Forwarded-* headers. Normalize them so
     * REMOTE_ADDR is the actual client and ZF1 builds correct https URLs and
     * redirects (otherwise PHP sees plain http on :80 and mixed-content /
     * redirect-to-http bugs creep in).
     */
    protected function normalizeProxy()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Leftmost entry is the original client (the ALB appends hops).
            $_SERVER['REMOTE_ADDR'] = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }

        $https = (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

        if ($https) {
            // Make Zend_Controller_Request_Http::getScheme() see https.
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['SERVER_PORT'] = 443;
        }

        defined('HTTPS') || define('HTTPS', $https);
    }

    protected function defineConstants()
    {
        defined('APPLICATION_ROOT')     || define('APPLICATION_ROOT', $this->root);
        defined('APPLICATION_PATH')     || define('APPLICATION_PATH', $this->root . '/application');
        defined('PUBLIC_PATH')          || define('PUBLIC_PATH', $this->root . '/public');
        defined('VENDOR_PATH')          || define('VENDOR_PATH', $this->root . '/vendor');
        defined('TIGER_CORE_PATH')      || define('TIGER_CORE_PATH', $this->root . '/vendor/webtigers/tiger-core');
        defined('LIBRARY_PATH')         || define('LIBRARY_PATH', $this->root . '/vendor/webtigers/tigerzf/library');
        defined('PROJECT_LIBRARY_PATH') || define('PROJECT_LIBRARY_PATH', $this->root . '/library');
        defined('MODULES_PATH')         || define('MODULES_PATH', $this->root . '/application/modules');
        defined('LOG_PATH')             || define('LOG_PATH', $this->root . '/logs');
        defined('TMP_PATH')             || define('TMP_PATH', $this->root . '/tmp');

        // The canonical "Tiger version" is tiger-core's version — the framework IS Tiger, so Tiger and
        // TigerCore are in lockstep by construction (no separately-drifting number to confuse a module
        // dev). Modules declare compatibility against this; Tiger_Module_Compat reads it.
        defined('TIGER_VERSION')        || define('TIGER_VERSION', Tiger_Version::VERSION);

        $env = getenv('APPLICATION_ENV') ?: ($_SERVER['APPLICATION_ENV'] ?? 'production');
        defined('APPLICATION_ENV') || define('APPLICATION_ENV', $env);
    }

    protected function setIncludePath()
    {
        set_include_path(implode(PATH_SEPARATOR, [
            PROJECT_LIBRARY_PATH,
            TIGER_CORE_PATH . '/library',
            LIBRARY_PATH,
            MODULES_PATH,
            get_include_path(),
        ]));
    }

    /** Optional app entry hook: app constants, helper functions, pre-bootstrap tweaks. */
    protected function loadCustomHook()
    {
        $custom = APPLICATION_ROOT . '/custom.php';
        if (is_file($custom)) {
            require $custom;
        }
    }

    /**
     * Config cascade (later wins):
     *   core.ini (Tiger, in the package) <- application.ini (app) <- local.ini (app, gitignored)
     * The base bootstrap folds in per-org DB overrides at request time.
     */
    protected function buildConfig()
    {
        $config = new Zend_Config_Ini(
            TIGER_CORE_PATH . '/configs/core.ini',
            APPLICATION_ENV,
            ['allowModifications' => true]
        );

        foreach ([
            TIGER_CORE_PATH . '/configs/routes.ini',   // core route aliases (resources.router.routes.*)
            APPLICATION_PATH . '/configs/routes.ini',   // app route aliases (optional)
            APPLICATION_PATH . '/configs/application.ini',
            APPLICATION_PATH . '/configs/local.ini',
        ] as $file) {
            if (is_file($file)) {
                $config->merge(new Zend_Config_Ini($file, APPLICATION_ENV));
            }
        }

        $config->setReadOnly();
        return $config;
    }

    /** Last-resort handler for a boot/dispatch failure: log + HTTP 500. */
    protected function fail(\Throwable $e)
    {
        error_log('Tiger fatal: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        if (APPLICATION_ENV !== 'production') {
            echo '<pre style="padding:2em;font-family:monospace">'
                . htmlspecialchars((string) $e) . '</pre>';
        } else {
            echo '<h1>An error occurred.</h1><p>Please try again later.</p>';
        }
        exit(1);
    }
}
