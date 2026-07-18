// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
//
// tiger.admin-nav.js — per-user drag-to-reorder for the admin sidebar. Each parent list
// (the top-level nav + any submenu) is an independent SortableJS instance, so items sort
// WITHIN their parent and never move across parents. On drop, the new order of the group's
// data-keys is saved to the config table at user scope (System_Service_Nav::sort), where it
// overrides the default order on the next request. Press-and-hold anywhere on the item to
// drag; a normal click still follows the link.
(function () {
    'use strict';
    if (typeof Sortable === 'undefined') { return; }

    var lists = document.querySelectorAll('.tiger-nav[data-nav-group]');
    Array.prototype.forEach.call(lists, function (ul) {
        Sortable.create(ul, {
            // No shared group name → items can't be dragged out of this list (one parent only).
            // The whole <li> is the drag surface; native link-drag is disabled in the markup.
            draggable: 'li[data-key]',
            delay: 160,                 // press-hold ~160ms to start a drag; a quick click navigates
            delayOnTouchOnly: false,
            animation: 150,

            onEnd: function (evt) {
                if (evt.oldIndex === evt.newIndex) { return; }   // nothing moved

                var keys = [];
                Array.prototype.forEach.call(ul.children, function (li) {
                    if (li.tagName === 'LI' && li.getAttribute('data-key')) {
                        keys.push(li.getAttribute('data-key'));
                    }
                });

                var fd = new URLSearchParams();
                fd.set('module', 'system');
                fd.set('service', 'nav');
                fd.set('method', 'sort');
                fd.set('group', ul.getAttribute('data-nav-group'));
                fd.set('keys', JSON.stringify(keys));

                // Fire-and-forget — the reorder is already visible; no toast (the user just did it).
                fetch('/api', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                }).catch(function () {});
            }
        });
    });
})();
