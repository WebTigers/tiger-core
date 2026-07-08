<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_View_Helper_MediaField — a form field that picks media via TigerMediaPicker.
 *
 * Renders a hidden input (holding the selected media_id, or a comma list when multiple)
 * plus a live preview and Choose/Clear buttons. The picker JS (tiger.media-picker.js,
 * loaded by the admin layout) auto-wires it — no per-field script.
 *
 *   <?= $this->mediaField('hero_image', $page->hero_image, ['kind' => 'image', 'label' => 'Hero image']) ?>
 *
 * Options: kind (restrict type), multiple (bool), label, id.
 *
 * @api
 */
class Tiger_View_Helper_MediaField extends Zend_View_Helper_Abstract
{
    public function mediaField($name, $value = '', array $options = [])
    {
        $esc      = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
        $kind     = (string) ($options['kind'] ?? '');
        $multiple = !empty($options['multiple']) ? '1' : '0';
        $label    = (string) ($options['label'] ?? 'Choose media');
        $id       = (string) ($options['id'] ?? $name);
        $value    = (string) $value;
        $hasVal   = trim($value) !== '';

        // Server-render the preview for an existing single value.
        $preview = '';
        if ($hasVal && $multiple === '0') {
            $model = new Tiger_Model_Media();
            $row   = $model->findById($value);
            if ($row) {
                $m = $row->toArray();
                $preview = ($m['kind'] === 'image')
                    ? '<img src="' . $esc($model->thumbUrl($m)) . '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;">'
                    : '<span class="d-inline-flex align-items-center justify-content-center bg-body-secondary rounded" style="width:56px;height:56px;"><i class="fa-solid fa-file"></i></span>';
            }
        }

        return '<div class="media-field d-flex align-items-center gap-2" data-media-field>'
            . '<div data-media-preview class="d-flex gap-1">' . $preview . '</div>'
            . '<input type="hidden" name="' . $esc($name) . '" id="' . $esc($id) . '" value="' . $esc($value) . '">'
            . '<button type="button" class="btn btn-sm btn-outline-primary" data-media-choose data-kind="' . $esc($kind) . '" data-multiple="' . $multiple . '">'
            . '<i class="fa-solid fa-photo-film me-1"></i>' . $esc($label) . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-media-clear' . ($hasVal ? '' : ' hidden') . '>Clear</button>'
            . '</div>';
    }
}
