/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * PUMA no-FOUC theme resolver (MIT, Tiger-original).
 *
 * Loaded in <head> BEFORE the stylesheets so the first paint is correct. Explicit
 * light/dark are already set on <html data-bs-theme> server-side; this only needs
 * to resolve the 'browser' preference to a concrete mode via the OS setting.
 *
 * Vanilla, dependency-free, and intentionally tiny.
 */
(function () {
    try {
        var m    = document.cookie.match(/(?:^|;\s*)tiger_theme=([^;]+)/);
        var pref = m ? decodeURIComponent(m[1]) : 'browser';
        if (pref !== 'light' && pref !== 'dark') {
            pref = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-bs-theme', pref);

        // Restore the collapsed-sidebar preference here too (before paint, no jump).
        if (localStorage.getItem('tiger_sidebar') === '1') {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    } catch (e) { /* cookies/storage blocked — fall back to server-rendered mode */ }
})();
