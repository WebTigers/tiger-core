/*!
 * TigerLightbox v1.0.0 — a tiny, dependency-free lightbox & media viewer.
 * https://github.com/WebTigers/TigerLightbox · MIT License
 *
 * Zero dependencies, ~5KB. Handles images, video, PDFs/iframes, and embeds, with gallery
 * navigation, keyboard, touch-swipe, focus-trap, and CSS-variable theming.
 *
 * Attribute API (auto-wired, works with dynamically-added elements):
 *   <a href="big.jpg" data-tiger-lightbox="gallery" data-caption="A view">…</a>
 *   items sharing the same group value form one gallery.
 *   optional: data-src (overrides href), data-type (image|video|pdf|iframe), data-title.
 *
 * JS API:
 *   TigerLightbox.open([{type,src,caption,title}], startIndex)
 *   TigerLightbox.close()
 */
(function (global, document) {
    'use strict';

    var SVG = {
        close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        prev:  '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18 9 12l6-6"/></svg>',
        next:  '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>'
    };

    var S = { items: [], index: 0, root: null, prevFocus: null, touch: null };

    function elem(tag, cls) { var e = document.createElement(tag); if (cls) { e.className = cls; } return e; }

    function build() {
        if (S.root) { return S.root; }
        var root = elem('div', 'tlb');
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Media viewer');
        root.hidden = true;
        root.innerHTML =
            '<div class="tlb-backdrop" data-tlb="close"></div>' +
            '<div class="tlb-counter" data-tlb-counter></div>' +
            '<button class="tlb-btn tlb-close" data-tlb="close" type="button" aria-label="Close">' + SVG.close + '</button>' +
            '<button class="tlb-btn tlb-prev" data-tlb="prev" type="button" aria-label="Previous">' + SVG.prev + '</button>' +
            '<button class="tlb-btn tlb-next" data-tlb="next" type="button" aria-label="Next">' + SVG.next + '</button>' +
            '<figure class="tlb-stage"><div class="tlb-media" data-tlb-media></div>' +
            '<figcaption class="tlb-caption" data-tlb-caption></figcaption></figure>';
        document.body.appendChild(root);

        root.addEventListener('click', function (e) {
            var t = e.target.closest('[data-tlb]');
            if (!t) { return; }
            var a = t.getAttribute('data-tlb');
            if (a === 'close') { close(); } else if (a === 'prev') { go(-1); } else if (a === 'next') { go(1); }
        });
        root.addEventListener('touchstart', function (e) { S.touch = e.changedTouches[0]; }, { passive: true });
        root.addEventListener('touchend', function (e) { swipe(e.changedTouches[0]); }, { passive: true });

        S.root = root;
        return root;
    }

    function render() {
        var root = S.root, item = S.items[S.index] || {};
        var media = root.querySelector('[data-tlb-media]');
        var cap = root.querySelector('[data-tlb-caption]');
        var counter = root.querySelector('[data-tlb-counter]');
        var type = item.type || 'image';
        var node;

        media.innerHTML = '';
        media.className = 'tlb-media tlb-loading';

        if (type === 'video') {
            node = elem('video', 'tlb-el');
            node.src = item.src; node.controls = true; node.autoplay = true; node.playsInline = true;
            media.classList.remove('tlb-loading');
        } else if (type === 'iframe' || type === 'pdf') {
            node = elem('iframe', 'tlb-el tlb-frame');
            node.src = item.src; node.setAttribute('allowfullscreen', ''); node.setAttribute('title', item.caption || 'Document');
            node.onload = function () { media.classList.remove('tlb-loading'); };
        } else {
            node = elem('img', 'tlb-el');
            node.alt = item.caption || item.title || '';
            node.onload = function () { media.classList.remove('tlb-loading'); };
            node.onerror = function () { media.classList.remove('tlb-loading'); };
            node.src = item.src;
        }
        media.appendChild(node);

        cap.textContent = item.caption || item.title || '';
        cap.hidden = !cap.textContent;

        var multi = S.items.length > 1;
        root.querySelector('.tlb-prev').hidden = !multi;
        root.querySelector('.tlb-next').hidden = !multi;
        counter.hidden = !multi;
        counter.textContent = multi ? (S.index + 1) + ' / ' + S.items.length : '';
        preload();
    }

    function preload() {
        [S.index - 1, S.index + 1].forEach(function (i) {
            var it = S.items[(i + S.items.length) % S.items.length];
            if (it && (it.type || 'image') === 'image' && it.src) { var im = new Image(); im.src = it.src; }
        });
    }

    function go(delta) {
        if (S.items.length < 2) { return; }
        S.index = (S.index + delta + S.items.length) % S.items.length;
        render();
    }

    function swipe(end) {
        if (!S.touch || !end) { return; }
        var dx = end.clientX - S.touch.clientX, dy = end.clientY - S.touch.clientY;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { go(dx < 0 ? 1 : -1); }
        else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) { close(); }
        S.touch = null;
    }

    function onKey(e) {
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'ArrowLeft') { go(-1); }
        else if (e.key === 'ArrowRight') { go(1); }
        else if (e.key === 'Tab') {
            var f = S.root.querySelectorAll('.tlb-btn:not([hidden])');
            if (!f.length) { return; }
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
            else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
        }
    }

    function open(items, index) {
        if (!items || !items.length) { return; }
        build();
        S.items = items;
        S.index = Math.max(0, Math.min(index || 0, items.length - 1));
        S.prevFocus = document.activeElement;
        S.root.hidden = false;
        document.documentElement.classList.add('tlb-open');
        document.addEventListener('keydown', onKey);
        render();
        var c = S.root.querySelector('.tlb-close'); if (c) { c.focus(); }
        requestAnimationFrame(function () { S.root.classList.add('tlb-in'); });
    }

    function close() {
        if (!S.root || S.root.hidden) { return; }
        S.root.classList.remove('tlb-in');
        document.removeEventListener('keydown', onKey);
        document.documentElement.classList.remove('tlb-open');
        var root = S.root;
        setTimeout(function () { root.hidden = true; root.querySelector('[data-tlb-media]').innerHTML = ''; }, 200);
        if (S.prevFocus && S.prevFocus.focus) { S.prevFocus.focus(); }
    }

    function guessType(src) {
        var s = (src || '').split('?')[0].toLowerCase();
        if (/\.(mp4|webm|ogv|mov|m4v)$/.test(s)) { return 'video'; }
        if (/\.pdf$/.test(s)) { return 'pdf'; }
        if (/youtube\.com|youtu\.be|vimeo\.com/.test(s)) { return 'iframe'; }
        return 'image';
    }

    function nodeToItem(el) {
        var src = el.getAttribute('data-src') || el.getAttribute('href') || '';
        return {
            type: el.getAttribute('data-type') || guessType(src),
            src: src,
            caption: el.getAttribute('data-caption') || '',
            title: el.getAttribute('data-title') || ''
        };
    }

    // Auto-wire: a click on any [data-tiger-lightbox] opens its group as a gallery.
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-tiger-lightbox]');
        if (!t) { return; }
        e.preventDefault();
        var group = t.getAttribute('data-tiger-lightbox') || '';
        var nodes = group
            ? Array.prototype.slice.call(document.querySelectorAll('[data-tiger-lightbox="' + group.replace(/"/g, '\\"') + '"]'))
            : [t];
        open(nodes.map(nodeToItem), nodes.indexOf(t));
    });

    global.TigerLightbox = { open: open, close: close, version: '1.0.0' };
})(window, document);
