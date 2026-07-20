/* SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * TigerSchedule — the client glue for the reusable schedule-control partial. Delegated (like
 * TigerButton/TigerDOM), so it works for the Scheduler screen AND for a schedule-control embedded in
 * any other admin screen (e.g. Backup) with zero per-page wiring. Markup carries the intent:
 *   [data-schedule-form data-job="<key>" data-feedback="<id>"? data-reload?]
 *     select[data-sch="every"], input[data-sch="at"], select[data-sch="dow"], input[data-sch="dom"],
 *     input[data-sch="enabled"], button[data-schedule-save]
 *   button[data-schedule-run="<key>"]  — run a job now
 */
(function (w, d) {
    'use strict';

    function post(method, params, btn) {
        var fd = new URLSearchParams(params || {});
        fd.set('module', 'schedule'); fd.set('service', 'schedule'); fd.set('method', method);
        var task = function () {
            return fetch('/api', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(function (r) { return r.json().catch(function () { return {}; }); });
        };
        return (btn && w.TigerButton) ? TigerButton.run(btn, task) : task();
    }

    function notify(id, res) {
        var fb = id ? d.getElementById(id) : null;
        if (!fb || !w.TigerDOM) { return; }
        (res && res.messages || []).forEach(function (m) { TigerDOM.notify(fb, m.message, { type: m.class }); });
    }

    // Show/hide time + day fields for the chosen frequency.
    function sync(form) {
        var every = (form.querySelector('[data-sch="every"]') || {}).value;
        var timed = every === 'daily' || every === 'weekly' || every === 'monthly';
        var el;
        if ((el = form.querySelector('[data-sch-time]'))) { el.classList.toggle('d-none', !timed); }
        if ((el = form.querySelector('[data-sch-dow]')))  { el.classList.toggle('d-none', every !== 'weekly'); }
        if ((el = form.querySelector('[data-sch-dom]')))  { el.classList.toggle('d-none', every !== 'monthly'); }
    }

    d.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-sch="every"]');
        if (sel) { var f = sel.closest('[data-schedule-form]'); if (f) { sync(f); } }
    });

    d.addEventListener('click', function (e) {
        var save = e.target.closest('[data-schedule-save]');
        if (save) {
            var form = save.closest('[data-schedule-form]');
            if (!form) { return; }
            var get = function (k) { var x = form.querySelector('[data-sch="' + k + '"]'); return x ? x.value : ''; };
            var params = {
                key: form.getAttribute('data-job'),
                every: get('every'), at: get('at'), dow: get('dow'), dom: get('dom'),
                enabled: (form.querySelector('[data-sch="enabled"]') || {}).checked ? 1 : 0
            };
            post('setSchedule', params, save).then(function (res) {
                notify(form.getAttribute('data-feedback'), res);
                if (res && res.result === 1 && form.hasAttribute('data-reload')) { setTimeout(function () { location.reload(); }, 600); }
            });
            return;
        }
        var run = e.target.closest('[data-schedule-run]');
        if (run) {
            post('runNow', { key: run.getAttribute('data-schedule-run') }, run).then(function (res) {
                notify(run.getAttribute('data-feedback'), res);
                if (res && res.result === 1 && run.hasAttribute('data-reload')) { setTimeout(function () { location.reload(); }, 800); }
            });
        }
    });

    w.TigerSchedule = { post: post };
})(window, document);
