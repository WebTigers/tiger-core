/* SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * TigerSearch — the public ⌘K search launcher. Any element `[data-tiger-search]` containing a
 * `[data-search-input]` and a `[data-search-results]` panel becomes a live search box: it debounces
 * input, POSTs to the public /api (Search_Service_Search::query — which fans out across every
 * Tiger_Search provider), and renders grouped results with keyboard nav. Enter (or "see all") goes to
 * the /search results page. ⌘K / Ctrl-K focuses it from anywhere. Zero deps, progressive: with JS off
 * the input still submits its form to /search.
 */
(function (w, d) {
    'use strict';

    function debounce(fn, ms) {
        var t;
        return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
    }
    function esc(s) { var e = d.createElement('div'); e.textContent = (s == null ? '' : String(s)); return e.innerHTML; }

    function wire(box) {
        var input = box.querySelector('[data-search-input]');
        var panel = box.querySelector('[data-search-results]');
        if (!input || !panel) { return; }
        var sel = -1;

        function close() { panel.classList.remove('show'); sel = -1; }
        function go(url) { if (url) { w.location.href = url; } }
        function submit() { var q = input.value.trim(); if (q) { go('/search?q=' + encodeURIComponent(q)); } }
        function items() { return panel.querySelectorAll('.tiger-search-item'); }
        function highlight() {
            var links = items();
            links.forEach(function (l, i) { l.classList.toggle('active', i === sel); if (i === sel) { l.scrollIntoView({ block: 'nearest' }); } });
        }

        function render(res) {
            var groups = (res && res.data && res.data.groups) || [];
            var q = esc(input.value.trim());
            if (!groups.length) {
                panel.innerHTML = '<div class="px-3 py-2 text-body-secondary small">No results.</div>';
                panel.classList.add('show'); sel = -1; return;
            }
            var html = '';
            groups.forEach(function (g) {
                html += '<h6 class="dropdown-header"><i class="fa-solid ' + esc(g.icon) + ' me-2"></i>' + esc(g.label) + '</h6>';
                g.hits.forEach(function (h) {
                    html += '<a class="dropdown-item tiger-search-item py-2" href="' + esc(h.url) + '">'
                        + '<div class="fw-semibold text-truncate">' + esc(h.title) + '</div>'
                        + (h.snippet ? '<div class="small text-body-secondary text-truncate">' + esc(h.snippet) + '</div>' : '')
                        + '</a>';
                });
            });
            html += '<div class="dropdown-divider"></div>'
                + '<button type="button" class="dropdown-item small text-primary" data-search-all>See all results for &ldquo;' + q + '&rdquo;</button>';
            panel.innerHTML = html;
            panel.classList.add('show'); sel = -1;
        }

        var run = debounce(function () {
            var q = input.value.trim();
            if (!q) { close(); return; }
            var body = new URLSearchParams({ module: 'search', service: 'search', method: 'query', q: q, limit: '5' });
            fetch('/api', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
                .then(function (r) { return r.json(); }).then(render).catch(close);
        }, 200);

        input.addEventListener('input', run);
        input.addEventListener('focus', function () { if (input.value.trim() && panel.children.length) { panel.classList.add('show'); } });
        input.addEventListener('keydown', function (e) {
            var links = items();
            if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, links.length - 1); highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, -1); highlight(); }
            else if (e.key === 'Enter') { e.preventDefault(); if (sel >= 0 && links[sel]) { go(links[sel].getAttribute('href')); } else { submit(); } }
            else if (e.key === 'Escape') { close(); input.blur(); }
        });
        panel.addEventListener('click', function (e) { if (e.target.closest('[data-search-all]')) { e.preventDefault(); submit(); } });
        d.addEventListener('click', function (e) { if (!box.contains(e.target)) { close(); } });
    }

    d.addEventListener('DOMContentLoaded', function () {
        d.querySelectorAll('[data-tiger-search]').forEach(wire);
    });

    // ⌘K / Ctrl-K focuses the first search box.
    d.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
            var i = d.querySelector('[data-tiger-search] [data-search-input]');
            if (i) { e.preventDefault(); i.focus(); i.select(); }
        }
    });
})(window, document);
