<?php
/**
 * Access_Form_Org — create/edit an organization (tenant).
 *
 * name + a URL-safe slug (unique) + an optional parent org (self-referential
 * hierarchy) + status. The parent dropdown is built from the live org list; pass the
 * org being edited to the constructor to exclude it from its own parent options (a
 * belt-and-braces guard — the service also rejects parent == self). Slug uniqueness
 * and the self-parent guard live in Access_Service_Org::save.
 */
class Access_Form_Org extends Tiger_Form
{
    /** @var string org_id to exclude from the parent options (the org being edited). */
    protected $_excludeId = '';

    public function __construct($excludeId = '', $options = null)
    {
        $this->_excludeId = (string) $excludeId;   // set before parent ctor -> init() -> elements()
        parent::__construct($options);
    }

    protected function elements(): array
    {
        $control = ['class' => 'form-control'];
        $select  = ['class' => 'form-select'];

        $parentOpts = ['' => '— none (root organization) —'];
        $orgModel   = new Tiger_Model_Org();
        foreach ($orgModel->fetchAll($orgModel->activeSelect()->order('name ASC')) as $o) {
            if ($o->org_id === $this->_excludeId) { continue; }   // can't be its own parent
            $parentOpts[$o->org_id] = $o->name;
        }

        return [
            ['hidden', 'org_id', []],

            ['text', 'name', [
                'required' => true,
                'filters'  => ['StringTrim'],
                'attribs'  => array_merge($control, ['id' => 'access-org-name', 'maxlength' => 255]),
            ]],

            ['text', 'slug', [
                'filters' => ['StringTrim'],
                'attribs' => array_merge($control, ['id' => 'access-org-slug', 'maxlength' => 191]),
            ]],

            ['select', 'parent_org_id', [
                'multiOptions' => $parentOpts,
                'attribs'      => array_merge($select, ['id' => 'access-org-parent']),
            ]],

            ['select', 'status', [
                'multiOptions' => ['active' => 'Active', 'suspended' => 'Suspended'],
                'value'        => 'active',
                'attribs'      => array_merge($select, ['id' => 'access-org-status']),
            ]],
        ];
    }
}
