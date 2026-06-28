<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 |                    .oooO                              |
 |                    (   )   Oooo.                      |
 +---------------------\ (----(   )----------------------+
                        \_)    ) /
                              (_/

 +-------------------------------------------------------+
 |  db_backup_helpers.php  - Admin database backup       |
 |  helpers.                                             |
 +-------------------------------------------------------+*/

/**
 * Usage: Quote a database identifier for backup SQL.
 * Referenced by: table structure and row export helpers in this file.
 *
 * @param string $identifier Database/table/column identifier.
 * @return string Backtick-quoted identifier.
 */
function corebb_db_backup_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Normalize file and directory name fragments used in backup filenames.
 * Referenced by: corebb_db_backup_run().
 *
 * @param string $value Raw filename fragment.
 * @param string $fallback Fallback when the fragment has no safe characters.
 * @return string Safe filename fragment.
 */
function corebb_db_backup_slug(string $value, string $fallback = 'corebb'): string
{
    $slug = preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($value)) ?: '';
    $slug = trim($slug, '_-');
    return $slug !== '' ? substr($slug, 0, 80) : $fallback;
}

/**
 * Usage: Locate the private directory that should own database backups.
 * Referenced by: backup writer and recent-backup listing.
 *
 * @return string Backup directory path.
 */
function corebb_db_backup_directory(): string
{
    $override = trim((string)(getenv('COREBB_DATABASE_BACKUP_DIR') ?: getenv('COREBB_BACKUP_DIR') ?: ''));
    if ($override !== '') {
        return rtrim(str_replace('\\', '/', $override), '/');
    }

    if (defined('COREBB_PRIVATE_CONFIG_FILE')) {
        return rtrim(str_replace('\\', '/', dirname((string)COREBB_PRIVATE_CONFIG_FILE)), '/') . '/database_backups';
    }

    if (defined('COREBB_APP_ROOT')) {
        return rtrim(corebb_config_private_base_dir((string)COREBB_APP_ROOT), '/') . '/database_backups';
    }

    $root = defined('COREBB_APP_ROOT') ? (string)COREBB_APP_ROOT : dirname(__DIR__, 2);
    return rtrim(str_replace('\\', '/', dirname($root)), '/') . '/corebb_private/database_backups';
}

/**
 * Usage: Format byte counts for the admin backup list.
 * Referenced by: backup result and recent-backup display models.
 *
 * @param int $bytes Raw byte count.
 * @return string Human-readable size.
 */
function corebb_db_backup_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, $bytes);
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string)(int)$size : number_format($size, 2)) . ' ' . $units[$unit];
}

/**
 * Usage: Create the backup directory and add an Apache deny file.
 * Referenced by: corebb_db_backup_run().
 *
 * @return array{ok: bool, path: string, error: string} Directory status.
 */
function corebb_db_backup_prepare_directory(): array
{
    $dir = corebb_db_backup_directory();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'path' => $dir, 'error' => 'Could not create backup directory.'];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'path' => $dir, 'error' => 'Backup directory is not writable.'];
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
    }

    return ['ok' => true, 'path' => $dir, 'error' => ''];
}

/**
 * Usage: Return base tables and views in dependency-friendly backup order.
 * Referenced by: corebb_db_backup_run().
 *
 * @param PDO $pdo Active database connection.
 * @return array{tables: array<int, string>, views: array<int, string>} Table and view names.
 */
function corebb_db_backup_objects(PDO $pdo): array
{
    $tables = [];
    $views = [];
    $stmt = $pdo->query('SHOW FULL TABLES');
    while ($row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false) {
        $name = (string)($row[0] ?? '');
        $type = strtoupper((string)($row[1] ?? 'BASE TABLE'));
        if ($name === '') {
            continue;
        }
        if ($type === 'VIEW') {
            $views[] = $name;
        } else {
            $tables[] = $name;
        }
    }
    if ($stmt) {
        $stmt->closeCursor();
    }
    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    sort($views, SORT_NATURAL | SORT_FLAG_CASE);
    return ['tables' => $tables, 'views' => $views];
}

/**
 * Usage: Convert a PHP value to a SQL literal for INSERT rows.
 * Referenced by: corebb_db_backup_write_table_data().
 *
 * @param PDO $pdo Active database connection.
 * @param mixed $value Row value from PDO.
 * @return string SQL literal.
 */
function corebb_db_backup_sql_literal(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $quoted = $pdo->quote((string)$value);
    if ($quoted !== false) {
        return $quoted;
    }

    return "'" . str_replace(
        ["\\", "\0", "\n", "\r", "'", "\x1a"],
        ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z"],
        (string)$value
    ) . "'";
}

/**
 * Usage: Write one table's CREATE statement to the backup file.
 * Referenced by: corebb_db_backup_run().
 *
 * @param PDO $pdo Active database connection.
 * @param resource $handle Open backup file handle.
 * @param string $table Table name.
 * @return void
 */
function corebb_db_backup_write_table_schema(PDO $pdo, $handle, string $table): void
{
    fwrite($handle, "\n--\n-- Table structure for " . corebb_db_backup_identifier($table) . "\n--\n\n");
    fwrite($handle, 'DROP TABLE IF EXISTS ' . corebb_db_backup_identifier($table) . ";\n");

    $row = $pdo->query('SHOW CREATE TABLE ' . corebb_db_backup_identifier($table))->fetch(PDO::FETCH_ASSOC);
    $create = '';
    foreach ((array)$row as $key => $value) {
        if (stripos((string)$key, 'Create') !== false) {
            $create = (string)$value;
        }
    }
    fwrite($handle, $create . ";\n");
}

/**
 * Usage: Stream one table's row data as batched INSERT statements.
 * Referenced by: corebb_db_backup_run().
 *
 * @param PDO $pdo Active database connection.
 * @param resource $handle Open backup file handle.
 * @param string $table Table name.
 * @return int Number of rows written.
 */
function corebb_db_backup_write_table_data(PDO $pdo, $handle, string $table): int
{
    $rowsWritten = 0;
    $columnsSql = '';
    $batch = [];
    $batchSize = 50;

    $stmt = $pdo->prepare('SELECT * FROM ' . corebb_db_backup_identifier($table));
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($columnsSql === '') {
            $columnsSql = implode(', ', array_map('corebb_db_backup_identifier', array_keys($row)));
            fwrite($handle, "\n--\n-- Data for " . corebb_db_backup_identifier($table) . "\n--\n\n");
        }

        $values = [];
        foreach ($row as $value) {
            $values[] = corebb_db_backup_sql_literal($pdo, $value);
        }
        $batch[] = '(' . implode(', ', $values) . ')';
        $rowsWritten++;

        if (count($batch) >= $batchSize) {
            fwrite($handle, 'INSERT INTO ' . corebb_db_backup_identifier($table) . ' (' . $columnsSql . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        }
    }
    $stmt->closeCursor();

    if ($batch) {
        fwrite($handle, 'INSERT INTO ' . corebb_db_backup_identifier($table) . ' (' . $columnsSql . ") VALUES\n" . implode(",\n", $batch) . ";\n");
    }

    return $rowsWritten;
}

/**
 * Usage: Write one view definition to the backup file.
 * Referenced by: corebb_db_backup_run().
 *
 * @param PDO $pdo Active database connection.
 * @param resource $handle Open backup file handle.
 * @param string $view View name.
 * @return void
 */
function corebb_db_backup_write_view(PDO $pdo, $handle, string $view): void
{
    fwrite($handle, "\n--\n-- View structure for " . corebb_db_backup_identifier($view) . "\n--\n\n");
    fwrite($handle, 'DROP VIEW IF EXISTS ' . corebb_db_backup_identifier($view) . ";\n");

    $row = $pdo->query('SHOW CREATE VIEW ' . corebb_db_backup_identifier($view))->fetch(PDO::FETCH_ASSOC);
    $create = '';
    foreach ((array)$row as $key => $value) {
        if (stripos((string)$key, 'Create') !== false) {
            $create = (string)$value;
        }
    }
    fwrite($handle, $create . ";\n");
}

/**
 * Usage: Run a full schema/data backup of the active CoreBB database.
 * Referenced by: Database Tools admin action.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @return array<string, mixed> Backup result for the admin page.
 */
function corebb_db_backup_run(array $viewer): array
{
    $pdo = corebb_db_connection();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'message' => 'Database connection is unavailable: ' . db_error()];
    }

    $dirStatus = corebb_db_backup_prepare_directory();
    if (empty($dirStatus['ok'])) {
        return ['ok' => false, 'message' => $dirStatus['error'], 'directory' => $dirStatus['path']];
    }

    $database = corebb_db_connection_name();
    $env = defined('COREBB_ENV') ? (string)COREBB_ENV : 'local';
    $fileBase = 'corebb_' . corebb_db_backup_slug($env, 'env') . '_' . corebb_db_backup_slug($database, 'database') . '_' . date('Ymd_His') . '.sql';
    $finalPath = rtrim((string)$dirStatus['path'], '/\\') . '/' . $fileBase;
    $tempPath = $finalPath . '.tmp';

    @set_time_limit(0);
    $handle = @fopen($tempPath, 'wb');
    if (!$handle) {
        return ['ok' => false, 'message' => 'Could not open backup file for writing.', 'directory' => $dirStatus['path']];
    }

    $tableCount = 0;
    $viewCount = 0;
    $rowCount = 0;

    try {
        $objects = corebb_db_backup_objects($pdo);
        fwrite($handle, "-- CoreBB database backup\n");
        fwrite($handle, "-- Database: " . $database . "\n");
        fwrite($handle, "-- Environment: " . $env . "\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Generated by: " . (string)($viewer['username'] ?? 'Unknown') . "\n\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($handle, "SET time_zone = '+00:00';\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        foreach ($objects['tables'] as $table) {
            corebb_db_backup_write_table_schema($pdo, $handle, $table);
            $rowCount += corebb_db_backup_write_table_data($pdo, $handle, $table);
            $tableCount++;
        }

        foreach ($objects['views'] as $view) {
            corebb_db_backup_write_view($pdo, $handle, $view);
            $viewCount++;
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);
        $handle = null;

        if (!@rename($tempPath, $finalPath)) {
            @unlink($tempPath);
            return ['ok' => false, 'message' => 'Backup completed, but the final file could not be moved into place.', 'directory' => $dirStatus['path']];
        }

        $bytes = (int)@filesize($finalPath);
        return [
            'ok' => true,
            'message' => 'Database backup created: ' . $fileBase . ' (' . corebb_db_backup_format_bytes($bytes) . ').',
            'file' => $fileBase,
            'path' => $finalPath,
            'directory' => $dirStatus['path'],
            'bytes' => $bytes,
            'size' => corebb_db_backup_format_bytes($bytes),
            'tables' => $tableCount,
            'views' => $viewCount,
            'rows' => $rowCount,
        ];
    } catch (Throwable $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Database backup failed: ' . $e->getMessage(), 'directory' => $dirStatus['path']];
    }
}

/**
 * Usage: List recent backup files for the Database Tools page.
 * Referenced by: corebb_admin_maintenance_model().
 *
 * @param int $limit Maximum files to return.
 * @return array<int, array<string, mixed>> Recent backup file summaries.
 */
function corebb_db_backup_recent(int $limit = 5): array
{
    $dir = corebb_db_backup_directory();
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (glob(rtrim($dir, '/\\') . '/*.sql') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $mtime = (int)@filemtime($path);
        $bytes = (int)@filesize($path);
        $files[] = [
            'file' => basename($path),
            'size' => corebb_db_backup_format_bytes($bytes),
            'bytes' => $bytes,
            'created' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '',
            'mtime' => $mtime,
        ];
    }

    usort($files, static function (array $a, array $b): int {
        return (int)($b['mtime'] ?? 0) <=> (int)($a['mtime'] ?? 0);
    });

    return array_slice($files, 0, max(1, $limit));
}
