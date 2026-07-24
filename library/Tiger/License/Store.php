<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_License_Store — where an install keeps the licenses it holds (one record per licensed module).
 *
 * A record is a plain array: `key`, `authority` (the URL to verify against), `vendor` (the trust anchor),
 * `public_key` (to verify the authority's signed verdict), plus the cached `verdict`/`expires_at` the
 * Checker writes back. The default implementation is the lazy `option` tier (Tiger_License_Store_Option);
 * this interface exists so the Checker can be driven with an in-memory store in tests, and so a host can
 * swap the persistence if it ever needs to.
 *
 * @api
 * @see Tiger_License_Checker
 * @see Tiger_License_Store_Option
 */
interface Tiger_License_Store
{
    /**
     * The stored license record for a module slug, or null if none is held.
     *
     * @param  string $slug the module slug
     * @return array|null the record, or null
     */
    public function get(string $slug): ?array;

    /**
     * Persist (upsert) a module's license record.
     *
     * @param  string $slug   the module slug
     * @param  array  $record the record to store
     * @return void
     */
    public function put(string $slug, array $record): void;

    /**
     * Remove a module's license record (on uninstall).
     *
     * @param  string $slug the module slug
     * @return void
     */
    public function forget(string $slug): void;
}
