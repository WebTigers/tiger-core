<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Signup_Service_Signup — the functional account signup (/api service=signup).
 *
 * validate → transaction → create the tenant graph, then email a verification link:
 *   org (slug derived from the company name, uniquified)
 *   user (status = 'pending' — Authentication::login rejects non-active users, so the
 *         account is effectively guest until the email is verified)
 *   org_user membership (role = 'user')
 *   address + contact, each linked to BOTH the org and the user (org_address / user_address
 *         / org_contact / user_contact)
 * verifyEmail() redeems the email_verify challenge and flips the user to 'active' — from
 * then on they can sign in as role 'user'. ACL-gated to guest (public signup).
 */
class Signup_Service_Signup extends Tiger_Service_Service
{
    const VERIFY_TTL = 86400;   // 24h

    public function create(array $params): void
    {
        // No in-service gate needed: the /api ServiceFactory already ACL-authorized this call
        // (modules/signup/configs/acl.ini allows guest on Signup_Service_Signup).
        $form = new Signup_Form_Signup();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        try {
            $ids = $this->_transaction(function () use ($v) {
                $orgId = (new Tiger_Model_Org())->insert([
                    'name' => $v['company'],
                    'slug' => $this->_uniqueSlug(Tiger_Install::slugify($v['company'])),
                ]);

                $userId = (new Tiger_Model_User())->insert([
                    'email'    => strtolower(trim((string) $v['email'])),
                    'username' => $v['username'],
                    'status'   => 'pending',                       // guest until email verified
                ]);
                (new Tiger_Model_UserCredential())->setPassword($userId, (string) $v['password']);
                (new Tiger_Model_OrgUser())->insert(['org_id' => $orgId, 'user_id' => $userId, 'role' => 'user']);

                // Address — linked to the org (HQ) and the user (personal), demonstrating both joins.
                $addressId = (new Tiger_Model_Address())->insert([
                    'line1'  => $v['street'], 'city' => $v['city'], 'region' => $v['region'],
                    'postal' => $v['postal'], 'country' => $v['country'],
                ]);
                (new Tiger_Model_OrgAddress())->insert(['org_id' => $orgId, 'address_id' => $addressId, 'label' => 'primary', 'is_primary' => 1]);
                (new Tiger_Model_UserAddress())->insert(['user_id' => $userId, 'address_id' => $addressId, 'label' => 'primary', 'is_primary' => 1]);

                // Contact (phone) — linked to both as well.
                $contactId = (new Tiger_Model_Contact())->insert([
                    'kind' => 'phone', 'type' => $v['phone_type'], 'value' => $v['phone'],
                ]);
                (new Tiger_Model_OrgContact())->insert(['org_id' => $orgId, 'contact_id' => $contactId, 'is_primary' => 1]);
                (new Tiger_Model_UserContact())->insert(['user_id' => $userId, 'contact_id' => $contactId, 'is_primary' => 1]);

                return ['user_id' => $userId, 'email' => strtolower(trim((string) $v['email']))];
            });

            $this->_sendVerification($ids['user_id'], $ids['email']);
            $this->_success(['sent' => 1, 'email' => $ids['email']], 'signup.check_email');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Redeem an email_verify challenge and activate the account. Returns ['ok'=>bool]. */
    public function verifyEmail($challengeId, $code): array
    {
        $model = new Tiger_Model_AuthChallenge();
        $row   = $model->redeem((string) $challengeId, (string) $code);   // verifies + single-use
        if (!$row || empty($row->user_id)) {
            return ['ok' => false];
        }
        $userModel = new Tiger_Model_User();
        $userModel->update(
            ['status' => 'active'],
            $userModel->getAdapter()->quoteInto('user_id = ?', $row->user_id)
        );
        return ['ok' => true, 'user_id' => $row->user_id];
    }

    /** A slug not yet used by any org (company-name based, then -2, -3, … on collision). */
    protected function _uniqueSlug($base): string
    {
        $base = ($base !== '') ? $base : 'org';
        $orgModel = new Tiger_Model_Org();
        $slug = $base;
        $n = 1;
        while ($orgModel->findBySlug($slug)) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** Issue an email_verify challenge and email the verification link. */
    protected function _sendVerification($userId, $email): void
    {
        $token       = bin2hex(random_bytes(32));
        $challengeId = (new Tiger_Model_AuthChallenge())->issue($userId, 'email_verify', $token, self::VERIFY_TTL);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url    = $scheme . '://' . $host . '/signup/index/verify/cid/' . rawurlencode($challengeId) . '/code/' . rawurlencode($token);

        try {
            (new Tiger_Mail())
                ->to($email)
                ->subject('Verify your email')
                ->html(
                    '<p>Welcome to Tiger! Please confirm your email address to activate your account:</p>'
                    . '<p><a href="' . htmlspecialchars($url) . '">Verify my email</a></p>'
                    . '<p>Or paste this link into your browser:<br>' . htmlspecialchars($url) . '</p>'
                )
                ->send();
        } catch (Throwable $e) {
            error_log('Tiger signup verification mail failed: ' . $e->getMessage());
        }
    }
}
