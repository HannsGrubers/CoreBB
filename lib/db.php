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
 |  db.php  - PDO-backed database helper layer for       |
 |  CoreBB.                                              |
 +-------------------------------------------------------+*/

if (!defined('COREBB_DB_LOADED')) {
    define('COREBB_DB_LOADED', true);
}

$GLOBALS['COREBB_DB_LINK'] = $GLOBALS['COREBB_DB_LINK'] ?? ($GLOBALS['WB_DB_LINK'] ?? null);
$GLOBALS['COREBB_DB_NAME'] = $GLOBALS['COREBB_DB_NAME'] ?? ($GLOBALS['WB_DB_NAME'] ?? null);
$GLOBALS['COREBB_DB_LAST_ERROR'] = $GLOBALS['COREBB_DB_LAST_ERROR'] ?? ($GLOBALS['WB_DB_LAST_ERROR'] ?? '');
$GLOBALS['COREBB_DB_LAST_ERRNO'] = $GLOBALS['COREBB_DB_LAST_ERRNO'] ?? ($GLOBALS['WB_DB_LAST_ERRNO'] ?? 0);
$GLOBALS['COREBB_DB_LAST_INSERT_ID'] = $GLOBALS['COREBB_DB_LAST_INSERT_ID'] ?? ($GLOBALS['WB_DB_LAST_INSERT_ID'] ?? 0);
$GLOBALS['COREBB_DB_LAST_AFFECTED_ROWS'] = $GLOBALS['COREBB_DB_LAST_AFFECTED_ROWS'] ?? ($GLOBALS['WB_DB_LAST_AFFECTED_ROWS'] ?? 0);

/**
 * Mirror CoreBB database globals to the legacy WB global names.
 *
 * Usage: keep older include files working while the PDO-backed helpers own the
 * canonical connection and error state.
 * Referenced by: db.php bootstrap and corebb_set_db_error().
 *
 * @return void
 */
function corebb_sync_legacy_db_globals(): void
{
    $GLOBALS['WB_DB_LINK'] = $GLOBALS['COREBB_DB_LINK'];
    $GLOBALS['WB_DB_NAME'] = $GLOBALS['COREBB_DB_NAME'];
    $GLOBALS['WB_DB_LAST_ERROR'] = $GLOBALS['COREBB_DB_LAST_ERROR'];
    $GLOBALS['WB_DB_LAST_ERRNO'] = $GLOBALS['COREBB_DB_LAST_ERRNO'];
    $GLOBALS['WB_DB_LAST_INSERT_ID'] = $GLOBALS['COREBB_DB_LAST_INSERT_ID'];
    $GLOBALS['WB_DB_LAST_AFFECTED_ROWS'] = $GLOBALS['COREBB_DB_LAST_AFFECTED_ROWS'];
}

corebb_sync_legacy_db_globals();

/**
 * Split a database host string into host and optional port.
 *
 * Usage: support config values like localhost:3307 when building the PDO DSN.
 * Referenced by: db_connect().
 *
 * @param string $host Configured database host.
 * @return array{0: string, 1: int|null} Host and optional port.
 */
function corebb_parse_db_host(string $host): array
{
    $port = null;
    if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        [$hostPart, $portPart] = explode(':', $host, 2);
        if ($portPart !== '' && ctype_digit($portPart)) {
            $host = $hostPart;
            $port = (int)$portPart;
        }
    }
    return [$host, $port];
}

/**
 * Store the last database error in CoreBB and legacy globals.
 *
 * Usage: record PDO exceptions or explicit error strings for db_error()/db_errno().
 * Referenced by: connection, statement, transaction, and insert-id helpers.
 *
 * @param Throwable|string $error Exception or error message.
 * @param int $code Optional numeric error code for string errors.
 * @return void
 */
function corebb_set_db_error(Throwable|string $error, int $code = 0): void
{
    if ($error instanceof Throwable) {
        $GLOBALS['COREBB_DB_LAST_ERROR'] = $error->getMessage();
        $GLOBALS['COREBB_DB_LAST_ERRNO'] = (int)$error->getCode();
    } else {
        $GLOBALS['COREBB_DB_LAST_ERROR'] = $error;
        $GLOBALS['COREBB_DB_LAST_ERRNO'] = $code;
    }
    corebb_sync_legacy_db_globals();
}

/**
 * Open and store the primary PDO database connection.
 *
 * Usage: initialize CoreBB's database layer from config.php values or explicit
 * connection parameters.
 * Referenced by: database.php, dbheader.php, API bootstrap, and lazy connection
 * helpers.
 *
 * @param mixed $server Hostname or host:port.
 * @param mixed $username Database username.
 * @param mixed $password Database password.
 * @param mixed $database Database/schema name.
 * @param mixed $link Retained for legacy call compatibility.
 * @return PDO|false PDO connection or false on failure.
 */
function db_connect($server = null, $username = null, $password = null, $database = null, $link = null)
{
    $server = $server ?: ($GLOBALS['MySQL_Host'] ?? 'localhost');
    $username = $username ?? ($GLOBALS['MySQL_User'] ?? 'root');
    $password = $password ?? ($GLOBALS['MySQL_Pass'] ?? '');
    $database = $database ?? ($GLOBALS['MySQL_Database'] ?? null);

    [$host, $port] = corebb_parse_db_host((string)$server);
    $dsn = 'mysql:host=' . $host . ($port ? ';port=' . $port : '') . ($database ? ';dbname=' . $database : '') . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, (string)$username, (string)$password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        $GLOBALS['COREBB_DB_LINK'] = $pdo;
        if ($database) {
            $GLOBALS['COREBB_DB_NAME'] = (string)$database;
        }
        corebb_set_db_error('', 0);
        return $pdo;
    } catch (Throwable $e) {
        corebb_set_db_error($e);
        return false;
    }
}

/**
 * Validate an identifier used in dynamic database SQL.
 *
 * Usage: allow only simple table/column/index names before quoting identifiers.
 * Referenced by: db_quote_identifier() and schema helpers.
 *
 * @param string $identifier Identifier candidate.
 * @return bool True when the identifier is safe.
 */
function db_identifier_is_safe(string $identifier): bool
{
    return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
}

/**
 * Quote a safe database identifier.
 *
 * Usage: compose SQL fragments for selected table/column/database names.
 * Referenced by: db_use_database() and schema helper code.
 *
 * @param string $identifier Identifier to quote.
 * @return string Backtick-quoted identifier.
 *
 * @throws InvalidArgumentException When the identifier is unsafe.
 */
function db_quote_identifier(string $identifier): string
{
    if (!db_identifier_is_safe($identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Switch the active database/schema on the current PDO connection.
 *
 * Usage: support legacy code paths that select databases after connecting.
 * Referenced by: database/bootstrap tools as needed.
 *
 * @param string $database Database/schema name.
 * @param mixed $link Optional PDO connection.
 * @return bool True when the USE statement succeeds.
 */
function db_use_database(string $database, $link = null): bool
{
    $pdo = corebb_db_connection($link);
    if (!$pdo instanceof PDO || !db_identifier_is_safe($database)) {
        return false;
    }

    try {
        $pdo->exec('USE ' . db_quote_identifier($database));
        $GLOBALS['COREBB_DB_NAME'] = $database;
        corebb_set_db_error('', 0);
        return true;
    } catch (Throwable $e) {
        corebb_set_db_error($e);
        return false;
    }
}

/**
 * Return a PDO connection, opening one lazily when needed.
 *
 * Usage: central connection resolver for every db_* query helper.
 * Referenced by: db_prepare_statement(), transactions, performance helpers, and
 * schema utilities.
 *
 * @param mixed $link Optional explicit PDO connection.
 * @return PDO|false Active PDO connection or false.
 */
function corebb_db_connection($link = null)
{
    $pdo = $link instanceof PDO ? $link : ($GLOBALS['COREBB_DB_LINK'] ?? null);
    if (!$pdo && !db_connect()) {
        return false;
    }
    return $pdo ?: ($GLOBALS['COREBB_DB_LINK'] ?? false);
}

/**
 * Wrap a value with an explicit PDO parameter type.
 *
 * Usage: force integer/string/bool/null binding for LIMIT clauses and other
 * places where automatic typing is not enough.
 * Referenced by: typed db_param_* helpers and import/performance repairs.
 *
 * @param mixed $value Parameter value.
 * @param int $type PDO::PARAM_* type.
 * @return array{__corebb_db_param: bool, value: mixed, type: int} Typed parameter.
 */
function db_param($value, int $type): array
{
    return ['__corebb_db_param' => true, 'value' => $value, 'type' => $type];
}

/**
 * Build an explicitly integer-bound database parameter.
 *
 * Usage: bind LIMIT/OFFSET and id parameters as integers.
 * Referenced by: performance/import/admin helpers.
 *
 * @param mixed $value Value to cast.
 * @return array<string, mixed> Typed database parameter.
 */
function db_param_int($value): array { return db_param((int)$value, PDO::PARAM_INT); }

/**
 * Build an explicitly string-bound database parameter.
 *
 * Usage: force string binding for values that could otherwise look numeric.
 * Referenced by: query helpers as needed.
 *
 * @param mixed $value Value to cast.
 * @return array<string, mixed> Typed database parameter.
 */
function db_param_str($value): array { return db_param((string)$value, PDO::PARAM_STR); }

/**
 * Build an explicitly boolean-bound database parameter.
 *
 * Usage: bind boolean values through PDO with the correct type.
 * Referenced by: query helpers as needed.
 *
 * @param mixed $value Value to cast.
 * @return array<string, mixed> Typed database parameter.
 */
function db_param_bool($value): array { return db_param((bool)$value, PDO::PARAM_BOOL); }

/**
 * Build an explicitly null-bound database parameter.
 *
 * Usage: bind SQL NULL values without relying on automatic type inference.
 * Referenced by: query helpers as needed.
 *
 * @return array<string, mixed> Typed database parameter.
 */
function db_param_null(): array { return db_param(null, PDO::PARAM_NULL); }

/**
 * Infer the PDO bind type for an ordinary parameter value.
 *
 * Usage: give unwrapped db_* parameters sensible PDO types.
 * Referenced by: corebb_db_bind_params().
 *
 * @param mixed $value Parameter value.
 * @return int PDO::PARAM_* type.
 */
function corebb_db_param_type($value): int
{
    if (is_int($value)) { return PDO::PARAM_INT; }
    if (is_bool($value)) { return PDO::PARAM_BOOL; }
    if ($value === null) { return PDO::PARAM_NULL; }
    return PDO::PARAM_STR;
}

/**
 * Bind positional or named parameters onto a prepared statement.
 *
 * Usage: execute all db_* helpers through one binding implementation, including
 * explicitly typed db_param() wrappers.
 * Referenced by: db_prepare_statement().
 *
 * @param PDOStatement $stmt Prepared statement.
 * @param array<int|string, mixed> $params Parameters to bind.
 * @return void
 */
function corebb_db_bind_params(PDOStatement $stmt, array $params): void
{
    $position = 1;
    foreach ($params as $key => $param) {
        $name = is_string($key) ? (str_starts_with($key, ':') ? $key : ':' . $key) : $position++;
        if (is_array($param) && isset($param['__corebb_db_param'])) {
            $stmt->bindValue($name, $param['value'], (int)$param['type']);
        } else {
            $stmt->bindValue($name, $param, corebb_db_param_type($param));
        }
    }
}

/**
 * Prepare, bind, and execute a database statement.
 *
 * Usage: shared execution path for read/write helpers, keeping affected rows,
 * insert id, and last error state updated.
 * Referenced by: db_run(), db_all(), db_one(), db_value(), db_exists(), and
 * db_column().
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return PDOStatement|false Executed statement or false on failure.
 */
function db_prepare_statement($query, array $params = [], $link = null)
{
    $pdo = corebb_db_connection($link);
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare((string)$query);
        corebb_db_bind_params($stmt, $params);
        $stmt->execute();
        $GLOBALS['COREBB_DB_LAST_AFFECTED_ROWS'] = (int)$stmt->rowCount();
        $GLOBALS['COREBB_DB_LAST_INSERT_ID'] = (int)$pdo->lastInsertId();
        corebb_set_db_error('', 0);
        return $stmt;
    } catch (Throwable $e) {
        corebb_set_db_error($e);
        return false;
    }
}

/**
 * Execute a statement and discard any result set.
 *
 * Usage: INSERT/UPDATE/DELETE/ALTER helper.
 * Referenced by: almost every write path in CoreBB.
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return bool True when execution succeeds.
 */
function db_run($query, array $params = [], $link = null): bool
{
    $stmt = db_prepare_statement($query, $params, $link);
    if (!$stmt) {
        return false;
    }
    $stmt->closeCursor();
    return true;
}

/**
 * Fetch all rows from a query.
 *
 * Usage: list queries and view-model row collections.
 * Referenced by: board/thread/search/admin/API helpers.
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return array<int, array<string, mixed>> Result rows.
 */
function db_all($query, array $params = [], $link = null): array
{
    $stmt = db_prepare_statement($query, $params, $link);
    if (!$stmt) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return is_array($rows) ? $rows : [];
}

/**
 * Fetch one row from a query.
 *
 * Usage: single-record lookups throughout public, API, and admin flows.
 * Referenced by: user/topic/post/profile/message helpers.
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return array<string, mixed>|false First row or false.
 */
function db_one($query, array $params = [], $link = null)
{
    $stmt = db_prepare_statement($query, $params, $link);
    if (!$stmt) {
        return false;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $row === false ? false : $row;
}

/**
 * Fetch the first column from the first row of a query.
 *
 * Usage: scalar lookups for counts, settings, and simple ids.
 * Referenced by: count helpers and settings/model code across CoreBB.
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $default Value returned when no row/column is available.
 * @param mixed $link Optional PDO connection.
 * @return mixed First column value or default.
 */
function db_value($query, array $params = [], $default = null, $link = null)
{
    $stmt = db_prepare_statement($query, $params, $link);
    if (!$stmt) {
        return $default;
    }
    $value = $stmt->fetchColumn(0);
    $stmt->closeCursor();
    return $value === false ? $default : $value;
}

/**
 * Check whether a query returns at least one row.
 *
 * Usage: existence checks for permissions, schema, duplicates, and rate limits.
 * Referenced by: schema helpers and validation flows.
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return bool True when a row exists.
 */
function db_exists($query, array $params = [], $link = null): bool
{
    $stmt = db_prepare_statement($query, $params, $link);
    if (!$stmt) {
        return false;
    }
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $stmt->closeCursor();
    return $row !== false;
}

/**
 * Fetch the first column from every row in a query.
 *
 * Usage: collect id lists for moderation, repair, and batch operations.
 * Referenced by: moderation helpers and admin/tooling flows.
 *
 * @param mixed $query SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return array<int, mixed> Column values.
 */
function db_column($query, array $params = [], $link = null): array
{
    $stmt = db_prepare_statement($query, $params, $link);
    if (!$stmt) {
        return [];
    }
    $values = [];
    while (($value = $stmt->fetchColumn(0)) !== false) {
        $values[] = $value;
    }
    $stmt->closeCursor();
    return $values;
}

/**
 * Legacy scalar alias around db_value().
 *
 * Usage: preserve old call sites while routing through the PDO helper layer.
 * Referenced by: legacy compatibility code.
 *
 * @param mixed $query SQL query.
 * @param mixed $default Default value.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return mixed Scalar query value or default.
 */
function db_scalar($query, $default = false, array $params = [], $link = null)
{
    return db_value($query, $params, $default, $link);
}

/**
 * Return an integer scalar count query.
 *
 * Usage: concise COUNT(*) wrapper for view models and performance helpers.
 * Referenced by: board/thread/search/performance helpers.
 *
 * @param mixed $query SQL count query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $link Optional PDO connection.
 * @return int Count value.
 */
function db_count_sql($query, array $params = [], $link = null): int
{
    return (int)db_value($query, $params, 0, $link);
}

/**
 * Start a database transaction.
 *
 * Usage: wrap multi-table post, moderation, PM, and admin operations.
 * Referenced by: post workflow, moderation helpers, admin tools, and repairs.
 *
 * @param mixed $link Optional PDO connection.
 * @return bool True when a transaction is active.
 */
function db_begin($link = null): bool
{
    $pdo = corebb_db_connection($link);
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        if ($pdo->inTransaction()) {
            return true;
        }
        return $pdo->beginTransaction();
    } catch (Throwable $e) {
        corebb_set_db_error($e);
        return false;
    }
}

/**
 * Commit the active database transaction.
 *
 * Usage: finish multi-table writes after all steps succeed.
 * Referenced by: post workflow, moderation helpers, admin tools, and repairs.
 *
 * @param mixed $link Optional PDO connection.
 * @return bool True when committed or no transaction was active.
 */
function db_commit($link = null): bool
{
    $pdo = corebb_db_connection($link);
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        return $pdo->inTransaction() ? $pdo->commit() : true;
    } catch (Throwable $e) {
        corebb_set_db_error($e);
        return false;
    }
}

/**
 * Roll back the active database transaction.
 *
 * Usage: undo multi-table writes when a later step fails.
 * Referenced by: post workflow, moderation helpers, admin tools, and repairs.
 *
 * @param mixed $link Optional PDO connection.
 * @return bool True when rolled back or no transaction was active.
 */
function db_rollback($link = null): bool
{
    $pdo = corebb_db_connection($link);
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        return $pdo->inTransaction() ? $pdo->rollBack() : true;
    } catch (Throwable $e) {
        corebb_set_db_error($e);
        return false;
    }
}

/**
 * Return the last insert id for the connection.
 *
 * Usage: retrieve ids after INSERT statements.
 * Referenced by: registration, post creation, polls, avatars, admin tools.
 *
 * @param mixed $link Optional PDO connection.
 * @return int Last insert id.
 */
function db_insert_id($link = null): int
{
    $pdo = $link instanceof PDO ? $link : null;
    if ($pdo instanceof PDO) {
        try { return (int)$pdo->lastInsertId(); } catch (Throwable $e) { corebb_set_db_error($e); }
    }
    return (int)($GLOBALS['COREBB_DB_LAST_INSERT_ID'] ?? 0);
}

/**
 * Return the current database/schema name.
 *
 * Usage: feed INFORMATION_SCHEMA checks in migration and helper code.
 * Referenced by: moderation, rate limit, avatar, and performance helpers.
 *
 * @return string Database/schema name or empty string.
 */
function corebb_db_connection_name(): string
{
    return (string)($GLOBALS['COREBB_DB_NAME'] ?? $GLOBALS['MySQL_Database'] ?? '');
}

/**
 * Return the last affected-row count recorded by the DB layer.
 *
 * Usage: inspect write impact after db_run()/db_prepare_statement().
 * Referenced by: helper code that needs affected-row metadata.
 *
 * @return int Last affected row count.
 */
function corebb_db_last_affected_rows(): int
{
    return (int)($GLOBALS['COREBB_DB_LAST_AFFECTED_ROWS'] ?? 0);
}

/**
 * Return the last database error message.
 *
 * Usage: surface DB failures in result models and admin/support messages.
 * Referenced by: most write helpers after db_run() failures.
 *
 * @param mixed $link Retained for legacy call compatibility.
 * @return string Last database error.
 */
function db_error($link = null): string { return (string)($GLOBALS['COREBB_DB_LAST_ERROR'] ?? ''); }

/**
 * Return the last database error number.
 *
 * Usage: detect duplicate-key and other driver-specific failures.
 * Referenced by: poll vote duplicate handling and similar write paths.
 *
 * @param mixed $link Retained for legacy call compatibility.
 * @return int Last database error number.
 */
function db_errno($link = null): int { return (int)($GLOBALS['COREBB_DB_LAST_ERRNO'] ?? 0); }

/**
 * Read and unserialize a legacy CoreBB cookie safely.
 *
 * Usage: decode signed login cookies before security verification.
 * Referenced by: CookieEngine.php, API bootstrap, logout, and auth helpers.
 *
 * @param string $name Cookie name.
 * @return array<string, mixed> Cookie payload or empty array.
 */
function corebb_read_serialized_cookie(string $name): array
{
    if (!isset($_COOKIE[$name]) || $_COOKIE[$name] === '') {
        return [];
    }
    $raw = stripslashes((string)$_COOKIE[$name]);
    try {
        $data = @unserialize($raw, ['allowed_classes' => false]);
    } catch (Throwable $e) {
        return [];
    }
    return is_array($data) ? $data : [];
}

/**
 * Clear a cookie using the security helper when it has been loaded.
 *
 * Usage: remove login cookies from both classic and API auth flows.
 * Referenced by: CookieEngine.php, API bootstrap, and logout helpers.
 *
 * @param string $name Cookie name.
 * @return void
 */
function corebb_clear_cookie(string $name): void
{
    global $CookieDomain;
    if (function_exists('corebb_security_clear_cookie')) {
        corebb_security_clear_cookie($name, '/', $CookieDomain ?? '');
        return;
    }
    setcookie($name, '', time() - 3600, '/');
    unset($_COOKIE[$name]);
}
