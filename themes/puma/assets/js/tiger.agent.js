// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * tiger.agent.js — the TigerAgent aside client (vanilla, zero deps).
 *
 * The browser half of a turn (TIGERAGENT.md §5): it POSTs the user's message to
 * /api (agent/agent/send), renders the model's `say` + the Forge action ledger, runs any
 * `navigate`, and drives the human-in-the-loop approval of proposed write actions
 * (agent/agent/approve). The conversation is server-persisted and the active thread id +
 * the open/width UI state live in localStorage, so the aside survives full-page navigation —
 * on each load it re-materializes the active thread's history.
 */
(function () {
    'use strict';

    var aside = document.getElementById('tiger-agent');
    if (!aside) { return; }

    var root   = document.documentElement;
    var logEl  = aside.querySelector('[data-agent-log]');
    var input  = aside.querySelector('[data-agent-input]');
    var form   = aside.querySelector('[data-agent-form]');
    var status = aside.querySelector('[data-agent-status]');
    var csrfEl = aside.querySelector('input[name="_csrf"]');

    var LS = {
        open:  'tiger_agent_open',
        width: 'tiger_agent_w',
        cid:   'tiger_agent_cid',
        mode:  'tiger_agent_mode'
    };

    function getMode() {
        var sel = aside.querySelector('[data-agent-mode]');
        return sel ? sel.value : 'ask';
    }

    // ----- UI state (persists across navigation) -----------------------------

    function setOpen(open) {
        root.classList.toggle('agent-open', !!open);
        try { localStorage.setItem(LS.open, open ? '1' : '0'); } catch (e) {}
        if (open) { setTimeout(function () { input && input.focus(); }, 60); }
    }
    function applyWidth(w) {
        w = Math.max(300, Math.min(720, w | 0));
        root.style.setProperty('--tiger-agent-w', w + 'px');
        return w;
    }

    function activeCid()      { try { return localStorage.getItem(LS.cid) || ''; } catch (e) { return ''; } }
    function setActiveCid(id) { try { id ? localStorage.setItem(LS.cid, id) : localStorage.removeItem(LS.cid); } catch (e) {} }

    // ----- /api transport ----------------------------------------------------

    function api(method, params) {
        var fd = new URLSearchParams();
        fd.set('module', 'agent'); fd.set('service', 'agent'); fd.set('method', method);
        if (csrfEl) { fd.set('_csrf', csrfEl.value); }
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            fd.set(k, (typeof v === 'object') ? JSON.stringify(v) : v);
        });
        return fetch('/api', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (r) { return r.json().catch(function () { return { result: 0 }; }); });
    }

    // ----- rendering ---------------------------------------------------------

    function clearEmpty() {
        var e = logEl.querySelector('.tiger-agent-empty');
        if (e) { e.remove(); }
    }
    function scrollDown() { logEl.scrollTop = logEl.scrollHeight; }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    // Tiny, safe markdown: **bold**, `code`, [text](/path) links, and line breaks. Everything is
    // escaped first (no raw HTML passthrough); links are restricted to SAME-ORIGIN PATHS ("/…", not
    // "//host" or a scheme) so the AI can never render an off-site or javascript: link.
    function mdLite(s) {
        return escapeHtml(s)
            .replace(/\[([^\]]+)\]\((\/[^)\s]+)\)/g, function (m, text, href) {
                if (href.indexOf('//') === 0) { return text; }   // reject protocol-relative
                return '<a href="' + href + '" class="agent-link">' + text + '</a>';
            })
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br>');
    }

    function addBubble(role, content) {
        clearEmpty();
        var wrap = document.createElement('div');
        wrap.className = 'agent-msg ' + (role === 'user' ? 'user' : 'assistant');
        var b = document.createElement('div');
        b.className = 'bubble';
        b.innerHTML = mdLite(content);
        wrap.appendChild(b);
        logEl.appendChild(wrap);
        scrollDown();
        return wrap;
    }

    function actionLabel(a) {
        var t = a.type || '';
        var act = a.action || {};
        if (t === 'api')    { return act.module + '/' + act.service + '/' + act.method; }
        if (t === 'file')   { return act.path || 'file'; }
        if (t === 'code')   { return act.name || 'snippet'; }
        if (t === 'module') { return act.name || 'module'; }
        if (t === 'read.file' || t === 'read.tree') { return act.path || t; }
        if (t === 'read.grep') { return '"' + (act.query || '') + '"'; }
        if (t === 'read.inventory') { return 'system map'; }
        return t;
    }

    function renderActions(host, actions, runId) {
        if (!actions || !actions.length) { return; }
        var box = document.createElement('div');
        box.className = 'agent-actions';
        actions.forEach(function (a, i) {
            var el = document.createElement('div');
            el.className = 'agent-action';
            el.setAttribute('data-status', a.status || 'done');
            var icon = ({ done: 'fa-circle-check text-success', error: 'fa-circle-xmark text-danger',
                          denied: 'fa-ban text-danger', proposed: 'fa-clock text-warning' })[a.status] || 'fa-circle';
            el.innerHTML =
                '<div class="a-head"><i class="fa-solid ' + icon + '"></i>' +
                '<span class="a-type">' + escapeHtml(a.type) + '</span>' +
                '<span class="text-body-secondary">' + escapeHtml(actionLabel(a)) + '</span></div>' +
                (a.reason ? '<div class="a-reason">' + escapeHtml(a.reason) + '</div>' : '') +
                '<div class="a-summary">' + escapeHtml(a.summary || '') + '</div>';

            if (a.status === 'proposed' && runId) {
                var btn = document.createElement('button');
                btn.className = 'btn btn-sm btn-warning mt-2';
                btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Approve';
                btn.addEventListener('click', function () { approve(runId, i, el, box); });
                el.appendChild(btn);
            }
            box.appendChild(el);
        });
        host.appendChild(box);
        scrollDown();
    }

    // The agent suggested a page. Consistent with the approval model, the USER clicks to go —
    // except in YOLO, where (like every other action) it just happens.
    function handleNavigate(wrap, path) {
        if (!path) { return; }
        if (getMode() === 'yolo') {
            status.textContent = 'Opening ' + path + '…';
            setTimeout(function () { location.href = path; }, 800);
            return;
        }
        var host = wrap || addBubble('assistant', '');
        var chip = document.createElement('button');
        chip.className = 'btn btn-sm btn-outline-primary mt-2 agent-nav';
        chip.innerHTML = '<i class="fa-solid fa-arrow-right-long me-1"></i>Go to <code>' + escapeHtml(path) + '</code>';
        chip.addEventListener('click', function () { location.href = path; });
        host.appendChild(chip);
        scrollDown();
    }

    // ----- turn + approval ---------------------------------------------------

    function busy(on, msg) {
        status.textContent = on ? (msg || 'Working…') : ' ';
        var send = aside.querySelector('[data-agent-send]');
        if (send) { send.disabled = !!on; }
    }

    function send() {
        var text = (input.value || '').trim();
        if (!text) { return; }
        input.value = '';
        addBubble('user', text);
        busy(true);

        api('send', {
            conversation_id: activeCid(),
            message: text,
            context: { path: location.pathname },
            mode: getMode()
        }).then(function (res) {
            busy(false);
            if (!res || res.result !== 1 || !res.data) {
                var m = (res && res.messages && res.messages[0] && res.messages[0].message) || 'Something went wrong.';
                addBubble('assistant', m);
                return;
            }
            var d = res.data;
            if (d.conversation_id) { setActiveCid(d.conversation_id); }
            var wrap = addBubble('assistant', d.say || '');
            renderActions(wrap, d.actions, d.run_id);
            handleNavigate(wrap, d.navigate);
        }).catch(function () {
            busy(false);
            addBubble('assistant', 'Network error — please try again.');
        });
    }

    function approve(runId, index, chipEl, box) {
        busy(true, 'Running…');
        api('approve', { run_id: runId, indexes: JSON.stringify([index]), mode: getMode() }).then(function (res) {
            busy(false);
            if (!res || res.result !== 1 || !res.data) { return; }
            // Re-render the whole ledger for this run in place (statuses now updated).
            var parent = box.parentNode;
            box.remove();
            renderActions(parent, res.data.actions, runId);
            // The AI's closing word (or next step) after the approval.
            if (res.data.follow) { renderFollow(res.data.follow); }
        }).catch(function () { busy(false); });
    }

    // Render a follow-up turn (the AI reporting back / continuing after an approval).
    function renderFollow(f) {
        if (!f) { return; }
        var wrap = null;
        if (f.say || (f.actions && f.actions.length)) {
            wrap = addBubble('assistant', f.say || '');
            renderActions(wrap, f.actions, f.run_id);
        }
        handleNavigate(wrap, f.navigate);
    }

    // ----- history / threads -------------------------------------------------

    function loadHistory(cid) {
        api('history', { conversation_id: cid }).then(function (res) {
            if (!res || res.result !== 1 || !res.data || !res.data.messages) { return; }
            logEl.innerHTML = '';
            res.data.messages.forEach(function (m) {
                var wrap = addBubble(m.role, m.content);
                if (m.meta && m.meta.actions) {
                    // find the run id from any proposed action so re-approval still works
                    renderActions(wrap, m.meta.actions, runIdOf(m.meta));
                }
            });
            if (!res.data.messages.length) { showEmpty(); }
        });
    }
    function runIdOf(meta) {
        // meta doesn't carry run_id directly; approval of reloaded proposals is best-effort.
        return (meta && meta.run_id) || '';
    }
    function showEmpty() {
        logEl.innerHTML = '<div class="tiger-agent-empty">Start a conversation — the agent acts with your permissions.</div>';
    }
    function newChat() {
        setActiveCid('');
        showEmpty();
        input.focus();
    }

    // ----- resize ------------------------------------------------------------

    function wireResize() {
        var handle = aside.querySelector('[data-agent-resize]');
        if (!handle) { return; }
        var dragging = false;
        handle.addEventListener('pointerdown', function (e) {
            dragging = true; handle.setPointerCapture(e.pointerId);
            document.body.style.userSelect = 'none';
        });
        handle.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            applyWidth(window.innerWidth - e.clientX);
        });
        handle.addEventListener('pointerup', function (e) {
            if (!dragging) { return; }
            dragging = false; document.body.style.userSelect = '';
            var w = parseInt(getComputedStyle(root).getPropertyValue('--tiger-agent-w'), 10) || 380;
            try { localStorage.setItem(LS.width, String(w)); } catch (er) {}
        });
    }

    // ----- init --------------------------------------------------------------

    function init() {
        var savedW = parseInt((function () { try { return localStorage.getItem(LS.width); } catch (e) { return ''; } })(), 10);
        if (savedW) { applyWidth(savedW); }

        var wasOpen = (function () { try { return localStorage.getItem(LS.open) === '1'; } catch (e) { return false; } })();
        setOpen(wasOpen);

        // Restore the active thread's history (or show the empty state).
        var cid = activeCid();
        if (cid) { loadHistory(cid); }

        // Controls.
        document.querySelectorAll('[data-agent-open]').forEach(function (b) { b.addEventListener('click', function () { setOpen(true); }); });
        aside.querySelector('[data-agent-close]').addEventListener('click', function () { setOpen(false); });
        aside.querySelector('[data-agent-new]').addEventListener('click', newChat);
        form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });

        // Restore the chosen automation mode (only if it's still an offered option — the install
        // ceiling may have tightened since last visit).
        var modeSel = aside.querySelector('[data-agent-mode]');
        if (modeSel) {
            var savedMode = (function () { try { return localStorage.getItem(LS.mode); } catch (e) { return ''; } })();
            if (savedMode && modeSel.querySelector('option[value="' + savedMode + '"]')) { modeSel.value = savedMode; }
            modeSel.addEventListener('change', function () {
                try { localStorage.setItem(LS.mode, modeSel.value); } catch (e) {}
            });
        }
        wireResize();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
