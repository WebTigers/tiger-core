/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * tiger.autologout.js — server-authoritative inactivity auto-logout.
 *
 * More than a bare JS timer: roughly once a minute it polls /auth/session for the server's
 * authoritative time-left, reporting whether the user GENUINELY interacted (active=1) so
 * the server clock only resets on real activity — an idle poll checks time-left without
 * resetting it. It also fires immediately if a poll finds the session already gone
 * server-side (logout in another tab, admin revoke, TTL reaped) — which a pure client timer
 * could never know.
 *
 * Two phases:
 *   1. Normal — count down locally, reconcile against the server each minute, and reset on
 *      genuine user interaction.
 *   2. Warning (final `warn` seconds) — once the "still there?" modal opens it is COMMITTED:
 *      polling stops, idle mouse/scroll activity no longer cancels it, and the countdown
 *      runs uninterrupted to zero → logout / lock. Only an explicit "Stay signed in" click
 *      (or "…now") ends the warning. This is what stops the modal from flapping when the
 *      pointer moves toward it.
 *
 * Config: window.TIGER_SESSION { enabled, seconds, warn, action, pollMs }, reconciled live
 * from every poll response.
 */
(function () {
  'use strict';

  var cfg = window.TIGER_SESSION || {};
  if (!cfg.enabled) { return; }   // off at page load; enabling it takes effect on next navigation

  var POLL_MS = Math.max(15000, cfg.pollMs || 60000);
  var seconds = Math.max(30, cfg.seconds || 900);
  var warn    = clampWarn(cfg.warn || 0);
  var action  = cfg.action === 'lock' ? 'lock' : 'logout';

  var remaining   = seconds;
  var sawActivity = false;
  var warning     = false;   // the committed final-countdown phase (modal open)
  var fired       = false;
  var tickTimer   = null;
  var pollTimer   = null;

  // Never let the warning lead exceed half the window — so a short timeout (e.g. 65s)
  // gets a proportionate heads-up, not a modal that's up for almost the whole countdown.
  function clampWarn(w) { return Math.max(0, Math.min(w, Math.floor(seconds / 2))); }

  // --- warning modal (built lazily; Bootstrap is present in the admin layout) ---
  var modalEl, modalBs, countEl;
  function buildModal() {
    var nowLabel = action === 'lock' ? 'Lock now' : 'Sign out now';
    modalEl = document.createElement('div');
    modalEl.className = 'modal fade';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('data-bs-backdrop', 'static');
    modalEl.setAttribute('data-bs-keyboard', 'false');
    modalEl.innerHTML =
      '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
        '<div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-clock me-2 text-warning"></i>Still there?</h5></div>' +
        '<div class="modal-body">For your security you’ll be signed out in ' +
          '<strong data-al-count>0</strong> second(s) due to inactivity.</div>' +
        '<div class="modal-footer">' +
          '<button type="button" class="btn btn-outline-secondary" data-al-now>' + nowLabel + '</button>' +
          '<button type="button" class="btn btn-primary" data-al-stay>Stay signed in</button>' +
        '</div>' +
      '</div></div>';
    document.body.appendChild(modalEl);
    countEl = modalEl.querySelector('[data-al-count]');
    modalBs = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalEl.querySelector('[data-al-stay]').addEventListener('click', staySignedIn);
    modalEl.querySelector('[data-al-now]').addEventListener('click', function () { doAction(); });
  }
  function showWarn() { if (!modalEl) { buildModal(); } modalBs.show(); }
  function hideWarn() { if (modalBs) { modalBs.hide(); } }

  function doAction(force) {
    if (fired) { return; }
    fired = true;
    clearInterval(tickTimer);
    clearTimeout(pollTimer);
    // /logout ends the session server-side and shows the signed-out card;
    // /auth/lock arms the screen lock. (Never redirect straight to the login page — that
    // would leave the session alive.)
    window.location.href = ((force || action) === 'lock') ? '/auth/lock' : '/logout';
  }

  // Enter the committed final countdown: show the modal and STOP polling.
  function enterWarning() {
    if (warning) { return; }
    warning = true;
    clearTimeout(pollTimer);   // no more polling once the window is open
    showWarn();
  }

  function staySignedIn() {
    warning = false;
    remaining = seconds;
    sawActivity = true;
    hideWarn();
    schedulePoll(0);   // resume the poll loop, confirm the reprieve now
  }

  // --- genuine user interaction: reset the clock — but ONLY before the warning is up ---
  function onActivity() {
    if (warning) { return; }   // once committed, idle activity can't silently cancel it
    sawActivity = true;
    remaining = seconds;
  }
  function throttle(fn, ms) {
    var last = 0;
    return function () { var t = +new Date(); if (t - last >= ms) { last = t; fn(); } };
  }
  var onAct = throttle(onActivity, 1000);
  ['mousedown', 'keydown', 'touchstart', 'scroll', 'mousemove'].forEach(function (ev) {
    window.addEventListener(ev, onAct, { passive: true });
  });

  // --- 1s local ticker ---
  function tick() {
    if (fired) { return; }
    remaining -= 1;
    if (remaining <= 0) { doAction(); return; }
    if (remaining <= warn) {
      enterWarning();
      if (countEl) { countEl.textContent = remaining; }
    }
  }

  // --- the server poll: authoritative time-left; paused during the warning phase ---
  function poll() {
    if (warning || fired) { return; }
    var body = new URLSearchParams();
    body.set('active', sawActivity ? '1' : '0');
    sawActivity = false;
    fetch('/auth/session', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        if (!s) { return; }
        if (!s.authenticated) { doAction(); return; }        // session already gone server-side
        if (!s.enabled) { fired = true; clearInterval(tickTimer); clearTimeout(pollTimer); return; }  // disabled live
        if (warning) { return; }                             // committed — don't reconcile mid-countdown
        seconds = s.seconds || seconds;
        warn    = clampWarn((s.warn != null) ? s.warn : warn);
        action  = s.action === 'lock' ? 'lock' : 'logout';
        remaining = Math.max(0, s.remaining);                // server is authoritative
        if (remaining <= 0) { doAction(); }
      })
      .catch(function () { /* transient network error — keep local state, retry next interval */ })
      .finally(function () { schedulePoll(POLL_MS); });
  }

  function schedulePoll(delay) {
    clearTimeout(pollTimer);
    if (fired || warning) { return; }
    pollTimer = setTimeout(poll, delay);
  }

  tickTimer = setInterval(tick, 1000);
  poll();   // sync immediately on load
})();
