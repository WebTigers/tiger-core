/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * tiger.address.js — address autocomplete on top of the Tiger Location Service.
 *
 * Put `data-tiger-address` on an address (street) input and, as the user types, it queries
 * /api (Tiger_Service_Location::suggest → the configured adapter) and drops a suggestion
 * list beneath the field. Picking one fills the street itself plus the mapped sibling
 * fields, then nudges them to re-validate. Zero cost with the default Nominatim adapter;
 * swap the provider in config and this UI is unchanged. Field mapping via data-attrs:
 *   <input data-tiger-address data-fill-city="su-city" data-fill-region="su-region"
 *          data-fill-postal="su-postal" data-fill-country="su-country"
 *          data-fill-lat="su-lat" data-fill-lng="su-lng">
 * The optional data-fill-lat / data-fill-lng target hidden inputs and capture the picked
 * result's cached geocode. They're CLEARED the moment the user hand-edits the street, since
 * a typed address no longer corresponds to the picked coordinates.
 */
(function (global) {
    'use strict';
    var API = '/api';

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]; }); }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function fire(el) { el.dispatchEvent(new Event('change', { bubbles: true })); el.dispatchEvent(new Event('focusout', { bubbles: true })); }

    function attach(input) {
        if (input.__tgAddr) { return; }
        input.__tgAddr = true;
        input.setAttribute('autocomplete', 'off');

        // Wrap the input so the menu can sit right beneath it.
        var wrap = document.createElement('div');
        wrap.className = 'position-relative';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var menu = document.createElement('div');
        menu.className = 'list-group shadow-sm';
        menu.style.cssText = 'position:absolute;top:100%;left:0;width:100%;z-index:1080;display:none;max-height:16rem;overflow:auto;';
        wrap.appendChild(menu);

        var results = [];

        function setField(id, val) {
            if (!id || val == null) { return; }
            var el = document.getElementById(id);
            if (!el) { return; }
            el.value = val;                       // for a <select>, only "sticks" if the option exists
            fire(el);
        }
        // Set a hidden geocode field by id, no validation nudge (hidden inputs need none).
        function setGeo(lat, lng) {
            var la = input.getAttribute('data-fill-lat'), lo = input.getAttribute('data-fill-lng');
            var el;
            if (la && (el = document.getElementById(la))) { el.value = (lat == null ? '' : lat); }
            if (lo && (el = document.getElementById(lo))) { el.value = (lng == null ? '' : lng); }
        }
        function pick(p) {
            if (p.line1) { input.value = p.line1; fire(input); }
            setField(input.getAttribute('data-fill-city'), p.city);
            setField(input.getAttribute('data-fill-region'), p.region);
            setField(input.getAttribute('data-fill-postal'), p.postal);
            setField(input.getAttribute('data-fill-country'), p.country);
            setGeo(p.latitude, p.longitude);   // cache the picked result's geocode
            hide();
        }
        function render() {
            if (!results.length) { hide(); return; }
            menu.innerHTML = results.map(function (p, i) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 small" data-i="' + i + '">' + esc(p.label || '') + '</button>';
            }).join('');
            menu.style.display = 'block';
        }
        function hide() { menu.style.display = 'none'; }

        var query = debounce(function () {
            var q = input.value.trim();
            if (q.length < 3) { results = []; hide(); return; }
            var body = new URLSearchParams();
            body.set('module', 'tiger'); body.set('service', 'location'); body.set('method', 'suggest'); body.set('q', q);
            // Bias suggestions to the chosen country (faster + more relevant).
            var ccId = input.getAttribute('data-fill-country');
            var ccEl = ccId && document.getElementById(ccId);
            if (ccEl && ccEl.value) { body.set('country', ccEl.value); }
            fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body.toString(), credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) { results = ((res && res.data) || {}).results || []; render(); })
                .catch(function () {});
        }, 300);

        // Real keystrokes only reach here (pick() fires change/focusout, never input), so a typed
        // edit invalidates the cached geocode.
        input.addEventListener('input', function () { setGeo('', ''); query(); });
        // mousedown (not click) so the pick fires before the input's blur hides the menu.
        menu.addEventListener('mousedown', function (e) {
            var b = e.target.closest('[data-i]');
            if (!b) { return; }
            e.preventDefault();
            var p = results[+b.getAttribute('data-i')];
            if (p) { pick(p); }
        });
        input.addEventListener('blur', function () { setTimeout(hide, 150); });
    }

    function scan(root) { (root || document).querySelectorAll('input[data-tiger-address]').forEach(attach); }

    // IP → pre-select an as-yet-unchosen <select data-tiger-ip-country> on load (best-effort).
    // Coarse-but-forgiving: country only, never city/state (the street pick fills those
    // precisely). Skips a select the user already touched, and only sets a country the list
    // actually offers.
    function prefillCountry() {
        var selects = document.querySelectorAll('select[data-tiger-ip-country]');
        if (!selects.length) { return; }
        var body = new URLSearchParams();
        body.set('module', 'tiger'); body.set('service', 'location'); body.set('method', 'ip');
        fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body.toString(), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var place = ((res && res.data) || {}).place;
                var cc = place && place.country;
                if (!cc) { return; }
                selects.forEach(function (sel) {
                    if (sel.value) { return; }   // never override a choice already made
                    if (Array.prototype.some.call(sel.options, function (o) { return o.value === cc; })) {
                        sel.value = cc;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            })
            .catch(function () {});
    }

    function init() { scan(); prefillCountry(); }
    if (document.readyState !== 'loading') { init(); }
    else { document.addEventListener('DOMContentLoaded', init); }

    global.TigerAddress = { scan: scan, prefillCountry: prefillCountry };
})(window);
