<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms_IndexController — the CMS module's PUBLIC face.
 *
 * indexAction serves the `/cms` marketing landing: the WordPress-audience variant of the
 * home landing ("/" sells the foundation to developers; "/cms" sells the escape route to
 * WordPress users). Thin by design — it bakes no markup, renders in the active theme's
 * PUBLIC layout (no admin chrome), and reaches nothing but the view. The authoring surface
 * of this module lives on its other controllers (/cms/page, /cms/menu, /cms/settings).
 *
 * Public in configs/acl.ini (guest-allowed). Reached at the native /cms path (module=cms,
 * controller=index, action=index).
 */
class Cms_IndexController extends Tiger_Controller_Action
{
    /**
     * /cms — the "modern WordPress alternative" marketing landing.
     *
     * @return void
     */
    public function indexAction()
    {
        // Rendered via modules/cms/views/scripts/index/index.phtml, wrapped in the active
        // theme's public layout (Bootstrap + --bs-* tokens, so it restyles with theme/skin).
    }
}
