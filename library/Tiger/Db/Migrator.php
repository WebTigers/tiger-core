<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Db_Migrator — a tiny, dependency-free schema migration runner.
 *
 * WHY hand-rolled (not Doctrine/Phinx/etc.): ZF1 has no migration tool, and pulling
 * a heavyweight one in would drag a large dependency into every Tiger app. Migrations
 * are simple enough — ordered, idempotent, tracked — to own directly, and owning them
 * keeps the "zero unnecessary plumbing" promise.
 *
 * HOW IT WORKS:
 *   - A migration is a file named `NNNN_snake_name.php` returning
 *     ['up' => [...sql], 'down' => [...sql]]. `NNNN` is the version (sort key).
 *   - Migrations are discovered across MULTIPLE paths — core ships them in
 *     tiger-core/migrations; an app in application/migrations; a module in
 *     modules/<m>/migrations — and merged into one ascending-by-version sequence.
 *   - Applied versions are recorded in `tiger_migration`, so migrate() only ever
 *     runs what's pending, and is safe to run repeatedly.
 *
 * CAVEAT (MySQL/MariaDB): DDL statements auto-commit — a CREATE/ALTER can't be
 * rolled back inside a transaction. So keep each migration to ONE logical change;
 * if a migration half-applies, fix forward. We record a migration as applied only
 * after ALL its statements succeed, so a failure leaves it un-recorded and it will
 * be retried on the next run.
 *
 * @api
 */
class Tiger_Db_Migrator
{
    /** @var Zend_Db_Adapter_Abstract */
    private $db;

    /** @var string[] directories to scan for migration files, in precedence order */
    private $paths;

    /**
     * Construct the migrator over a DB adapter and a set of migration directories.
     *
     * @param Zend_Db_Adapter_Abstract $db
     * @param string[]                 $paths migration directories (existing ones; missing are ignored)
     * @return void
     */
    public function __construct(Zend_Db_Adapter_Abstract $db, array $paths)
    {
        $this->db    = $db;
        $this->paths = array_values(array_filter($paths, 'is_dir'));
    }

    /**
     * Apply all pending migrations in version order.
     *
     * @param  callable|null $log optional fn(string $message) for progress output
     * @return array         [version => name] of migrations applied this run
     */
    public function migrate($log = null)
    {
        $this->ensureTrackingTable();
        $applied = $this->appliedVersions();
        $ran     = [];

        foreach ($this->discover() as $version => $m) {
            if (isset($applied[$version])) {
                continue;
            }
            $this->emit($log, "  migrating {$version}_{$m['name']} ...");
            foreach ($m['up'] as $stmt) {
                $this->run($stmt);
            }
            // Record only after every statement succeeded (see class caveat).
            $this->db->insert('tiger_migration', [
                'version'    => $version,
                'name'       => $m['name'],
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            $ran[$version] = $m['name'];
        }

        $this->emit($log, $ran ? '  applied ' . count($ran) . ' migration(s).' : '  nothing to migrate.');
        return $ran;
    }

    /**
     * Reverse the most recently applied migrations (highest versions first).
     *
     * @param  int           $steps how many to roll back
     * @param  callable|null $log
     * @return array         [version => name] rolled back
     */
    public function rollback($steps = 1, $log = null)
    {
        $this->ensureTrackingTable();
        $all     = $this->discover();
        $applied = array_keys($this->appliedVersions());
        rsort($applied);                    // newest first
        $target  = array_slice($applied, 0, max(0, (int) $steps));
        $done    = [];

        foreach ($target as $version) {
            if (!isset($all[$version])) {
                $this->emit($log, "  ! no file for applied version {$version}; skipping");
                continue;
            }
            $m = $all[$version];
            $this->emit($log, "  rolling back {$version}_{$m['name']} ...");
            foreach ($m['down'] as $stmt) {
                $this->run($stmt);
            }
            $this->db->delete('tiger_migration', $this->db->quoteInto('version = ?', $version));
            $done[$version] = $m['name'];
        }
        return $done;
    }

    /**
     * Run one migration step. A **string** is executed as SQL (the common case). A **callable**
     * (a `function ($db) { … }` — anything non-string that's callable) is invoked with the DB adapter,
     * for DATA migrations SQL can't express cleanly (e.g. transforming a JSON column across rows). The
     * string-vs-callable split is by type, not `is_callable`, so a SQL string that happens to name a
     * PHP function is never mistaken for one.
     *
     * @param  string|callable $stmt an SQL string, or fn(Zend_Db_Adapter_Abstract $db): void
     * @return void
     */
    protected function run($stmt)
    {
        if (!is_string($stmt) && is_callable($stmt)) {
            $stmt($this->db);
            return;
        }
        $this->db->query($stmt);
    }

    /**
     * Discovered vs applied, for a `migrate:status` view.
     *
     * @return array [version => ['name' => string, 'applied' => bool]]
     */
    public function status()
    {
        $this->ensureTrackingTable();
        $applied = $this->appliedVersions();
        $out     = [];
        foreach ($this->discover() as $version => $m) {
            $out[$version] = ['name' => $m['name'], 'applied' => isset($applied[$version])];
        }
        return $out;
    }

    // --- internals ---------------------------------------------------------

    /**
     * Scan all paths for migration files and merge into one version-sorted map.
     *
     * @return array [version => ['name' => string, 'up' => string[], 'down' => string[]]]
     */
    private function discover()
    {
        $found = [];
        foreach ($this->paths as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $base = basename($file, '.php');           // e.g. "0001_create_org"
                if (!preg_match('/^(\d+)_(.+)$/', $base, $mm)) {
                    continue;                              // ignore non-conforming files
                }
                list(, $version, $name) = $mm;
                $spec = include $file;
                $found[$version] = [
                    'name' => $name,
                    'up'   => isset($spec['up'])   ? (array) $spec['up']   : [],
                    'down' => isset($spec['down']) ? (array) $spec['down'] : [],
                ];
            }
        }
        ksort($found, SORT_STRING);   // zero-padded versions sort correctly as strings
        return $found;
    }

    /** @return array [version => true] of already-applied versions */
    private function appliedVersions()
    {
        $rows = $this->db->fetchCol('SELECT version FROM tiger_migration');
        return array_fill_keys($rows, true);
    }

    /** Create the tracking table if it doesn't exist. */
    private function ensureTrackingTable()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `tiger_migration` (
                `version`    VARCHAR(64)  NOT NULL,
                `name`       VARCHAR(191) NOT NULL,
                `applied_at` DATETIME     NOT NULL,
                PRIMARY KEY (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function emit($log, $message)
    {
        if (is_callable($log)) {
            $log($message);
        }
    }
}
