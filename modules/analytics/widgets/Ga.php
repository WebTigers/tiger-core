<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Analytics_Widget_Ga — the admin-dashboard "Traffic" widget. Renders a shell (top-line numbers + a
 * sparkline canvas) that fetches its data over /api (Analytics_Service_Reports) and draws it with the
 * vendored Chart.js — so the dashboard never blocks on a (possibly cold) GA API call. Shows a connect
 * prompt when Google Analytics isn't wired up yet.
 *
 * @api
 */
class Analytics_Widget_Ga
{
    /**
     * Render the widget card body.
     *
     * @return string HTML
     */
    public function render(): string
    {
        if (!class_exists('Tiger_Google_Analytics') || !Tiger_Google_Analytics::isConnected()) {
            return '<div class="text-center text-body-secondary py-3">'
                 . '<i class="fa-solid fa-chart-line fs-3 mb-2 d-block opacity-50"></i>'
                 . '<p class="small mb-2">Connect Google Analytics to see traffic.</p>'
                 . '<a href="/analytics/admin" class="btn btn-sm btn-outline-primary">Set up</a></div>';
        }

        $id = 'gaw-' . substr(md5(uniqid('', true)), 0, 8);
        ob_start(); ?>
<div id="<?= $id ?>-body">
    <div class="d-flex justify-content-between align-items-end mb-2">
        <div><div class="display-6 fw-bold lh-1" id="<?= $id ?>-users"><span class="placeholder col-6"></span></div>
             <div class="small text-body-secondary">active users &middot; 28d</div></div>
        <div class="text-end"><div class="fw-semibold" id="<?= $id ?>-views">&nbsp;</div>
             <div class="small text-body-secondary">page views</div></div>
    </div>
    <div style="height:56px;"><canvas id="<?= $id ?>-chart"></canvas></div>
    <div class="mt-2"><a href="/analytics/admin/dashboard" class="small text-decoration-none">View dashboard <i class="fa-solid fa-arrow-right ms-1"></i></a></div>
</div>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    var fd = new URLSearchParams({ module: 'analytics', service: 'reports', method: 'summary', days: '28' });
    fetch('/api', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (res) {
            if (!res || res.result !== 1 || !res.data || !res.data.summary) { return; }
            var s = res.data.summary, t = s.totals || [], series = s.series || [];
            document.getElementById('<?= $id ?>-users').textContent = Math.round(t[0] || 0).toLocaleString();
            document.getElementById('<?= $id ?>-views').textContent = Math.round(t[2] || 0).toLocaleString();
            var p = (getComputedStyle(document.documentElement).getPropertyValue('--bs-primary') || '#0d6efd').trim();
            new Chart(document.getElementById('<?= $id ?>-chart'), {
                type: 'line',
                data: { labels: series.map(function () { return ''; }),
                    datasets: [{ data: series.map(function (x) { return x.users; }), borderColor: p,
                        backgroundColor: 'rgba(13,110,253,0.10)', fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false, beginAtZero: true } } }
            });
        }).catch(function () {});
})();
</script>
<?php
        return (string) ob_get_clean();
    }
}
