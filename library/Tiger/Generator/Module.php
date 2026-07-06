<?php
/**
 * Tiger_Generator_Module — scaffolds a new application module.
 *
 * Powers `bin/tiger make:module <name>`. Writes a self-contained, immediately-live
 * module under application/modules/<name>/: it's auto-discovered by module scanning
 * (via its Bootstrap), routable (/<name>/ through the default route), ACL-governed
 * (its configs/acl.ini is loaded by Tiger_Acl_Acl), and ships both a controller and
 * an /api service so the developer sees both extension patterns.
 *
 * Templates are NOWDOC strings (no PHP-var interpolation) with {{name}}/{{Name}}
 * placeholders, so generated code like $params/$this is emitted verbatim.
 *
 * @api
 */
class Tiger_Generator_Module
{
    /**
     * Generate the module. Returns the list of created file paths.
     *
     * @param  string $name        lowercase module name (a-z0-9, starts a-z)
     * @param  string $modulesPath  application/modules
     * @return string[]
     * @throws RuntimeException on a bad name or an existing module
     */
    public static function generate($name, $modulesPath)
    {
        $name = strtolower(trim((string) $name));
        if (!preg_match('/^[a-z][a-z0-9]*$/', $name)) {
            throw new RuntimeException('Module name must be a lowercase word (a-z, 0-9) starting with a letter.');
        }
        $Name = ucfirst($name);
        $dir  = rtrim($modulesPath, '/') . '/' . $name;
        if (is_dir($dir)) {
            throw new RuntimeException("Module '{$name}' already exists at {$dir}.");
        }

        $created = [];
        foreach (self::_templates() as $rel => $template) {
            $path    = $dir . '/' . $rel;
            $content = str_replace(['{{name}}', '{{Name}}'], [$name, $Name], $template);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $content);
            $created[] = $path;
        }
        return $created;
    }

    /** relative path => template. */
    protected static function _templates()
    {
        $bootstrap = <<<'PHP'
<?php
/**
 * {{Name}} module bootstrap.
 *
 * An empty bootstrap is enough: its presence enables ZF1's module resource
 * autoloader ({{Name}}_Service_*, {{Name}}_Model_*, {{Name}}_Form_*, …) and lets
 * Tiger's module scanning pick this module up automatically. Add _init* methods
 * here for module-specific setup.
 */
class {{Name}}_Bootstrap extends Zend_Application_Module_Bootstrap
{
}

PHP;

        $controller = <<<'PHP'
<?php
/**
 * {{Name}}_IndexController — reachable at /{{name}}/ via the default route.
 *
 * Every dispatch is authorized by Tiger_Controller_Plugin_Authorization against
 * this class name (deny-by-default) — see configs/acl.ini for who may reach it.
 * Extends Tiger_Controller_Action for the shared conveniences (config/flash/_json).
 */
class {{Name}}_IndexController extends Tiger_Controller_Action
{
    public function indexAction()
    {
        // Renders views/scripts/index/index.phtml inside the active theme's layout.
    }
}

PHP;

        $service = <<<'PHP'
<?php
/**
 * {{Name}}_Service_Example — an /api service (the TIGER message pattern).
 *
 * Call it by POSTing a message to the single /api endpoint:
 *   POST /api   { module: '{{name}}', service: 'example', method: 'ping', ... }
 * The whole message arrives as $params; respond via $this->_success()/_error().
 * ACL-gated: resource = {{Name}}_Service_Example, privilege = the method name.
 */
class {{Name}}_Service_Example extends Tiger_Service_Service
{
    public function ping(array $params)
    {
        $this->_success(['pong' => true, 'module' => '{{name}}']);
    }
}

PHP;

        $view = <<<'PHTML'
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="h3 mb-3">The <strong>{{name}}</strong> module</h1>
            <p class="text-secondary">
                Scaffolded by <code>bin/tiger make:module</code>. Build it out under
                <code>application/modules/{{name}}/</code> — controllers, services,
                models, migrations. This page is
                <code>views/scripts/index/index.phtml</code>.
            </p>
        </div>
    </div>
</div>

PHTML;

        $moduleIni = <<<'INI'
; {{Name}} module metadata.
[module]
name        = "{{Name}}"
description = "The {{name}} module."
version     = "0.1.0"

INI;

        $aclIni = <<<'INI'
; ACL policy for the {{name}} module. The platform is DENY-BY-DEFAULT, so a
; resource with no allow rule is BLOCKED — grant access here.
[production]

; Resources this module governs (controller + service class names):
acl.resources.{{name}}_index.resource   = "{{Name}}_IndexController"
acl.resources.{{name}}_example.resource = "{{Name}}_Service_Example"

; Grant: authenticated users may reach them. Change "user" to a higher role
; (manager/admin/…) to restrict, or to "guest" to make the page public.
acl.rules.{{name}}_index_allow.role       = "user"
acl.rules.{{name}}_index_allow.resource   = "{{Name}}_IndexController"
acl.rules.{{name}}_index_allow.permission = "allow"

acl.rules.{{name}}_example_allow.role       = "user"
acl.rules.{{name}}_example_allow.resource   = "{{Name}}_Service_Example"
acl.rules.{{name}}_example_allow.permission = "allow"

[staging : production]

[testing : production]

[development : production]

INI;

        $routesIni = <<<'INI'
; Custom routes for the {{name}} module (optional).
;
; The default route already maps /{{name}}/:controller/:action, so add routes here
; only for pretty/custom URLs, e.g.:
;
; [production]
; routes.{{name}}_home.route               = "{{name}}"
; routes.{{name}}_home.defaults.module     = "{{name}}"
; routes.{{name}}_home.defaults.controller = "index"
; routes.{{name}}_home.defaults.action     = "index"
;
; [staging : production]
; [testing : production]
; [development : production]

INI;

        return [
            'Bootstrap.php'                     => $bootstrap,
            'controllers/IndexController.php'   => $controller,
            'services/Example.php'              => $service,
            'views/scripts/index/index.phtml'   => $view,
            'configs/module.ini'                => $moduleIni,
            'configs/acl.ini'                   => $aclIni,
            'configs/routes.ini'                => $routesIni,
            'models/.gitkeep'                   => '',
            'migrations/.gitkeep'               => '',
        ];
    }
}
