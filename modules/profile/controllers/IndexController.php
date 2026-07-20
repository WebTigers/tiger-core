<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_IndexController — the signed-in user's own account screen (/profile).
 *
 * Self-service, allowed to role `user` (and up). Thin per ADMIN.md: it renders the tabbed shell
 * pre-filled from the current identity's user row; every save is an /api call to a Profile_Service_*.
 * The tabs come from Tiger_Profile_Tabs (extensible) — this controller renders whatever is registered.
 */
class Profile_IndexController extends Tiger_Controller_Admin_Action
{
    /**
     * Admin shell (layout) comes from the base; keep the explicit init cascade.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
    }

    /**
     * Render the profile tabs pre-filled from the signed-in user's identity row.
     *
     * @return void
     */
    public function indexAction()
    {
        $identity = Zend_Auth::getInstance()->getIdentity();
        $userId   = is_object($identity) ? (string) ($identity->user_id ?? '') : '';
        $user     = $userId !== '' ? (new Tiger_Model_User())->findById($userId) : null;

        // Supported languages come from config (tiger.i18n.locales) — map the codes to display
        // names, falling back to the uppercased code.
        $cfg         = Zend_Registry::get('Zend_Config');
        $i18n        = ($cfg->tiger && $cfg->tiger->i18n) ? (string) $cfg->tiger->i18n->get('locales') : 'en';
        $supported   = array_values(array_filter(array_map('trim', explode(',', $i18n)))) ?: ['en'];
        $localeNames = ['en' => 'English', 'es' => 'Español'];
        $locales     = [];
        foreach ($supported as $code) {
            $locales[$code] = $localeNames[$code] ?? strtoupper($code);
        }

        // Current avatar (option tier → media_id → URL); blank when none set.
        $avatarUrl = '';
        if ($userId !== '') {
            $mediaId = (new Tiger_Model_Option())->get(Tiger_Model_Option::SCOPE_USER, $userId, Profile_Service_Avatar::OPTION_KEY);
            if ($mediaId) {
                $mrow = (new Tiger_Model_Media())->findById($mediaId);
                if ($mrow) { $avatarUrl = (new Tiger_Model_Media())->url($mrow->toArray()); }
            }
        }

        // Friendly display name (option tier) — overrides the email label in the header user menu.
        $displayName = $userId !== ''
            ? (string) (new Tiger_Model_Option())->get(Tiger_Model_Option::SCOPE_USER, $userId, Profile_Service_User::OPTION_DISPLAY_NAME)
            : '';

        // Phone field default country (config; ISO-3166 alpha-2). The layout loads intl-tel-input
        // only when useIntlTel is set — the Contacts tab's Phone type uses it.
        $cfg          = Zend_Registry::get('Zend_Config');
        $phoneDefault = ($cfg->tiger && $cfg->tiger->profile && $cfg->tiger->profile->phone)
            ? (string) $cfg->tiger->profile->phone->get('default_country') : 'US';

        $this->view->useIntlTel = true;
        $this->view->useAddress = true;
        $this->view->title = Zend_Registry::get('Zend_Translate')->translate('profile.title') . ' — Tiger';
        $this->view->tabs  = Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER);
        $this->view->model = [
            'form'         => new Profile_Form_UserProfile(),
            'passwordForm' => new Profile_Form_Password(),
            'contactForm'  => new Profile_Form_Contact(),
            'avatarUrl'    => $avatarUrl,
            'displayName'  => $displayName,
            'contacts'     => $userId !== '' ? (new Tiger_Model_UserContact())->withContact($userId) : [],
            'contactTypes' => Tiger_Profile_Types::contact(),
            'phoneDefault' => $phoneDefault ?: 'US',
            'addressForm'  => new Profile_Form_Address(),
            'addresses'    => $userId !== '' ? (new Tiger_Model_UserAddress())->withAddress($userId) : [],
            'addressTypes' => Tiger_Profile_Types::address(),
            'countries'    => Tiger_I18n_Country::grouped(),
            'username'  => $user && isset($user->username) ? (string) $user->username : '',
            'email'     => $user && isset($user->email) ? (string) $user->email : '',
            'locale'    => $user && isset($user->locale) ? (string) $user->locale : '',
            'timezone'  => $user && isset($user->timezone) ? (string) $user->timezone : '',
            'locales'   => $locales,
            'timezones' => Tiger_I18n_Timezone::options(),
        ];
    }
}
