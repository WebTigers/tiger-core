<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Backup_Database — a portable, shell-free SQL dump + restore over the app's DB adapter.
 *
 * No `mysqldump`, no shell — it reads schema (`SHOW CREATE TABLE`) and rows through the live Zend_Db
 * adapter and emits standard SQL, so it works on locked-down cPanel/shared hosting exactly where a
 * shell tool wouldn't. The dump stays plain-`mysql`-import compatible (statement boundaries are
 * carried on `--` comment lines, which mysql ignores) while our own restore splits on a per-dump
 * random token — collision-proof against anything a row's data could contain.
 *
 * @api
 */
class Tiger_Backup_Database
{
    /** Rows per INSERT statement (bounds statement size / memory). */
    const CHUNK = 200;

    /**
     * Dump the whole database to a .sql file.
     *
     * @param  string $path destination file
     * @return array  ['tables' => int, 'rows' => int, 'token' => string]
     * @throws RuntimeException on a write failure
     */
    public static function dump($path)
    {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        if (!$db) { throw new RuntimeException('Tiger_Backup_Database: no DB adapter.'); }

        $token = bin2hex(random_bytes(6));
        $sep   = "\n-- @" . $token . "@\n";

        $fh = @fopen($path, 'wb');
        if (!$fh) { throw new RuntimeException('Tiger_Backup_Database: cannot write ' . $path); }

        $now = date('Y-m-d H:i:s');
        fwrite($fh, "-- TigerBackup SQL dump ({$now})\n-- TIGER_STMT_TOKEN: {$token}\n");
        foreach (["SET NAMES utf8mb4", "SET FOREIGN_KEY_CHECKS=0", "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"] as $s) {
            fwrite($fh, $s . ';' . $sep);
        }

        $tableCount = 0; $rowCount = 0;
        foreach ($db->listTables() as $table) {
            $create = $db->fetchRow('SHOW CREATE TABLE ' . $db->quoteIdentifier($table));
            $ddl    = $create['Create Table'] ?? ($create['Create View'] ?? null);
            if (!$ddl) { continue; }
            $isView = !isset($create['Create Table']);

            fwrite($fh, 'DROP ' . ($isView ? 'VIEW' : 'TABLE') . ' IF EXISTS ' . $db->quoteIdentifier($table) . ';' . $sep);
            fwrite($fh, $ddl . ';' . $sep);
            $tableCount++;
            if ($isView) { continue; }   // no rows to dump for a view

            $cols    = array_keys($db->describeTable($table));
            $colList = implode(',', array_map([$db, 'quoteIdentifier'], $cols));
            $qTable  = $db->quoteIdentifier($table);
            $offset  = 0;

            do {
                $rows = $db->fetchAll(sprintf('SELECT * FROM %s LIMIT %d OFFSET %d', $qTable, self::CHUNK, $offset));
                if (!$rows) { break; }
                $values = [];
                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($cols as $c) {
                        $v = $row[$c] ?? null;
                        $cells[] = $v === null ? 'NULL' : $db->quote($v);
                    }
                    $values[] = '(' . implode(',', $cells) . ')';
                }
                fwrite($fh, 'INSERT INTO ' . $qTable . ' (' . $colList . ") VALUES\n" . implode(",\n", $values) . ';' . $sep);
                $rowCount += count($rows);
                $offset   += self::CHUNK;
            } while (count($rows) === self::CHUNK);
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;" . $sep);
        fclose($fh);

        return ['tables' => $tableCount, 'rows' => $rowCount, 'token' => $token];
    }

    /**
     * Restore a .sql file produced by dump() into the current database (destructive — drops/recreates
     * the dumped tables). Wrapped in FK-checks-off so table order never matters.
     *
     * @param  string $path the .sql file
     * @return int    statements executed
     * @throws RuntimeException on a malformed dump or a failed statement
     */
    public static function import($path)
    {
        $sql = @file_get_contents($path);
        if ($sql === false) { throw new RuntimeException('Tiger_Backup_Database: cannot read ' . $path); }
        if (!preg_match('/^-- TIGER_STMT_TOKEN: ([0-9a-f]+)/m', $sql, $m)) {
            throw new RuntimeException('Tiger_Backup_Database: not a TigerBackup SQL dump (no statement token).');
        }
        $sep   = "\n-- @" . $m[1] . "@\n";
        $stmts = explode($sep, $sql);

        $db  = Zend_Db_Table_Abstract::getDefaultAdapter();
        $pdo = $db->getConnection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $n = 0;
        try {
            foreach ($stmts as $stmt) {
                $stmt = trim($stmt);
                // Skip blanks and pure-comment lines (the header).
                if ($stmt === '' || preg_match('/^--[^\n]*$/', $stmt)) { continue; }
                $pdo->exec($stmt);
                $n++;
            }
        } catch (Throwable $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            throw new RuntimeException('Tiger_Backup_Database: restore failed at statement ' . ($n + 1) . ': ' . $e->getMessage(), 0, $e);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return $n;
    }
}
