<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Controller_Plugin_ScheduleTick — the WordPress-style pseudo-cron for Tiger_Schedule.
 *
 * The zero-setup fallback: when no real cron is wired, a visitor request quietly drives the
 * scheduler. It is cheap and self-limiting — at most once/~minute (a file-mtime throttle), it never
 * runs if a real cron ticked recently (it yields), and the actual work is deferred to AFTER the
 * response is delivered (`fastcgi_finish_request`) so the visitor never waits. A lock file keeps
 * concurrent visitors from double-running. This is inherently traffic-dependent, so the admin UI
 * still nudges toward a real cron for reliable, on-time scheduling.
 *
 * @internal
 */
class Tiger_Controller_Plugin_ScheduleTick extends Zend_Controller_Plugin_Abstract
{
    /**
     * Decide (cheaply) whether this request should drive a tick, and if so defer the run to shutdown.
     *
     * @param  Zend_Controller_Request_Abstract $request
     * @return void
     */
    public function routeShutdown(Zend_Controller_Request_Abstract $request)
    {
        try {
            if (!class_exists('Tiger_Schedule')
                || !Tiger_Schedule::pseudoCronEnabled()   // disabled by config
                || Tiger_Schedule::realCronRecent()       // a real cron is handling it → yield
                || !Tiger_Schedule::tickDue()) {          // throttle: at most ~once/minute
                return;
            }
            Tiger_Schedule::markTick();   // claim this minute so sibling requests don't pile on

            register_shutdown_function(static function () {
                // Detach from the client so the visitor's page is already delivered, then work.
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                @ignore_user_abort(true);
                if (function_exists('set_time_limit')) { @set_time_limit(0); }
                try {
                    // A tight budget: on non-FPM (no finish_request) this runs before the socket closes.
                    Tiger_Schedule::runDue(function_exists('fastcgi_finish_request') ? 40 : 15, 'pseudo');
                } catch (Throwable $e) { /* a scheduler hiccup must never surface on a page render */ }
            });
        } catch (Throwable $e) {
            // never let scheduling interfere with the request
        }
    }
}
