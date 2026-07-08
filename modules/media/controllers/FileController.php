<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Media_FileController — streams PRIVATE media (and any object whose adapter can't mint a
 * direct URL) through an ACL check. Public files are served directly by the web server
 * and never reach here. Route: /media/file/serve/id/<id>[/v/<variant>].
 *
 * Admin-gated for P1 (configs/acl.ini); the in-action check also enforces org scope. A
 * video kept `in_review` stays unreadable here until moderation approves it (P4).
 */
class Media_FileController extends Tiger_Controller_Action
{
    public function serveAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $response = $this->getResponse();

        $model = new Tiger_Model_Media();
        $id    = (string) $this->getParam('id', '');
        $row   = $id !== '' ? $model->findById($id) : null;
        if (!$row) { $response->setHttpResponseCode(404); return; }
        $media = $row->toArray();

        // Org-scope guard for private objects (belt-and-suspenders atop the ACL).
        if ($media['visibility'] === Tiger_Model_Media::VISIBILITY_PRIVATE && $media['org_id'] !== '') {
            $idn = Zend_Auth::getInstance()->getIdentity();
            if (!$idn || empty($idn->org_id) || $idn->org_id !== $media['org_id']) {
                $response->setHttpResponseCode(403); return;
            }
        }

        $variant = (string) $this->getParam('v', '');
        $key = $media['storage_key'];
        $mime = $media['mime_type'] ?: 'application/octet-stream';
        if ($variant !== '' && $variant !== 'original') {
            $variants = $model->variants($media);
            if (!empty($variants[$variant]['key'])) { $key = (string) $variants[$variant]['key']; }
        }

        try {
            $adapter = Tiger_Media_Storage::disk($media['disk']);
            if (!$adapter->exists($key, $media['visibility'])) { $response->setHttpResponseCode(404); return; }
            $size   = $adapter->size($key, $media['visibility']);
            $stream = $adapter->stream($key, $media['visibility']);
        } catch (Throwable $e) {
            $response->setHttpResponseCode(404); return;
        }

        $cache = ($media['visibility'] === Tiger_Model_Media::VISIBILITY_PUBLIC)
            ? 'public, max-age=31536000' : 'private, no-store';
        $response->setHeader('Content-Type', $mime, true)
                 ->setHeader('Content-Length', (string) $size, true)
                 ->setHeader('Content-Disposition', 'inline; filename="' . rawurlencode((string) $media['filename']) . '"', true)
                 ->setHeader('Cache-Control', $cache, true)
                 ->sendHeaders();
        fpassthru($stream);
        fclose($stream);
        exit;   // raw byte stream — end the request without ZF appending output
    }
}
