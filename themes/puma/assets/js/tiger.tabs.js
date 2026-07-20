/**
 * tiger.tabs.js — URL-hash deep-linking for Bootstrap tabs/pills, app-wide.
 *
 * Zero-config: drop it in the layout and every Bootstrap tab set on every page gains deep-linking,
 * keyed on real element IDs (no per-page wiring, no special data-attributes). Two behaviours:
 *
 *   1. #<paneId>    -> activates that tab pane.
 *   2. #<anchorId>  -> if the element lives INSIDE a tab pane, activates the containing tab and
 *                      THEN scrolls to the element (the two-step reveal: show tab -> scroll to anchor).
 *
 * It also reflects the active tab back into the URL hash when the user switches tabs (via
 * history.replaceState, so the Back button leaves the page rather than cycling through tabs), and
 * it re-runs on `hashchange` so ordinary in-page links to a #tab or a #deep-anchor Just Work.
 *
 * House-style vanilla helper (zero deps beyond bootstrap.bundle). No build step.
 */
(function () {
    'use strict';

    var TOGGLE = '[data-bs-toggle="tab"],[data-bs-toggle="pill"]';
    var suppress = false;   // true while WE drive a tab programmatically — don't reflect that back out

    /** The tab trigger (button/link) that controls a given pane, matched by its target/href. */
    function triggerFor(pane) {
        if (!pane || !pane.id) { return null; }
        var target = '#' + pane.id;
        var list = document.querySelectorAll(TOGGLE);
        for (var i = 0; i < list.length; i++) {
            if (list[i].getAttribute('data-bs-target') === target || list[i].getAttribute('href') === target) {
                return list[i];
            }
        }
        return null;
    }

    /** Resolve the current URL hash to a tab (and maybe an inner anchor) and reveal it. */
    function reveal() {
        if (!window.bootstrap) { return; }
        var id = (location.hash || '').replace(/^#/, '');
        if (!id) { return; }
        var el = document.getElementById(id);
        if (!el) { return; }

        var pane = el.classList.contains('tab-pane') ? el : el.closest('.tab-pane');
        if (!pane) { return; }                 // a normal anchor outside any tab — let the browser handle it
        var trigger = triggerFor(pane);
        if (!trigger) { return; }

        var anchor  = (el !== pane) ? el : null;   // hash pointed at something INSIDE the pane
        var scroll  = function () { if (anchor) { anchor.scrollIntoView({ behavior: 'smooth', block: 'start' }); } };

        if (pane.classList.contains('active')) { scroll(); return; }   // tab already open — just scroll

        suppress = true;
        trigger.addEventListener('shown.bs.tab', function once() {
            trigger.removeEventListener('shown.bs.tab', once);
            suppress = false;
            scroll();                          // step 2: scroll only once the pane is actually visible
        });
        bootstrap.Tab.getOrCreateInstance(trigger).show();   // step 1: activate the tab
    }

    /** Mirror the active tab out to the URL hash on a user switch (replaceState = no history spam). */
    function wireReflect() {
        var list = document.querySelectorAll(TOGGLE);
        for (var i = 0; i < list.length; i++) {
            list[i].addEventListener('shown.bs.tab', function (e) {
                if (suppress) { return; }      // this switch was our own deep-link reveal — leave the hash as-is
                var id = (e.target.getAttribute('data-bs-target') || e.target.getAttribute('href') || '').replace(/^#/, '');
                if (id) { history.replaceState(null, '', '#' + id); }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireReflect();
        reveal();
    });
    window.addEventListener('hashchange', reveal);   // in-page links to a #tab or a #deep-anchor
})();
