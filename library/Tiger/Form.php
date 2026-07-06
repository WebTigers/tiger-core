<?php
/**
 * Tiger_Form — base class for every Tiger form.
 *
 * Forms are Zend_Form subclasses configured with ARRAY config (never .ini), and
 * they're AJAX-submitted: the VIEW owns all markup, so the base strips element
 * decorators to ViewHelper-only. A subclass declares just its elements — either
 * declaratively via elements() (preferred, most human-readable), or imperatively
 * via build() with addElement() calls:
 *
 *   class Billing_Form_Invoice extends Tiger_Form
 *   {
 *       protected function elements(): array
 *       {
 *           return [
 *               ['text', 'amount', [
 *                   'required'   => true,
 *                   'filters'    => ['StringTrim'],
 *                   'validators' => [['Float']],
 *                   'attribs'    => ['class' => 'form-control', 'placeholder' => $this->_t('invoice.amount')],
 *               ]],
 *               ['textarea', 'memo', [
 *                   'filters'    => ['StringTrim', 'StripTags'],
 *                   'attribs'    => ['class' => 'form-control', 'rows' => 3],
 *               ]],
 *           ];
 *       }
 *   }
 *
 * Baked in for every form: POST method, ViewHelper-only decorators, a translate
 * closure ($this->_t('some.key')), and CSRF (a hidden token element; override
 * csrf() to disable for an idempotent search/filter form). Validate in a service
 * with isValid()/isValidPartial() — see Tiger_Service_Service::_transaction() for
 * the canonical validate-then-transaction flow.
 *
 * @api
 */
class Tiger_Form extends Zend_Form
{
    /** @var callable translate helper backing $this->_t() */
    protected $_translator;

    public function init()
    {
        $this->setMethod('post');
        $this->setDecorators([]);   // no <form> chrome; the view renders fields itself

        $translate = Zend_Registry::isRegistered('Zend_Translate')
            ? Zend_Registry::get('Zend_Translate')
            : null;
        $this->_translator = static function ($key) use ($translate) {
            return ($translate && $translate->isTranslated($key)) ? $translate->translate($key) : $key;
        };

        // CSRF: a per-session token every form carries by default.
        if ($this->csrf()) {
            $this->addElement('hash', '_csrf', ['salt' => static::class, 'timeout' => 0]);
        }

        // Declarative schema: [type, name, options].
        foreach ($this->elements() as $spec) {
            $this->addElement($spec[0], $spec[1], $spec[2] ?? []);
        }

        // Imperative hook (alternative/complement to elements()).
        $this->build();

        // The view owns markup — strip every element to its ViewHelper.
        $this->setElementDecorators(['ViewHelper']);
    }

    /** Override: return an array of [type, name, options] element specs. */
    protected function elements(): array
    {
        return [];
    }

    /** Override: add elements imperatively (addElement/addElements). */
    protected function build(): void
    {
    }

    /** Override to disable CSRF (e.g. an idempotent GET-style search/filter form). */
    protected function csrf(): bool
    {
        return true;
    }

    /** Translate a semantic key (returns the key itself when untranslated). */
    protected function _t(string $key): string
    {
        return ($this->_translator)($key);
    }
}
