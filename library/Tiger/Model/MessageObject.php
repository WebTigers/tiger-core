<?php
/**
 * Tiger_Model_MessageObject — one feedback message in the API response envelope.
 *
 * Ported from AskLevi's proven Levi_Model_MessageObject. `class` maps to a
 * Bootstrap alert context (success/error/alert/info) the client renders. The
 * message is treated as a translation KEY: it's translated in the current locale,
 * falling back to the default language, and finally to the raw string (so plain
 * text passed in still works). This keeps every API message localized without the
 * service having to think about it.
 *
 * @api
 */
class Tiger_Model_MessageObject
{
    /** @var string translated (or literal) message text */
    public $message;

    /** @var string success | error | alert | info */
    public $class;

    /** @var string|null field this message attaches to, if any */
    public $field;

    public function __construct($message, $class = 'info', $field = null)
    {
        $translate = Zend_Registry::isRegistered('Zend_Translate')
            ? Zend_Registry::get('Zend_Translate')
            : null;

        $default = defined('SUPPORTED_LANGS') ? (SUPPORTED_LANGS[0] ?? null) : null;

        if ($translate && $translate->isTranslated($message)) {
            $this->message = $translate->translate($message);
        } elseif ($translate && $default && $translate->isTranslated($message, $default)) {
            $this->message = $translate->translate($message, $default);
        } else {
            $this->message = $message;   // no translations wired, or plain text
        }

        $this->class = $class;
        $this->field = $field;
    }
}
