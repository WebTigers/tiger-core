/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * tiger.button.js — busy state for AJAX buttons (TigerButton).
 *
 * Wrap any async action and the button disables, its icon swaps to an animated "working"
 * icon, and — held for a brief minimum so it's actually perceptible even when the call is
 * instant — re-enables and restores the icon when done. Failure-safe (the button always
 * comes back) and accessible (aria-busy).
 *
 *   TigerButton.run(btn, function () { return fetch('/api', {...}).then(r => r.json()); })
 *       .then(function (res) { ... })          // fires exactly when the button un-spins
 *       .catch(function () { ... });
 *
 * Markup: nothing required. The icon's current classes are captured as the default on
 * first use; the working icon defaults to a spinner. Override the working icon per button:
 *   <i class="fa-solid fa-floppy-disk me-2" data-ajax="fa-solid fa-cloud-arrow-up"></i>
 * Non-FontAwesome classes (spacing like me-2) are preserved across the swap.
 *
 * Convention: buttons are always <button type="button"> (Tiger never page-POSTs a form),
 * so a busy button never accidentally submits.
 */
(function (global) {
    'use strict';

    var MIN_MS = 400;                                   // minimum visible "working" time
    var SPINNER = 'fa-solid fa-spinner fa-spin';        // default working icon

    function iconEl(btn) { return btn.querySelector('i, svg.svg-inline--fa, .fa'); }
    function now() { return (global.performance && performance.now) ? performance.now() : Date.now(); }

    // Swap the FontAwesome tokens in `current` for `replacement`, preserving everything
    // else (margins, sizing) so the icon doesn't jump.
    function faSwap(current, replacement) {
        var kept = current.split(/\s+/).filter(function (c) {
            return c && c.indexOf('fa-') !== 0 && !/^fa[srbldt]?$/.test(c);
        });
        return kept.concat(replacement.split(/\s+/)).join(' ').trim();
    }

    function busy(btn) {
        if (!btn || btn.__tgBusy) { return; }
        btn.__tgBusy = true;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        var ic = iconEl(btn);
        if (ic) {
            // Button has an icon — swap it to the working icon (default: spinner).
            if (ic.dataset.default == null) { ic.dataset.default = ic.className; }
            ic.className = faSwap(ic.dataset.default, ic.dataset.ajax || SPINNER);
        } else {
            // Text-only button — inject a temporary spinner so there's still visible feedback.
            var spin = document.createElement('i');
            spin.className = SPINNER + (btn.textContent.trim() ? ' me-2' : '');
            spin.setAttribute('data-tg-injected', '1');
            btn.insertBefore(spin, btn.firstChild);
        }
    }

    function done(btn) {
        if (!btn || !btn.__tgBusy) { return; }
        btn.__tgBusy = false;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        var injected = btn.querySelector('[data-tg-injected]');
        if (injected) { injected.remove(); return; }   // remove our injected spinner first
        var ic = iconEl(btn);
        if (ic && ic.dataset.default != null) { ic.className = ic.dataset.default; }
    }

    /**
     * Run an async task in the button's busy state. Resolves/rejects with the task's own
     * result/error, but only AFTER the minimum visible time has elapsed and the button has
     * been restored — so a success toast and the icon reset land together.
     */
    function run(btn, task, opts) {
        var min = (opts && opts.min != null) ? opts.min : MIN_MS;
        busy(btn);
        var t0 = now();
        var settle = function () {
            var wait = Math.max(0, min - (now() - t0));
            return new Promise(function (res) { setTimeout(res, wait); }).then(function () { done(btn); });
        };
        return Promise.resolve().then(function () { return typeof task === 'function' ? task() : task; })
            .then(
                function (val) { return settle().then(function () { return val; }); },
                function (err) { return settle().then(function () { throw err; }); }
            );
    }

    global.TigerButton = { busy: busy, done: done, run: run };
})(window);
