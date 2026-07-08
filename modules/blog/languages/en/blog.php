<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog module — English strings. Semantic, owner-prefixed keys (blog.*). Loaded on top
 * of core/app strings by the translate cascade; API response messages resolve these
 * automatically in the caller's locale.
 */
return [
    // API responses
    'blog.post.saved'    => 'Article saved.',
    'blog.post.deleted'  => 'Article deleted.',
    'blog.post.restored' => 'Article restored to the selected version.',
    'blog.error.slug'          => 'This article needs a title or slug.',
    'blog.error.slug_reserved' => 'That slug is reserved (post, category, tag, feed). Pick another.',

    // editor labels
    'blog.editor.kicker'       => 'Kicker',
    'blog.editor.title'        => 'Title',
    'blog.editor.subtitle'     => 'Subtitle',
    'blog.editor.preamble'     => 'Preamble',
    'blog.editor.body'         => 'Article',
    'blog.editor.excerpt'      => 'Excerpt',
    'blog.editor.feature'      => 'Feature image',
    'blog.editor.author'       => 'Author',
    'blog.editor.categories'   => 'Categories',
    'blog.editor.tags'         => 'Tags',
    'blog.editor.status'       => 'Status',
    'blog.editor.publish_at'   => 'Publish date',
    'blog.editor.seo'          => 'SEO & social',
    'blog.editor.seo_title'    => 'Meta title',
    'blog.editor.seo_desc'     => 'Meta description',
    'blog.editor.canonical'    => 'Canonical URL',
    'blog.editor.comments'     => 'Allow comments',

    // placeholders
    'blog.ph.kicker'   => 'Kicker — a short label above the title',
    'blog.ph.title'    => 'Title',
    'blog.ph.subtitle' => 'Add a subtitle…',
    'blog.ph.preamble' => 'A larger-font opening that draws the reader in…',
    'blog.ph.body'     => 'Tell your story…',

    // list
    'blog.list.title'    => 'Articles',
    'blog.list.new'      => 'New article',
    'blog.list.empty'    => 'No articles yet — write your first.',
];
