<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Service_User — the /api service behind the user's own Basic-info tab.
 *
 * Self-service and STRICTLY self-scoped: it only ever writes the CURRENT identity's row
 * ($this->_user_id) — it never accepts a user_id from the payload, so a `user` role can't edit
 * anyone else. Writes the base identity fields the profile owns: `username` (unique) and the two
 * i18n primitives `locale` + `timezone` (the deliberate thin-user carve-out, ARCHITECTURE §7).
 *
 * @api
 */
class Profile_Service_User extends Tiger_Service_Service
{
    /**
     * option(scope=user) key for the user's friendly display name. It's PROFILE data, not identity,
     * so it lives in the per-user option tier (the thin-user rule) — never a column on `user`. The
     * admin header reads it to override the email label in the user menu.
     */
    const OPTION_DISPLAY_NAME = 'tiger.user.display_name';

    /**
     * Save the current user's basic info.
     *
     * @param  array $params username, locale, timezone (blank clears the optional ones)
     * @return void
     */
    public function save(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') {
            $this->_error('core.api.error.not_allowed');
            return;
        }

        $form = new Profile_Form_UserProfile();
        if (!$form->isValid($params)) {
            $this->_formErrors($form);
            return;
        }

        $user        = new Tiger_Model_User();
        $displayName = trim((string) $form->getValue('display_name'));
        $username    = trim((string) $form->getValue('username'));
        if ($username !== '' && $user->isTaken('username', $username, $userId)) {
            $this->_error('profile.user.username_taken');
            return;
        }

        // Membership checks live here (dynamic valid sets): a supported language, a real IANA zone.
        // Supported langs come from CONFIG (always available) — not the SUPPORTED_LANGS request
        // constant, which only exists after LocalePrefix runs, so a non-web caller stays correct.
        $cfg       = Zend_Registry::get('Zend_Config');
        $i18n      = ($cfg->tiger && $cfg->tiger->i18n) ? (string) $cfg->tiger->i18n->get('locales') : 'en';
        $supported = array_values(array_filter(array_map('trim', explode(',', $i18n)))) ?: ['en'];
        $locale    = (string) $form->getValue('locale');
        $locale    = in_array($locale, $supported, true) ? $locale : null;
        $timezone  = (string) $form->getValue('timezone');
        $timezone  = in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null;

        try {
            $this->_transaction(function () use ($user, $userId, $username, $locale, $timezone, $displayName) {
                $user->update(
                    [
                        'username' => $username !== '' ? $username : null,
                        'locale'   => $locale,
                        'timezone' => $timezone,
                    ],
                    $user->getAdapter()->quoteInto('user_id = ?', $userId)
                );
                // Display name is profile data, not identity — per-user option tier, not a user column.
                // Blank clears it (the UI falls back to email).
                $opt = new Tiger_Model_Option();
                if ($displayName !== '') {
                    $opt->set(Tiger_Model_Option::SCOPE_USER, $userId, self::OPTION_DISPLAY_NAME, $displayName);
                } else {
                    $opt->forget(Tiger_Model_Option::SCOPE_USER, $userId, self::OPTION_DISPLAY_NAME);
                }
            });
            $this->_success(['locale' => $locale, 'display_name' => $displayName], 'profile.user.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }
}
