/**
 * tiger.validate.js — TigerValidateJS: convenience validation.
 *
 * As you blur a field, it asks the server (the module's validate service over /api) whether
 * the value is good — the SAME validators that run at submit, applied per-field, BEFORE you
 * click the button. Invalid → the field goes `.is-invalid` with the server's message beneath
 * it; fix it and tab on to watch it clear (and go `.is-valid`). No value is a surprise at
 * submit.
 *
 * Opt in per form — the form declares which module's validate service to call:
 *   <form data-tiger-validate data-module="signup" data-form="Signup"> … </form>
 * Each field sits in a container ([data-field] or the nearest form group); the message shows
 * in a `.invalid-feedback` there (created if absent). Add `data-no-validate` to a field to
 * skip it. `TigerValidate.all(form)` validates every field at once (returns a Promise<bool>)
 * for a belt-and-suspenders check on submit.
 */
(function (global) {
    'use strict';

    var API = '/api';

    function container(input) {
        return input.closest('[data-field]')
            || input.closest('.mb-3, .mb-2, .mb-4, .form-group, .col')
            || input.parentElement;
    }
    function feedback(input) {
        var c = container(input);
        var fb = c.querySelector('.invalid-feedback');
        if (!fb) { fb = document.createElement('div'); fb.className = 'invalid-feedback'; c.appendChild(fb); }
        return fb;
    }
    function eligible(input) {
        if (!input.name || input.name === '_csrf') { return false; }
        if (input.disabled || input.type === 'hidden' || input.type === 'submit' || input.type === 'button') { return false; }
        return !input.hasAttribute('data-no-validate');
    }

    function apply(input, data) {
        var valid = !data || data.valid !== false;
        var msg = (data && data.message) || '';
        input.classList.toggle('is-invalid', !valid);
        input.classList.toggle('is-valid', valid && input.value.trim() !== '' && input.dataset.tgTouched === '1');
        var fb = feedback(input);
        fb.textContent = msg;
        fb.style.display = (!valid && msg) ? 'block' : '';
    }

    /** Validate one field against the server. Returns a Promise<bool valid>. */
    function validate(input) {
        var form = input.form;
        if (!form) { return Promise.resolve(true); }
        var body = new URLSearchParams();
        body.set('module', 'tiger');                                   // -> Tiger_Service_Validate (core, ACL-gated)
        body.set('service', 'validate');
        body.set('method', 'field');
        body.set('form_module', form.getAttribute('data-module') || '');   // the form's own module
        body.set('form', form.getAttribute('data-form') || '');
        body.set('field', input.name);
        body.set('value', input.value);
        // The rest of the form travels as context (cross-field validators: confirm, etc.).
        form.querySelectorAll('[name]').forEach(function (el) {
            if (el.name && el.name !== '_csrf' && el.name !== input.name) { body.set(el.name, el.value); }
        });

        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var data = (res && res.data) ? res.data : res;   // unwrap the /api envelope
                apply(input, data);
                return !data || data.valid !== false;
            })
            .catch(function () { return true; });   // network hiccup — don't false-flag the field
    }

    /** Validate every eligible field in a form. Promise<bool all-valid>. */
    function all(form) {
        var inputs = [];
        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
            if (eligible(el)) { el.dataset.tgTouched = '1'; inputs.push(el); }
        });
        return Promise.all(inputs.map(validate)).then(function (results) {
            return results.every(Boolean);
        });
    }

    // Blur → validate.
    document.addEventListener('focusout', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('input, select, textarea')) { return; }
        var form = input.form;
        if (!form || !form.hasAttribute('data-tiger-validate') || !eligible(input)) { return; }
        input.dataset.tgTouched = '1';
        validate(input);
    });

    // While a field is flagged invalid, re-check as they type (debounced) so it clears live.
    document.addEventListener('input', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('input, select, textarea')) { return; }
        var form = input.form;
        if (!form || !form.hasAttribute('data-tiger-validate') || !eligible(input)) { return; }
        if (!input.classList.contains('is-invalid')) { return; }
        clearTimeout(input.__tgT);
        input.__tgT = setTimeout(function () { validate(input); }, 350);
    });

    global.TigerValidate = { validate: validate, all: all };
})(window);
