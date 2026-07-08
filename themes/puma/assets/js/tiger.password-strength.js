/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * tiger.password-strength.js — password meter, show/hide toggle, and valid/invalid state.
 *
 * Put `data-tiger-strength` on a password input (optionally inside an .input-group with a
 * `[data-tiger-pw-toggle]` eye button). This gives you:
 *   • a slim 2px bar beneath the field that fills red → yellow → green on keyup
 *   • on blur: weak → the field goes .is-invalid (red) and the bar fades to a red message
 *     with an icon; strong → the field goes .is-valid (green). Typing returns it to neutral.
 *   • an eye button that shows/hides the password (fa-eye ⇄ fa-eye-slash)
 * The server password policy (Tiger_Validate_Password) stays the authority at submit; this
 * is the live UX. Zero markup beyond the attribute (+ the optional eye button).
 */
(function (global) {
    'use strict';

    function score(pw) {
        var s = 0;
        if (pw.length >= 8) { s++; }
        if (pw.length >= 12) { s++; }
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) { s++; }
        if (/\d/.test(pw)) { s++; }
        if (/[^A-Za-z0-9]/.test(pw)) { s++; }
        return Math.min(s, 4);
    }
    function weakness(pw) {
        if (pw.length < 8) { return 'Use at least 8 characters.'; }
        if (!/\d/.test(pw) && !/[^A-Za-z0-9]/.test(pw)) { return 'Add a number or a symbol.'; }
        if (!/[A-Z]/.test(pw) || !/[a-z]/.test(pw)) { return 'Mix upper- and lower-case for a stronger password.'; }
        return '';
    }
    var COLORS = ['#dc3545', '#dc3545', '#fd7e14', '#ffc107', '#198754'];   // red → green, index = score

    function attach(input) {
        if (input.__tgStrength) { return; }
        input.__tgStrength = true;
        var anchor = input.closest('.input-group') || input;   // inject AFTER the whole group

        var track = document.createElement('div');
        track.className = 'tg-strength';
        track.style.cssText = 'height:2px;margin-top:6px;border-radius:2px;background:var(--bs-border-color);overflow:hidden;transition:opacity .25s ease;';
        var bar = document.createElement('div');
        bar.style.cssText = 'height:2px;width:0;border-radius:2px;transition:width .25s ease, background-color .25s ease;';
        track.appendChild(bar);

        var msg = document.createElement('div');
        msg.className = 'form-text text-danger';
        msg.style.cssText = 'margin-top:6px;opacity:0;height:0;overflow:hidden;transition:opacity .25s ease;';

        anchor.insertAdjacentElement('afterend', msg);
        anchor.insertAdjacentElement('afterend', track);

        function paint() {
            var s = score(input.value);
            bar.style.width = (input.value ? (s / 4) * 100 : 0) + '%';
            bar.style.backgroundColor = COLORS[s];
        }
        function neutral() { input.classList.remove('is-invalid', 'is-valid'); }
        function hideMsg() { msg.style.opacity = '0'; msg.style.height = '0'; }

        // Typing: neutral field, bar visible, no message.
        function live() { neutral(); hideMsg(); track.style.opacity = '1'; paint(); }

        input.addEventListener('keyup', live);
        input.addEventListener('input', live);
        input.addEventListener('focus', live);
        input.addEventListener('blur', function () {
            if (!input.value) { neutral(); hideMsg(); track.style.opacity = '1'; paint(); return; }
            var w = weakness(input.value);
            if (w) {
                input.classList.add('is-invalid'); input.classList.remove('is-valid');
                track.style.opacity = '0';                       // bar fades out…
                msg.textContent = w;                             // …plain message (the field shows the state icon)
                msg.style.height = 'auto'; msg.style.opacity = '1';
            } else {
                input.classList.add('is-valid'); input.classList.remove('is-invalid');
                hideMsg(); track.style.opacity = '1'; paint();
            }
        });
        paint();
    }

    // Eye toggle (delegated) — show/hide the password in the button's input-group.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-tiger-pw-toggle]') : null;
        if (!btn) { return; }
        e.preventDefault();
        var group = btn.closest('.input-group');
        var input = group && group.querySelector('input');
        if (!input) { return; }
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        var icon = btn.querySelector('i');
        if (icon) {   // swap only the eye token — preserve size/color classes
            icon.classList.toggle('fa-eye', !reveal);
            icon.classList.toggle('fa-eye-slash', reveal);
        }
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });

    function scan(root) { (root || document).querySelectorAll('input[data-tiger-strength]').forEach(attach); }
    if (document.readyState !== 'loading') { scan(); }
    else { document.addEventListener('DOMContentLoaded', function () { scan(); }); }

    global.TigerPasswordStrength = { scan: scan };
})(window);
