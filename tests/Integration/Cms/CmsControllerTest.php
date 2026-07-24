<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Cms;

use Cms_IndexController;
use Cms_MenuController;
use Cms_PageController;
use Cms_SettingsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ControllerTestCase;
use Tiger_Model_Page;
use Zend_Config;
use Zend_Registry;
use Zend_Session;

/**
 * The four CMS admin/public controllers, dispatched through the ControllerTestCase harness with
 * view-rendering off. These are thin READ+RENDER screens (the client/server rule): each action sets
 * up view-vars + a form and gets out of the way — every mutation is an /api call, covered by the
 * service tests. So the assertion is the SHELL: the action dispatches cleanly, sets its `view->title`,
 * `useDataTables`, form, and the theme-template / version / palette vars the .phtml then draws.
 *
 * A fixture theme (a temp dir with a `content/` page + a `components/` block + a manifest) is wired in
 * via the `Tiger_ThemeDir` registry seam so the page-list's "customize a theme template" branch and the
 * GrapesJS design canvas's block/menu-preview branches actually run (with no theme, `Tiger_Theme` short-
 * circuits to empty). Zend_Session unit-test mode is on so the FlashMessenger the base controller wires
 * in init() has no live session to reach for.
 */
#[CoversClass(Cms_PageController::class)]
#[CoversClass(Cms_MenuController::class)]
#[CoversClass(Cms_SettingsController::class)]
#[CoversClass(Cms_IndexController::class)]
final class CmsControllerTest extends ControllerTestCase
{
    private bool $priorUnitTestMode;
    private string $themeDir;
    private bool $hadThemeDir = false;
    private $priorThemeDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];

        // designAction redirects a missing page via the redirector; keep gotoUrl from exit()-ing so the
        // Location header is assertable instead of ending the PHP process.
        \Zend_Controller_Action_HelperBroker::getStaticHelper('redirector')->setExit(false);

        // A throwaway theme on disk, registered as the active theme so Tiger_Theme::pages()/components()/
        // manifest() return real data — driving the theme-template + builder-block branches.
        $this->themeDir = sys_get_temp_dir() . '/w6-cms-theme-' . getmypid();
        @mkdir($this->themeDir . '/content', 0777, true);
        @mkdir($this->themeDir . '/components', 0777, true);
        file_put_contents($this->themeDir . '/theme.json', json_encode([
            'key' => 'testtheme', 'name' => 'Test Theme', 'canvasCss' => ['/theme/canvas.css'],
        ]));
        file_put_contents(
            $this->themeDir . '/content/welcome.phtml',
            "<!-- tiger:page title=\"Welcome\" layout=\"page\" -->\n<h1>Welcome</h1>"
        );
        file_put_contents(
            $this->themeDir . '/components/cta.phtml',
            "<!-- tiger:block label=\"CTA\" category=\"Test\" icon=\"fa-star\" -->\n<div>CTA</div>"
        );

        $reg = Zend_Registry::getInstance();
        $this->hadThemeDir = $reg->offsetExists('Tiger_ThemeDir');
        if ($this->hadThemeDir) { $this->priorThemeDir = Zend_Registry::get('Tiger_ThemeDir'); }
        Zend_Registry::set('Tiger_ThemeDir', $this->themeDir);
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($this->hadThemeDir) { Zend_Registry::set('Tiger_ThemeDir', $this->priorThemeDir); }
        elseif ($reg->offsetExists('Tiger_ThemeDir')) { $reg->offsetUnset('Tiger_ThemeDir'); }
        $this->rmrf($this->themeDir);

        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function seedPage(array $overrides): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'title'  => 'Seed',
            'body'   => '',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_DRAFT,
        ], $overrides));
    }

    // ----- Cms_PageController -------------------------------------------------------------------

    #[Test]
    public function page_index_renders_the_content_list_shell_with_theme_templates(): void
    {
        $this->loginAs('admin');
        // A page already claims the theme template's slug → the "already customized" flag branch.
        $id = $this->seedPage(['title' => 'Welcome', 'slug' => 'welcome', 'page_key' => 'welcome']);

        $res = $this->dispatchAction(Cms_PageController::class, 'index', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertSame('Content — Tiger Admin', $view->title);
        $this->assertTrue($view->useDataTables);
        $this->assertSame('Test Theme', $view->themeName);
        $this->assertNotEmpty($view->themeTemplates, 'the active theme templates are surfaced');

        $welcome = null;
        foreach ($view->themeTemplates as $t) { if ($t['slug'] === 'welcome') { $welcome = $t; } }
        $this->assertNotNull($welcome, 'the welcome template appears');
        $this->assertSame($id, $welcome['page_id'], 'the template is flagged customized (a page claims its slug)');
    }

    #[Test]
    public function page_edit_with_no_id_renders_a_blank_new_form(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(Cms_PageController::class, 'edit', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertStringContainsString('New', $view->title);
        $this->assertNull($view->page, 'no page to edit');
        $this->assertSame([], $view->versions);
        $this->assertNotNull($view->form);
    }

    #[Test]
    public function page_edit_with_an_id_populates_the_form_from_the_row_and_its_meta(): void
    {
        $this->loginAs('admin');
        $meta = json_encode([
            'seo'          => ['description' => 'A seo blurb'],
            'head_html'    => '<meta name="x">',
            'body_scripts' => '<script>1</script>',
        ]);
        $id = $this->seedPage(['title' => 'Editable', 'slug' => 'editable', 'page_key' => 'editable', 'meta' => $meta]);

        $res = $this->dispatchAction(Cms_PageController::class, 'edit', ['id' => $id], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertStringContainsString('Edit', $view->title);
        $this->assertNotNull($view->page, 'the row loaded');
        $this->assertSame('Editable', $view->page->title);
        // _editValues fed the form; the meta.seo.description round-trips into the form value.
        $this->assertSame('A seo blurb', $view->form->getValue('meta_description'));
    }

    #[Test]
    public function page_design_redirects_when_the_page_is_missing(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Cms_PageController::class, 'design', ['id' => 'no-such-page'], 'GET');
        $this->assertStringContainsString('/cms/page', $this->redirectLocation(), 'a bad id bounces back to the list');
    }

    #[Test]
    public function page_design_renders_the_builder_with_theme_blocks_and_the_builder_project(): void
    {
        $this->loginAs('admin');
        $builder = json_encode(['pages' => [['frames' => []]]]);
        $id = $this->seedPage([
            'title'  => 'Designed', 'slug' => 'designed', 'page_key' => 'designed',
            'format' => Tiger_Model_Page::FORMAT_BUILDER,
            'meta'   => json_encode(['builder' => json_decode($builder, true)]),
        ]);

        $res = $this->dispatchAction(Cms_PageController::class, 'design', ['id' => $id], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertSame('Designed', $view->title);
        $this->assertNotNull($view->projectData, 'the lossless builder project is passed to the canvas');
        $this->assertNotEmpty($view->themeBlocks, 'the active theme components seed the block palette');
        $this->assertSame(['/theme/canvas.css'], $view->canvasCss, 'the manifest canvasCss is loaded into the canvas');
        $this->assertIsArray($view->menus, 'menus are pre-rendered for the live Menu-component preview');
    }

    // ----- Cms_MenuController -------------------------------------------------------------------

    #[Test]
    public function menu_index_renders_the_list_shell(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatchAction(Cms_MenuController::class, 'index', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
        $view = $this->controller()->view;
        $this->assertSame('Menus — Tiger Admin', $view->title);
        $this->assertTrue($view->useDataTables);
    }

    #[Test]
    public function menu_edit_new_has_an_empty_tree_and_menu_edit_with_a_key_builds_the_page_palette(): void
    {
        $this->loginAs('admin');

        // New menu (no key): empty tree, still renders the form + palette.
        $this->dispatchAction(Cms_MenuController::class, 'edit', [], 'GET');
        $newView = $this->controller()->view;
        $this->assertStringContainsString('New Menu', $newView->title);
        $this->assertSame([], $newView->tree);
        $this->assertNotNull($newView->form);

        // Existing menu (a key) + a keyed published page → the "Pages" palette source is populated.
        $this->seedPage(['title' => 'Palette Page', 'slug' => 'palette', 'page_key' => 'palette', 'status' => Tiger_Model_Page::STATUS_PUBLISHED]);
        $this->dispatchAction(Cms_MenuController::class, 'edit', ['key' => 'primary'], 'GET');
        $editView = $this->controller()->view;
        $this->assertStringContainsString('Edit Menu', $editView->title);
        $this->assertSame('primary', $editView->menuKey);
        $keys = array_column($editView->pages, 'page_key');
        $this->assertContains('palette', $keys, 'a keyed published page is offered in the palette');
    }

    // ----- Cms_SettingsController ---------------------------------------------------------------

    #[Test]
    public function settings_index_prefills_the_form_from_the_live_config(): void
    {
        $this->loginAs('admin');
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['site' => ['name' => 'Configured Site', 'home_page' => 'home-slug']],
        ]));

        $res = $this->dispatchAction(Cms_SettingsController::class, 'index', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());

        $view = $this->controller()->view;
        $this->assertSame('Settings — Tiger Admin', $view->title);
        $this->assertSame('Configured Site', $view->form->getValue('site_name'));
        $this->assertSame('home-slug', $view->form->getValue('home_page'));
    }

    #[Test]
    public function settings_index_falls_back_to_the_default_site_name_when_config_is_empty(): void
    {
        $this->loginAs('admin');
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => []]));

        $this->dispatchAction(Cms_SettingsController::class, 'index', [], 'GET');
        $view = $this->controller()->view;
        $this->assertSame('Tiger', $view->form->getValue('site_name'), 'the built-in default fills in when unset');
    }

    // ----- Cms_IndexController (public marketing face) ------------------------------------------

    #[Test]
    public function the_public_cms_landing_dispatches_cleanly(): void
    {
        // Guest-allowed marketing page — no model, no forward, just the view. It must dispatch without error.
        $res = $this->dispatchAction(Cms_IndexController::class, 'index', [], 'GET');
        $this->assertSame(200, $res->getHttpResponseCode());
    }
}
