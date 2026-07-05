<?php
/**
 * Tiger_Install — first-run bootstrap helpers.
 *
 * Creates the founding org + user + password + membership for a fresh install.
 * This is a SYSTEM/genesis operation: there's no logged-in actor, so the created
 * rows get created_by = NULL. Kept as a class (not inline in bin/tiger) so
 * create-project / a web installer can reuse it. bin/tiger `install:admin` gathers
 * input and calls createOwner().
 *
 * @api
 */
class Tiger_Install
{
    const MIN_PASSWORD = 8;

    /**
     * Create the founding org + owner user + password credential + membership.
     *
     * @param  string      $email
     * @param  string      $password
     * @param  string      $orgName
     * @param  string|null $orgSlug  derived from the org name if null
     * @param  string      $role     the membership role (default 'developer' = god,
     *                               because a fresh install's founder needs full access)
     * @param  string|null $username optional display username (email stays the login id)
     * @return array{org_id:string,user_id:string,org_user_id:string,role:string,email:string,username:?string,org:string,slug:string}
     * @throws RuntimeException on validation error or conflict (existing email/slug/username)
     */
    public static function createOwner($email, $password, $orgName, $orgSlug = null, $role = 'developer', $username = null)
    {
        $email   = trim(strtolower((string) $email));
        $orgName = trim((string) $orgName);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }
        $violations = (new Tiger_Policy_Password())->validate((string) $password);
        if ($violations) {
            throw new RuntimeException('Password does not meet policy: ' . implode(', ', $violations));
        }
        if ($orgName === '') {
            throw new RuntimeException('An organization name is required.');
        }
        $slug = $orgSlug ? self::slugify($orgSlug) : self::slugify($orgName);

        $userModel = new Tiger_Model_User();
        if ($userModel->findByEmail($email)) {
            throw new RuntimeException("A user with email {$email} already exists.");
        }
        $orgModel = new Tiger_Model_Org();
        if ($orgModel->findBySlug($slug)) {
            throw new RuntimeException("An organization with slug '{$slug}' already exists.");
        }

        // Optional username — must be unique if given (email stays the login id).
        $username = ($username !== null) ? trim((string) $username) : '';
        if ($username !== '' && $userModel->fetchRow($userModel->activeSelect()->where('username = ?', $username))) {
            throw new RuntimeException("A user with username '{$username}' already exists.");
        }

        // Genesis rows (no actor -> created_by NULL).
        $orgId    = $orgModel->insert(array('name' => $orgName, 'slug' => $slug));
        $userData = array('email' => $email);
        if ($username !== '') { $userData['username'] = $username; }
        $userId   = $userModel->insert($userData);
        (new Tiger_Model_UserCredential())->setPassword($userId, (string) $password);
        $ouId = (new Tiger_Model_OrgUser())->insert(array(
            'org_id'  => $orgId,
            'user_id' => $userId,
            'role'    => $role,
        ));

        return array(
            'org_id'      => $orgId,
            'user_id'     => $userId,
            'org_user_id' => $ouId,
            'role'        => $role,
            'email'       => $email,
            'username'    => $username !== '' ? $username : null,
            'org'         => $orgName,
            'slug'        => $slug,
        );
    }

    /** URL-safe slug from a name. */
    public static function slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-') ?: 'org';
    }
}
