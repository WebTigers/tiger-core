/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * PUMA top-bar preferences + sidebar (MIT, Tiger-original).
 *
 * Vanilla JS — NO jQuery, NO plugin bundle. The markup supplies only data-
 * attributes; this wires the behavior:
 *
 *   .tiger-lang-switch[data-lang]    -> set `locale` cookie, persist, reload in-locale
 *   .tiger-theme-switch[data-theme]  -> set `tiger_theme` cookie, flip data-bs-theme, persist
 *   [data-tiger-toggle="sidebar"]    -> collapse/expand (desktop) or open/close (mobile)
 *   [data-tiger-toggle="aside"]      -> close the right aside on mobile
 *
 * Preferences persist best-effort through the Tiger /api gateway
 * (core/user/setprefs). The cookie is the source of truth — persistence is a
 * convenience so the choice follows the user across devices once that service
 * exists. Failure is silent by design (the cookie already did the job).
 */
(function () {
    'use strict';

    var YEAR = 365 * 864e5;
    var ICON = { browser: 'fa-solid fa-display', light: 'fa-solid fa-sun', dark: 'fa-solid fa-moon' };
    var root = document.documentElement;

    function setCookie(name, value) {
        document.cookie = name + '=' + encodeURIComponent(value) +
            '; expires=' + new Date(Date.now() + YEAR).toUTCString() + '; path=/; samesite=lax';
    }

    function persist(params) {
        try {
            fetch('/api', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ module: 'core', service: 'user', method: 'setprefs' }, params)),
                keepalive: true
            });
        } catch (e) { /* best-effort */ }
    }

    function resolve(pref) {
        if (pref === 'dark')  { return 'dark'; }
        if (pref === 'light') { return 'light'; }
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }

    function applyTheme(pref) {
        root.setAttribute('data-bs-theme', resolve(pref));
        var icon = document.getElementById('tiger-theme-icon');
        if (icon) { icon.className = ICON[pref] || ICON.browser; }
        var items = document.querySelectorAll('.tiger-theme-switch');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('active', items[i].getAttribute('data-theme') === pref);
        }
    }

    document.addEventListener('click', function (e) {
        var lang = e.target.closest('.tiger-lang-switch');
        if (lang) {
            e.preventDefault();
            var l = lang.getAttribute('data-lang');
            setCookie('locale', l);
            try { localStorage.setItem('tiger_locale', l); } catch (e2) {}
            persist({ lang: l });
            window.location.reload();   // server re-renders the page in the new locale
            return;
        }

        var theme = e.target.closest('.tiger-theme-switch');
        if (theme) {
            e.preventDefault();
            var t = theme.getAttribute('data-theme');
            setCookie('tiger_theme', t);
            applyTheme(t);
            persist({ mode: t });
            return;
        }

        var skin = e.target.closest('.tiger-skin-switch');
        if (skin) {
            e.preventDefault();
            var s = skin.getAttribute('data-skin');
            setCookie('tiger_skin', s);
            // Hot-swap the skin stylesheet in place — no reload. Keep the same path,
            // just change the /skins/<name>.css filename.
            var link = document.getElementById('tiger-skin');
            if (link) { link.setAttribute('href', link.getAttribute('href').replace(/skins\/[^\/?]+\.css/, 'skins/' + s + '.css')); }
            var label = document.getElementById('tiger-skin-label');
            if (label) { label.textContent = skin.getAttribute('data-label') || s; }
            var items = document.querySelectorAll('.tiger-skin-switch');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('active', items[i].getAttribute('data-skin') === s);
            }
            persist({ skin: s });
            return;
        }

        var sidebar = e.target.closest('[data-tiger-toggle="sidebar"]');
        if (sidebar) {
            e.preventDefault();
            if (window.matchMedia('(max-width: 768px)').matches) {
                root.classList.toggle('sidebar-open');       // off-canvas
            } else {
                root.classList.toggle('sidebar-collapsed');  // icons-only rail
                try { localStorage.setItem('tiger_sidebar', root.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e2) {}
            }
            return;
        }

        var aside = e.target.closest('[data-tiger-toggle="aside"]');
        if (aside) { e.preventDefault(); root.classList.toggle('aside-open'); return; }

        // Sidebar submenu (e.g. Settings): a parent toggles its children open/closed and
        // never navigates. Height is animated by TigerDOM; the .open class drives the caret
        // (and the initial server-rendered open state).
        var submenu = e.target.closest('[data-tiger-toggle="submenu"]');
        if (submenu) {
            e.preventDefault();
            var li = submenu.closest('.has-children');
            var ul = li && li.querySelector(':scope > .tiger-nav-children');
            if (!li) { return; }
            if (li.classList.contains('open')) {
                // Keep .open (caret + visibility) through the close animation, then drop it.
                if (ul && window.TigerDOM) {
                    TigerDOM.collapse(ul).then(function () { li.classList.remove('open'); });
                } else { li.classList.remove('open'); }
            } else {
                li.classList.add('open');
                if (ul && window.TigerDOM) { TigerDOM.expand(ul); }
            }
        }
    });

    // Keep localStorage in step with the server-resolved `locale` cookie — e.g. after
    // a /xx/ URL visit set the cookie server-side. The cookie is the source of truth.
    try {
        var lm = document.cookie.match(/(?:^|;\s*)locale=([^;]+)/);
        if (lm) { localStorage.setItem('tiger_locale', decodeURIComponent(lm[1])); }
    } catch (e4) {}

    // Keep 'browser' mode following the OS while the page is open.
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            var m = document.cookie.match(/(?:^|;\s*)tiger_theme=([^;]+)/);
            var pref = m ? decodeURIComponent(m[1]) : 'browser';
            if (pref === 'browser') { applyTheme('browser'); }
        });
    }
})();
