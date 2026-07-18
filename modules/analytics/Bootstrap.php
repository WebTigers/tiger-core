<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Analytics module bootstrap.
 *
 * First-party Google Analytics (GA4) integration — the WordPress-parity "paste your tracking ID"
 * feature, done the Tiger way: config-backed (no deploy), consent-aware, and provider-extensible via
 * the Tiger_Tracking registry. This module owns only GA; the GDPR cookie banner is a separate feature
 * (Settings → Security → Cookies) that gates every registered tracker through Tiger_Consent.
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource autoloader, so
 * Analytics_Service_* / Analytics_Form_* / Analytics_Plugin_* load by convention; controllers load
 * via the registered module dir; configs/acl.ini + languages/ are picked up by the core globs.
 */
class Analytics_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Contribute the GA tag to the head (config-driven, consent-gated). High stackIndex = after routing. */
    protected function _initAnalyticsTag()
    {
        Zend_Controller_Front::getInstance()->registerPlugin(new Analytics_Plugin_Tag(), 92);
    }

    /**
     * Declare GA to the tracking registry so the cookie-consent feature's "auto" mode can tell that
     * tracking is present (and pop the banner). Active = enabled AND a GA4 Measurement ID is set.
     * Guarded so the module still loads on a Core that predates Tiger_Tracking.
     */
    protected function _initAnalyticsTracker()
    {
        if (!class_exists('Tiger_Tracking')) {
            return;
        }
        Tiger_Tracking::register('ga4', [
            'label'    => 'Google Analytics',
            'category' => 'analytics',
            'active'   => Analytics_Plugin_Tag::isConfigured(),
        ]);
    }

    /**
     * Register the "Traffic" dashboard widget (GA reports). Also teaches the module autoloader the
     * `widgets/` resource type so Analytics_Widget_* resolves. ACL-gated + activation-gated for free.
     */
    protected function _initAnalyticsWidget()
    {
        if (method_exists($this, 'getResourceLoader') && $this->getResourceLoader()) {
            $this->getResourceLoader()->addResourceType('widget', 'widgets', 'Widget');
        }
        if (class_exists('Tiger_Dashboard')) {
            Tiger_Dashboard::registerWidget([
                'id'       => 'analytics.traffic',
                'module'   => 'analytics',
                'title'    => 'Traffic',
                'icon'     => 'fa-chart-line',
                'widget'   => 'Analytics_Widget_Ga',
                'resource' => 'Analytics_AdminController',
                'width'    => 2,
                'order'    => 30,
            ]);
        }
    }

    /** List Analytics under the admin Settings tree (ACL-gated to Analytics_AdminController). */
    protected function _initAdminSettings()
    {
        Tiger_Admin_Settings::register([
            'key'      => 'analytics',
            'label'    => 'Analytics',
            'icon'     => 'fa-chart-line',
            'href'     => '/analytics/admin',
            'resource' => 'Analytics_AdminController',
            'order'    => 40,
        ]);
    }
}
