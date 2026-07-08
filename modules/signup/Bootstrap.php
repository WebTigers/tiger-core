<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Signup module bootstrap.
 *
 * First-party public module: account creation. The reference form
 * (Signup_Form_Signup) is the gold-standard example for building any Tiger form —
 * full convenience validation, DB-uniqueness, password policy + strength, and a
 * transactional create of the org/user/address/contact graph.
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource
 * autoloader, so Signup_Service_* and Signup_Form_* load by convention.
 */
class Signup_Bootstrap extends Zend_Application_Module_Bootstrap
{
}
