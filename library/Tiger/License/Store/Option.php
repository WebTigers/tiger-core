<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_License_Store_Option — the default license store, backed by the lazy `option` tier.
 *
 * License records are per-install, read on demand (only when a module's update is checked) — exactly the
 * lazy/scoped state the `option` tier is for (never the eager `config` tier). One global-scoped row per
 * module, keyed `tiger.license.<slug>`. A license key is domain-bound and inert elsewhere, so plaintext
 * here is fine (no secret encryption needed — the same call the TigerPass/licensing design makes).
 *
 * @api
 * @see Tiger_License_Store
 * @see Tiger_Model_Option
 */
class Tiger_License_Store_Option implements Tiger_License_Store
{
    /** Option-key prefix; one row per module slug. */
    const KEY_PREFIX = 'tiger.license.';

    /**
     * The stored license record for a module slug, or null.
     *
     * @param  string $slug the module slug
     * @return array|null the record, or null
     */
    public function get(string $slug): ?array
    {
        $v = (new Tiger_Model_Option())->getJson(Tiger_Model_Option::SCOPE_GLOBAL, '', self::KEY_PREFIX . $slug, null);
        return is_array($v) ? $v : null;
    }

    /**
     * Persist (upsert) a module's license record as JSON.
     *
     * @param  string $slug   the module slug
     * @param  array  $record the record to store
     * @return void
     */
    public function put(string $slug, array $record): void
    {
        (new Tiger_Model_Option())->setJson(Tiger_Model_Option::SCOPE_GLOBAL, '', self::KEY_PREFIX . $slug, $record);
    }

    /**
     * Remove a module's license record.
     *
     * @param  string $slug the module slug
     * @return void
     */
    public function forget(string $slug): void
    {
        (new Tiger_Model_Option())->forget(Tiger_Model_Option::SCOPE_GLOBAL, '', self::KEY_PREFIX . $slug);
    }
}
