<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_View_Helper_Menu — render a custom menu in a view: `<?= $this->menu('primary') ?>`.
 *
 * A thin wrapper over Tiger_Menu::getHTML (auth-filtered, translated, active-state). The
 * developer owns the container — this emits only the <ul>. Pass options to set the outer
 * list's class/id: `$this->menu('primary', ['class' => 'navbar-nav', 'id' => 'main-nav'])`.
 *
 * @api
 */
class Tiger_View_Helper_Menu extends Zend_View_Helper_Abstract
{
    public function menu($menuKey = null, array $options = [])
    {
        if ($menuKey === null) {
            return $this;   // allow `$this->menu()->…` style access if ever needed
        }
        return Tiger_Menu::getHTML((string) $menuKey, $options);
    }
}
