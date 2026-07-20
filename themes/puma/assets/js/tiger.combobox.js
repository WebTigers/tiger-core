/* SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * TigerCombo — turn any <select> into a searchable, keyboard-navigable combobox, zero deps.
 *
 * House style (like TigerButton / TigerDOM): progressive enhancement over a real <select>. Put
 * `data-tiger-combo` on the select; the original stays in the DOM (hidden) and remains the form's
 * source of truth, so submit/reset/validation are unchanged — the widget only writes back to it.
 * Matching is a plain case-insensitive substring over each option's TEXT, so a rich label like
 * "America/New_York (EST, UTC-05:00)" is findable by the city, the abbreviation, or the offset.
 *
 * Auto-scans on DOMContentLoaded; call TigerCombo.scan(root) after injecting markup dynamically.
 * Theme-aware for free — it's built from Bootstrap classes (.form-control, .dropdown-menu), so
 * light/dark follow data-bs-theme like everything else.
 */
(function (window, document) {
    'use strict';

    var MAX_VISIBLE = 60;   // cap rendered rows; a filtered list rarely needs more, keeps open snappy

    function build(select) {
        if (select.dataset.tigerCombo === 'on') { return; }   // already enhanced
        select.dataset.tigerCombo = 'on';

        // Collect options up front (value, label, disabled). Skip nothing — the placeholder ("—")
        // stays selectable so a user can clear the field.
        var opts = Array.prototype.map.call(select.options, function (o) {
            return { value: o.value, label: o.textContent.trim(), search: o.textContent.trim().toLowerCase() };
        });

        var wrap = document.createElement('div');
        wrap.className = 'tiger-combo position-relative';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = select.className.replace('form-select', 'form-control');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        input.autocomplete = 'off';
        if (select.id) { input.id = select.id + '-combo'; }
        if (select.disabled) { input.disabled = true; }
        var ph = select.getAttribute('data-placeholder') || 'Type to search…';
        input.placeholder = ph;

        var menu = document.createElement('div');
        // Keep .dropdown-menu for the theme-aware surface (light/dark), but pin position explicitly —
        // Bootstrap's default placement needs Popper (data-bs-popper); we have no Popper here.
        menu.className = 'dropdown-menu tiger-combo-menu shadow-sm';
        menu.style.cssText = 'position:absolute;top:100%;left:0;width:100%;z-index:1080;max-height:18rem;overflow-y:auto;';

        // Insert the widget right after the (now hidden) select.
        select.style.display = 'none';
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(input);
        wrap.appendChild(menu);

        var open = false, active = -1, filtered = [];

        function labelFor(val) {
            for (var i = 0; i < opts.length; i++) { if (opts[i].value === val) { return opts[i].label; } }
            return '';
        }
        function syncInputFromSelect() {
            var val = select.value;
            var lbl = labelFor(val);
            // Show blank (placeholder) for an empty/placeholder value so the field reads as "unset".
            input.value = (val === '' ) ? '' : lbl;
            input.dataset.value = val;
        }

        function render(q) {
            var query = (q || '').trim().toLowerCase();
            filtered = opts.filter(function (o) {
                if (o.value === '') { return query === ''; }   // hide the "—" placeholder while searching
                return query === '' || o.search.indexOf(query) !== -1;
            });
            var shown = filtered.slice(0, MAX_VISIBLE);
            menu.innerHTML = '';
            if (!shown.length) {
                var none = document.createElement('span');
                none.className = 'dropdown-item-text text-body-secondary small';
                none.textContent = 'No matches';
                menu.appendChild(none);
                return;
            }
            shown.forEach(function (o, i) {
                var a = document.createElement('button');
                a.type = 'button';
                a.className = 'dropdown-item text-truncate' + (o.value === select.value ? ' active' : '');
                a.textContent = o.label || '—';
                a.dataset.value = o.value;
                a.dataset.i = i;
                menu.appendChild(a);
            });
            if (filtered.length > shown.length) {
                var more = document.createElement('span');
                more.className = 'dropdown-item-text text-body-secondary small';
                more.textContent = 'Showing ' + shown.length + ' of ' + filtered.length + ' — keep typing to narrow';
                menu.appendChild(more);
            }
            active = -1;
        }

        function show() {
            if (open || input.disabled) { return; }
            open = true;
            menu.classList.add('show');
            input.setAttribute('aria-expanded', 'true');
            render('');
        }
        function hide() {
            if (!open) { return; }
            open = false;
            menu.classList.remove('show');
            input.setAttribute('aria-expanded', 'false');
            syncInputFromSelect();   // snap the text back to the committed choice
        }
        function commit(val) {
            select.value = val;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncInputFromSelect();
            hide();
        }
        function highlight(next) {
            var items = menu.querySelectorAll('.dropdown-item');
            if (!items.length) { return; }
            active = (active + next + items.length) % items.length;
            items.forEach(function (el, i) { el.classList.toggle('active', i === active); });
            items[active].scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('focus', show);
        input.addEventListener('input', function () { if (!open) { show(); } render(input.value); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) { show(); } else { highlight(1); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(-1); }
            else if (e.key === 'Enter') {
                var items = menu.querySelectorAll('.dropdown-item');
                if (open && active >= 0 && items[active]) { e.preventDefault(); commit(items[active].dataset.value); }
                else if (open && items.length === 1) { e.preventDefault(); commit(items[0].dataset.value); }
            }
            else if (e.key === 'Escape') { if (open) { e.preventDefault(); hide(); } }
        });
        // mousedown (not click) so the pick fires before the input's blur closes the menu.
        menu.addEventListener('mousedown', function (e) {
            var b = e.target.closest('.dropdown-item');
            if (!b) { return; }
            e.preventDefault();
            commit(b.dataset.value);
        });
        input.addEventListener('blur', function () { setTimeout(hide, 120); });

        // Keep the widget honest if code changes the select programmatically.
        select.addEventListener('change', function () { if (!open) { syncInputFromSelect(); } });

        syncInputFromSelect();
    }

    function scan(root) {
        (root || document).querySelectorAll('select[data-tiger-combo]').forEach(build);
    }

    document.addEventListener('DOMContentLoaded', function () { scan(document); });

    window.TigerCombo = { scan: scan, enhance: build };
})(window, document);
