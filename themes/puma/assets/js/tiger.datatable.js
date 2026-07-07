/**
 * tiger.datatable.js — the one wiring for every Tiger data grid (MIT, Tiger-original).
 *
 * Per the client/server paradigm, a data grid fetches its rows from an /api service
 * (server-side processing) — rows are never server-rendered. This helper hides the
 * /api plumbing: it POSTs {module, service, action} + the DataTables request params
 * to /api, then unwraps the Tiger response envelope ({result, data, messages}) into
 * the shape DataTables consumes ({draw, recordsTotal, recordsFiltered, data}).
 *
 * The service returns STRUCTURED data only (never HTML) plus per-row permission
 * flags; the caller's `columns[].render` functions build the cells and gate controls
 * off those flags — so authorization stays server-side.
 *
 * Requires jQuery + DataTables (loaded by the admin layout when a view sets
 * $this->useDataTables).
 *
 *   tigerDataTable('#my-table', {
 *     service: { module: 'cms', service: 'page', action: 'datatable' },
 *     order:   [[5, 'desc']],
 *     columns: [ { data: 'title', render: ... }, ... ],
 *     extraData: function () { return { status: $('#f').val() }; }   // optional filters
 *   });
 */
(function (window) {
    'use strict';

    function tigerDataTable(selector, opts) {
        var $ = window.jQuery;
        if (!$ || !$.fn || !$.fn.DataTable) { return null; }

        opts = opts || {};
        var svc   = opts.service || {};
        var extra = (typeof opts.extraData === 'function') ? opts.extraData : function () { return {}; };

        // Sensible defaults; caller's opts win. `service`/`extraData` are ours, not DT's.
        var config = $.extend({
            serverSide: true,
            processing: true,
            pageLength: 25,
            language: { search: '', searchPlaceholder: 'Search…' }
        }, opts);
        delete config.service;
        delete config.extraData;

        config.ajax = {
            url: '/api',
            type: 'POST',
            dataType: 'json',
            data: function (d) {
                // DataTables params + the message dispatch fields + caller filters.
                return $.extend({}, d, {
                    module:  svc.module,
                    service: svc.service,
                    action:  svc.action
                }, extra() || {});
            },
            // Unwrap the Tiger envelope { result, data:{…}, messages } -> DataTables shape.
            dataFilter: function (raw) {
                try {
                    var p = JSON.parse(raw);
                    if (p && p.result === 1 && p.data) {
                        return JSON.stringify(p.data);
                    }
                    return JSON.stringify({
                        draw: 0, recordsTotal: 0, recordsFiltered: 0, data: [],
                        error: (p && p.messages && p.messages[0]) ? p.messages[0].message : 'request failed'
                    });
                } catch (e) {
                    return JSON.stringify({ draw: 0, recordsTotal: 0, recordsFiltered: 0, data: [], error: 'invalid response' });
                }
            }
        };

        return $(selector).DataTable(config);
    }

    // Small shared escaper for column renderers (prevents XSS from row data).
    tigerDataTable.esc = function (s) {
        return window.jQuery('<div>').text(s == null ? '' : String(s)).html();
    };

    window.tigerDataTable = tigerDataTable;
})(window);
