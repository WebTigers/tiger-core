<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Scanner_Rekognition — AI content moderation via AWS Rekognition.
 *
 * IMAGES (synchronous): `DetectModerationLabels` on the bytes; any label at/above the
 * confidence threshold rejects the upload. VIDEOS (asynchronous): `StartContentModeration`
 * against an S3 object returns a JobId and publishes the result to an SNS topic — an
 * SNS→webhook (Media_CallbackController) flips the media row from `in_review` when it lands
 * (see MEDIA.md §4).
 *
 * Needs `aws/aws-sdk-php` + credentials (instance role). Absent the SDK this degrades to an
 * `error` result (the feature is config-gated off by default anyway).
 *
 * @api
 */
class Tiger_Media_Scanner_Rekognition implements Tiger_Media_Scanner_Interface
{
    protected $_threshold;
    protected $_region;

    public function __construct(float $threshold = 80.0, string $region = 'us-east-1')
    {
        $this->_threshold = $threshold;
        $this->_region    = $region;
    }

    public function scan(string $path, ?string $mime = null): array
    {
        if (!class_exists('Aws\\Rekognition\\RekognitionClient')) {
            return ['status' => 'error', 'reason' => 'aws-sdk-php not installed', 'meta' => []];
        }
        try {
            $client = new Aws\Rekognition\RekognitionClient(['region' => $this->_region, 'version' => 'latest']);
            $res    = $client->detectModerationLabels([
                'Image'        => ['Bytes' => file_get_contents($path)],
                'MinConfidence' => $this->_threshold,
            ]);
            $labels = $res['ModerationLabels'] ?? [];
            if (!empty($labels)) {
                $top = $labels[0];
                return [
                    'status' => 'rejected',
                    'reason' => $top['Name'] . ' (' . round((float) $top['Confidence']) . '%)',
                    'meta'   => ['scanner' => 'rekognition', 'labels' => array_map(function ($l) {
                        return ['name' => $l['Name'], 'confidence' => round((float) $l['Confidence'], 1)];
                    }, $labels)],
                ];
            }
            return ['status' => 'clean', 'reason' => null, 'meta' => ['scanner' => 'rekognition']];
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => $e->getMessage(), 'meta' => []];
        }
    }

    /**
     * Submit a stored S3 VIDEO for async moderation. Returns the Rekognition JobId (kept in
     * the media row's scan_meta so the webhook can match the result), or null on failure.
     *
     * TODO(P4/P5): wire once S3 storage + the SNS topic/role are provisioned — needs
     * media.scan.video_sns_topic + media.scan.video_role and the S3 disk.
     */
    public function submitVideo(string $bucket, string $key, string $snsTopicArn, string $roleArn): ?string
    {
        if (!class_exists('Aws\\Rekognition\\RekognitionClient')) {
            return null;
        }
        try {
            $client = new Aws\Rekognition\RekognitionClient(['region' => $this->_region, 'version' => 'latest']);
            $res = $client->startContentModeration([
                'Video'             => ['S3Object' => ['Bucket' => $bucket, 'Name' => $key]],
                'MinConfidence'     => $this->_threshold,
                'NotificationChannel' => ['SNSTopicArn' => $snsTopicArn, 'RoleArn' => $roleArn],
            ]);
            return $res['JobId'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
