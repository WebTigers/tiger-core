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

    public function __construct($root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function run()
    {
        try {
            $this->boot()->run();
        } catch (\Throwable $e) {
            $this->fail($e);
        }
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
