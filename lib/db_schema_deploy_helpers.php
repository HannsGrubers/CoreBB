<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  db_schema_deploy_helpers.php - Non-destructive       |
 |  schema comparison and deploy helpers.                |
 +-------------------------------------------------------+*/

function corebb_schema_clean_space(string $value): string
{
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function corebb_schema_normalize_def(string $value): string
{
    return strtolower(corebb_schema_clean_space($value));
}

function corebb_schema_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier: ' . $identifier);
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function corebb_schema_is_archive_identifier(string $name): bool
{
    return preg_match('/(^|_)(archive|legacy|vn|vault)(_|$)|secure_archive|is_archive_user|legacy_/i', $name) === 1;
}

function corebb_schema_split_clauses(string $sql): array
{
    $parts = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $current .= $char;

        if ($quote !== null) {
            if ($char === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }

        if ($char === '`' || $char === "'" || $char === '"') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')' && $depth > 0) {
            $depth--;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $parts[] = rtrim(substr($current, 0, -1));
            $current = '';
        }
    }

    if (trim($current) !== '') {
        $parts[] = trim($current);
    }
    return $parts;
}

function corebb_schema_index_name(string $definition): ?string
{
    if (preg_match('/^PRIMARY KEY\b/i', $definition)) {
        return 'PRIMARY';
    }
    if (preg_match('/^(?:UNIQUE\s+)?(?:FULLTEXT\s+|SPATIAL\s+)?KEY\s+`([^`]+)`/i', $definition, $match)) {
        return $match[1];
    }
    return null;
}

function corebb_schema_find_matching_paren(string $sql, int $openPos): int
{
    $depth = 0;
    $quote = null;
    $length = strlen($sql);

    for ($i = $openPos; $i < $length; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            if ($char === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }

        if ($char === '`' || $char === "'" || $char === '"') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return -1;
}

function corebb_schema_extract_create_tables(string $sql): array
{
    $tables = [];
    if (!preg_match_all('/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return $tables;
    }

    foreach ($matches as $match) {
        $table = $match[1][0];
        $openPos = $match[0][1] + strlen($match[0][0]) - 1;
        $closePos = corebb_schema_find_matching_paren($sql, $openPos);
        if ($closePos < 0) {
            continue;
        }

        $semicolon = strpos($sql, ';', $closePos);
        if ($semicolon === false) {
            continue;
        }

        $tables[] = [
            'table' => $table,
            'body' => substr($sql, $openPos + 1, $closePos - $openPos - 1),
            'options' => substr($sql, $closePos + 1, $semicolon - $closePos - 1),
        ];
    }

    return $tables;
}

function corebb_schema_parse_sql(string $sql): array
{
    $schema = [];
    foreach (corebb_schema_extract_create_tables($sql) as $createTable) {
        $table = $createTable['table'];
        $schema[$table] = [
            'columns' => [],
            'indexes' => [],
            'options' => corebb_schema_clean_space($createTable['options']),
        ];

        foreach (corebb_schema_split_clauses($createTable['body']) as $line) {
            $line = trim($line);
            if (preg_match('/^`([^`]+)`\s+(.+)$/s', $line, $columnMatch)) {
                $schema[$table]['columns'][$columnMatch[1]] = corebb_schema_clean_space($line);
                continue;
            }
            $indexName = corebb_schema_index_name($line);
            if ($indexName !== null) {
                $schema[$table]['indexes'][$indexName] = corebb_schema_clean_space($line);
            }
        }
    }

    if (preg_match_all('/ALTER TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.*?);/is', $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $table = $match[1];
            if (!isset($schema[$table])) {
                continue;
            }
            foreach (corebb_schema_split_clauses($match[2]) as $clause) {
                $clause = trim($clause);
                if (preg_match('/^ADD\s+(PRIMARY KEY\b.+)$/is', $clause, $indexMatch)
                    || preg_match('/^ADD\s+((?:UNIQUE\s+)?(?:FULLTEXT\s+|SPATIAL\s+)?KEY\b.+)$/is', $clause, $indexMatch)) {
                    $definition = corebb_schema_clean_space($indexMatch[1]);
                    $indexName = corebb_schema_index_name($definition);
                    if ($indexName !== null) {
                        $schema[$table]['indexes'][$indexName] = $definition;
                    }
                    continue;
                }
                if (preg_match('/^MODIFY\s+(`([^`]+)`\s+.+)$/is', $clause, $columnMatch)) {
                    $schema[$table]['columns'][$columnMatch[2]] = corebb_schema_clean_space($columnMatch[1]);
                }
            }
        }
    }

    ksort($schema);
    return $schema;
}

function corebb_schema_parse_dump(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Schema dump not found: ' . $path);
    }
    return corebb_schema_parse_sql((string)file_get_contents($path));
}

function corebb_schema_create_table_sql(string $table, array $tableSchema): string
{
    $lines = [];
    foreach ($tableSchema['columns'] as $definition) {
        $lines[] = '  ' . $definition;
    }
    foreach ($tableSchema['indexes'] as $definition) {
        $lines[] = '  ' . $definition;
    }

    return 'CREATE TABLE IF NOT EXISTS ' . corebb_schema_identifier($table) . " (\n"
        . implode(",\n", $lines) . "\n) " . ($tableSchema['options'] ?: 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') . ';';
}

function corebb_schema_build_plan(array $current, array $target): array
{
    $plan = ['operations' => [], 'warnings' => []];

    foreach ($target as $table => $targetTable) {
        if (!isset($current[$table])) {
            $plan['operations'][] = [
                'type' => 'create_table',
                'table' => $table,
                'archive' => corebb_schema_is_archive_identifier($table),
                'sql' => corebb_schema_create_table_sql($table, $targetTable),
            ];
            continue;
        }

        foreach ($targetTable['columns'] as $column => $definition) {
            if (!isset($current[$table]['columns'][$column])) {
                $plan['operations'][] = [
                    'type' => 'add_column',
                    'table' => $table,
                    'column' => $column,
                    'archive' => corebb_schema_is_archive_identifier($table) || corebb_schema_is_archive_identifier($column),
                    'sql' => 'ALTER TABLE ' . corebb_schema_identifier($table) . ' ADD COLUMN ' . $definition . ';',
                ];
                continue;
            }
            if (corebb_schema_normalize_def($current[$table]['columns'][$column]) !== corebb_schema_normalize_def($definition)) {
                $plan['warnings'][] = 'Definition drift preserved for column ' . $table . '.' . $column . '.';
            }
        }

        foreach ($targetTable['indexes'] as $index => $definition) {
            if (!isset($current[$table]['indexes'][$index])) {
                $plan['operations'][] = [
                    'type' => 'add_index',
                    'table' => $table,
                    'index' => $index,
                    'archive' => corebb_schema_is_archive_identifier($table) || corebb_schema_is_archive_identifier($index),
                    'sql' => 'ALTER TABLE ' . corebb_schema_identifier($table) . ' ADD ' . $definition . ';',
                ];
                continue;
            }
            if (corebb_schema_normalize_def($current[$table]['indexes'][$index]) !== corebb_schema_normalize_def($definition)) {
                $plan['warnings'][] = 'Definition drift preserved for index ' . $table . '.' . $index . '.';
            }
        }
    }

    foreach ($current as $table => $currentTable) {
        if (!isset($target[$table])) {
            $label = corebb_schema_is_archive_identifier($table) ? 'Archive/legacy table preserved: ' : 'Extra live table preserved: ';
            $plan['warnings'][] = $label . $table . '.';
            continue;
        }
        foreach ($currentTable['columns'] as $column => $_) {
            if (!isset($target[$table]['columns'][$column])) {
                $label = (corebb_schema_is_archive_identifier($table) || corebb_schema_is_archive_identifier($column))
                    ? 'Archive/legacy column preserved: '
                    : 'Extra live column preserved: ';
                $plan['warnings'][] = $label . $table . '.' . $column . '.';
            }
        }
    }

    return $plan;
}

function corebb_schema_connect(array $credentials): PDO
{
    $host = trim((string)($credentials['host'] ?? ''));
    $database = trim((string)($credentials['database'] ?? ''));
    $username = (string)($credentials['username'] ?? '');
    $password = (string)($credentials['password'] ?? '');
    $port = trim((string)($credentials['port'] ?? ''));

    if ($host === '' || $database === '' || $username === '') {
        throw new InvalidArgumentException('Database host, name, and username are required.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new InvalidArgumentException('Database name contains unsupported characters.');
    }
    if ($port === '' && strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        [$host, $port] = explode(':', $host, 2);
    }

    $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . (int)$port : '') . ';dbname=' . $database . ';charset=utf8mb4';
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

function corebb_schema_current_db_schema_pdo(PDO $pdo, string $database = ''): array
{
    if ($database === '') {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    }
    if ($database === '') {
        throw new RuntimeException('Unable to determine current database name.');
    }

    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME ASC');
    $stmt->execute([$database, 'BASE TABLE']);
    $sql = '';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $table = (string)$table;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }
        $row = $pdo->query('SHOW CREATE TABLE ' . corebb_schema_identifier($table))->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && isset($row['Create Table'])) {
            $sql .= $row['Create Table'] . ";\n";
        }
    }

    return corebb_schema_parse_sql($sql);
}

function corebb_schema_operation_still_needed(array $operation, array $current): bool
{
    $table = $operation['table'];
    if ($operation['type'] === 'create_table') {
        return !isset($current[$table]);
    }
    if (!isset($current[$table])) {
        return true;
    }
    if ($operation['type'] === 'add_column') {
        return !isset($current[$table]['columns'][$operation['column']]);
    }
    if ($operation['type'] === 'add_index') {
        return !isset($current[$table]['indexes'][$operation['index']]);
    }
    return false;
}

function corebb_schema_apply_plan_pdo(PDO $pdo, array $plan, string $database = ''): array
{
    $messages = [];
    foreach ($plan['operations'] as $operation) {
        $fresh = corebb_schema_current_db_schema_pdo($pdo, $database);
        if (!corebb_schema_operation_still_needed($operation, $fresh)) {
            $messages[] = 'Skipped already present: ' . $operation['type'] . ' on ' . $operation['table'] . '.';
            continue;
        }
        $pdo->exec($operation['sql']);
        $messages[] = 'Applied ' . $operation['type'] . ' on ' . $operation['table'] . '.';
    }
    return $messages;
}

function corebb_schema_plan_text(array $plan): string
{
    $out = "Schema deploy plan\n";
    $out .= "==================\n";
    $out .= 'Operations: ' . count($plan['operations']) . "\n";
    $out .= 'Warnings: ' . count($plan['warnings']) . "\n\n";

    foreach ($plan['operations'] as $i => $operation) {
        $label = strtoupper(str_replace('_', ' ', $operation['type']));
        $archive = $operation['archive'] ? ' [archive-sensitive]' : '';
        $out .= ($i + 1) . '. ' . $label . $archive . ' on ' . $operation['table'];
        if (isset($operation['column'])) {
            $out .= '.' . $operation['column'];
        } elseif (isset($operation['index'])) {
            $out .= '.' . $operation['index'];
        }
        $out .= "\n" . $operation['sql'] . "\n\n";
    }

    if ($plan['warnings']) {
        $out .= "Warnings/preserved drift\n";
        $out .= "------------------------\n";
        foreach ($plan['warnings'] as $warning) {
            $out .= '* ' . $warning . "\n";
        }
        $out .= "\n";
    }
    return $out;
}
