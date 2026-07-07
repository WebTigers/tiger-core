<?php
/**
 * Cms_Form_MenuItem — one menu item (a row in `menu`).
 *
 * Properties map 1-to-1 to columns (no ganging): a label (key or literal), one of two
 * link kinds (a CMS page by key OR a literal url — page wins), and render/auth extras
 * (icon, css classes, dom id, target, ACL resource/privilege). menu_key / parent_id /
 * sort_order are context the editor supplies (hidden), not user-typed here.
 */
class Cms_Form_MenuItem extends Tiger_Form
{
    /**
     * No CSRF token. The menu builder saves items rapidly and reload-free (SPA-style),
     * which a single-use token would fight; the sibling reorder/delete endpoints are
     * likewise tokenless. All four are ACL-gated admin-only /api mutations — the trade
     * is deliberate and consistent.
     */
    protected function csrf(): bool
    {
        return false;
    }

    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        // Pages you can link by key (published pages with a stable key).
        $pages = ['' => '— none —'];
        $pm    = new Tiger_Model_Page();
        foreach ($pm->fetchAll(
            $pm->activeSelect()
               ->where('type = ?', Tiger_Model_Page::TYPE_PAGE)
               ->where('page_key IS NOT NULL')
               ->order(['title ASC', 'locale ASC'])
        ) as $p) {
            if ((string) $p->page_key === '') { continue; }
            $pages[$p->page_key] = ($p->title ?: $p->slug ?: $p->page_key) . '  (#' . $p->page_key . ')';
        }

        return [
            ['text', 'label', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-label', 'maxlength' => 255,
                    'placeholder' => $this->_t('cms.menu.label_hint')]),
            ]],
            ['select', 'page_key', [
                'multiOptions' => $pages,
                'attribs'      => array_merge($select, ['id' => 'mi-page-key']),
            ]],
            ['text', 'url', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-url', 'maxlength' => 2048,
                    'placeholder' => '/path or https://…']),
            ]],
            ['text', 'icon', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-icon', 'maxlength' => 64,
                    'placeholder' => 'fa-solid fa-house']),
            ]],
            ['text', 'css_class', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-css', 'maxlength' => 255,
                    'placeholder' => 'nav-item featured']),
            ]],
            ['text', 'dom_id', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-dom-id', 'maxlength' => 191]),
            ]],
            ['select', 'link_target', [
                'multiOptions' => ['' => 'Same tab', '_blank' => 'New tab (_blank)'],
                'attribs'      => array_merge($select, ['id' => 'mi-target']),
            ]],
            ['text', 'link_rel', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-rel', 'maxlength' => 64,
                    'placeholder' => 'nofollow']),
            ]],
            ['text', 'resource', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-resource', 'maxlength' => 191,
                    'placeholder' => 'Cms_PageController']),
            ]],
            ['text', 'privilege', [
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'mi-privilege', 'maxlength' => 64]),
            ]],
            ['select', 'status', [
                'multiOptions' => ['published' => 'Published', 'draft' => 'Draft'],
                'attribs'      => array_merge($select, ['id' => 'mi-status']),
            ]],
        ];
    }
}
