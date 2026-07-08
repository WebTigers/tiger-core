<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Media_CallbackController — AWS SNS delivery endpoint for ASYNC video moderation.
 *
 * When media.scan.video is on, a video is stored private + `in_review` and Rekognition
 * (StartContentModeration) posts its result to an SNS topic pointed here (POST /media/callback).
 * Guest-accessible (SNS carries no session) — so in production the SNS **signature MUST be
 * verified** before trusting a payload (TODO below). Config-gated + a scaffold until the
 * S3 disk + SNS topic/role are provisioned (P4/P5).
 */
class Media_CallbackController extends Tiger_Controller_Action
{
    public function indexAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $resp = $this->getResponse();

        $msg = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($msg)) { $resp->setHttpResponseCode(400)->setBody('bad request'); return; }
        $type = (string) ($msg['Type'] ?? '');

        // First-time topic handshake — confirm the subscription.
        if ($type === 'SubscriptionConfirmation' && !empty($msg['SubscribeURL'])) {
            // TODO(P4): verify the SNS signature before confirming.
            @file_get_contents((string) $msg['SubscribeURL']);
            $resp->setBody('confirmed'); return;
        }

        if ($type === 'Notification') {
            // TODO(P4): (1) verify the SNS signature; (2) JSON-decode $msg['Message'] -> JobId;
            // (3) match the media row via scan_meta.job_id; (4) Rekognition getContentModeration
            // (JobId) for the verdict; (5) set scan_status approved|rejected + unlock visibility.
            // Needs aws/aws-sdk-php + the job->media mapping recorded at submit time.
            $resp->setBody('ok'); return;
        }

        $resp->setBody('ignored');
    }
}
