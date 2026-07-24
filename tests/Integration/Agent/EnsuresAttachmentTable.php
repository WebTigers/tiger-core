<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use Zend_Db;

/**
 * The `agent_attachment` table ships as a MODULE migration (modules/agent/migrations/…), which the
 * integration harness's migrator does NOT scan — it only applies core `/migrations` (see
 * IntegrationTestCase::ensureMigrated + the module-lifecycle-migrations backlog note). So a test that
 * touches Agent_Model_Attachment must create the table itself.
 *
 * We apply the DDL on a SEPARATE, short-lived connection (not the shared, per-test-transactional
 * adapter) so the auto-committing CREATE never disturbs the outer test transaction. Guarded by a
 * process-static flag + `IF NOT EXISTS`, so it runs at most once and is idempotent across the suite.
 * The DDL mirrors modules/agent/migrations/20260724000001_agent_attachments.php verbatim (plus the
 * IF NOT EXISTS guard).
 */
trait EnsuresAttachmentTable
{
    private static bool $attachmentTableReady = false;

    protected function ensureAttachmentTable(): void
    {
        if (self::$attachmentTableReady) {
            return;
        }
        $sep = Zend_Db::factory('Pdo_Mysql', [
            'host'     => getenv('TIGER_TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('TIGER_TEST_DB_PORT') ?: 3306),
            'dbname'   => getenv('TIGER_TEST_DB_NAME'),
            'username' => getenv('TIGER_TEST_DB_USER') ?: 'root',
            'password' => getenv('TIGER_TEST_DB_PASS') ?: '',
            'charset'  => 'utf8mb4',
        ]);
        $sep->query(
            "CREATE TABLE IF NOT EXISTS `agent_attachment` (
                `attachment_id`   CHAR(36)     NOT NULL,
                `conversation_id` CHAR(36)         NULL,
                `message_id`      CHAR(36)         NULL,
                `user_id`         CHAR(36)     NOT NULL,
                `org_id`          CHAR(36)     NOT NULL,
                `disk`            VARCHAR(64)  NOT NULL DEFAULT 'local',
                `storage_key`     VARCHAR(512)     NULL,
                `filename`        VARCHAR(255) NOT NULL,
                `mime_type`       VARCHAR(128)     NULL,
                `file_size`       BIGINT           NULL,
                `kind`            VARCHAR(16)  NOT NULL DEFAULT 'file',
                `extract`         MEDIUMTEXT       NULL,
                `deleted`         TINYINT(1)   NOT NULL DEFAULT 0,
                `created_by`      CHAR(36)         NULL,
                `updated_by`      CHAR(36)         NULL,
                `created_at`      DATETIME     NOT NULL,
                `updated_at`      DATETIME         NULL,
                PRIMARY KEY (`attachment_id`),
                KEY `ix_agent_attach_msg`  (`conversation_id`, `message_id`),
                KEY `ix_agent_attach_user` (`user_id`, `message_id`, `deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $sep->closeConnection();
        self::$attachmentTableReady = true;
    }

    /**
     * Drop the side-loaded table again so it never persists into other suites — notably
     * InstallerLifecycleTest, which installs the agent module for real and creates this table itself
     * (a plain CREATE that would collide with a leftover). The using class calls this from
     * tearDownAfterClass. Uses a separate connection (the shared adapter's txn is already torn down).
     */
    protected static function dropAttachmentTable(): void
    {
        if (!self::$attachmentTableReady) {
            return;
        }
        $sep = Zend_Db::factory('Pdo_Mysql', [
            'host'     => getenv('TIGER_TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('TIGER_TEST_DB_PORT') ?: 3306),
            'dbname'   => getenv('TIGER_TEST_DB_NAME'),
            'username' => getenv('TIGER_TEST_DB_USER') ?: 'root',
            'password' => getenv('TIGER_TEST_DB_PASS') ?: '',
            'charset'  => 'utf8mb4',
        ]);
        $sep->query('DROP TABLE IF EXISTS `agent_attachment`');
        $sep->closeConnection();
        self::$attachmentTableReady = false;
    }
}
