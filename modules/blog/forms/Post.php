<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_Form_Post — create/edit an article.
 *
 * Declarative Tiger_Form schema (elements only; the view owns markup, the base adds
 * CSRF + ViewHelper decorators). Validated by Blog_Service_Post::save() before the
 * write. Only `title` is hard-required — the service derives slug + page_key and packs
 * the meta fields (kicker/subtitle/preamble/excerpt/SEO) into page.meta.
 *
 * `body` is the article HTML (Editor.js output) — NOT StripTags-filtered, or the markup
 * would be corrupted. The short text fields are StringTrim only; escaping happens at
 * render time.
 */
class Blog_Form_Post extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        return [
            ['hidden', 'post_id', []],

            ['text', 'title', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'post-title', 'maxlength' => 255]),
            ]],

            ['text', 'slug', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-slug', 'maxlength' => 191]),
            ]],

            // Medium masthead fields (→ page.meta)
            ['text', 'kicker', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-kicker', 'maxlength' => 120]),
            ]],
            ['text', 'subtitle', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-subtitle', 'maxlength' => 255]),
            ]],
            ['textarea', 'preamble', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-preamble', 'rows' => 3]),
            ]],
            ['textarea', 'excerpt', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-excerpt', 'rows' => 2]),
            ]],

            ['textarea', 'body', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-body', 'rows' => 20]),
            ]],

            // media + author (→ page.meta)
            ['hidden', 'feature_media_id', ['attribs' => ['id' => 'post-feature']]],
            ['hidden', 'author_id',        ['attribs' => ['id' => 'post-author']]],

            // taxonomy — comma-separated names; the service resolves/creates terms
            ['text', 'categories', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-categories']),
            ]],
            ['text', 'tags', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-tags']),
            ]],

            ['select', 'status', [
                'multiOptions' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'],
                'value'        => 'draft',
                'attribs'      => array_merge($select, ['id' => 'post-status']),
            ]],
            ['select', 'locale', [
                'multiOptions' => ['en' => 'English', 'es' => 'Español'],
                'value'        => 'en',
                'attribs'      => array_merge($select, ['id' => 'post-locale']),
            ]],
            ['text', 'published_at', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-published-at', 'placeholder' => 'YYYY-MM-DD HH:MM:SS']),
            ]],
            ['checkbox', 'allow_comments', [
                'attribs' => ['id' => 'post-allow-comments'],
            ]],

            // SEO (→ page.meta.seo)
            ['text', 'seo_title', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-seo-title', 'maxlength' => 255]),
            ]],
            ['textarea', 'seo_description', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-seo-desc', 'rows' => 2]),
            ]],
            ['hidden', 'og_image_id', ['attribs' => ['id' => 'post-og-image']]],
            ['text', 'canonical', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'post-canonical']),
            ]],
        ];
    }
}
