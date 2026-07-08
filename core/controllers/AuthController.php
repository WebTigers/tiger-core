<?php
/**
 * AuthController — the login gateway (default namespace).
 *
 * Guest-accessible (allowed in core acl.ini), because the authorization plugin
 * redirects unauthenticated callers here. Delegates to the Tiger_Service_
 * Authentication kernel service — which is reserved from /api, so login is a thin
 * controller endpoint that returns JSON (the same client/server contract as /api,
 * just not routed through it).
 *
 * URL style: no query strings. Notice flags ride the ZF1 `:action/*` param
 * convention (`/auth/login/out/1`), and the post-auth destination is kept in the
 * SESSION (Tiger_Service_Authentication::setReturnTo/takeReturnTo), never a URL param.
 */
class AuthController extends Tiger_Controller_Action
{
    /** Roles that land on the admin back office after sign-in. */
    const ADMIN_ROLES = ['admin', 'superadmin', 'developer'];

    /**
     * GET  /auth/login            -> the themed sign-in card (auth layout).
     * GET  /auth/login/out/1      -> …with a "signed out" notice.
     * GET  /auth/login/reset/1    -> …with a "password reset" notice.
     * POST /auth/login            -> verify credentials, return JSON {result, redirect}.
     */
    public function loginAction()
    {
        $request = $this->getRequest();
        $auth    = new Tiger_Service_Authentication();

        if ($request->isPost()) {
            // The password step is guest-facing -> bot-gate it (a no-op unless reCAPTCHA
            // is enabled). The 2FA code step is post-password, so it's exempt.
            $code       = (string) $request->getPost('code');
            $twoFaStep  = ($code !== '' && $auth->isTwoFactorPending());
            if (!$twoFaStep && !$this->_recaptchaGate()) {
                return;   // gate emitted the JSON; returnTo left intact for the retry
            }

            // Read the intended destination BEFORE anything regenerates the session
            // (login / verifyTwoFactor both rotate the id on success, wiping the return).
            $return = $auth->takeReturnTo();

            // Second step: a 2FA code against a pending challenge (password already OK).
            if ($twoFaStep) {
                $identity = $auth->verifyTwoFactor($code);
                if ($identity) {
                    $this->_json(['result' => 1, 'data' => $identity, 'redirect' => ($return !== '' ? $return : $this->_roleHome($identity))]);
                } else {
                    if ($return !== '') { $auth->setReturnTo($return); }   // keep it for the retry
                    $this->_json(['result' => 0, 'message' => 'core.api.error.login_failed'], 401);
                }
                return;
            }

            // First step: identifier + password.
            $result = $auth->login((string) $request->getPost('identifier'), (string) $request->getPost('password'));
            if ($result === Tiger_Service_Authentication::TWOFA_REQUIRED) {
                if ($return !== '') { $auth->setReturnTo($return); }   // carry it to the code step
                $this->_json(['result' => 1, 'twofa' => true]);
                return;
            }
            if ($result) {
                $this->_json(['result' => 1, 'data' => $result, 'redirect' => ($return !== '' ? $return : $this->_roleHome($result))]);
            } else {
                if ($return !== '') { $auth->setReturnTo($return); }
                $this->_json(['result' => 0, 'message' => 'core.api.error.login_failed'], 401);
            }
            return;
        }

        // Already signed in? Skip the form.
        if ($auth->isAuthenticated()) {
            $this->_helper->redirector->gotoUrl($this->_roleHome(Zend_Auth::getInstance()->getIdentity()));
            return;
        }

        $this->_helper->layout()->setLayout('auth');
        $this->view->title  = 'Sign in — Tiger';
        $this->view->notice = null;
        if ($request->getParam('reset')) {
            $this->view->notice = 'Your password has been reset — please sign in.';
        } elseif ($request->getParam('out')) {
            $this->view->notice = "You've been signed out.";
        }
    }

    /** Destroy the session and return to the sign-in screen with a confirmation. */
    public function logoutAction()
    {
        (new Tiger_Service_Authentication())->logout();
        $this->_helper->redirector->gotoUrl('/auth/login/out/1');
    }

    /**
     * GET/POST /auth/session — the auto-logout heartbeat. Returns the session's inactivity
     * time-left (JSON) so the client is server-authoritative, not a bare JS timer. Pass
     * `active=1` to report GENUINE user interaction since the last poll (refreshes the
     * clock); an idle poll (`active=0`, the default) reads time-left WITHOUT resetting it.
     * Reachable while locked so the poller keeps working on the lock card.
     */
    public function sessionAction()
    {
        $active = (string) $this->getRequest()->getParam('active') === '1';
        $this->_json((new Tiger_Service_Authentication())->sessionStatus($active));
    }

    /**
     * GET  /auth/lock  -> arm the screen lock + render the lock card.
     * POST /auth/lock  -> unlock by re-verifying the password (JSON {result,redirect}).
     *
     * The authenticated session is untouched; the authorization plugin holds every
     * other route at this card until unlock succeeds, returning the user afterward to
     * wherever they were headed (remembered in session, not a URL param).
     */
    public function lockAction()
    {
        $auth = new Tiger_Service_Authentication();
        if (!$auth->isAuthenticated()) {
            $this->_helper->redirector->gotoUrl('/auth/login');
            return;
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            // Email an unlock code to the (known) identity — for code-based unlock.
            if ($request->getPost('send_code')) {
                $auth->requestUnlockCode();
                $this->_json(['result' => 1]);
                return;
            }

            // Unlock by code (if a code was entered) or by password — both clear the
            // lock and keep the session (a re-verify, not a re-login).
            $code = (string) $request->getPost('code');
            $ok   = ($code !== '')
                ? $auth->unlockWithCode($code)
                : $auth->unlock((string) $request->getPost('password'));

            if ($ok) {
                $return = $auth->takeReturnTo();
                $this->_json(['result' => 1, 'redirect' => ($return !== '' ? $return : $this->_roleHome($auth->getIdentity()))]);
            } else {
                $this->_json(['result' => 0, 'message' => 'core.api.error.login_failed'], 401);
            }
            return;
        }

        $auth->lock();   // arm on view (idempotent)
        $this->_helper->layout()->setLayout('auth');
        $this->view->title    = 'Locked — Tiger';
        $this->view->identity = $auth->getIdentity();
    }

    /**
     * GET  /auth/forgot  -> request-a-reset form.
     * POST /auth/forgot  -> email a reset link. ALWAYS returns JSON success — the
     *                       response never reveals whether the account exists.
     */
    public function forgotAction()
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            if (!$this->_recaptchaGate()) { return; }
            $baseUrl = $request->getScheme() . '://' . $request->getHttpHost();
            (new Tiger_Service_Authentication())->requestPasswordReset((string) $request->getPost('email'), $baseUrl);
            $this->_json(['result' => 1]);
            return;
        }
        $this->_helper->layout()->setLayout('auth');
        $this->view->title = 'Reset password — Tiger';
    }

    /**
     * GET  /auth/reset/cid/…/code/…  -> set-a-new-password form (from the emailed link).
     * POST /auth/reset               -> redeem the token + set the password (JSON).
     */
    public function resetAction()
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            if (!$this->_recaptchaGate()) { return; }
            $res = (new Tiger_Service_Authentication())->resetPassword(
                (string) $request->getPost('cid'),
                (string) $request->getPost('code'),
                (string) $request->getPost('password'),
                (string) $request->getPost('confirm')
            );
            if ($res['ok']) {
                $this->_json(['result' => 1, 'redirect' => '/auth/login/reset/1']);
            } else {
                $this->_json(['result' => 0, 'message' => $res['error']], 400);
            }
            return;
        }
        $this->_helper->layout()->setLayout('auth');
        $this->view->title = 'Set a new password — Tiger';
        $this->view->cid   = (string) $request->getParam('cid', '');
        $this->view->code  = (string) $request->getParam('code', '');
    }

    /**
     * GET  /auth/otp  -> the "sign in with a code" card (email step).
     * POST /auth/otp  -> with a `code`: verify it + establish the session (JSON
     *                    {result, redirect}); without a code: email a login code —
     *                    ALWAYS JSON success (no enumeration; the UI advances to code
     *                    entry either way).
     *
     * Email channel only in this version; an SMS channel plugs in at the service layer
     * (see Tiger_Service_Authentication OTP section). No account enumeration; the code
     * is attempt-limited + expiring in the challenge model.
     */
    public function otpAction()
    {
        $request = $this->getRequest();
        $auth    = new Tiger_Service_Authentication();

        if ($request->isPost()) {
            $email = (string) $request->getPost('email');
            $code  = (string) $request->getPost('code');

            if ($code !== '') {
                $return   = $auth->takeReturnTo();
                $identity = $auth->verifyLoginCode($email, $code);
                if ($identity) {
                    $this->_json(['result' => 1, 'data' => $identity, 'redirect' => ($return !== '' ? $return : $this->_roleHome($identity))]);
                } else {
                    $this->_json(['result' => 0, 'message' => 'core.api.error.login_failed'], 401);
                }
                return;
            }

            if (!$this->_recaptchaGate()) { return; }   // gate the code-request step (bot/abuse vector)
            $auth->requestLoginCode($email);
            $this->_json(['result' => 1]);   // always the same — no account enumeration
            return;
        }

        $this->_helper->layout()->setLayout('auth');
        $this->view->title = 'Sign in with a code — Tiger';
    }

    /**
     * GET /auth/security -> the "Two-factor authentication" management screen, in the
     * admin shell. Shows enrollment (QR + manual key + confirm) or, once enabled, the
     * recovery-code count + a disable control. Signed-in users only.
     *
     * The mutations are AJAX to the sibling actions below (thin controller, JSON out) —
     * the same client/server contract as the rest of auth.
     */
    public function securityAction()
    {
        $auth = new Tiger_Service_Authentication();
        if (!$auth->isAuthenticated()) {
            $auth->setReturnTo('/auth/security');
            $this->_helper->redirector->gotoUrl('/auth/login');
            return;
        }
        if ($auth->isLocked()) {   // a locked screen must re-verify before touching 2FA
            $this->_helper->redirector->gotoUrl('/auth/lock');
            return;
        }
        $this->_helper->layout()->setLayout('admin');
        $this->view->title = 'Two-Factor Authentication — Tiger Admin';
        $this->view->twofa = $auth->getTwoFactorStatus();
    }

    /**
     * POST /auth/totp-setup -> begin enrollment: returns the base32 secret, the
     * otpauth:// URI (rendered client-side as a QR), and the one-time-shown recovery
     * codes. Nothing persists until totp-activate confirms a live code.
     */
    public function totpSetupAction()
    {
        $auth = new Tiger_Service_Authentication();
        if (!$auth->isAuthenticated() || $auth->isLocked()) {
            $this->_json(['result' => 0, 'message' => 'core.api.error.not_allowed'], 401);
            return;
        }
        $data = $auth->beginTotpEnrollment();
        if (!$data) {
            $this->_json(['result' => 0, 'message' => 'core.auth.twofa.unavailable'], 400);
            return;
        }
        $this->_json(['result' => 1, 'data' => $data]);
    }

    /** POST /auth/totp-activate -> confirm enrollment with a live code (JSON {result}). */
    public function totpActivateAction()
    {
        $auth = new Tiger_Service_Authentication();
        if (!$auth->isAuthenticated() || $auth->isLocked()) {
            $this->_json(['result' => 0, 'message' => 'core.api.error.not_allowed'], 401);
            return;
        }
        if ($auth->activateTotp((string) $this->getRequest()->getPost('code'))) {
            $this->_json(['result' => 1, 'message' => 'core.auth.twofa.enabled']);
        } else {
            $this->_json(['result' => 0, 'message' => 'core.auth.twofa.bad_code'], 400);
        }
    }

    /** POST /auth/totp-disable -> turn 2FA off; requires a current TOTP or recovery code. */
    public function totpDisableAction()
    {
        $auth = new Tiger_Service_Authentication();
        if (!$auth->isAuthenticated() || $auth->isLocked()) {
            $this->_json(['result' => 0, 'message' => 'core.api.error.not_allowed'], 401);
            return;
        }
        if ($auth->disableTotp((string) $this->getRequest()->getPost('code'))) {
            $this->_json(['result' => 1, 'message' => 'core.auth.twofa.disabled']);
        } else {
            $this->_json(['result' => 0, 'message' => 'core.auth.twofa.bad_code'], 400);
        }
    }

    /** GET /auth/me -> the current identity as JSON (handy for clients + verification). */
    public function meAction()
    {
        $identity = (new Tiger_Service_Authentication())->getIdentity();
        $this->_json(['result' => $identity ? 1 : 0, 'data' => $identity]);
    }

    /** Role-appropriate landing after auth: admin back office for admin+, else public home. */
    protected function _roleHome($identity)
    {
        $role = ($identity && isset($identity->role)) ? $identity->role : '';
        return in_array($role, self::ADMIN_ROLES, true) ? '/admin' : '/';
    }

    /**
     * Bot gate for the guest auth forms. When reCAPTCHA is disabled this is a no-op
     * (returns true); when enabled it verifies the posted token and, on failure, emits
     * the JSON error itself (with a `recaptcha` flag the client uses to reset the
     * widget) and returns false — so callers just `if (!$this->_recaptchaGate()) return;`.
     */
    protected function _recaptchaGate()
    {
        if (!Tiger_Recaptcha::isEnabled()) {
            return true;
        }
        if ((new Tiger_Validate_Recaptcha())->isValid(null, $this->getRequest()->getPost())) {
            return true;
        }
        $this->_json(['result' => 0, 'recaptcha' => true, 'message' => 'core.form.recaptcha.failed'], 400);
        return false;
    }
}
