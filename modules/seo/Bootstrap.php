<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Seo_Bootstrap — TigerSEO's module bootstrap. Phase 1 registers the head plugin that contributes a CMS
 * page's SEO metadata to the head registry (see ARCHITECTURE.md / FEATURES.md in this dir for the full
 * design). It adds NO custom head registry of its own — it appends to TigerZF's headTitle/headMeta/
 * headLink, which the core layout now renders. Uninstall the module and the head still renders, with less.
 */
class Seo_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Register the head plugin — high stackIndex so it runs after core routing plugins (PageDispatch). */
    protected function _initSeoHead()
    {
        Zend_Controller_Front::getInstance()->registerPlugin(new Seo_Plugin_Head(), 90);
    }
}
