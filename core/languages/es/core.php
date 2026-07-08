<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * TigerCore — Español (es) core strings. Claves semánticas con prefijo (core.*).
 */
return [
    // --- Respuestas de servicios /api (valores por defecto) ---
    'core.api.success'               => 'Listo.',
    'core.api.error.general'         => 'Algo salió mal. Inténtalo de nuevo.',
    'core.api.error.form'            => 'Corrige los campos resaltados.',
    'core.api.error.invalid_action'  => 'Esa acción no está disponible.',
    'core.api.error.not_allowed'     => 'No tienes permiso para hacer eso.',
    'core.api.error.login_required'  => 'Inicia sesión para continuar.',
    'core.api.error.login_failed'    => 'Correo o contraseña inválidos.',
    'core.api.error.missing_module'  => 'No se especificó ningún módulo.',
    'core.api.error.missing_service' => 'No se especificó ningún servicio.',
    'core.api.error.missing_action'  => 'No se especificó ninguna acción.',

    // --- Formularios: validación de reCAPTCHA ---
    'core.form.recaptcha.missing'    => 'Confirma que no eres un robot.',
    'core.form.recaptcha.failed'     => 'La verificación de reCAPTCHA falló. Inténtalo de nuevo.',
    'core.form.recaptcha.error'      => 'No se pudo verificar reCAPTCHA en este momento. Inténtalo de nuevo.',

    // --- Autenticación de dos factores (TOTP) ---
    'core.auth.twofa.enabled'        => 'La autenticación de dos factores está activada.',
    'core.auth.twofa.disabled'       => 'Se desactivó la autenticación de dos factores.',
    'core.auth.twofa.bad_code'       => 'Ese código es incorrecto o ha caducado.',
    'core.auth.twofa.unavailable'    => 'La autenticación de dos factores no está disponible en esta instalación.',

    // --- Páginas de error ---
    'core.error.403.title'           => 'No tienes acceso a eso.',
    'core.error.404.title'           => 'Esa página no existe.',
    'core.error.500.title'           => 'Algo salió mal.',
];
