<?php
/**
 * Code_Form_Code — create/edit a snippet. Declarative Tiger_Form schema (the view owns
 * markup; the base adds CSRF). Only `name` is required; `code` is validated by an actual
 * `php -l` in the service (not StripTags-filtered — it's source). v1 forces language=php +
 * run_location=global in the service, so this form stays lean.
 */
class Code_Form_Code extends Tiger_Form
{
    protected function elements(): array
    {
        $control = ['class' => 'form-control'];

        return [
            ['hidden', 'code_id', []],

            ['text', 'name', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'code-name', 'maxlength' => 191]),
            ]],

            ['text', 'description', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'code-description', 'maxlength' => 255]),
            ]],

            ['text', 'priority', [
                'filters'    => ['StringTrim'],
                'validators' => [['Int']],
                'value'      => 100,
                'attribs'    => array_merge($control, ['id' => 'code-priority', 'type' => 'number']),
            ]],

            ['checkbox', 'active', [
                'attribs' => ['id' => 'code-active'],
            ]],

            ['textarea', 'code', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'code-code', 'rows' => 22, 'spellcheck' => 'false']),
            ]],
        ];
    }
}
