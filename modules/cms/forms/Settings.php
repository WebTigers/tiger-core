<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms_Form_Settings — site/CMS settings (site name + the home page served at "/").
 *
 * Values are stored in the `config` table (scope=global) by Cms_Service_Settings —
 * NOT a settings table (see the config-discipline: config store + registry, no
 * option landfill). The home-page dropdown lists published pages; its value is a
 * `page_id` ('' = the built-in landing page).
 */
class Cms_Form_Settings extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        $home = ['' => '— Built-in landing page —'];
        $pm   = new Tiger_Model_Page();
        foreach ($pm->fetchAll(
            $pm->activeSelect()
               ->where('type = ?', Tiger_Model_Page::TYPE_PAGE)
               ->where('status = ?', Tiger_Model_Page::STATUS_PUBLISHED)
               ->order(['title ASC', 'locale ASC'])
        ) as $p) {
            $label = ($p->title ?: $p->slug ?: $p->page_key) . ' (' . $p->locale . ')';
            $home[$p->page_id] = $label;
        }

        return [
            ['text', 'site_name', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'set-site-name', 'maxlength' => 191]),
            ]],
            ['select', 'home_page', [
                'multiOptions' => $home,
                'attribs'      => array_merge($select, ['id' => 'set-home-page']),
            ]],
        ];
    }
}
