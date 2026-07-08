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

    global.TigerDOM = { expand: expand, collapse: collapse, toggle: toggle };
})(window);
