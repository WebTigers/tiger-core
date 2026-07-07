<?php
/**
 * Tiger_View_Helper_FormRecaptcha — renders the Google reCAPTCHA widget.
 *
 * The view-helper backing Tiger_Form_Element_Recaptcha (so a form declares
 * `['recaptcha', 'recaptcha', []]` and the markup appears), and also callable directly
 * in a hand-written view as `$this->formRecaptcha()`.
 *
 * v2 -> the checkbox widget. v3 -> loads the score API and, on submit, injects a fresh
 * token into a hidden `g-recaptcha-response` field of the surrounding form. The api.js
 * script is emitted once per render pass. When reCAPTCHA is disabled (or no site key),
 * it renders NOTHING — so a form is unencumbered in dev.
 *
 * @api
 */
class Tiger_View_Helper_FormRecaptcha extends Zend_View_Helper_Abstract
{
    /** Emit the shared <script> only once even with multiple widgets on a page. */
    protected static $_scriptEmitted = false;

    /**
     * @param string $name   element name (v3 uses it as the hidden field name; default fine)
     * @param mixed  $value  ignored (the token is produced by the widget)
     * @param array  $attribs optional: ['action' => 'login'] for v3, ['theme'|'size'] for v2
     * @return string
     */
    public function formRecaptcha($name = 'g-recaptcha-response', $value = null, $attribs = null)
    {
        if (!Tiger_Recaptcha::isEnabled()) {
            return '';
        }
        $site = Tiger_Recaptcha::siteKey();
        if ($site === '') {
            return '';
        }
        $attribs = is_array($attribs) ? $attribs : [];
        $siteEsc = htmlspecialchars($site, ENT_QUOTES);

        return (Tiger_Recaptcha::version() === 'v3')
            ? $this->_v3($site, $siteEsc, $attribs)
            : $this->_v2($siteEsc, $attribs);
    }

    /** v2 checkbox: a div the api.js turns into the widget. */
    protected function _v2($siteEsc, array $attribs)
    {
        $data = 'class="g-recaptcha" data-sitekey="' . $siteEsc . '"';
        if (!empty($attribs['theme'])) {
            $data .= ' data-theme="' . htmlspecialchars($attribs['theme'], ENT_QUOTES) . '"';
        }
        if (!empty($attribs['size'])) {
            $data .= ' data-size="' . htmlspecialchars($attribs['size'], ENT_QUOTES) . '"';
        }
        return '<div ' . $data . '></div>' . $this->_script();
    }

    /**
     * v3 invisible: a hidden field the script fills on submit. Progressive — if JS is
     * off the field stays empty and the server-side validator fails closed.
     */
    protected function _v3($site, $siteEsc, array $attribs)
    {
        $action = isset($attribs['action']) ? preg_replace('/[^A-Za-z0-9_\/]/', '', (string) $attribs['action']) : 'submit';
        $field  = 'g-recaptcha-response';
        $html   = '<input type="hidden" name="' . $field . '" class="g-recaptcha-response">';
        $html  .= '<script src="' . Tiger_Recaptcha::SCRIPT_URL . '?render=' . rawurlencode($site) . '"></script>';
        // Bind every form containing our hidden field: refresh a token just before submit.
        $html  .= '<script>(function(){'
                . 'function bind(){grecaptcha.ready(function(){'
                . 'document.querySelectorAll("input.g-recaptcha-response").forEach(function(inp){'
                . 'var form=inp.form; if(!form||form.__grcBound)return; form.__grcBound=true;'
                . 'form.addEventListener("submit",function(e){'
                . 'if(form.__grcOk)return; e.preventDefault();'
                . 'grecaptcha.execute(' . json_encode($site) . ',{action:' . json_encode($action) . '}).then(function(t){'
                . 'inp.value=t; form.__grcOk=true;'
                . 'if(typeof form.requestSubmit==="function")form.requestSubmit();else form.submit();});'
                . '},true);});});}'
                . 'if(window.grecaptcha)bind();else{var i=setInterval(function(){if(window.grecaptcha){clearInterval(i);bind();}},200);}'
                . '})();</script>';
        return $html;
    }

    /** The api.js loader (emitted once). */
    protected function _script()
    {
        if (self::$_scriptEmitted) {
            return '';
        }
        self::$_scriptEmitted = true;
        return '<script src="' . Tiger_Recaptcha::SCRIPT_URL . '" async defer></script>';
    }
}
