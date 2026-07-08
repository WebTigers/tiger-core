/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * tiger.dom.js — TigerDOM, reborn vanilla (no jQuery).
 *
 * The elegant reveal from the original jQuery TigerDOM, rebuilt on the Web Animations API:
 *   open  → expand the container 0→content-height, THEN fade the content in
 *   close → fade the content out, THEN collapse the container →0
 * Promise-based (each phase awaits the last), interruptible (a re-toggle cancels the
 * in-flight animation and a generation guard stops the stale chain from stomping styles —
 * the old lib's queue-jam bug), and it honors prefers-reduced-motion. Flash-free: the inline
 * "base" style is set to each phase's END value so a non-filling animation resolves cleanly.
 *
 * API:  TigerDOM.expand(el, opts) · TigerDOM.collapse(el, opts) · TigerDOM.toggle(el, opts)
 *       opts = { expand: ms, fade: ms, easing: string }   (defaults tuned for menus)
 */
(function (global) {
    'use strict';

    var REDUCE = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var DEFAULTS = { expand: 240, fade: 140, easing: 'cubic-bezier(.4, 0, .2, 1)' };

    function merge(o) { var r = {}, k; for (k in DEFAULTS) { r[k] = DEFAULTS[k]; } for (k in (o || {})) { r[k] = o[k]; } return r; }
    function cancel(el) { if (el.__tgAnim) { try { el.__tgAnim.cancel(); } catch (e) {} el.__tgAnim = null; } }
    function run(el, frames, ms, easing) { var a = el.animate(frames, { duration: ms, easing: easing }); el.__tgAnim = a; return a.finished; }

    function expand(el, opts) {
        opts = merge(opts);
        var gen = (el.__tgGen = (el.__tgGen || 0) + 1);
        cancel(el);
        el.__tgOpen = true;
        el.style.display = '';

        if (REDUCE) { el.style.height = ''; el.style.overflow = ''; el.style.opacity = ''; return Promise.resolve(); }

        el.style.height = '';                       // auto, so we measure true content height
        var target = el.scrollHeight;
        el.style.overflow = 'hidden';
        el.style.opacity = '0';                     // content stays hidden while the container opens
        el.style.height = target + 'px';            // base = phase-1 END (no flash on settle)

        return run(el, [{ height: '0px' }, { height: target + 'px' }], opts.expand, opts.easing)
            .then(function () {
                if (el.__tgGen !== gen) { return; }
                el.style.height = '';               // settle to auto (responsive to content changes)
                el.style.overflow = '';
                el.style.opacity = '1';             // base = phase-2 END
                return run(el, [{ opacity: 0 }, { opacity: 1 }], opts.fade, opts.easing);
            })
            .then(function () { if (el.__tgGen === gen) { el.style.opacity = ''; el.__tgAnim = null; } })
            .catch(function () {});                 // cancelled by a re-toggle — the new call owns state
    }

    function collapse(el, opts) {
        opts = merge(opts);
        var gen = (el.__tgGen = (el.__tgGen || 0) + 1);
        cancel(el);
        el.__tgOpen = false;

        if (REDUCE) { el.style.display = 'none'; el.style.height = ''; el.style.overflow = ''; el.style.opacity = ''; return Promise.resolve(); }

        el.style.overflow = 'hidden';
        el.style.opacity = '0';                     // base = phase-1 END
        return run(el, [{ opacity: 1 }, { opacity: 0 }], opts.fade, opts.easing)
            .then(function () {
                if (el.__tgGen !== gen) { return; }
                var start = el.scrollHeight;
                el.style.height = '0px';            // base = phase-2 END
                return run(el, [{ height: start + 'px' }, { height: '0px' }], opts.expand, opts.easing);
            })
            .then(function () {
                if (el.__tgGen !== gen) { return; }
                el.style.display = 'none';
                el.style.height = ''; el.style.overflow = ''; el.style.opacity = '';
                el.__tgAnim = null;
            })
            .catch(function () {});
    }

    function toggle(el, opts) { return el.__tgOpen ? collapse(el, opts) : expand(el, opts); }

    // --- content: elegant insert + dismiss -----------------------------------
    //
    // Never slam content onto the page: insert() reveals it (expand then fade, via the
    // primitives above) and wires dismissal; dismiss() reverses it and removes the node.
    // notify() is the streamlined "message envelope" — it builds a themed alert and applies
    // sensible defaults so callers stop hand-rolling alertHtml + innerHTML everywhere.

    function el(sel) { return typeof sel === 'string' ? document.querySelector(sel) : sel; }

    /**
     * Reveal-insert `content` (HTML string or Node) into `container`. Returns the inserted
     * wrapper element (pass it to dismiss(), or let the wired auto/click dismissal handle it).
     * opts: { prepend, replace, dismissAfter(ms, 0=never), dismissOnClick, onDismiss }
     */
    function insert(container, content, opts) {
        opts = opts || {};
        container = el(container);
        if (!container) { return null; }
        if (opts.replace) { container.innerHTML = ''; }

        var node = document.createElement('div');
        if (typeof content === 'string') { node.innerHTML = content; } else { node.appendChild(content); }
        // Start collapsed so the reveal doesn't flash the full height first.
        node.style.height = '0px';
        node.style.overflow = 'hidden';
        node.style.opacity = '0';
        if (opts.prepend) { container.insertBefore(node, container.firstChild); } else { container.appendChild(node); }

        wireDismiss(node, opts);
        expand(node);
        return node;
    }

    /** Fade-out → collapse → remove. Idempotent. Resolves when the node is gone. */
    function dismiss(node, opts) {
        opts = opts || {};
        node = el(node);
        if (!node || node.__tgDismissing) { return Promise.resolve(); }
        node.__tgDismissing = true;
        return collapse(node).then(function () {
            if (node.parentNode) { node.parentNode.removeChild(node); }
            if (typeof opts.onDismiss === 'function') { opts.onDismiss(); }
        });
    }

    function wireDismiss(node, opts) {
        var timer = null;
        var after = (opts.dismissAfter != null) ? opts.dismissAfter : 0;
        var go = function () { clearTimeout(timer); dismiss(node, { onDismiss: opts.onDismiss }); };

        var closers = node.querySelectorAll('[data-tg-dismiss], .btn-close');
        for (var i = 0; i < closers.length; i++) {
            closers[i].addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); go(); });
        }
        if (opts.dismissOnClick) { node.style.cursor = 'pointer'; node.addEventListener('click', go); }

        if (after > 0) {
            var start = function () { timer = setTimeout(go, after); };
            node.addEventListener('mouseenter', function () { clearTimeout(timer); });   // pause while reading
            node.addEventListener('mouseleave', start);
            start();
        }
    }

    var ALERT_CTX = { error: 'danger', danger: 'danger', success: 'success', info: 'info', warning: 'warning', alert: 'warning' };
    var ALERT_ICON = { success: 'fa-circle-check', danger: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };

    /**
     * The streamlined message envelope: build a themed alert (icon + message + close) and
     * reveal-insert it. Defaults: success/info/warning auto-dismiss in 5s; errors STICK
     * (dismissAfter 0) so they're read; pause-on-hover; one message per container (replace).
     * opts: { type, dismissAfter, dismissOnClick, prepend, replace, icon(false|faClass), onDismiss }
     */
    function notify(container, message, opts) {
        opts = opts || {};
        var ctx = ALERT_CTX[opts.type || 'info'] || 'info';
        var node = document.createElement('div');
        node.className = 'alert alert-' + ctx + ' d-flex align-items-start gap-2 mb-2';
        node.setAttribute('role', 'alert');
        var ic = (opts.icon === false) ? '' :
            '<i class="fa-solid ' + (typeof opts.icon === 'string' ? opts.icon : (ALERT_ICON[ctx] || 'fa-circle-info')) + ' mt-1"></i>';
        node.innerHTML = ic + '<div class="flex-grow-1">' + message + '</div>' +
            '<button type="button" class="btn-close" data-tg-dismiss aria-label="Close"></button>';

        return insert(container, node, {
            prepend: opts.prepend,
            replace: (opts.replace !== false),
            dismissAfter: (opts.dismissAfter != null) ? opts.dismissAfter : (ctx === 'danger' ? 0 : 5000),
            dismissOnClick: !!opts.dismissOnClick,
            onDismiss: opts.onDismiss
        });
    }

    /** notify() into an auto-created fixed region (stacked, top-right). */
    function toast(message, opts) {
        var region = document.getElementById('tg-toasts');
        if (!region) {
            region = document.createElement('div');
            region.id = 'tg-toasts';
            region.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:1090;width:min(92vw,24rem);';
            document.body.appendChild(region);
        }
        opts = opts || {};
        opts.replace = false;   // toasts stack
        return notify(region, message, opts);
    }

    global.TigerDOM = {
        expand: expand, collapse: collapse, toggle: toggle,
        insert: insert, dismiss: dismiss, notify: notify, toast: toast
    };
})(window);
