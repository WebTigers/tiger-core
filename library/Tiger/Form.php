<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
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
    /** CSRF token lifetime, seconds (must be > 0). Override in a subclass if needed. */
    const CSRF_TIMEOUT = 7200;

    /**
     * @var callable translate helper backing $this->_t(). Named `_translateFn`, NOT
     * `_translator` — the latter is Zend_Form's own property (a Zend_Translate), and
     * shadowing it with a closure makes the form hand that closure to every validator
     * ("Invalid translator specified"). Kept separate so Zend_Form's real translator
     * (the registered Zend_Translate) still localizes validation messages.
     */
    protected $_translateFn;

    /**
     * Build the form: POST method, ViewHelper-only decorators, CSRF, translate helper, and
     * the declared elements.
     *
     * @return void
     */
    public function init()
    {
        $this->setMethod('post');
        $this->setDecorators([]);   // no <form> chrome; the view renders fields itself

        // Tiger's own element types (e.g. 'recaptcha' -> Tiger_Form_Element_Recaptcha).
        $this->addPrefixPath('Tiger_Form_Element', 'Tiger/Form/Element/', self::ELEMENT);

        $translate = Zend_Registry::isRegistered('Zend_Translate')
            ? Zend_Registry::get('Zend_Translate')
            : null;
        $this->_translateFn = static function ($key) use ($translate) {
            return ($translate && $translate->isTranslated($key)) ? $translate->translate($key) : $key;
        };

        // CSRF: a per-session token every form carries by default. `timeout` is the
        // token's lifetime in SECONDS and must be positive — Zend_Form_Element_Hash
        // rejects 0 ("Seconds must be positive"), which silently breaks the token.
        // Two hours is generous enough for long edits without outliving the session.
        // CSRF is a COOKIE-mode defense. A STATELESS token request (Authorization: Bearer …) carries
        // no session cookie, so it's CSRF-immune by construction and MUST skip the check — see the
        // per-mode design in WEBSERVICES.md §8. The gateway flags token requests via the registry.
        if ($this->csrf() && !(Zend_Registry::isRegistered('tiger.auth.stateless') && Zend_Registry::get('tiger.auth.stateless'))) {
            $this->addElement('hash', '_csrf', ['salt' => static::class, 'timeout' => static::CSRF_TIMEOUT]);
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
        return ($this->_translateFn)($key);
    }

    /**
     * Convenience validation: validate ONE field against $value, returning the first error.
     * This is the server-side check TigerValidateJS runs on blur — the same validators that
     * run at submit, applied per-field as you tab through the form, so a value is known-good
     * before the user ever clicks the button. $context is the rest of the posted form, so
     * cross-field validators (e.g. a password-confirm Identical, a NoRecordExists uniqueness
     * check) have what they need. Unknown/CSRF field → valid (never block on those; the real
     * isValid() at submit is still authoritative).
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  array  $context the other submitted field values
     * @return array{valid:bool,message:string}
     */
    public function convenienceValidate(string $name, $value, array $context = []): array
    {
        if ($name === '' || $name === '_csrf') {
            return ['valid' => true, 'message' => ''];
        }
        $el = $this->getElement($name);
        if (!$el) {
            return ['valid' => true, 'message' => ''];
        }
        if ($el->isValid($value, $context)) {
            return ['valid' => true, 'message' => ''];
        }
        $messages = $el->getMessages();   // keyed by failure; values already localized
        return ['valid' => false, 'message' => (string) reset($messages)];
    }
}
