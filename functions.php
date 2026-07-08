<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger global helper functions, autoloaded via composer "files". Apps add
 * their own helpers in custom.php. Guarded so an app may redefine if needed.
 */

if (!function_exists('pr')) {
    /** Quick print_r for debugging (dies by default). */
    function pr($var, $die = true)
    {
        header('Content-Type: text/plain');
        if ($die) { die(print_r($var, true)); }
        print_r($var, true);
    }
}

if (!function_exists('zd')) {
    /** Zend_Debug dump for debugging (dies by default). */
    function zd($var, $die = true)
    {
        if ($die) { die(Zend_Debug::dump($var, null, false)); }
        Zend_Debug::dump($var);
    }
}

if (!function_exists('is_json')) {
    /** Test (and optionally return) decoded JSON. */
    function is_json($string, $returnData = false)
    {
        if (!is_string($string)) { return false; }
        $data = json_decode($string);
        return json_last_error() === JSON_ERROR_NONE
            ? ($returnData ? $data : true)
            : false;
    }
}
