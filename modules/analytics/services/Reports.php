<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Analytics_Service_Reports — the /api service the analytics dashboard (and widget) fetch from. Returns
 * the file-cached GA4 summary (totals + daily series + top pages/sources). Admin-only; read-only, so it
 * follows the client/server pattern (the view renders an empty shell, the data arrives over /api).
 *
 * @api
 */
class Analytics_Service_Reports extends Tiger_Service_Service
{
    /**
     * The GA4 traffic summary for the requested window (default 28 days).
     *
     * @param  array $params optional `days` (1..365) and `fresh` (bypass cache)
     * @return void
     */
    public function summary(array $params): void
    {
        if (!$this->_isAdmin()) {
            $this->_error('core.api.error.not_allowed');
            return;
        }
        if (!class_exists('Tiger_Google_Analytics') || !Tiger_Google_Analytics::isConnected()) {
            $this->_error('analytics.reports.not_connected');
            return;
        }
        $days = isset($params['days']) ? (int) $params['days'] : 28;
        $data = Tiger_Google_Analytics::summary($days, !empty($params['fresh']));
        if ($data === null) {
            $this->_error('analytics.reports.error');
            return;
        }
        $this->_success(['summary' => $data]);
    }

    /**
     * Live connection self-test for the Troubleshooting panel: mints a token + makes one GA4 call and
     * returns a plain diagnosis. A failed *connection* is still a successful *call* (result=1) — the
     * client reads `data.test.ok` and shows the message/hint — so the panel can explain what's wrong.
     *
     * @param  array $params unused
     * @return void
     */
    public function test(array $params): void
    {
        if (!$this->_isAdmin()) {
            $this->_error('core.api.error.not_allowed');
            return;
        }
        if (!class_exists('Tiger_Google_Analytics')) {
            $this->_error('analytics.reports.error');
            return;
        }
        $this->_success(['test' => Tiger_Google_Analytics::testConnection()]);
    }
}
