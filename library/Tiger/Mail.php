<?php
/**
 * Tiger_Mail — a thin, fluent wrapper over Zend_Mail.
 *
 * Build a message and send it; the TRANSPORT is resolved from the config cascade
 * (so per-deploy overrides + secrets live in local.ini, never in code):
 *
 *   mail.transport            = mail | smtp     (default: mail = PHP sendmail())
 *   mail.from.email / .name   = default sender
 *   mail.smtp.host/port/ssl/auth/username/password
 *
 *   (new Tiger_Mail())
 *       ->to('ada@example.com', 'Ada')
 *       ->subject('Reset your password')
 *       ->html('<p>Click <a href="…">here</a></p>')
 *       ->send();
 *
 * A plain-text alternative is auto-derived from the HTML when not set explicitly, so
 * every message is multipart (deliverability). send() throws on a transport failure —
 * callers (the auth services) wrap it and degrade gracefully rather than 500.
 *
 * @api
 */
class Tiger_Mail
{
    /** @var array<int,array{0:string,1:string}> */
    protected $_to = [];
    /** @var array{0:string,1:string}|null */
    protected $_from;
    /** @var array{0:string,1:string}|null */
    protected $_replyTo;
    protected $_subject = '';
    protected $_html;
    protected $_text;
    /** @var Zend_Config|null */
    protected $_config;

    public function __construct()
    {
        $this->_config = Zend_Registry::isRegistered('Zend_Config')
            ? Zend_Registry::get('Zend_Config')
            : null;
    }

    public function to($email, $name = '')      { $this->_to[]    = [(string) $email, (string) $name]; return $this; }
    public function from($email, $name = '')    { $this->_from    = [(string) $email, (string) $name]; return $this; }
    public function replyTo($email, $name = '') { $this->_replyTo = [(string) $email, (string) $name]; return $this; }
    public function subject($subject)           { $this->_subject = (string) $subject; return $this; }
    public function html($html)                 { $this->_html    = (string) $html; return $this; }
    public function text($text)                 { $this->_text    = (string) $text; return $this; }

    /**
     * Send the message. Pass a transport to override the config-resolved one (handy
     * for tests — e.g. a Zend_Mail_Transport_File that captures instead of delivers).
     * Throws Zend_Mail_Exception / transport exceptions on failure.
     */
    public function send($transport = null)
    {
        $mail = new Zend_Mail('UTF-8');

        [$fromEmail, $fromName] = $this->_from ?: $this->_configFrom();
        $mail->setFrom($fromEmail, $fromName);

        foreach ($this->_to as $t) { $mail->addTo($t[0], $t[1]); }
        if ($this->_replyTo) { $mail->setReplyTo($this->_replyTo[0], $this->_replyTo[1]); }
        $mail->setSubject($this->_subject);

        if ($this->_html !== null && $this->_html !== '') {
            $mail->setBodyHtml($this->_html);
            $mail->setBodyText($this->_text !== null ? $this->_text : $this->_htmlToText($this->_html));
        } else {
            $mail->setBodyText((string) $this->_text);
        }

        $mail->send($transport ?: $this->transport());
        return $this;
    }

    /**
     * The transport for the active config: SMTP (with TLS/auth) when
     * mail.transport=smtp AND a host is set, else PHP mail() (sendmail).
     *
     * @return Zend_Mail_Transport_Abstract
     */
    public function transport()
    {
        $m    = $this->_config ? $this->_config->get('mail') : null;
        $type = ($m && $m->get('transport')) ? strtolower((string) $m->transport) : 'mail';

        if ($type === 'smtp' && $m && $m->get('smtp') && (string) $m->smtp->get('host') !== '') {
            $s    = $m->smtp;
            $opts = [];
            if ((string) $s->get('auth') !== '') {
                $opts['auth']     = (string) $s->auth;
                $opts['username'] = (string) $s->get('username');
                $opts['password'] = (string) $s->get('password');
            }
            if ((string) $s->get('port') !== '') { $opts['port'] = (int) $s->port; }
            if ((string) $s->get('ssl') !== '')  { $opts['ssl']  = (string) $s->ssl; }
            return new Zend_Mail_Transport_Smtp((string) $s->host, $opts);
        }

        return new Zend_Mail_Transport_Sendmail();   // boring, reliable PHP mail()
    }

    /** The configured default sender ([email, name]). */
    protected function _configFrom()
    {
        $from = ($this->_config && $this->_config->get('mail')) ? $this->_config->mail->get('from') : null;
        return [
            ($from && (string) $from->get('email') !== '') ? (string) $from->email : 'no-reply@localhost',
            ($from && (string) $from->get('name')  !== '') ? (string) $from->name  : 'Tiger',
        ];
    }

    /** A readable plain-text fallback from an HTML body (links preserved as text). */
    protected function _htmlToText($html)
    {
        $text = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', (string) $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|table)>/i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}
