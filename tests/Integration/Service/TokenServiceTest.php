<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Ajax_ServiceFactory;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Tiger_Service_Token;
use Zend_Acl;
use Zend_Acl_Resource;
use Zend_Acl_Role;
use Zend_Auth;
use Zend_Controller_Request_Http;
use Zend_Registry;

/**
 * Tiger_Service_Token — a user manages their OWN personal access tokens over the service surface.
 * Driven against the real `user_credential` store with a signed-in identity (the base's login()): mint
 * (plaintext shown once), list (prefix + timestamps, never the secret), revoke (ownership-guarded), and
 * the login-required guard for every action when no one is signed in.
 */
#[CoversClass(Tiger_Service_Token::class)]
final class TokenServiceTest extends IntegrationTestCase
{
    /** Create an active user and sign them in for the request. */
    private function signedInUser(): string
    {
        $uid = (new Tiger_Model_User())->insert([
            'email'  => 'tok-' . bin2hex(random_bytes(8)) . '@example.test',
            'status' => 'active',
        ]);
        $this->login($uid);   // writes a non-persistent Zend_Auth identity {user_id: $uid}
        return $uid;
    }

    private function response(array $message): object
    {
        return (new Tiger_Service_Token($message))->getResponse();
    }

    // ----- create ------------------------------------------------------------

    #[Test]
    public function create_mints_a_token_and_returns_the_plaintext_once(): void
    {
        $this->signedInUser();
        $res = $this->response(['action' => 'create']);

        $this->assertSame(1, $res->result);
        $this->assertNotEmpty($res->data['token'], 'the plaintext token is returned exactly once');
        $this->assertStringStartsWith('tgr_', $res->data['token']);
        $this->assertNotEmpty($res->data['prefix']);
        $this->assertSame($res->data['prefix'] . '', substr($res->data['token'], 4, 12), 'the returned prefix matches the token');
    }

    // ----- all ---------------------------------------------------------------

    #[Test]
    public function all_lists_the_users_tokens_by_prefix_never_the_secret(): void
    {
        $this->signedInUser();
        $minted = $this->response(['action' => 'create'])->data;

        $res = $this->response(['action' => 'all']);
        $this->assertSame(1, $res->result);
        $this->assertNotEmpty($res->data['tokens']);
        $row = $res->data['tokens'][0];
        $this->assertSame($minted['prefix'], $row['prefix']);
        $this->assertArrayHasKey('credential_id', $row);
        $this->assertArrayNotHasKey('secret', $row, 'the secret is never listed');
    }

    // ----- revoke ------------------------------------------------------------

    #[Test]
    public function revoke_soft_deletes_a_token_the_caller_owns(): void
    {
        $uid = $this->signedInUser();
        $this->response(['action' => 'create']);
        $credId = (new Tiger_Model_UserCredential())->tokensFor($uid)[0]['credential_id'];

        $res = $this->response(['action' => 'revoke', 'credential_id' => $credId]);
        $this->assertSame(1, $res->result);
        $this->assertSame([], (new Tiger_Model_UserCredential())->tokensFor($uid), 'the revoked token no longer lists');
    }

    #[Test]
    public function revoke_without_a_credential_id_is_an_error(): void
    {
        $this->signedInUser();
        $res = $this->response(['action' => 'revoke']);
        $this->assertSame(0, $res->result);
        $this->assertSame('core.api.error.general', $res->messages[0]->message);
    }

    #[Test]
    public function revoke_only_touches_the_callers_own_token(): void
    {
        // Owner A mints a token.
        $ownerA = (new Tiger_Model_User())->insert(['email' => 'a-' . bin2hex(random_bytes(6)) . '@ex.test', 'status' => 'active']);
        $aTokenId = (new Tiger_Model_UserCredential())->createToken($ownerA)['credential_id'];

        // Attacker B tries to revoke A's token id.
        $this->signedInUser();
        $res = $this->response(['action' => 'revoke', 'credential_id' => $aTokenId]);

        $this->assertSame(1, $res->result, 'the call succeeds shape-wise (no enumeration)');
        $this->assertNotEmpty((new Tiger_Model_UserCredential())->tokensFor($ownerA), "A's token is untouched — ownership guards the WHERE");
    }

    // ----- login-required guard ----------------------------------------------

    #[Test]
    public function every_action_requires_a_signed_in_user(): void
    {
        Zend_Auth::getInstance()->clearIdentity();   // guest
        foreach (['create', 'all', 'revoke'] as $action) {
            $res = $this->response(['action' => $action]);
            $this->assertSame(0, $res->result, "$action is refused for a guest");
            $this->assertSame('core.api.error.login_required', $res->messages[0]->message);
        }
    }

    // ----- stateless Bearer-token dispatch through the /api gateway -----------

    #[Test]
    public function a_bearer_token_authenticates_an_api_call_statelessly_through_the_gateway(): void
    {
        // Covers Tiger_Ajax_ServiceFactory's Authorization: Bearer path — the token resolves the request
        // identity WITHOUT a session, then the same call the browser makes (module=tiger,service=token)
        // dispatches as that user. Kernel Tiger_Service_* is reachable over /api gated purely by ACL.
        $uid   = (new Tiger_Model_User())->insert(['email' => 'bearer-' . bin2hex(random_bytes(6)) . '@ex.test', 'status' => 'active']);
        $token = (new Tiger_Model_UserCredential())->createToken($uid)['token'];

        // Allow the base authenticated role to reach the token service (a fresh user resolves to 'user').
        $acl = new Zend_Acl();
        $acl->addRole(new Zend_Acl_Role('guest'));
        $acl->addRole(new Zend_Acl_Role('user'), 'guest');
        $acl->addResource(new Zend_Acl_Resource('Tiger_Service_Token'));
        $acl->allow('user', 'Tiger_Service_Token');
        Zend_Registry::set('Zend_Acl', $acl);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        try {
            $req = new Zend_Controller_Request_Http();
            $req->setParam('svc_module', 'tiger');
            $req->setParam('svc_service', 'token');
            $req->setParam('svc_action', 'all');

            $res = (new Tiger_Ajax_ServiceFactory($req))->getResponse();

            $this->assertSame(1, $res->result, 'the token authenticates the call end-to-end');
            $this->assertNotEmpty($res->data['tokens'], "the caller's own token lists");
            $this->assertTrue(
                (bool) Zend_Registry::get('tiger.auth.stateless'),
                'the request is flagged stateless (CSRF-exempt) for a token client'
            );
        } finally {
            unset($_SERVER['HTTP_AUTHORIZATION']);
            $reg = Zend_Registry::getInstance();
            if ($reg->offsetExists('Zend_Acl')) { $reg->offsetUnset('Zend_Acl'); }
            if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        }
    }
}
