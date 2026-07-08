/**
 * tiger.autologout.js — server-authoritative inactivity auto-logout.
 *
 * More than a bare JS timer: roughly once a minute it polls /auth/session for the server's
 * authoritative time-left, reporting whether the user GENUINELY interacted (active=1) so
 * the server clock only resets on real activity — an idle poll checks time-left without
 * resetting it. When the inactivity window runs out it logs the user out or locks the
 * screen (per config); a warning modal offers a "stay signed in" reprieve first. It also
 * fires immediately if a poll finds the session already gone server-side (logout in another
 * tab, admin revoke, TTL reaped) — which a pure client timer could never know.
 *
 * Config: window.TIGER_SESSION { enabled, seconds, warn, action, pollMs }, reconciled live
 * from every poll response (so changing the setting propagates without a hard refresh).
 */
(function () {
  'use strict';

  var cfg = window.TIGER_SESSION || {};
  if (!cfg.enabled) { return; }   // off at page load; enabling it takes effect on next navigation

  var POLL_MS   = Math.max(15000, cfg.pollMs || 60000);
  var seconds   = Math.max(30, cfg.seconds || 900);
  var warn      = Math.max(0, cfg.warn || 0);
  var action    = cfg.action === 'lock' ? 'lock' : 'logout';
  var remaining = seconds;
  var sawActivity = false;
  var warned = false;
  var fired = false;
  var tickTimer = null, pollTimer = null;

  // --- warning modal (built lazily; Bootstrap is present in the admin layout) ---
  var modalEl, modalBs, countEl;
  function buildModal() {
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
          '<button type="button" class="btn btn-outline-secondary" data-al-now>Sign out now</button>' +
          '<button type="button" class="btn btn-primary" data-al-stay>Stay signed in</button>' +
        '</div>' +
      '</div></div>';
    document.body.appendChild(modalEl);
    countEl = modalEl.querySelector('[data-al-count]');
    modalBs = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalEl.querySelector('[data-al-stay]').addEventListener('click', staySignedIn);
    modalEl.querySelector('[data-al-now]').addEventListener('click', function () { doAction('logout'); });
  }
  function showWarn() { if (!modalEl) { buildModal(); } modalBs.show(); }
  function hideWarn() { if (modalBs) { modalBs.hide(); } }

  function doAction(force) {
    if (fired) { return; }
    fired = true;
    clearInterval(tickTimer);
    clearTimeout(pollTimer);
    window.location.href = ((force || action) === 'lock') ? '/auth/lock' : '/auth/logout';
  }

  function resetCountdown() { remaining = seconds; if (warned) { warned = false; hideWarn(); } }

  function staySignedIn() {
    resetCountdown();
    sawActivity = true;
    poll();   // confirm the reprieve with the server right away
  }

  // --- genuine user interaction: optimistically reset + flag for the next poll ---
  function onActivity() { sawActivity = true; resetCountdown(); }
  function throttle(fn, ms) {
    var last = 0;
    return function () { var t = +new Date(); if (t - last >= ms) { last = t; fn(); } };
  }
  var onAct = throttle(onActivity, 1000);
  ['mousedown', 'keydown', 'touchstart', 'scroll', 'mousemove'].forEach(function (ev) {
    window.addEventListener(ev, onAct, { passive: true });
  });

  // --- 1s local ticker: smooth UX between the minute polls ---
  function tick() {
    if (fired) { return; }
    remaining -= 1;
    if (remaining <= 0) { doAction(); return; }
    if (warn > 0 && remaining <= warn) {
      if (!warned) { warned = true; showWarn(); }
      if (countEl) { countEl.textContent = remaining; }
    }
  }

  // --- the server poll: authoritative time-left, reconciles config, detects server death ---
  function poll() {
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
        seconds = s.seconds || seconds;
        warn    = (s.warn != null) ? s.warn : warn;
        action  = s.action === 'lock' ? 'lock' : 'logout';
        remaining = Math.max(0, s.remaining);                // server is authoritative
        if (remaining > warn && warned) { warned = false; hideWarn(); }
        if (remaining <= 0) { doAction(); }
      })
      .catch(function () { /* transient network error — keep local state, retry next interval */ })
      .finally(function () { if (!fired) { pollTimer = setTimeout(poll, POLL_MS); } });
  }

  tickTimer = setInterval(tick, 1000);
  poll();   // sync immediately on load
})();
