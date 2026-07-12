<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms_Form_Page — create/edit a CMS page, layout, or partial.
 *
 * A declarative Tiger_Form schema (elements only — the view owns all markup; the
 * base strips decorators to ViewHelper-only and adds CSRF). Validated by
 * Cms_Service_Page::save() before the write. Only `title` is hard-required here:
 * the service derives a page_key from the slug/title and applies type-specific
 * rules, so layouts/partials (no slug) and pages (no explicit key) both validate.
 *
 * Note: `body` is deliberately NOT StripTags-filtered — a body is template source
 * (HTML / Markdown / PHTML), so stripping tags would corrupt it.
 *
 * @api
 */
class Cms_Form_Page extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        return [
            ['hidden', 'page_id', []],

            ['text', 'title', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'cms-title', 'maxlength' => 255]),
            ]],

            ['text', 'slug', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'cms-slug', 'maxlength' => 191]),
            ]],

            ['text', 'page_key', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'cms-key', 'maxlength' => 191]),
            ]],

            ['select', 'type', [
                'multiOptions' => ['page' => 'Page', 'layout' => 'Layout', 'partial' => 'Partial'],
                'value'        => 'page',
                'attribs'      => array_merge($select, ['id' => 'cms-type']),
            ]],

            ['select', 'format', [
                'multiOptions' => ['html' => 'HTML', 'markdown' => 'Markdown', 'phtml' => 'PHTML (trusted code)', 'builder' => 'Visual Builder'],
                'value'        => 'html',
                'attribs'      => array_merge($select, ['id' => 'cms-format']),
            ]],

            ['select', 'status', [
                'multiOptions' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'],
                'value'        => 'draft',
                'attribs'      => array_merge($select, ['id' => 'cms-status']),
            ]],

            ['select', 'locale', [
                'multiOptions' => ['en' => 'English', 'es' => 'Español'],
                'value'        => 'en',
                'attribs'      => array_merge($select, ['id' => 'cms-locale']),
            ]],

            ['text', 'layout_key', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'cms-layout', 'maxlength' => 191]),
            ]],

            ['text', 'published_at', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'cms-published-at', 'placeholder' => 'YYYY-MM-DD HH:MM:SS']),
            ]],

            ['textarea', 'body', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'cms-body', 'rows' => 18, 'spellcheck' => 'false']),
            ]],

            // --- SEO & head (stored in the page `meta`; rendered into the public page) ---
            ['textarea', 'meta_description', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'cms-meta-description', 'rows' => 2, 'maxlength' => 320]),
            ]],
            // Raw <head> additions (link/meta/style/script src) + end-of-body scripts. Admin-authored
            // (trusted) — output verbatim. Emptied by default; a tenant-safe editor would sanitize.
            ['textarea', 'head_html', [
                'attribs' => array_merge($control, ['id' => 'cms-head-html', 'rows' => 4, 'spellcheck' => 'false',
                    'placeholder' => '<link rel="stylesheet" href="…">   <meta …>   <script src="…"></script>']),
            ]],
            ['textarea', 'body_scripts', [
                'attribs' => array_merge($control, ['id' => 'cms-body-scripts', 'rows' => 4, 'spellcheck' => 'false',
                    'placeholder' => '<script src="…"></script>   <script>…</script>']),
            ]],
        ];
    }
}
