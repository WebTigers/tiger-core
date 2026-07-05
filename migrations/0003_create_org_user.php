<?php
/**
 * Migration 0003 — create `org_user` (membership = tenancy boundary + role).
 *
 * See Tiger_Model_OrgUser for the semantics. Schema notes:
 *   - UNIQUE (org_id, user_id): a user has at most one membership per org. This
 *     index also serves org-scoped lookups (leftmost prefix on org_id).
 *   - Separate ix on user_id: for the reverse lookup ("which orgs is this user
 *     in?"), since org_id is the leftmost column of the unique key.
 *   - FKs ON DELETE CASCADE: deleting an org or a user removes their memberships
 *     automatically — a membership can't outlive either side.
 *   - `role` defaults to 'member'; it's the per-tenant role the ACL engine reads.
 */
return array(
    'up' => array(
        "CREATE TABLE `org_user` (
            `org_user_id` CHAR(36)    NOT NULL,
            `org_id`      CHAR(36)    NOT NULL,
            `user_id`     CHAR(36)    NOT NULL,
            `role`        VARCHAR(64) NOT NULL DEFAULT 'member',  -- role IN this org
            `status`      VARCHAR(32) NOT NULL DEFAULT 'active',  -- active/invited/suspended
            `deleted`     TINYINT(1)  NOT NULL DEFAULT 0,         -- soft-delete flag (1 = deleted)
            `created_by`  CHAR(36)        NULL,                   -- user_id who created (NULL = system)
            `updated_by`  CHAR(36)        NULL,                   -- user_id who last updated
            `created_at`  DATETIME    NOT NULL,
            `updated_at`  DATETIME        NULL,
            PRIMARY KEY (`org_user_id`),
            UNIQUE KEY `uq_org_user`      (`org_id`, `user_id`),
            KEY        `ix_org_user_user` (`user_id`),
            CONSTRAINT `fk_org_user_org`
                FOREIGN KEY (`org_id`)  REFERENCES `org`  (`org_id`)  ON DELETE CASCADE,
            CONSTRAINT `fk_org_user_user`
                FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ),
    'down' => array(
        "DROP TABLE IF EXISTS `org_user`",
    ),
);
