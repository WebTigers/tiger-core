<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Policy_Password — the configurable password policy.
 *
 * Wraps the credential + history models to validate a candidate password. Called
 * by a change-password / signup flow BEFORE Tiger_Model_UserCredential::setPassword
 * (which is the data operation + history archive). Config-driven from
 * `tiger.password.*` — and because it reads the RESOLVED Zend_Config, the policy is
 * per-app AND per-org (an org can tighten it via a config row).
 *
 * NIST-informed defaults (SP 800-63B):
 *   - min_length is the primary strength lever.
 *   - history (reuse-prevention) is the common compliance requirement.
 *   - require_complexity: OFF by default — composition rules ('Password1!') are
 *     discouraged. Enable for orgs that must comply.
 *   - max_age_days: OFF by default — forced periodic rotation is discouraged.
 *     Enable for orgs that must comply.
 *
 * @api
 */
class Tiger_Policy_Password
{
    /** Effective policy (defaults <- tiger.password.* in the resolved, per-org config). */
    public function config()
    {
        $defaults = [
            'min_length'         => 8,
            'history'            => 5,   // disallow reusing the last N passwords (0 = off)
            'require_complexity' => 0,   // off (length beats composition)
            'max_age_days'       => 0,   // off (no forced rotation)
        ];

        $node = null;
        if (Zend_Registry::isRegistered('Zend_Config')) {
            $c = Zend_Registry::get('Zend_Config');
            if ($c->get('tiger') && $c->tiger->get('password')) {
                $node = $c->tiger->password;
            }
        }
        $cfg = $defaults;
        if ($node) {
            foreach ($defaults as $key => $default) {
                $val = $node->get($key);
                if ($val !== null) { $cfg[$key] = (int) $val; }
            }
        }
        return $cfg;
    }

    /**
     * Validate a candidate password. Returns violation keys (empty array = OK) so a
     * service can surface them as response messages.
     *
     * @param  string      $plain
     * @param  string|null $userId when given, enables reuse-prevention against the
     *                             current + retired hashes
     * @return string[]
     */
    public function validate($plain, $userId = null)
    {
        $cfg   = $this->config();
        $plain = (string) $plain;
        $out   = [];

        if (strlen($plain) < $cfg['min_length']) {
            $out[] = 'password.too_short';
        }
        if ($cfg['require_complexity'] && !$this->_isComplex($plain)) {
            $out[] = 'password.needs_complexity';
        }
        if ($userId !== null && $cfg['history'] > 0 && $this->_isReused($userId, $plain, $cfg['history'])) {
            $out[] = 'password.reused';
        }
        return $out;
    }

    /** Convenience boolean. */
    public function isValid($plain, $userId = null)
    {
        return $this->validate($plain, $userId) === [];
    }

    /** Has the user's password exceeded max_age_days? (Always false when expiry is off.) */
    public function isExpired($userId)
    {
        $cfg = $this->config();
        if ($cfg['max_age_days'] <= 0) {
            return false;
        }
        $cred = (new Tiger_Model_UserCredential())->passwordCredential($userId);
        if (!$cred || $cred->verified_at === null) {
            return false;
        }
        return (time() - strtotime($cred->verified_at)) > ($cfg['max_age_days'] * 86400);
    }

    protected function _isComplex($p)
    {
        return preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p)
            && preg_match('/[0-9]/', $p) && preg_match('/[^a-zA-Z0-9]/', $p);
    }

    /**
     * Does the candidate match the current or any of the last N retired hashes?
     * Runs a bcrypt verify per stored hash — deliberately not cheap, but a password
     * change is rare, so the cost is fine.
     */
    protected function _isReused($userId, $plain, $count)
    {
        $cred = (new Tiger_Model_UserCredential())->passwordCredential($userId);
        if ($cred && $cred->secret !== null && $this->_match($plain, $cred->secret)) {
            return true;
        }
        foreach ((new Tiger_Model_PasswordHistory())->recentForUser($userId, $count) as $row) {
            if ($this->_match($plain, $row->secret)) {
                return true;
            }
        }
        return false;
    }

    /** Match a candidate against a stored hash under the current (peppered) scheme, and —
     *  when a pepper is set — also the legacy raw scheme, so reuse is caught across the
     *  pepper migration boundary. */
    protected function _match($plain, $hash)
    {
        foreach (Tiger_Security::passwordVerifiers((string) $plain) as $candidate) {
            if (password_verify($candidate, (string) $hash)) {
                return true;
            }
        }
        return false;
    }
}
