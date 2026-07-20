<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile_Service_Avatar — self-service avatar upload for the profile Basic tab.
 *
 * The general media library (Media_Service_Media) is admin-gated, so a plain `user` gets this
 * purpose-built, self-scoped endpoint instead: it accepts the ALREADY-CROPPED image (Cropper.js
 * exports a square canvas client-side — the server never crops, matching Tiger_Media_Image's
 * "contain, never crop" rule), stores it as a public image through the same media subsystem, and
 * links it to the user via the per-user `option` tier (`tiger.user.avatar` = media_id) — the
 * endorsed home for an avatar reference (ARCHITECTURE §7), never a column on the thin user row.
 *
 * @api
 */
class Profile_Service_Avatar extends Tiger_Service_Service
{
    /** option(scope=user, scope_id=<user_id>) key holding the avatar's media_id. */
    const OPTION_KEY = 'tiger.user.avatar';

    /** Hard cap — a cropped avatar is small; this is just a sanity ceiling. */
    const MAX_BYTES = 5242880;   // 5 MB

    /** Accepted image types → canonical extension. */
    const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Store the cropped avatar for the current user and link it via the option tier.
     *
     * @param  array $params (file rides in $_FILES['file'])
     * @return void
     */
    public function upload(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') { $this->_error('core.api.error.not_allowed'); return; }

        $file = $_FILES['file'] ?? null;
        if (!$file || !is_uploaded_file((string) $file['tmp_name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            $this->_error('profile.avatar.error_upload'); return;
        }
        $tmp  = (string) $file['tmp_name'];
        $size = (int) $file['size'];
        if ($size > self::MAX_BYTES) { $this->_error('profile.avatar.error_large'); return; }

        $dims = @getimagesize($tmp);
        $mime = $dims ? (string) ($dims['mime'] ?? '') : '';
        if (!$dims || !isset(self::TYPES[$mime])) { $this->_error('profile.avatar.error_type'); return; }
        $ext  = self::TYPES[$mime];

        $disk = Tiger_Media_Storage::defaultDisk();
        $org  = preg_replace('/[^a-zA-Z0-9-]/', '', (string) $this->_org_id) ?: '_shared';
        $key  = $org . '/' . Tiger_Model_Media::kindFolder(Tiger_Model_Media::KIND_IMAGE)
              . '/avatar-' . $userId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;

        // Storage isn't transactional, so store first, then wrap the DB writes; delete the bytes if
        // the DB half fails, so a failed link never orphans a file (the media-service pattern).
        try {
            Tiger_Media_Storage::disk($disk)->put($key, $tmp, Tiger_Model_Media::VISIBILITY_PUBLIC, $mime);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general'); return;
        }

        try {
            $mediaId = $this->_transaction(function () use ($disk, $key, $mime, $ext, $size, $dims, $userId) {
                $id = (new Tiger_Model_Media())->insert([
                    'org_id'      => (string) $this->_org_id,
                    'locale'      => '',
                    'disk'        => $disk,
                    'storage_key' => $key,
                    'visibility'  => Tiger_Model_Media::VISIBILITY_PUBLIC,
                    'kind'        => Tiger_Model_Media::KIND_IMAGE,
                    'mime_type'   => $mime,
                    'extension'   => $ext,
                    'file_size'   => $size,
                    'width'       => (int) $dims[0],
                    'height'      => (int) $dims[1],
                    'filename'    => 'avatar.' . $ext,
                    'title'       => 'Avatar',
                ]);
                (new Tiger_Model_Option())->set(Tiger_Model_Option::SCOPE_USER, $userId, self::OPTION_KEY, $id);
                return $id;
            });
        } catch (Throwable $e) {
            Tiger_Media_Storage::disk($disk)->delete($key, Tiger_Model_Media::VISIBILITY_PUBLIC);
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general'); return;
        }

        $row = (new Tiger_Model_Media())->findById($mediaId);
        $this->_success(
            ['media_id' => $mediaId, 'url' => $row ? (new Tiger_Model_Media())->url($row->toArray()) : ''],
            'profile.avatar.saved'
        );
    }

    /**
     * Clear the current user's avatar (unlink the option; the media row is left in place).
     *
     * @param  array $params (unused)
     * @return void
     */
    public function remove(array $params): void
    {
        $userId = (string) $this->_user_id;
        if ($userId === '') { $this->_error('core.api.error.not_allowed'); return; }
        (new Tiger_Model_Option())->forget(Tiger_Model_Option::SCOPE_USER, $userId, self::OPTION_KEY);
        $this->_success([], 'profile.avatar.removed');
    }
}
