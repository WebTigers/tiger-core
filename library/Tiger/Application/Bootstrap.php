<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger's base application bootstrap.
 *
 * An app's application/Bootstrap.php extends this to inherit module scanning,
 * default-namespace wiring, theme-as-path resolution, and the config cascade —
 * without copying any of it. Tiger-owned; extend behavior by adding _init*
 * methods in the app subclass, not by editing this file.
 *
 * @api
 */
class Tiger_Application_Bootstrap extends Zend_Application_Bootstrap_Bootstrap
{
    /**
     * Wire ZF1 paths. The default (module-less) namespace is served from the
     * tiger-core package; app modules come from resources.frontController.
     * moduleDirectory (core.ini), and first-party core modules are registered
     * here too, so resources.modules can auto-bootstrap both.
     */
    protected function _initTigerPaths()
    {
        // Resolve the `modules` resource to Tiger's subclass (adds the activation gate).
        // Registered here (a class resource) so it's in place before the `modules` plugin
        // resource runs. class_exists/autoload finds the class; the path is a fallback.
        $this->getPluginLoader()->addPrefixPath(
            'Tiger_Application_Resource',
            TIGER_CORE_PATH . '/library/Tiger/Application/Resource'
        );

        $this->bootstrap('frontController');
        $front = $this->getResource('frontController');

        // Default namespace = Core, shipped from the package. Use ADD (not SET) —
        // setControllerDirectory() wipes the whole module->dir map first, which would
        // erase every app module the frontController.moduleDirectory scan registered.
        $front->addControllerDirectory(TIGER_CORE_PATH . '/core/controllers', 'default');

        // First-party core modules (if any ship in the package):
        if (is_dir(TIGER_CORE_PATH . '/modules')) {
            $front->addModuleDirectory(TIGER_CORE_PATH . '/modules');
        }
    }

    /**
     * Register the shared vendor-library store's autoloaders, so any third-party lib provisioned
     * for a module (aws-sdk, stripe, …) resolves for every module. On a Composer box these live in
     * vendor/ (Composer's autoloader already has them); this covers the NO-Composer store
     * (`vendor-libs/`) that Tiger_Vendor populates on shared hosting. No-op when the store is empty.
     * See DEPENDENCIES.md.
     */
    protected function _initVendorLibraries()
    {
        Tiger_Vendor::registerAutoloaders();
    }

    /**
     * Register the TIGER /api gateway routes: the bare `/api` (POST body carries
     * module/service/method — the primary form all JS clients use) and the URL
     * form `/api/:module/:service/:action` (svc_* params so they don't collide
     * with ZF1's reserved :module/:controller/:action). Both dispatch to the
     * default-namespace ApiController, which hands off to Tiger_Ajax_ServiceFactory.
     */
    protected function _initApiRoutes()
    {
        $this->bootstrap('frontController');
        $router = $this->getResource('frontController')->getRouter();

        $target = ['module' => 'default', 'controller' => 'api', 'action' => 'index'];

        // Bare /api (POST body form) — the primary TIGER message endpoint.
        $router->addRoute('tiger_api_root', new Zend_Controller_Router_Route('api', $target));

        // URL form /api/:module/:service/:action (secondary convenience form).
        $router->addRoute('tiger_api', new Zend_Controller_Router_Route(
            'api/:svc_module/:svc_service/:svc_action', $target
        ));
        // NB: /api/openapi needs no route — the default :controller/:action route dispatches it to
        // ApiController::openapiAction (the self-describing catalog). See WEBSERVICES.md §9.
    }

    /**
     * AUTHORIZATION: build the ACL (Tiger_Acl_Acl loads roles/resources/rules from
     * ini + DB) and register the unbypassable gate (Tiger_Controller_Plugin_
     * Authorization) on the front controller. Runs after frontController so the
     * plugin can attach; the ACL's DB layer is graceful if the acl_* tables aren't
     * migrated yet (the core acl.ini policy still applies).
     */
    protected function _initAuthorization()
    {
        $this->bootstrap('frontController');

        Zend_Registry::set('Zend_Acl', new Tiger_Acl_Acl());
        $this->getResource('frontController')
             ->registerPlugin(new Tiger_Controller_Plugin_Authorization());
    }

    /**
     * I18N: register the locale plugin — semantic /xx/ URLs + per-request language
     * resolution (URL prefix > cookie > browser > default). Supported languages and
     * the default come from config (tiger.i18n.*, language-only per convention). It
     * runs at routeStartup (before dispatch), so LANG is defined for every view and
     * the /xx/ segment is stripped before authorization resolves the resource.
     */
    protected function _initLocale()
    {
        $this->bootstrap('frontController');

        $tiger     = (array) $this->getOption('tiger');
        $i18n      = (array) ($tiger['i18n'] ?? []);
        $supported = !empty($i18n['locales'])
            ? array_values(array_filter(array_map('trim', explode(',', (string) $i18n['locales']))))
            : ['en'];
        $default   = !empty($i18n['default']) ? (string) $i18n['default'] : $supported[0];

        $this->getResource('frontController')
             ->registerPlugin(new Tiger_Controller_Plugin_LocalePrefix($supported, $default));
    }

    /**
     * Routing overrides: register the plugin that applies modules' declared pretty routes
     * (Tiger_Routing_Overrides) — e.g. /docs -> docs/index/docs. Runs at routeShutdown and only
     * claims URLs no real controller handles, so it never shadows a module's own paths. Bootstrap
     * 'modules' first so module Bootstraps have registered their declarations; register BEFORE
     * _initCms so a pretty route resolves its slug ahead of the CMS content fallback.
     */
    protected function _initRouteOverrides()
    {
        $this->bootstrap('frontController');
        $this->bootstrap('modules');
        $this->getResource('frontController')
             ->registerPlugin(new Tiger_Controller_Plugin_RouteOverride());
    }

    /**
     * CMS: register the page-dispatch plugin. It routes URLs that match no real
     * controller to CMS `page` content (or a 301 via page_redirect), and leaves
     * everything else to the normal 404 path. Runs at routeShutdown; graceful when
     * there's no DB / no page table yet.
     */
    protected function _initCms()
    {
        $this->bootstrap('frontController');
        $this->getResource('frontController')
             ->registerPlugin(new Tiger_Controller_Plugin_PageDispatch())
             // AFTER PageDispatch: a DB page wins; else the active theme's static content partial.
             ->registerPlugin(new Tiger_Controller_Plugin_ThemeContent());

        // [menu name="primary"] in html/markdown page bodies -> the rendered menu.
        // Same output as the {menu} view helper and Tiger_Menu::getHTML (auth-filtered).
        Tiger_Cms_Renderer::registerShortcode('menu', static function ($attrs) {
            $key = (string) ($attrs['name'] ?? ($attrs['key'] ?? ''));
            if ($key === '') {
                return '';
            }
            $options = [];
            if (!empty($attrs['class'])) { $options['class'] = $attrs['class']; }
            if (!empty($attrs['id']))    { $options['id']    = $attrs['id']; }
            return Tiger_Menu::getHTML($key, $options);
        });
    }

    /**
     * Tiger Code — load active GLOBAL PHP snippets so their functions/hooks are defined
     * before dispatch. Runs a single OPcache-warm include of a compiled bundle (no per-request
     * query — cache invalidation rides the config token loaded in _initConfigs). Fully guarded:
     * a compile/boot failure can never break bootstrap, and a snippet that fatals on load
     * auto-deactivates (Tiger_Code_Runtime). PHP is platform-scope only.
     */
    protected function _initCode()
    {
        $this->bootstrap('configs');   // config token + DB adapter must be up first
        try {
            Tiger_Code_Runtime::boot(Tiger_Code_Runtime::LOC_GLOBAL);
        } catch (Throwable $e) {
            // never let snippet loading break the request
        }
    }

    /**
     * I18N (translations): build the shared Zend_Translate (AN_ARRAY — human-readable
     * PHP array files of owner-prefixed semantic keys: core.*, app.*, <module>.*).
     * Message files CASCADE (last wins): core (package) -> package modules -> app
     * modules -> app global. Then LIVE DB overrides (the `translation` table) layer
     * on top — change a string with no deploy, exactly like the config table does for
     * config. All supported locales are loaded; the LocalePrefix plugin points the
     * active locale at LANG per request (routeStartup).
     */
    protected function _initTranslate()
    {
        $this->bootstrap('db');   // for the DB override layer (graceful if none)

        $tiger     = (array) $this->getOption('tiger');
        $i18n      = (array) ($tiger['i18n'] ?? []);
        $supported = !empty($i18n['locales'])
            ? array_values(array_filter(array_map('trim', explode(',', (string) $i18n['locales']))))
            : ['en'];
        $default   = !empty($i18n['default']) ? (string) $i18n['default'] : $supported[0];

        $translate = new Zend_Translate([
            'adapter'        => Zend_Translate::AN_ARRAY,
            'content'        => [],
            'locale'         => $default,
            'disableNotices' => true,
        ]);

        foreach ($supported as $lang) {
            foreach ($this->_languageFiles($lang) as $file) {
                $data = include $file;   // each file returns [key => string]
                if (is_array($data) && $data) {
                    $translate->addTranslation(['content' => $data, 'locale' => $lang]);
                }
            }
            // Live DB overrides on top (global scope). Per-org is reserved for later.
            try {
                $overrides = (new Tiger_Model_Translation())->getForLocale($lang, Tiger_Model_Translation::SCOPE_GLOBAL);
                if ($overrides) {
                    $translate->addTranslation(['content' => $overrides, 'locale' => $lang]);
                }
            } catch (Throwable $e) {
                // no DB / no `translation` table yet — files only
            }
        }

        Zend_Registry::set('Zend_Translate', $translate);
        return $translate;
    }

    /**
     * Language files for a locale, in cascade order (last wins): core (package) ->
     * package first-party modules -> app modules -> app global. Each is a PHP file
     * returning [key => string]; missing files are skipped.
     *
     * @return string[]
     */
    protected function _languageFiles($lang)
    {
        $files   = [];
        $files[] = TIGER_CORE_PATH . '/core/languages/' . $lang . '/core.php';
        foreach (glob(TIGER_CORE_PATH . '/modules/*/languages/' . $lang . '/*.php') ?: [] as $f) { $files[] = $f; }
        foreach (glob(APPLICATION_PATH . '/modules/*/languages/' . $lang . '/*.php') ?: [] as $f) { $files[] = $f; }
        $files[] = APPLICATION_PATH . '/languages/' . $lang . '/app.php';

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * Theme as a path (AskLevi-style, generalized). Active theme + skin resolve
     * from config now; per-org via the DB layer later. Layout comes from the
     * active theme; view script paths cascade Core -> theme -> app. No
     * inheritance — just paths.
     */
    protected function _initTheme()
    {
        $this->bootstrap('tigerPaths');
        $this->bootstrap('configs');   // resolve config (incl. per-org overrides) FIRST

        // Read theme/skin from the RESOLVED config (registry) so a per-org DB
        // override actually reskins that org. Falls back to the ini defaults.
        $tiger = Zend_Registry::isRegistered('Zend_Config')
            ? Zend_Registry::get('Zend_Config')->get('tiger')
            : null;
        $theme = ($tiger && $tiger->get('theme')) ? (string) $tiger->theme : 'puma';
        $skin  = ($tiger && $tiger->get('skin'))  ? (string) $tiger->skin  : '';

        // PREVIEW (THEMES.md §5a): an installed theme can be previewed via a `tiger_theme` cookie
        // (the Module Manager's Preview button sets it), same mechanism as the skin cookie below.
        // Validated against a real theme dir so it can never point outside the theme locations.
        if (!empty($_COOKIE['tiger_theme'])) {
            $ck = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $_COOKIE['tiger_theme']));
            if ($ck !== '' && (
                is_dir(APPLICATION_PATH . '/modules/theme-' . $ck) || is_dir(APPLICATION_PATH . '/themes/' . $ck) ||
                is_dir(TIGER_CORE_PATH  . '/modules/theme-' . $ck) || is_dir(TIGER_CORE_PATH  . '/themes/' . $ck)
            )) {
                $theme = $ck;
            }
        }

        // Resolve the theme's directory. A theme may live in a plain `themes/<name>` dir OR ship as a
        // `theme-<name>` MODULE (THEMES.md: a marketplace theme is a module, activated via the Module
        // Manager) — in which case the module dir IS the theme dir (its own layouts/, views/, assets/,
        // skins/). App-owned candidates win the package's; a missing theme falls back to packaged puma
        // so a stale `tiger.theme` config row can never boot into a broken path.
        $themeDir = null;
        foreach ([
            APPLICATION_PATH . '/themes/' . $theme,
            APPLICATION_PATH . '/modules/theme-' . $theme,
            TIGER_CORE_PATH  . '/modules/theme-' . $theme,
            TIGER_CORE_PATH  . '/themes/' . $theme,
        ] as $candidate) {
            if (is_dir($candidate)) { $themeDir = $candidate; break; }
        }
        if ($themeDir === null) {
            $themeDir = TIGER_CORE_PATH . '/themes/puma';
            $theme    = 'puma';
        }
        Zend_Registry::set('Tiger_ThemeDir', $themeDir);   // the theme-content fallback plugin reads this

        // Available skins = the CSS files on disk. The active skin may be overridden
        // per-request by the `tiger_skin` cookie (the skin switcher) — validated
        // against the file list, so the cookie can never point outside the skins dir.
        $availableSkins = [];
        foreach (glob($themeDir . '/assets/skins/*.css') ?: [] as $skinFile) {
            $availableSkins[] = basename($skinFile, '.css');
        }
        sort($availableSkins);
        $cookieSkin = isset($_COOKIE['tiger_skin'])
            ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $_COOKIE['tiger_skin']))
            : '';
        if ($cookieSkin !== '' && in_array($cookieSkin, $availableSkins, true)) {
            $skin = $cookieSkin;
        }

        defined('THEME') || define('THEME', $theme);
        defined('SKIN')  || define('SKIN', $skin);

        // View script paths cascade (last added wins): Core -> theme -> app.
        $view = new Zend_View();
        $view->doctype('HTML5');
        $view->addHelperPath(TIGER_CORE_PATH . '/library/Tiger/View/Helper', 'Tiger_View_Helper');
        $view->addScriptPath(TIGER_CORE_PATH . '/core/views/scripts');
        // Base-theme fallback (THEMES.md §5c): the admin/auth layouts + their partials always resolve
        // from the platform base theme (puma). So a PUBLIC-only active theme (e.g. Grey Mist, which
        // ships no admin.phtml) can be activated site-wide and the back office keeps working — public
        // requests use the active theme's layout, admin/auth fall back to puma. Added at LOW priority
        // (Zend_Layout adds the active theme's layout path at render time, so it always wins the public
        // `layout.phtml`; only the base-theme-only `admin.phtml`/`auth.phtml`/partials fall through here).
        $baseDir = TIGER_CORE_PATH . '/themes/puma';
        if ($themeDir !== $baseDir) {
            if (is_dir($baseDir . '/views/scripts'))   { $view->addScriptPath($baseDir . '/views/scripts'); }
            if (is_dir($baseDir . '/layouts/scripts')) { $view->addScriptPath($baseDir . '/layouts/scripts'); }
        }
        if (is_dir($themeDir . '/views/scripts')) {
            $view->addScriptPath($themeDir . '/views/scripts');
        }
        if (is_dir(APPLICATION_PATH . '/views/scripts')) {
            $view->addScriptPath(APPLICATION_PATH . '/views/scripts');
        }

        $view->theme       = $theme;
        $view->skin        = $skin;
        $view->skins       = $availableSkins;   // for the skin switcher
        $view->themeAssets = '/_theme';

        // Site name (config, per-org-aware) available to every view/layout.
        $cfg  = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $site = ($cfg && $cfg->get('tiger')) ? $cfg->tiger->get('site') : null;
        $view->siteName = ($site && (string) $site->get('name') !== '') ? (string) $site->name : 'Tiger';

        Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer')->setView($view);

        // Layout from the active theme:
        if (is_dir($themeDir . '/layouts/scripts')) {
            $layout = Zend_Layout::startMvc([
                'layoutPath' => $themeDir . '/layouts/scripts',
                'layout'     => 'layout',
            ]);
            $layout->setView($view);
        }

        Zend_Registry::set('Tiger_View', $view);
    }

    /**
     * Set up the default database adapter from config — if a DB is configured.
     *
     * GRACEFUL BY DESIGN: if no `tiger.db` host/dbname is set, we skip silently, so
     * the app still boots without a database (marketing pages, health checks, the
     * PUMA hello — none need one). Models and migrations require the adapter and
     * will fail clearly if it's absent. This is why a fresh Tiger install renders
     * before you've configured a database.
     *
     * Config (usually in local.ini, since it carries a secret):
     *   tiger.db.host / dbname / username / password
     *   tiger.db.adapter  (default "Pdo_Mysql")   tiger.db.charset (default "utf8mb4")
     *
     * PROD SECRETS (optional): if tiger.db.secret is set AND the AWS SDK is present,
     * credentials are pulled from Secrets Manager instead of living in config. The
     * SDK is NOT a hard dependency of tiger-core — apps that want SM add
     * aws/aws-sdk-php themselves. Keeps the platform lean for everyone else.
     *
     * @return Zend_Db_Adapter_Abstract|null
     */
    protected function _initDb()
    {
        $opts = $this->getOption('tiger') ?: [];
        $db   = isset($opts['db']) ? (array) $opts['db'] : [];

        if (empty($db['host']) || empty($db['dbname'])) {
            return null;   // no DB configured — boot without one
        }

        if (!empty($db['secret']) && class_exists('Aws\\SecretsManager\\SecretsManagerClient')) {
            $db = array_merge($db, $this->_resolveDbSecret($db['secret'], $opts));
        }

        $adapter = Zend_Db::factory(
            isset($db['adapter']) ? $db['adapter'] : 'Pdo_Mysql',
            [
                'host'     => $db['host'],
                'dbname'   => $db['dbname'],
                'username' => isset($db['username']) ? $db['username'] : '',
                'password' => isset($db['password']) ? $db['password'] : '',
                'charset'  => isset($db['charset']) ? $db['charset'] : 'utf8mb4',
            ]
        );

        // Make it the default for every Tiger_Model_Table, and expose it in the registry.
        Zend_Db_Table_Abstract::setDefaultAdapter($adapter);
        Zend_Registry::set('Zend_Db', $adapter);
        return $adapter;
    }

    /**
     * Resolve DB credentials from AWS Secrets Manager. Only reached when the SDK is
     * installed and tiger.db.secret is set (see _initDb). Expects the RDS-managed
     * secret shape (JSON with host/username/password/dbname); fills only what's present.
     *
     * @return array subset of host/username/password/dbname
     */
    protected function _resolveDbSecret($secretId, array $opts)
    {
        $region = isset($opts['aws']['region']) ? $opts['aws']['region'] : 'us-east-1';
        $client = new Aws\SecretsManager\SecretsManagerClient([
            'version' => '2017-10-17',
            'region'  => $region,
        ]);
        $result = $client->getSecretValue(['SecretId' => $secretId]);
        $secret = json_decode((string) $result['SecretString'], true) ?: [];

        $creds = [];
        foreach (['host', 'username', 'password', 'dbname'] as $key) {
            if (isset($secret[$key])) {
                $creds[$key] = $secret[$key];
            }
        }
        return $creds;
    }

    /**
     * Publish the effective config into the registry as 'Zend_Config'. Base is
     * the merged ini cascade (core <- application <- local). The per-org DB
     * override layer folds in here once the substrate (org + config table)
     * exists — à la AskLevi Core_Bootstrap::_initConfigs layer 3.
     */
    /**
     * Start the session, using the DB save handler when a DB + `session` table are
     * present (a shared store — required behind an ALB with >1 instance), else PHP's
     * default file handler (fine on a single box, and on a fresh install before
     * migrations run). Override with `tiger.session.handler = files|db`. MUST run
     * before anything reads Zend_Auth (setSaveHandler must precede session start).
     */
    protected function _initSession()
    {
        // A STATELESS token request (Authorization: Bearer tgr_…) must never touch the session — no
        // session start, no cookie, no session row. Give Zend_Auth REQUEST-ONLY storage so reading
        // the identity (in _initConfigs, the auth plugin, …) can't lazily start a session, and skip
        // Zend_Session::start() entirely. The /api gateway writes the token identity into this store.
        if ($this->_bearerRequest()) {
            Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_NonPersistent());
            if (!Zend_Session::isStarted()) {
                Zend_Session::setOptions(['use_cookies' => 0, 'use_only_cookies' => 1]);   // belt: no cookie even if something starts one
            }
            return;
        }

        $this->bootstrap('db');

        $opts    = $this->getOption('tiger') ?: [];
        $adapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $prefer  = isset($opts['session']['handler']) ? $opts['session']['handler'] : ($adapter ? 'db' : 'files');

        $useDb = false;
        if ($prefer === 'db' && $adapter) {
            try { $adapter->describeTable('session'); $useDb = true; } catch (Throwable $e) {}  // table must exist
        }
        if ($useDb) {
            try {
                Zend_Session::setSaveHandler(new Tiger_Session_SaveHandler_DbTable([
                    'name'           => 'session',
                    'primary'        => 'session_id',
                    'modifiedColumn' => 'modified',
                    'dataColumn'     => 'data',
                    'lifetimeColumn' => 'lifetime',
                ]));
            } catch (Throwable $e) {
                error_log('Tiger session: DB handler failed, using files — ' . $e->getMessage());
            }
        }

        if (!Zend_Session::isStarted()) {
            Zend_Session::start();
        }
    }

    /** True when this request carries a Tiger personal access token (stateless mode). */
    protected function _bearerRequest()
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return (bool) preg_match('/^\s*Bearer\s+tgr_/i', (string) $h);
    }

    protected function _initConfigs()
    {
        $this->bootstrap('db');
        $this->bootstrap('session');   // start the session (DB handler) BEFORE reading the identity

        $config = new Zend_Config($this->getOptions(), true);   // ini cascade base (modifiable)

        // DB override layer (the runtime tier of the cascade): global config first,
        // then the CURRENT ORG's config on top (org wins). Dot-notation keys fold
        // into the nested tree. This is also the per-org resolver — an org row
        // `tiger.skin = ...` reskins that org (see _initTheme). Graceful: no DB / no
        // `config` table yet -> ini-only config.
        try {
            $model = new Tiger_Model_Config();
            foreach ($model->getForScope(Tiger_Model_Config::SCOPE_GLOBAL) as $row) {
                $this->_setNestedConfig($config, $row->config_key, $row->config_value);
            }
            $orgId = $this->_currentOrgId();
            if ($orgId !== null) {
                foreach ($model->getForScope(Tiger_Model_Config::SCOPE_ORG, $orgId) as $row) {
                    $this->_setNestedConfig($config, $row->config_key, $row->config_value);
                }
            }
        } catch (Throwable $e) {
            // ini-only config (no DB / no config table yet)
        }

        $config->setReadOnly();
        Zend_Registry::set('Zend_Config', $config);
        return $config;
    }

    /** The active org id from the authenticated identity (session), or null. */
    protected function _currentOrgId()
    {
        $identity = Zend_Auth::getInstance()->getIdentity();
        return ($identity && !empty($identity->org_id)) ? $identity->org_id : null;
    }

    /** Fold a dot-notation key ('tiger.skin') + value into a modifiable Zend_Config tree. */
    protected function _setNestedConfig(Zend_Config $config, $key, $value)
    {
        $parts = explode('.', (string) $key);
        $node  = $config;
        while (count($parts) > 1) {
            $part = array_shift($parts);
            if (!isset($node->{$part}) || !($node->{$part} instanceof Zend_Config)) {
                $node->{$part} = [];   // Zend_Config wraps to a nested modifiable node
            }
            $node = $node->{$part};
        }
        $node->{$parts[0]} = $value;
    }
}
