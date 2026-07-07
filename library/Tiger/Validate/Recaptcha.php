<?php
/**
 * Tiger_Validate_Recaptcha — server-side validation of a Google reCAPTCHA response.
 *
 * A standard Zend_Validate you can attach to any element (or, via
 * Tiger_Form_Element_Recaptcha, get automatically). It reads the widget's token from
 * the form context (the widget always posts the fixed field `g-recaptcha-response`),
 * verifies it with Google (Tiger_Recaptcha::verify), and — for v3 — enforces the score
 * threshold and, optionally, the expected action.
 *
 * When reCAPTCHA is disabled in config it passes through, so forms validate normally in
 * dev without keys. See Tiger_Recaptcha for the fail-open-on-outage policy.
 *
 * @api
 */
class Tiger_Validate_Recaptcha extends Zend_Validate_Abstract
{
    const MISSING = 'recaptchaMissing';
    const FAILED  = 'recaptchaFailed';
    const ERROR   = 'recaptchaError';

    /** Message keys (semantic i18n keys, resolved by the registered translator). */
    protected $_messageTemplates = [
        self::MISSING => 'core.form.recaptcha.missing',
        self::FAILED  => 'core.form.recaptcha.failed',
        self::ERROR   => 'core.form.recaptcha.error',
    ];

    /** Optional expected v3 action name (defense against token replay across forms). */
    protected $_action;

    public function __construct($options = null)
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }
        if (is_array($options) && isset($options['action'])) {
            $this->_action = (string) $options['action'];
        }
    }

    /**
     * @param  mixed $value   the element value (usually empty — the real token is in context)
     * @param  array $context the full form data, carrying `g-recaptcha-response`
     * @return bool
     */
    public function isValid($value, $context = null)
    {
        if (!Tiger_Recaptcha::isEnabled()) {
            return true;   // feature off -> pass-through
        }

        // The widget posts a fixed field name regardless of the element name.
        $token = '';
        if (is_array($context) && !empty($context['g-recaptcha-response'])) {
            $token = (string) $context['g-recaptcha-response'];
        } elseif (!empty($value)) {
            $token = (string) $value;
        }
        $this->_setValue($token);

        if ($token === '') {
            $this->_error(self::MISSING);
            return false;
        }

        $resp = Tiger_Recaptcha::verify($token, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);

        if ($resp === null) {
            // Couldn't reach Google — apply the configured availability policy.
            if (Tiger_Recaptcha::failOpen()) {
                return true;
            }
            $this->_error(self::ERROR);
            return false;
        }

        $success = !empty($resp['success']);

        if ($success && Tiger_Recaptcha::version() === 'v3') {
            $score = isset($resp['score']) ? (float) $resp['score'] : 0.0;
            if ($score < Tiger_Recaptcha::minScore()) {
                $success = false;
            }
            if ($success && $this->_action !== null
                && isset($resp['action']) && $resp['action'] !== $this->_action) {
                $success = false;
            }
        }

        if (!$success) {
            $this->_error(self::FAILED);
            return false;
        }
        return true;
    }
}
