<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Service_Token — a user manages their own personal access tokens (stateless `/api` credentials).
 *
 * `create()` mints one (the plaintext is returned ONCE); `all()` lists them (prefix + timestamps,
 * never the secret); `revoke()` soft-deletes one the caller owns. You mint from a normal (session)
 * login; the token then authenticates future `/api` calls as `Authorization: Bearer <token>` with no
 * session at all. Any authenticated user may manage their OWN tokens — ownership is enforced here.
 * See WEBSERVICES.md §8.
 *
 * @api
 */
class Tiger_Service_Token extends Tiger_Service_Service
{
    /**
     * Mint a personal access token for the current user. The plaintext is shown ONCE.
     *
     * @param  array $params (none)
     * @return void
     */
    public function create(array $params): void
    {
        $userId = $this->_currentUserId();
        if ($userId === null) { $this->_error('core.api.error.login_required'); return; }
        $r = (new Tiger_Model_UserCredential())->createToken($userId);
        $this->_success(['token' => $r['token'], 'prefix' => $r['prefix']], 'core.token.created');
    }

    /**
     * List the current user's active tokens (prefix + timestamps; never the secret).
     *
     * @param  array $params (none)
     * @return void
     */
    public function all(array $params): void
    {
        $userId = $this->_currentUserId();
        if ($userId === null) { $this->_error('core.api.error.login_required'); return; }
        $this->_success(['tokens' => (new Tiger_Model_UserCredential())->tokensFor($userId)]);
    }

    /**
     * Revoke one of the current user's tokens.
     *
     * @param  array $params {credential_id: string}
     * @return void
     */
    public function revoke(array $params): void
    {
        $userId = $this->_currentUserId();
        if ($userId === null) { $this->_error('core.api.error.login_required'); return; }
        $id = (string) ($params['credential_id'] ?? '');
        if ($id === '') { $this->_error('core.api.error.general'); return; }
        (new Tiger_Model_UserCredential())->revokeToken($userId, $id);
        $this->_success([], 'core.token.revoked');
    }

    /** The authenticated user's id (works in both session and token mode), or null. */
    protected function _currentUserId()
    {
        $identity = Zend_Auth::getInstance()->getIdentity();
        return $identity->user_id ?? null;
    }
}
