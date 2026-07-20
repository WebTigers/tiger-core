<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Access_Service_User — /api service for the Users admin (datatable / save / delete).
 *
 * Users are thin identities (email / username / status); the list also summarizes
 * each user's membership (org count + the distinct roles they hold, via org_user).
 * Rows are structured data with per-row ACL flags (can_edit / can_delete) — the
 * client renders controls off them; you can never delete your own account. ACL:
 * admin+ (modules/access/configs/acl.ini).
 *
 * @api
 */
class Access_Service_User extends Tiger_Service_Service
{
    private const STATUSES = ['active', 'suspended'];

    /**
     * DataTables server-side source: user identity + a membership summary.
     *
     * @param  array $params the DataTables request (draw/start/length/search/order) + status filter
     * @return void
     */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt = $this->_dtParams($params);

        $data = (new Tiger_Model_User())->datatable([
            'search'   => $dt['search'],
            'status'   => in_array(($params['status'] ?? ''), self::STATUSES, true) ? (string) $params['status'] : '',
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'delete');

        $rows = [];
        foreach ($data['rows'] as $r) {
            $isSelf = ($r['user_id'] === $this->_user_id);
            $rows[] = [
                'user_id'    => $r['user_id'],
                'email'      => $r['email'],
                'username'   => ($r['username'] !== null && $r['username'] !== '') ? $r['username'] : '',
                'roles'      => (string) ($r['role_names'] ?? ''),
                'org_count'  => (int) $r['org_count'],
                'status'     => $r['status'],
                'created'    => substr((string) $r['created_at'], 0, 10),
                'is_self'    => $isSelf,
                'can_edit'   => $canEdit,
                'can_delete' => $canDelete && !$isSelf,   // never delete your own account
            ];
        }

        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /**
     * Create or update a user identity (insert when user_id is empty).
     *
     * @param  array $params the submitted form values + user_id
     * @return void
     */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Access_Form_User();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        $userId   = !empty($params['user_id']) ? (string) $params['user_id'] : null;
        $email    = strtolower(trim((string) $v['email']));
        $username = trim((string) $v['username']);
        $username = $username !== '' ? $username : null;

        $user = new Tiger_Model_User();
        if ($user->isTaken('email', $email, $userId)) { $this->_error('access.user.email_taken'); return; }
        if ($username !== null && $user->isTaken('username', $username, $userId)) { $this->_error('access.user.username_taken'); return; }

        $locale   = trim((string) $v['locale']);
        $timezone = trim((string) $v['timezone']);
        $newPw    = (string) $v['new_password'];
        $data = [
            'email'    => $email,
            'username' => $username,
            'status'   => $v['status'],
            'locale'   => $locale !== '' ? $locale : null,
            'timezone' => $timezone !== '' ? $timezone : null,
        ];

        try {
            $id = $this->_transaction(function () use ($user, $userId, $data, $newPw) {
                if ($userId) {
                    $user->update($data, ['user_id = ?' => $userId]);
                    $newId = $userId;
                } else {
                    $newId = $user->insert($data);
                }
                // Admin authority: set/reset the password outright when provided (no current-password
                // step — that's the self-service Profile_Service_Security path). Blank = unchanged.
                if ($newPw !== '') {
                    (new Tiger_Model_UserCredential())->setPassword($newId, $newPw);
                }
                return $newId;
            });
            $this->_success(['user_id' => $id], 'access.user.saved', '/access/user');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Soft-delete a user (never yourself).
     *
     * @param  array $params the request payload carrying user_id
     * @return void
     */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['user_id']) ? (string) $params['user_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }
        if ($id === $this->_user_id) { $this->_error('access.user.no_self_delete'); return; }

        try {
            (new Tiger_Model_User())->softDelete(['user_id = ?' => $id]);
            $this->_success(['user_id' => $id], 'access.user.deleted', '/access/user');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }
}
