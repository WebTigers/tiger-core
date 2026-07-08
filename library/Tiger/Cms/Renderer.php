<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Cms_Renderer — turn CMS page/layout/partial content into HTML.
 *
 * Renders a body by its `format`:
 *   html     -> output as-is, then the [shortcode] processor
 *   markdown -> Parsedown -> HTML, then the [shortcode] processor
 *   phtml    -> Zend_View string render (TigerZF's renderString) with a view
 *               CONTEXT — TRUSTED code; view vars + all helpers available, so a
 *               page can loop e.g. a $posts array.
 *
 * A page is then wrapped in its layout (layout_key -> a type=layout row) with the
 * rendered body handed to the layout as $this->content.
 *
 * Shortcodes ([name attr="x"]inner[/name] or self-closing [name attr="x"]) are the
 * SAFE dynamic mechanism for html/markdown authors: register a handler and it is
 * substituted at render time. phtml doesn't need them — it already has full code.
 *
 * @api
 */
class Tiger_Cms_Renderer
{
    /** @var array<string,callable> name => handler(array $attrs, ?string $inner, array $context) */
    protected static $_shortcodes = [];

    /** Register (or replace) a [shortcode] handler. */
    public static function registerShortcode($name, callable $handler)
    {
        self::$_shortcodes[strtolower($name)] = $handler;
    }

    /**
     * Render a page row to HTML: its body by format, then wrapped in its layout
     * (when layout_key is set). $context view vars are available to phtml bodies.
     *
     * @param object $page    a `page` row (->body, ->format, ->layout_key, ->locale, ->org_id)
     * @param array  $context extra view vars for phtml
     * @return string
     */
    public function render($page, array $context = [])
    {
        $context += ['page' => $page];
        $html = $this->renderBody($page->body, $page->format, $context);

        if (!empty($page->layout_key)) {
            $layout = (new Tiger_Model_Page())->fetchByKey(
                $page->layout_key,
                $page->locale ?? 'en',
                $page->org_id ?? '',
                Tiger_Model_Page::TYPE_LAYOUT
            );
            if ($layout) {
                $html = $this->renderBody($layout->body, $layout->format, $context + ['content' => $html]);
            }
        }
        return $html;
    }

    /**
     * Render a raw body string by format.
     *
     * @param string $body
     * @param string $format  html | markdown | phtml
     * @param array  $context view vars (phtml)
     * @return string
     */
    public function renderBody($body, $format, array $context = [])
    {
        $body = (string) $body;
        switch (strtolower((string) $format)) {
            case Tiger_Model_Page::FORMAT_PHTML:
                // TRUSTED code — rendered in a view scope with the context + helpers.
                return $this->_view($context)->renderString($body);

            case Tiger_Model_Page::FORMAT_MARKDOWN:
                require_once __DIR__ . '/vendor/Parsedown.php';
                $html = Parsedown::instance()->text($body);
                return $this->_shortcodes($html, $context);

            case Tiger_Model_Page::FORMAT_BUILDER:
                // GrapesJS output — a self-contained <style> + markup block. Rendered
                // verbatim (like html) then the [shortcode] pass; <script> was stripped
                // at save time, so it stays a SAFE format.
            case Tiger_Model_Page::FORMAT_HTML:
            default:
                return $this->_shortcodes($body, $context);
        }
    }

    /** A view for phtml rendering: clone the themed registry view (helpers + paths), assign context. */
    protected function _view(array $context)
    {
        if (Zend_Registry::isRegistered('Tiger_View')) {
            $view = clone Zend_Registry::get('Tiger_View');
            $view->clearVars();
        } else {
            $view = new Zend_View();
        }
        foreach ($context as $key => $value) {
            $view->$key = $value;
        }
        return $view;
    }

    /**
     * Substitute registered [shortcode]s in a string. Handles both
     * [name attr="x"]inner[/name] and self-closing [name attr="x"]. An unknown
     * shortcode is left untouched.
     */
    protected function _shortcodes($html, array $context = [])
    {
        if (!self::$_shortcodes || strpos($html, '[') === false) {
            return $html;
        }
        return preg_replace_callback(
            '/\[([a-zA-Z0-9_-]+)((?:\s+[a-zA-Z0-9_-]+="[^"]*")*)\s*\](?:(.*?)\[\/\1\])?/s',
            function ($m) use ($context) {
                $name = strtolower($m[1]);
                if (!isset(self::$_shortcodes[$name])) {
                    return $m[0];   // not registered — leave the literal text
                }
                $attrs = [];
                if (preg_match_all('/([a-zA-Z0-9_-]+)="([^"]*)"/', $m[2], $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $attrs[$pair[1]] = $pair[2];
                    }
                }
                return (string) call_user_func(
                    self::$_shortcodes[$name], $attrs, $m[3] ?? null, $context
                );
            },
            $html
        );
    }
}
