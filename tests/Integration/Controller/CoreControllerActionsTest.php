<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Controller;

use AdminController;
use ApiController;
use ArrayObject;
use AuthController;
use ErrorController;
use IndexController;
use PageController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ControllerTestCase;
use Tiger_Model_Page;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Zend_Auth;
use Zend_Auth_Storage_NonPersistent;
use Zend_Config;
use Zend_Controller_Action_Exception;
use Zend_Controller_Plugin_ErrorHandler;
use Zend_Registry;
use Zend_Session;

/**
 * Drives the six default-namespace controllers (`core/controllers/*`) through the dispatch harness,
 * covering the ACTION branches the reference `CoreControllerDispatchTest` left untouched: every
 * AuthController action (login GET/redirect, logout, lock arm/unlock, forgot/reset/otp, security,
 * me, the TOTP endpoints), ApiController's openapi discovery gate, IndexController's home-page +
 * marketing actions, ErrorController's 404/500/403 classification, PageController's CMS + theme
 * dispatch, and AdminController's dashboard.
 *
 * View rendering stays OFF (the harness covers action LOGIC, not the .phtml). Actions that end in a
 * `.phtml` render are asserted on their OUTCOME — the response code, the redirect/`_forward`, the
 * JSON body, or the view vars they set — never the rendered HTML. Session runs in Zend's array-backed
 * unit-test mode; a real crypto key + pepper are seeded so the password-verifying paths (unlock) run.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(AuthController::class)]
#[CoversClass(ErrorController::class)]
#[CoversClass(IndexController::class)]
#[CoversClass(PageController::class)]
#[CoversClass(AdminController::class)]
final class CoreControllerActionsTest extends ControllerTestCase
{
    private const KEY    = 'ERERERERERERERERERERERERERERERERERERERERERE=';
    private const PEPPER = 'cGVwcGVyLUEtMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=';

    private bool $priorUnitTestMode;
    private $priorConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];

        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $this->setTigerConfig([]);   // crypto + pepper only (no discovery, no home page)

        // The redirector exits the process by default (redirectAndExit) — fatal under CLI. Disable exit so
        // a `gotoUrl` just sets the Location header, which the harness reads via redirectLocation().
        \Zend_Controller_Action_HelperBroker::getStaticHelper('redirector')->setExit(false);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        }
        parent::tearDown();
    }

    /** Seed the process-global Zend_Config with crypto secrets + whatever extra `tiger.*` a test needs. */
    private function setTigerConfig(array $tigerExtra): void
    {
        $tiger = ['crypto' => ['key' => self::KEY], 'security' => ['pepper' => self::PEPPER], 'log' => ['writer' => 'null']] + $tigerExtra;
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger], true));
        \Tiger_Log::reset();   // rebuild the (cached) logger against this config — a null sink keeps test output clean
    }

    /** Create an active user with a password credential; returns [user_id, email]. */
    private function makeUserWithPassword(string $password): array
    {
        $email = 'ctl-' . bin2hex(random_bytes(8)) . '@example.test';
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'active']);
        (new Tiger_Model_UserCredential())->setPassword($uid, $password);
        return [$uid, $email];
    }

    /** Put a full identity object into Zend_Auth (in-memory storage — no $_SESSION under CLI). */
    private function writeIdentity(object $identity): void
    {
        $auth = Zend_Auth::getInstance();
        $auth->setStorage(new Zend_Auth_Storage_NonPersistent());
        $auth->getStorage()->write($identity);
    }

    /** Insert a published CMS page and return its id. */
    private function seedPage(array $overrides = []): string
    {
        return (new Tiger_Model_Page())->insert($overrides + [
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'slug'   => 'p-' . bin2hex(random_bytes(6)),
            'locale' => 'en',
            'title'  => 'Test Page',
            'body'   => '<p>Hello world.</p>',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
        ]);
    }

    // ===== ApiController ======================================================

    #[Test]
    public function openapi_action_404s_when_discovery_is_disabled(): void
    {
        $res = $this->dispatchAction(ApiController::class, 'openapi', [], 'GET');
        $this->assertSame(404, $res->getHttpResponseCode(), 'the API surface is not published unless opted in');
    }

    #[Test]
    public function openapi_action_emits_the_spec_when_discovery_is_enabled(): void
    {
        $this->setTigerConfig(['api' => ['discovery' => '1'], 'site' => ['name' => 'Acme']]);
        $res  = $this->dispatchAction(ApiController::class, 'openapi', [], 'GET');
        $json = $this->jsonResponse();

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertArrayHasKey('openapi', $json, 'a real OpenAPI 3 document is emitted');
        $this->assertSame('Acme API', $json['info']['title'] ?? '', 'the site name titles the spec');
    }

    // ===== IndexController ====================================================

    #[Test]
    public function index_action_serves_the_builtin_landing_when_no_home_page_is_set(): void
    {
        $res = $this->dispatchAction(IndexController::class, 'index', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertSame(\Tiger_Version::VERSION, $this->controller()->view->tigerVersion, 'the landing exposes the platform version');
        $this->assertNotEmpty($this->controller()->view->servedBy, 'the "served from vendor" proof var is set');
    }

    #[Test]
    public function index_action_forwards_to_the_cms_page_when_a_published_home_page_is_configured(): void
    {
        $pageId = $this->seedPage();
        $this->setTigerConfig(['site' => ['home_page' => $pageId]]);

        $this->dispatchAction(IndexController::class, 'index', [], 'GET');

        $fwd = $this->forwardedTo();
        $this->assertSame('page', $fwd['controller'], 'the home page dispatches through PageController');
        $this->assertSame('view', $fwd['action']);
        $this->assertFalse($fwd['dispatched'], 'a _forward re-queues the request for dispatch');
    }

    #[Test]
    public function every_marketing_action_dispatches_cleanly(): void
    {
        foreach (['vibe', 'agency', 'developers', 'creators', 'hosting', 'features'] as $action) {
            $res = $this->dispatchAction(IndexController::class, $action, [], 'GET');
            $this->assertSame(200, $res->getHttpResponseCode(), "the /$action marketing page dispatches without error");
        }
    }

    // ===== AuthController =====================================================

    #[Test]
    public function login_get_renders_the_form_for_a_guest(): void
    {
        $res = $this->dispatchAction(AuthController::class, 'login', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertSame('Sign in — Tiger', $this->controller()->view->title);
        $this->assertNull($this->controller()->view->notice, 'no notice without a reset/out flag');
    }

    #[Test]
    public function login_get_shows_the_reset_notice(): void
    {
        $this->dispatchAction(AuthController::class, 'login', ['reset' => '1'], 'GET');
        $this->assertStringContainsString('reset', (string) $this->controller()->view->notice);
    }

    #[Test]
    public function login_get_shows_the_signed_out_notice(): void
    {
        $this->dispatchAction(AuthController::class, 'login', ['out' => '1'], 'GET');
        $this->assertStringContainsString('signed out', (string) $this->controller()->view->notice);
    }

    #[Test]
    public function login_get_redirects_an_already_authenticated_admin_to_the_back_office(): void
    {
        $this->writeIdentity((object) ['user_id' => 'u1', 'org_id' => 'o1', 'role' => 'admin']);
        $this->dispatchAction(AuthController::class, 'login', [], 'GET');
        $this->assertStringContainsString('/admin', $this->redirectLocation(), 'an admin lands on /admin');
    }

    #[Test]
    public function login_get_redirects_an_already_authenticated_member_home(): void
    {
        $this->writeIdentity((object) ['user_id' => 'u1', 'org_id' => 'o1', 'role' => 'user']);
        $this->dispatchAction(AuthController::class, 'login', [], 'GET');
        $loc = $this->redirectLocation();
        $this->assertNotSame('', $loc, 'a signed-in member is redirected off the login form');
        $this->assertStringNotContainsString('/admin', $loc, 'a non-admin does not land on the back office');
    }

    #[Test]
    public function logout_action_renders_the_signed_out_card(): void
    {
        $res = $this->dispatchAction(AuthController::class, 'logout', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertSame('Signed out — Tiger', $this->controller()->view->title);
    }

    #[Test]
    public function lock_get_bounces_a_guest_to_login(): void
    {
        $res = $this->dispatchAction(AuthController::class, 'lock', [], 'GET');
        $this->assertStringContainsString('/auth/login', $this->redirectLocation(), 'the lock screen requires an identity');
    }

    #[Test]
    public function lock_get_arms_the_lock_for_an_authenticated_user(): void
    {
        $this->writeIdentity((object) ['user_id' => 'u1', 'org_id' => 'o1', 'role' => 'user']);
        $res = $this->dispatchAction(AuthController::class, 'lock', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertSame('Locked — Tiger', $this->controller()->view->title);
    }

    #[Test]
    public function lock_post_unlocks_with_the_correct_password(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('unlock me right 12');
        $this->writeIdentity((object) ['user_id' => $uid, 'org_id' => '', 'role' => 'user', 'email' => $email]);

        $res  = $this->dispatchAction(AuthController::class, 'lock', ['password' => 'unlock me right 12'], 'POST');
        $json = $this->jsonResponse();

        $this->assertSame(1, (int) ($json['result'] ?? 0), 'the right password clears the lock');
        $this->assertArrayHasKey('redirect', $json);
    }

    #[Test]
    public function lock_post_refuses_a_wrong_password(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('the real one 34');
        $this->writeIdentity((object) ['user_id' => $uid, 'org_id' => '', 'role' => 'user', 'email' => $email]);

        $res  = $this->dispatchAction(AuthController::class, 'lock', ['password' => 'the WRONG one'], 'POST');
        $json = $this->jsonResponse();

        $this->assertSame(0, (int) ($json['result'] ?? -1), 'a bad password stays locked');
        $this->assertSame(401, $res->getHttpResponseCode());
    }

    #[Test]
    public function lock_post_send_code_emails_an_unlock_code(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('code path pw 567');
        $this->writeIdentity((object) ['user_id' => $uid, 'org_id' => '', 'role' => 'user', 'email' => $email]);

        $this->dispatchAction(AuthController::class, 'lock', ['send_code' => '1'], 'POST');
        $this->assertSame(1, (int) ($this->jsonResponse()['result'] ?? 0), 'requesting an unlock code always returns success');
    }

    #[Test]
    public function forgot_get_renders_and_post_is_enumeration_safe(): void
    {
        $get = $this->dispatchAction(AuthController::class, 'forgot', [], 'GET');
        $this->assertSame('Reset password — Tiger', $this->controller()->view->title);

        $this->dispatchAction(AuthController::class, 'forgot', ['email' => 'nobody@nowhere.test'], 'POST');
        $this->assertSame(1, (int) ($this->jsonResponse()['result'] ?? 0), 'a reset request never reveals whether the account exists');
    }

    #[Test]
    public function reset_get_wires_the_token_and_post_rejects_a_bad_token(): void
    {
        $this->dispatchAction(AuthController::class, 'reset', ['cid' => 'abc', 'code' => 'xyz'], 'GET');
        $this->assertSame('abc', $this->controller()->view->cid);
        $this->assertSame('xyz', $this->controller()->view->code);

        $res = $this->dispatchAction(AuthController::class, 'reset', [
            'cid' => 'no-such', 'code' => 'nope', 'password' => 'a new one here', 'confirm' => 'a new one here',
        ], 'POST');
        $this->assertSame(0, (int) ($this->jsonResponse()['result'] ?? -1), 'an invalid reset token fails');
        $this->assertSame(400, $res->getHttpResponseCode());
    }

    #[Test]
    public function otp_get_renders_and_post_requests_a_code(): void
    {
        $this->dispatchAction(AuthController::class, 'otp', [], 'GET');
        $this->assertSame('Sign in with a code — Tiger', $this->controller()->view->title);

        // No `code` in the body → request-a-code step, always a silent success (no enumeration).
        $this->dispatchAction(AuthController::class, 'otp', ['email' => 'someone@nowhere.test'], 'POST');
        $this->assertSame(1, (int) ($this->jsonResponse()['result'] ?? 0));
    }

    #[Test]
    public function otp_post_with_a_bad_code_is_refused(): void
    {
        $res = $this->dispatchAction(AuthController::class, 'otp', [
            'email' => 'ghost@nowhere.test', 'code' => '000000',
        ], 'POST');
        $this->assertSame(0, (int) ($this->jsonResponse()['result'] ?? -1));
        $this->assertSame(401, $res->getHttpResponseCode());
    }

    #[Test]
    public function security_get_bounces_a_guest_and_serves_the_screen_for_a_user(): void
    {
        $guest = $this->dispatchAction(AuthController::class, 'security', [], 'GET');
        $this->assertStringContainsString('/auth/login', $this->redirectLocation(), 'a guest is sent to sign in first');

        $this->writeIdentity((object) ['user_id' => 'u1', 'org_id' => 'o1', 'role' => 'user']);
        $res = $this->dispatchAction(AuthController::class, 'security', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertIsArray($this->controller()->view->twofa, 'the 2FA status is handed to the view');
    }

    #[Test]
    public function me_action_reports_the_current_identity(): void
    {
        $guest = $this->dispatchAction(AuthController::class, 'me', [], 'GET');
        $this->assertSame(0, (int) ($this->jsonResponse()['result'] ?? -1), 'a guest has no identity');

        $this->writeIdentity((object) ['user_id' => 'u9', 'org_id' => 'o1', 'role' => 'user']);
        $this->dispatchAction(AuthController::class, 'me', [], 'GET');
        $json = $this->jsonResponse();
        $this->assertSame(1, (int) ($json['result'] ?? 0));
        $this->assertSame('u9', $json['data']['user_id'] ?? null);
    }

    #[Test]
    public function the_totp_management_endpoints_deny_a_guest(): void
    {
        foreach (['totpSetup', 'totpActivate', 'totpDisable'] as $action) {
            $res = $this->dispatchAction(AuthController::class, $action, [], 'POST');
            $this->assertSame(0, (int) ($this->jsonResponse()['result'] ?? -1), "$action refuses an unauthenticated caller");
            $this->assertSame(401, $res->getHttpResponseCode());
        }
    }

    // ===== ErrorController ====================================================

    #[Test]
    public function error_action_with_no_handler_falls_back_to_500(): void
    {
        $res = $this->dispatchAction(ErrorController::class, 'error', [], 'GET');
        $this->assertSame(500, $res->getHttpResponseCode());
        $this->assertSame(500, $this->controller()->view->statusCode);
    }

    #[Test]
    public function error_action_classifies_a_no_route_miss_as_404(): void
    {
        $handler = new ArrayObject([
            'type'      => Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ROUTE,
            'exception' => new \Exception('no route'),
        ], ArrayObject::ARRAY_AS_PROPS);

        $res = $this->dispatchAction(ErrorController::class, 'error', ['error_handler' => $handler], 'GET');
        $this->assertSame(404, $res->getHttpResponseCode());
        $this->assertSame(404, $this->controller()->view->statusCode);
    }

    #[Test]
    public function error_action_logs_and_renders_a_500_for_an_unhandled_exception(): void
    {
        $handler = new ArrayObject([
            'type'      => Zend_Controller_Plugin_ErrorHandler::EXCEPTION_OTHER,
            'exception' => new \RuntimeException('boom'),
        ], ArrayObject::ARRAY_AS_PROPS);

        $res = $this->dispatchAction(ErrorController::class, 'error', ['error_handler' => $handler], 'GET');
        $this->assertSame(500, $res->getHttpResponseCode());
        // Non-production (testing env) attaches the debug bundle for the view.
        $this->assertIsArray($this->controller()->view->debug ?? null, 'the debug bundle is built outside production');
        $this->assertSame('RuntimeException', $this->controller()->view->debug['exception']['type']);
    }

    #[Test]
    public function forbidden_action_renders_a_403(): void
    {
        $res = $this->dispatchAction(ErrorController::class, 'forbidden', [], 'GET');
        $this->assertSame(403, $res->getHttpResponseCode());
        $this->assertSame(403, $this->controller()->view->statusCode);
    }

    // ===== PageController =====================================================

    #[Test]
    public function page_view_throws_a_404_for_a_missing_page(): void
    {
        $this->expectException(Zend_Controller_Action_Exception::class);
        $this->dispatchAction(PageController::class, 'view', ['cms_page_id' => 'does-not-exist'], 'GET');
    }

    #[Test]
    public function page_view_renders_a_published_page_in_the_theme_layout(): void
    {
        $pageId = $this->seedPage(['title' => 'About Us', 'body' => '<p>About.</p>']);

        $res = $this->dispatchAction(PageController::class, 'view', ['cms_page_id' => $pageId], 'GET');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertSame('About Us', $this->controller()->view->title, 'the page title is handed to the layout');
        $this->assertStringContainsString('About.', (string) $this->controller()->view->cmsContent, 'the rendered body is passed to the view');
    }

    #[Test]
    public function page_view_renders_a_self_contained_layout_page_into_the_body(): void
    {
        // A layout row + a page that references it → PageController emits a self-contained document
        // (the theme layout is disabled and the HTML is set as the response body).
        (new Tiger_Model_Page())->insert([
            'type'     => Tiger_Model_Page::TYPE_LAYOUT,
            'page_key' => 'full-doc',
            'locale'   => 'en',
            'title'    => 'Full Doc Layout',
            // A phtml layout self-injects the page body via $this->content (renderer context var).
            'body'     => '<html><head></head><body><?= $this->content ?></body></html>',
            'format'   => Tiger_Model_Page::FORMAT_PHTML,
            'status'   => Tiger_Model_Page::STATUS_PUBLISHED,
        ]);
        $pageId = $this->seedPage([
            'title'      => 'Landing',
            'body'       => '<h1>Landing body</h1>',
            'layout_key' => 'full-doc',
            'meta'       => json_encode(['head_html' => '<meta name="x" content="y">', 'body_scripts' => '<script>void 0;</script>']),
        ]);

        $res  = $this->dispatchAction(PageController::class, 'view', ['cms_page_id' => $pageId], 'GET');
        $body = $res->getBody();

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertStringContainsString('Landing body', $body, 'the layout-wrapped page is emitted as the response body');
        $this->assertStringContainsString('<meta name="x"', $body, 'the admin-authored head_html is spliced into <head>');
        $this->assertStringContainsString('void 0;', $body, 'the admin-authored body scripts are spliced before </body>');
    }

    #[Test]
    public function theme_content_throws_a_404_when_no_theme_partial_matches(): void
    {
        // No active theme is booted in the harness → Tiger_Theme::dir() is '' → always a 404.
        $this->expectException(Zend_Controller_Action_Exception::class);
        $this->dispatchAction(PageController::class, 'themeContent', ['theme_content_slug' => 'nope'], 'GET');
    }

    #[Test]
    public function theme_content_serves_a_hinted_theme_partial(): void
    {
        // Stand up a throwaway theme dir with a hinted content partial, then point Tiger_Theme at it.
        $themeDir = sys_get_temp_dir() . '/w6-theme-' . bin2hex(random_bytes(4));
        mkdir($themeDir . '/content', 0777, true);
        file_put_contents(
            $themeDir . '/content/hello.phtml',
            "<!-- tiger:page title=\"Hello There\" skin=\"default\" css=\"demos/x.css\" view=\"view.hello\" -->\n<h1>Theme body</h1>"
        );
        Zend_Registry::set('Tiger_ThemeDir', $themeDir);

        try {
            $res = $this->dispatchAction(PageController::class, 'themeContent', ['theme_content_slug' => 'hello'], 'GET');

            $this->assertSame(200, $res->getHttpResponseCode());
            $this->assertSame('Hello There', $this->controller()->view->title, 'the hint title drives the page title');
            $this->assertStringContainsString('Theme body', (string) $this->controller()->view->cmsContent, 'the partial body (hint stripped) is handed to the view');
            $this->assertStringContainsString('demos/x.css', (string) $this->controller()->view->pageHead, 'the hint css becomes a <link> in the head');
            $this->assertStringContainsString('view.hello', (string) $this->controller()->view->pageScripts, 'the hint view becomes a <script> tag');
        } finally {
            Zend_Registry::set('Tiger_ThemeDir', '');
            @unlink($themeDir . '/content/hello.phtml');
            @rmdir($themeDir . '/content');
            @rmdir($themeDir);
        }
    }

    // ===== AdminController ====================================================

    #[Test]
    public function admin_dashboard_renders_for_an_admin(): void
    {
        $this->login('user-admin', 'org-test', 'admin');   // establishes the real ACL too
        $res = $this->dispatchAction(AdminController::class, 'index', [], 'GET');

        $this->assertSame(200, $res->getHttpResponseCode());
        $this->assertSame('Dashboard — Tiger Admin', $this->controller()->view->title);
        $this->assertIsArray($this->controller()->view->widgets, 'the dashboard hands an (ACL-filtered) widget list to the view');
    }
}
