<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * TigerCore — English (en) core strings.
 *
 * Owner-prefixed semantic keys (core.*). This file is the BASE for the framework's
 * own messages; an app overrides any key by redefining it in application/languages/
 * en/app.php (loads later), or live via the `translation` table (no deploy).
 */
return [
    // --- API service responses (Tiger_Service_Service / ServiceFactory defaults) ---
    'core.api.success'               => 'Done.',
    'core.api.error.general'         => 'Something went wrong. Please try again.',
    'core.api.error.form'            => 'Please correct the highlighted fields.',
    'core.api.error.invalid_action'  => 'That action is not available.',
    'core.api.error.not_allowed'     => "You don't have permission to do that.",
    'core.api.error.login_required'  => 'Please sign in to continue.',
    'core.api.error.login_failed'    => 'Invalid email or password.',
    'core.api.error.missing_module'  => 'No module was specified.',
    'core.api.error.missing_service' => 'No service was specified.',
    'core.api.error.missing_action'  => 'No action was specified.',

    // --- Forms: reCAPTCHA validation ---
    'core.form.recaptcha.missing'    => 'Please confirm you are not a robot.',
    'core.form.recaptcha.failed'     => "reCAPTCHA verification failed. Please try again.",
    'core.form.recaptcha.error'      => "Couldn't verify reCAPTCHA right now. Please try again.",

    // --- Two-factor auth (TOTP) ---
    'core.auth.twofa.enabled'        => 'Two-factor authentication is now on.',
    'core.auth.twofa.disabled'       => 'Two-factor authentication has been turned off.',
    'core.auth.twofa.bad_code'       => 'That code is incorrect or has expired.',
    'core.auth.twofa.unavailable'    => 'Two-factor authentication is not available on this install.',

    // --- Error pages ---
    'core.error.403.title'           => "You don't have access to that.",
    'core.error.404.title'           => "That page doesn't exist.",
    'core.error.500.title'           => 'Something went wrong.',
];
