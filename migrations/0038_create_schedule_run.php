<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Migration 0038 — create `schedule_run` (Tiger_Schedule's execution log + last-run state).
 *
 * One row per job run: written `running` at start (also the in-flight lock), flipped to ok|error at
 * finish. `lastRunTs()` reads MAX(started_at) to answer "did this job run for the current clock
 * slot?" — so the scheduler needs no separate next-run bookkeeping. `outcome`/`source` are used
 * instead of the standard `status` column (whose semantics differ) and the reserved word `trigger`.
 * Additive-only per AGENTS.md.
 */
return [
    'up' => [
        "CREATE TABLE `schedule_run` (
            `schedule_run_id` CHAR(36)     NOT NULL,
            `job_key`         VARCHAR(128) NOT NULL,
            `slot_at`         DATETIME         NULL,                 -- the scheduled slot this run satisfies
            `started_at`      DATETIME     NOT NULL,
            `finished_at`     DATETIME         NULL,
            `outcome`         VARCHAR(16)  NOT NULL DEFAULT 'running', -- running | ok | error
            `error`           VARCHAR(1000)    NULL,
            `duration_ms`     INT              NULL,
            `source`          VARCHAR(16)      NULL,                 -- cron | pseudo | manual
            `created_by`      CHAR(36)         NULL,
            `updated_by`      CHAR(36)         NULL,
            `created_at`      DATETIME     NOT NULL,
            `updated_at`      DATETIME         NULL,
            PRIMARY KEY (`schedule_run_id`),
            KEY `ix_schedule_run_job` (`job_key`, `started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `schedule_run`",
    ],
];
