<?php
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
        $this->bootstrap('frontController');
        $front = $this->getResource('frontController');

        // Default namespace = Core, shipped from the package:
        $front->setControllerDirectory(TIGER_CORE_PATH . '/core/controllers', 'default');

        // First-party core modules (if any ship in the package):
        if (is_dir(TIGER_CORE_PATH . '/modules')) {
            $front->addModuleDirectory(TIGER_CORE_PATH . '/modules');
        }
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

        $target = array('module' => 'default', 'controller' => 'api', 'action' => 'index');

        // Bare /api (POST body form) — the primary TIGER message endpoint.
        $router->addRoute('tiger_api_root', new Zend_Controller_Router_Route('api', $target));

        // URL form /api/:module/:service/:action (secondary convenience form).
        $router->addRoute('tiger_api', new Zend_Controller_Router_Route(
            'api/:svc_module/:svc_service/:svc_action', $target
        ));
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

        $opts  = $this->getOption('tiger') ?: array();
        $theme = isset($opts['theme']) && $opts['theme'] !== '' ? $opts['theme'] : 'puma';
        $skin  = isset($opts['skin'])  ? $opts['skin']  : '';

        // Prefer an app-provided theme dir, else the package's:
        $themeDir = APPLICATION_PATH . '/themes/' . $theme;
        if (!is_dir($themeDir)) {
            $themeDir = TIGER_CORE_PATH . '/themes/' . $theme;
        }

        defined('THEME') || define('THEME', $theme);
        defined('SKIN')  || define('SKIN', $skin);

        // View script paths cascade (last added wins): Core -> theme -> app.
        $view = new Zend_View();
        $view->doctype('HTML5');
        $view->addScriptPath(TIGER_CORE_PATH . '/core/views/scripts');
        if (is_dir($themeDir . '/views/scripts')) {
            $view->addScriptPath($themeDir . '/views/scripts');
        }
        if (is_dir(APPLICATION_PATH . '/views/scripts')) {
            $view->addScriptPath(APPLICATION_PATH . '/views/scripts');
        }

        $view->theme       = $theme;
        $view->skin        = $skin;
        $view->themeAssets = '/_theme';

        Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer')->setView($view);

        // Layout from the active theme:
        if (is_dir($themeDir . '/layouts/scripts')) {
            $layout = Zend_Layout::startMvc(array(
                'layoutPath' => $themeDir . '/layouts/scripts',
                'layout'     => 'layout',
            ));
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
        $opts = $this->getOption('tiger') ?: array();
        $db   = isset($opts['db']) ? (array) $opts['db'] : array();

        if (empty($db['host']) || empty($db['dbname'])) {
            return null;   // no DB configured — boot without one
        }

        if (!empty($db['secret']) && class_exists('Aws\\SecretsManager\\SecretsManagerClient')) {
            $db = array_merge($db, $this->_resolveDbSecret($db['secret'], $opts));
        }

        $adapter = Zend_Db::factory(
            isset($db['adapter']) ? $db['adapter'] : 'Pdo_Mysql',
            array(
                'host'     => $db['host'],
                'dbname'   => $db['dbname'],
                'username' => isset($db['username']) ? $db['username'] : '',
                'password' => isset($db['password']) ? $db['password'] : '',
                'charset'  => isset($db['charset']) ? $db['charset'] : 'utf8mb4',
            )
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
        $client = new Aws\SecretsManager\SecretsManagerClient(array(
            'version' => '2017-10-17',
            'region'  => $region,
        ));
        $result = $client->getSecretValue(array('SecretId' => $secretId));
        $secret = json_decode((string) $result['SecretString'], true) ?: array();

        $creds = array();
        foreach (array('host', 'username', 'password', 'dbname') as $key) {
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
    protected function _initConfigs()
    {
        $config = new Zend_Config($this->getOptions(), true);

        // TODO(substrate): merge DB config rows scoped 'global' + current org here.

        Zend_Registry::set('Zend_Config', $config);
        return $config;
    }
}
