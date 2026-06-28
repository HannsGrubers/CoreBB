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
 |  performance_helpers.php  - Performance helpers for   |
 |  the revived CoreBB codebase.                         |
 +-------------------------------------------------------+*/

if (!defined('COREBB_PERF_HELPERS_LOADED')) {
    define('COREBB_PERF_HELPERS_LOADED', true);
}

/**
 * Resolve the active PDO connection for performance helpers.
 *
 * Usage: give cache/rebuild/repair utilities direct access to the target
 * database connection.
 * Referenced by: performance DB-name lookup and VN repair helpers.
 *
 * @return PDO|null Active PDO connection or null.
 */
function corebb_perf_pdo(): ?PDO
{
    $pdo = corebb_db_connection();
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (db_connect()) {
        $pdo = corebb_db_connection();
        return $pdo instanceof PDO ? $pdo : null;
    }
    return null;
}

/**
 * Return the target database/schema name for INFORMATION_SCHEMA checks.
 *
 * Usage: cache the database name used by table/column/index existence helpers.
 * Referenced by: table, column, and index helpers in this file.
 *
 * @return string Database/schema name.
 */
function corebb_perf_db_name(): string
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $db = corebb_db_connection_name();
    if ($db === '') {
        $pdo = corebb_perf_pdo();
        if ($pdo) {
            $db = (string)db_value('SELECT DATABASE()', [], '');
        }
    }
    return $db;
}

/**
 * Fetch one scalar value for performance helpers.
 *
 * Usage: small wrapper around db_value() for count/cache helpers.
 * Referenced by: count, setting, and schema inspection helpers.
 *
 * @param string $sql Parameterized SQL query.
 * @param array<int|string, mixed> $params Bound parameters.
 * @param mixed $default Default value when no value is returned.
 * @return mixed Scalar value or default.
 */
function corebb_perf_fetch_value(string $sql, array $params = [], $default = null)
{
    return db_value($sql, $params, $default);
}

/**
 * Check whether a table exists in the target database.
 *
 * Usage: keep schema install/repair helpers safe on partially upgraded installs.
 * Referenced by: schema, cache, import repair, and VN cleanup helpers.
 *
 * @param string $table Table name to inspect.
 * @return bool True when the table exists.
 */
function corebb_perf_table_exists(string $table): bool
{
    static $cache = [];
    $key = corebb_perf_db_name() . '.' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $count = corebb_perf_fetch_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [corebb_perf_db_name(), $table],
        0
    );
    return $cache[$key] = ((int)$count > 0);
}

/**
 * Check whether a column exists in the target database.
 *
 * Usage: guard optional cached-count and archive repair columns before use.
 * Referenced by: schema install, cache checks, and rebuild helpers.
 *
 * @param string $table Table name to inspect.
 * @param string $column Column name to inspect.
 * @return bool True when the column exists.
 */
function corebb_perf_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = corebb_perf_db_name() . '.' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $count = corebb_perf_fetch_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [corebb_perf_db_name(), $table, $column],
        0
    );
    return $cache[$key] = ((int)$count > 0);
}

/**
 * Check whether every named column exists on a table.
 *
 * Usage: validate index specs before attempting ALTER TABLE.
 * Referenced by: index helpers and search fulltext readiness.
 *
 * @param string $table Table name to inspect.
 * @param array<int, string> $columns Column names to require.
 * @return bool True when every column exists.
 */
function corebb_perf_columns_exist(string $table, array $columns): bool
{
    foreach ($columns as $column) {
        if (!corebb_perf_column_exists($table, $column)) {
            return false;
        }
    }
    return true;
}

/**
 * Validate a dynamic schema identifier.
 *
 * Usage: prevent unsafe table/index/column names in performance ALTER SQL.
 * Referenced by: quoting and schema install helpers.
 *
 * @param string $identifier Identifier candidate.
 * @return bool True when the identifier is safe.
 */
function corebb_perf_valid_identifier(string $identifier): bool
{
    return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
}

/**
 * Quote a safe dynamic schema identifier.
 *
 * Usage: compose ALTER TABLE statements from known-safe identifiers.
 * Referenced by: column and index install helpers.
 *
 * @param string $identifier Identifier to quote.
 * @return string Backtick-quoted identifier.
 *
 * @throws InvalidArgumentException When the identifier is unsafe.
 */
function corebb_perf_quote_identifier(string $identifier): string
{
    if (!corebb_perf_valid_identifier($identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Check whether an index exists in the target database.
 *
 * Usage: keep schema/index setup idempotent.
 * Referenced by: index add helpers, search readiness, and rebuild checks.
 *
 * @param string $table Table name.
 * @param string $index Index name.
 * @return bool True when the index exists.
 */
function corebb_perf_index_exists(string $table, string $index): bool
{
    $count = corebb_perf_fetch_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [corebb_perf_db_name(), $table, $index],
        0
    );
    return ((int)$count > 0);
}

/**
 * Add a column if it is missing.
 *
 * Usage: install cached-count and moderation/archive columns during maintenance
 * without failing repeat runs.
 * Referenced by: private board setup and performance schema install.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param string $definition SQL column definition.
 * @return string Human-readable operation result.
 */
function corebb_perf_add_column_if_missing(string $table, string $column, string $definition): string
{
    if (!corebb_perf_valid_identifier($table) || !corebb_perf_valid_identifier($column)) {
        return "Skipped {$table}.{$column}: invalid identifier";
    }
    if (!corebb_perf_table_exists($table)) {
        return "Skipped {$table}.{$column}: table missing";
    }
    if (corebb_perf_column_exists($table, $column)) {
        return "Column exists: {$table}.{$column}";
    }
    db_run('ALTER TABLE ' . corebb_perf_quote_identifier($table) . ' ADD COLUMN ' . corebb_perf_quote_identifier($column) . ' ' . $definition);
    return db_error() ? "Column add error {$table}.{$column}: " . db_error() : "Added column: {$table}.{$column}";
}

/**
 * Add a normal index if it is missing.
 *
 * Usage: install query-performance indexes safely during maintenance.
 * Referenced by: private board setup and performance schema install.
 *
 * @param string $table Table name.
 * @param string $indexName Index name.
 * @param array<int, string> $columns Indexed column names.
 * @return string Human-readable operation result.
 */
function corebb_perf_add_index_if_missing(string $table, string $indexName, array $columns): string
{
    if (!corebb_perf_valid_identifier($table) || !corebb_perf_valid_identifier($indexName)) {
        return "Skipped index {$indexName}: invalid identifier";
    }
    foreach ($columns as $column) {
        if (!corebb_perf_valid_identifier((string)$column)) {
            return "Skipped index {$indexName}: invalid column identifier on {$table}";
        }
    }
    if (!corebb_perf_table_exists($table)) {
        return "Skipped index {$indexName}: table {$table} missing";
    }
    if (!corebb_perf_columns_exist($table, $columns)) {
        return "Skipped index {$indexName}: one or more columns missing on {$table}";
    }
    if (corebb_perf_index_exists($table, $indexName)) {
        return "Index exists: {$table}.{$indexName}";
    }
    $quotedColumns = array_map(static fn($c) => corebb_perf_quote_identifier((string)$c), $columns);
    db_run('ALTER TABLE ' . corebb_perf_quote_identifier($table) . ' ADD INDEX ' . corebb_perf_quote_identifier($indexName) . ' (' . implode(',', $quotedColumns) . ')');
    return db_error() ? "Index add error {$table}.{$indexName}: " . db_error() : "Added index: {$table}.{$indexName}";
}

/**
 * Add a fulltext index if it is missing.
 *
 * Usage: prepare optional fulltext search indexes for search_view_model.php.
 * Referenced by: search index preparation.
 *
 * @param string $table Table name.
 * @param string $indexName Fulltext index name.
 * @param array<int, string> $columns Indexed column names.
 * @return string Human-readable operation result.
 */
function corebb_perf_add_fulltext_index_if_missing(string $table, string $indexName, array $columns): string
{
    if (!corebb_perf_valid_identifier($table) || !corebb_perf_valid_identifier($indexName)) {
        return "Skipped fulltext index {$indexName}: invalid identifier";
    }
    foreach ($columns as $column) {
        if (!corebb_perf_valid_identifier((string)$column)) {
            return "Skipped fulltext index {$indexName}: invalid column identifier on {$table}";
        }
    }
    if (!corebb_perf_table_exists($table)) {
        return "Skipped fulltext index {$indexName}: table {$table} missing";
    }
    if (!corebb_perf_columns_exist($table, $columns)) {
        return "Skipped fulltext index {$indexName}: one or more columns missing on {$table}";
    }
    if (corebb_perf_index_exists($table, $indexName)) {
        return "Fulltext index exists: {$table}.{$indexName}";
    }
    $quotedColumns = array_map(static fn($c) => corebb_perf_quote_identifier((string)$c), $columns);
    db_run('ALTER TABLE ' . corebb_perf_quote_identifier($table) . ' ADD FULLTEXT KEY ' . corebb_perf_quote_identifier($indexName) . ' (' . implode(',', $quotedColumns) . ')');
    return db_error() ? "Fulltext index add error {$table}.{$indexName}: " . db_error() : "Added fulltext index: {$table}.{$indexName}";
}

/**
 * Return the fulltext search index specification list.
 *
 * Usage: keep search readiness checks and index creation in sync.
 * Referenced by: search readiness and preparation helpers.
 *
 * @return array<int, array{0: string, 1: string, 2: array<int, string>}> Fulltext index specs.
 */
function corebb_perf_search_index_specs(): array
{
    return [
        ['posts', 'ft_posts_title_body', ['title', 'body']],
        ['topics', 'ft_topics_title', ['title']],
    ];
}

/**
 * Check whether optional fulltext search indexes are ready.
 *
 * Usage: let search_view_model.php choose fulltext search only when configured
 * and verified.
 * Referenced by: search view model.
 *
 * @return bool True when search fulltext indexes are available.
 */
function corebb_perf_search_fulltext_ready(): bool
{
    if (corebb_perf_get_setting('search_fulltext_ready', '0') !== '1') {
        return false;
    }
    foreach (corebb_perf_search_index_specs() as [$table, $index, $columns]) {
        if (!corebb_perf_table_exists($table) || !corebb_perf_columns_exist($table, $columns) || !corebb_perf_index_exists($table, $index)) {
            return false;
        }
    }
    return true;
}

/**
 * Create optional fulltext search indexes and mark readiness.
 *
 * Usage: admin/maintenance action for preparing faster search.
 * Referenced by: admin maintenance/performance tools.
 *
 * @return array<int, string> Human-readable operation messages.
 */
function corebb_perf_prepare_search_indexes(): array
{
    $out = [];
    foreach (corebb_perf_search_index_specs() as [$table, $index, $columns]) {
        $out[] = corebb_perf_add_fulltext_index_if_missing($table, $index, $columns);
    }

    $ready = true;
    foreach (corebb_perf_search_index_specs() as [$table, $index, $columns]) {
        if (!corebb_perf_table_exists($table) || !corebb_perf_columns_exist($table, $columns) || !corebb_perf_index_exists($table, $index)) {
            $ready = false;
            break;
        }
    }

    corebb_perf_set_setting('search_fulltext_ready', $ready ? '1' : '0');
    if ($ready) {
        corebb_perf_set_setting('search_fulltext_prepared_at', date('Y-m-d H:i:s'));
        $out[] = 'Search fulltext indexes are ready.';
    } else {
        $out[] = 'Search fulltext indexes are not fully ready. Review any index errors above.';
    }

    return $out;
}

/**
 * Read a performance setting from systemsettings.
 *
 * Usage: store feature readiness flags and rebuild timestamps.
 * Referenced by: search/cache readiness and maintenance helpers.
 *
 * @param string $name Setting name.
 * @param string $default Fallback value.
 * @return string Setting value.
 */
function corebb_perf_get_setting(string $name, string $default = ''): string
{
    return (string)db_value('SELECT setting FROM systemsettings WHERE name = ? ORDER BY id DESC LIMIT 1', [$name], $default);
}

/**
 * Write a performance setting to systemsettings.
 *
 * Usage: persist cache/search readiness and rebuild timestamps.
 * Referenced by: search/cache preparation and rebuild helpers.
 *
 * @param string $name Setting name.
 * @param string $value Setting value.
 * @return void
 */
function corebb_perf_set_setting(string $name, string $value): void
{
    $row = db_one('SELECT id FROM systemsettings WHERE name = ? ORDER BY id ASC LIMIT 1', [$name]);
    if ($row) {
        db_run('UPDATE systemsettings SET setting = ? WHERE id = ?', [$value, (int)$row['id']]);
    } else {
        db_run('INSERT INTO systemsettings (name, setting) VALUES (?, ?)', [$name, $value]);
    }
}

/**
 * Check whether cached forum/topic counts can be trusted.
 *
 * Usage: let public board/thread views use cached counts only after the schema
 * and rebuild flag are ready.
 * Referenced by: public count helpers and post workflow increments.
 *
 * @return bool True when count cache is ready.
 */
function corebb_perf_cache_ready(): bool
{
    return corebb_perf_get_setting('perf_cache_ready', '0') === '1'
        && corebb_perf_column_exists('forums', 'topiccount')
        && corebb_perf_column_exists('forums', 'postcount')
        && corebb_perf_column_exists('topics', 'replycount')
        && corebb_perf_column_exists('topics', 'postcount');
}

/**
 * Install performance cache columns and indexes.
 *
 * Usage: maintenance action that prepares cached counts and common query
 * indexes for public board/thread/search paths.
 * Referenced by: count rebuild and last-activity rebuild helpers.
 *
 * @return array<int, string> Human-readable operation messages.
 */
function corebb_perf_install_schema(): array
{
    $out = [];
    $out[] = corebb_perf_add_column_if_missing('forums', 'topiccount', 'INT NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('forums', 'postcount', 'INT NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('topics', 'replycount', 'INT NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('topics', 'postcount', 'INT NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('topics', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('topics', 'deleted_at', "VARCHAR(64) NOT NULL DEFAULT ''");
    $out[] = corebb_perf_add_column_if_missing('topics', 'deleted_by', 'INT(11) NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('topics', 'delete_reason', 'TEXT NULL');
    $out[] = corebb_perf_add_column_if_missing('posts', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('posts', 'deleted_at', "VARCHAR(64) NOT NULL DEFAULT ''");
    $out[] = corebb_perf_add_column_if_missing('posts', 'deleted_by', 'INT(11) NOT NULL DEFAULT 0');
    $out[] = corebb_perf_add_column_if_missing('posts', 'delete_reason', 'TEXT NULL');

    $indexSpecs = [
        ['forums', 'idx_forums_category_position', ['categoryid', 'position']],
        ['forums', 'idx_forums_legacy_board', ['legacy_board_id']],
        ['topics', 'idx_topics_board_sticky_lastpost', ['boardid', 'sticky', 'lastpost']],
        ['topics', 'idx_topics_board_lastpost', ['boardid', 'lastpost']],
        ['topics', 'idx_topics_legacy_topic', ['legacy_source', 'legacy_topic_id']],
        ['topics', 'idx_topics_legacy_board', ['legacy_board_id']],
        ['topics', 'idx_topics_poster_time', ['posterid', 'time']],
        ['topics', 'idx_topics_deleted_board', ['is_deleted', 'boardid']],
        ['posts', 'idx_posts_thread_id', ['threadid', 'id']],
        ['posts', 'idx_posts_board_timeraw', ['boardid', 'posttimeraw']],
        ['posts', 'idx_posts_board_id', ['boardid', 'id']],
        ['posts', 'idx_posts_poster_id', ['posterid']],
        ['posts', 'idx_posts_ptd', ['ptd']],
        ['posts', 'idx_posts_legacy_post', ['legacy_source', 'legacy_post_id']],
        ['posts', 'idx_posts_legacy_topic', ['legacy_topic_id']],
        ['posts', 'idx_posts_legacy_board', ['legacy_board_id']],
        ['posts', 'idx_posts_deleted_thread', ['is_deleted', 'threadid']],
        ['posts', 'idx_posts_deleted_board', ['is_deleted', 'boardid']],
        ['users', 'idx_users_username', ['username']],
        ['users', 'idx_users_legacy_user', ['legacy_source', 'legacy_user_id']],
        ['favoriteboards', 'idx_favorite_owner_board', ['ownerid', 'boardid']],
        ['privatemessages', 'idx_pm_receiver_read', ['recieveid', 'markread', 'id']],
        ['privatemessages', 'idx_pm_sender', ['senderid', 'id']],
    ];

    foreach ($indexSpecs as [$table, $index, $columns]) {
        $out[] = corebb_perf_add_index_if_missing($table, $index, $columns);
    }
    return $out;
}

/**
 * Rebuild cached topic/forum/user post counts.
 *
 * Usage: maintenance action after migrations, imports, or counter drift.
 * Referenced by: admin maintenance/performance tools.
 *
 * @return array<int, string> Human-readable operation messages.
 */
function corebb_perf_rebuild_cached_counts(): array
{
    $out = corebb_perf_install_schema();
    db_run('UPDATE `topics` t LEFT JOIN (SELECT `threadid`, COUNT(*) AS c FROM `posts` WHERE `is_deleted` = 0 GROUP BY `threadid`) p ON p.`threadid` = t.`id` SET t.`postcount` = COALESCE(p.c, 0), t.`replycount` = GREATEST(COALESCE(p.c, 0) - 1, 0)');
    $out[] = db_error() ? 'Topic count rebuild error: ' . db_error() : 'Rebuilt topic post/reply counts.';

    db_run('UPDATE `forums` f LEFT JOIN (SELECT t.`boardid`, COUNT(DISTINCT t.`id`) AS c FROM `topics` t INNER JOIN `posts` p ON p.`threadid` = t.`id` AND p.`is_deleted` = 0 WHERE t.`is_deleted` = 0 GROUP BY t.`boardid`) tc ON tc.`boardid` = f.`id` SET f.`topiccount` = COALESCE(tc.c, 0)');
    $out[] = db_error() ? 'Forum topic-count rebuild error: ' . db_error() : 'Rebuilt forum topic counts.';

    db_run('UPDATE `forums` f LEFT JOIN (SELECT t.`boardid`, COUNT(*) AS c FROM `posts` p INNER JOIN `topics` t ON t.`id` = p.`threadid` AND t.`is_deleted` = 0 WHERE p.`is_deleted` = 0 GROUP BY t.`boardid`) pc ON pc.`boardid` = f.`id` SET f.`postcount` = COALESCE(pc.c, 0)');
    $out[] = db_error() ? 'Forum post-count rebuild error: ' . db_error() : 'Rebuilt forum post counts.';

    db_run('UPDATE `users` u LEFT JOIN (SELECT `posterid`, COUNT(*) AS c FROM `posts` WHERE `is_deleted` = 0 GROUP BY `posterid`) p ON p.`posterid` = u.`id` SET u.`posts` = COALESCE(p.c, 0)');
    $out[] = db_error() ? 'User post-count rebuild error: ' . db_error() : 'Rebuilt user post counts.';

    corebb_perf_set_setting('perf_cache_ready', '1');
    corebb_perf_set_setting('perf_cache_rebuilt_at', date('Y-m-d H:i:s'));
    $out[] = 'Performance count cache enabled.';
    return $out;
}

/**
 * Return the visible topic count for one forum.
 *
 * Usage: public board index count helper using cache when ready.
 * Referenced by: index/board view models.
 *
 * @param array<string, mixed> $forumRow Forum row.
 * @return int Visible topic count.
 */
function corebb_forum_topic_count(array $forumRow): int
{
    if (corebb_perf_cache_ready() && array_key_exists('topiccount', $forumRow)) {
        return (int)$forumRow['topiccount'];
    }
    return db_count_sql('SELECT COUNT(DISTINCT t.`id`) FROM `topics` t INNER JOIN `posts` p ON p.`threadid` = t.`id` AND p.`is_deleted` = 0 WHERE t.`boardid` = ? AND t.`is_deleted` = 0', [(int)($forumRow['id'] ?? 0)]);
}

/**
 * Return the visible post count for one forum.
 *
 * Usage: public board index count helper using cache when ready.
 * Referenced by: index/board view models.
 *
 * @param array<string, mixed> $forumRow Forum row.
 * @return int Visible post count.
 */
function corebb_forum_post_count(array $forumRow): int
{
    if (corebb_perf_cache_ready() && array_key_exists('postcount', $forumRow)) {
        return (int)$forumRow['postcount'];
    }
    return db_count_sql('SELECT COUNT(*) FROM `posts` p INNER JOIN `topics` t ON t.`id` = p.`threadid` AND t.`is_deleted` = 0 WHERE t.`boardid` = ? AND p.`is_deleted` = 0', [(int)($forumRow['id'] ?? 0)]);
}

/**
 * Return the visible reply count for one topic.
 *
 * Usage: board/thread view helper using cached replycount when ready.
 * Referenced by: board and thread view models.
 *
 * @param array<string, mixed> $topicRow Topic row.
 * @return int Visible reply count.
 */
function corebb_topic_reply_count(array $topicRow): int
{
    if (corebb_perf_cache_ready() && array_key_exists('replycount', $topicRow)) {
        return max(0, (int)$topicRow['replycount']);
    }
    $count = db_count_sql('SELECT COUNT(*) FROM `posts` WHERE `threadid` = ? AND `is_deleted` = 0', [(int)($topicRow['id'] ?? 0)]);
    return max(0, $count - 1);
}

/**
 * Return the visible post count for one topic id.
 *
 * Usage: topic/detail helper using cached postcount when ready.
 * Referenced by: thread and pagination helpers.
 *
 * @param int $topicId Topic id.
 * @return int Visible post count.
 */
function corebb_topic_post_count(int $topicId): int
{
    if (corebb_perf_cache_ready()) {
        $count = corebb_perf_fetch_value('SELECT `postcount` FROM `topics` WHERE `id` = ? LIMIT 1', [$topicId], null);
        if ($count !== null) {
            return (int)$count;
        }
    }
    return db_count_sql('SELECT COUNT(*) FROM `posts` WHERE `threadid` = ? AND `is_deleted` = 0', [(int)$topicId]);
}

/**
 * Return the board id for one topic.
 *
 * Usage: lightweight topic-to-board lookup for routing/count helpers.
 * Referenced by: public view models and maintenance helpers.
 *
 * @param int $topicId Topic id.
 * @return int Board id or 0.
 */
function corebb_topic_board_id(int $topicId): int
{
    return (int)corebb_perf_fetch_value('SELECT `boardid` FROM `topics` WHERE `id` = ? LIMIT 1', [$topicId], 0);
}

/**
 * Return total visible forum posts.
 *
 * Usage: footer/stat display and admin footer counts.
 * Referenced by: public/admin layout footers.
 *
 * @return int Total visible post count.
 */
function corebb_perf_total_posts(): int
{
    if (corebb_perf_cache_ready()) {
        return (int)corebb_perf_fetch_value('SELECT COALESCE(SUM(`postcount`), 0) FROM `forums`', [], 0);
    }
    if (corebb_perf_column_exists('posts', 'is_deleted')) {
        return db_count_sql('SELECT COUNT(*) FROM `posts` WHERE `is_deleted` = 0');
    }
    return db_count_sql('SELECT COUNT(*) FROM `posts`');
}

/**
 * Return total forum board rows.
 *
 * Usage: footer/stat display and admin footer counts.
 * Referenced by: public/admin layout footers.
 *
 * @return int Total forum count.
 */
function corebb_perf_total_forums(): int
{
    return db_count_sql('SELECT COUNT(*) FROM `forums`');
}

/**
 * Return the visible post count for one legacy ptd date.
 *
 * Usage: footer "messages today" statistics.
 * Referenced by: public/admin layout footers.
 *
 * @param string $ptd Legacy post-date field value.
 * @return int Visible posts for that date.
 */
function corebb_perf_today_posts(string $ptd): int
{
    if (corebb_perf_column_exists('posts', 'is_deleted')) {
        return db_count_sql('SELECT COUNT(*) FROM `posts` WHERE `ptd` = ? AND `is_deleted` = 0', [$ptd]);
    }
    return db_count_sql('SELECT COUNT(*) FROM `posts` WHERE `ptd` = ?', [$ptd]);
}

/**
 * Build a SQL expression for parsing CoreBB/VN timestamp strings.
 *
 * Usage: normalize legacy date strings while rebuilding last-activity fields.
 * Referenced by: corebb_perf_rebuild_last_activity().
 *
 * @param string $columnSql SQL expression for the source date column.
 * @return string SQL expression that parses supported timestamp shapes.
 */
function corebb_perf_sql_date_expr(string $columnSql): string
{
    // Handles old CoreBB timestamps like 2026-5-28 08:17:44 and repaired ISO-ish timestamps.
    return "COALESCE(STR_TO_DATE({$columnSql}, '%Y-%c-%e %H:%i:%s'), STR_TO_DATE({$columnSql}, '%Y-%m-%d %H:%i:%s'))";
}

/**
 * Rebuild last-activity fields from visible posts.
 *
 * Usage: maintenance action after migrations, moderation, or counter drift.
 * Referenced by: admin maintenance/performance tools.
 *
 * @return array<int, string> Human-readable operation messages.
 */
function corebb_perf_rebuild_last_activity(): array
{
    $out = corebb_perf_install_schema();
    $pdo = corebb_perf_pdo();
    if (!$pdo) {
        return ['Target DB connection unavailable.'];
    }

    @set_time_limit(0);

    $postDt = corebb_perf_sql_date_expr('`posttime`');

    // Normalize posttimeraw where possible. Some early imported rows had the formatted date string here.
    if (corebb_perf_column_exists('posts', 'posttimeraw')) {
        db_run("UPDATE `posts`
            SET `posttimeraw` = UNIX_TIMESTAMP({$postDt})
            WHERE `posttime` <> ''
              AND {$postDt} IS NOT NULL
              AND (`posttimeraw` IS NULL OR `posttimeraw` = '' OR `posttimeraw` = '0' OR `posttimeraw` NOT REGEXP '^[0-9]+$')");
        $out[] = db_error() ? 'Post raw-time normalization error: ' . db_error() : 'Normalized posttimeraw values where needed.';
    }

    // Rebuild topic last activity from the newest post in each topic.
    $topicSet = "t.`lastpost` = DATE_FORMAT(p.`last_dt`, '%Y-%m-%d %H:%i:%s')";
    if (corebb_perf_column_exists('topics', 'now')) {
        $topicSet .= ", t.`now` = p.`last_ts`";
    }
    if (corebb_perf_column_exists('topics', 'postcount')) {
        $topicSet .= ", t.`postcount` = p.`post_count`";
    }
    if (corebb_perf_column_exists('topics', 'replycount')) {
        $topicSet .= ", t.`replycount` = GREATEST(p.`post_count` - 1, 0)";
    }
    db_run("UPDATE `topics` t
        JOIN (
            SELECT `threadid`, MAX({$postDt}) AS `last_dt`, MAX(UNIX_TIMESTAMP({$postDt})) AS `last_ts`, COUNT(*) AS `post_count`
            FROM `posts`
            WHERE `threadid` > 0 AND `is_deleted` = 0 AND `posttime` <> '' AND {$postDt} IS NOT NULL
            GROUP BY `threadid`
        ) p ON p.`threadid` = t.`id`
        SET {$topicSet}");
    $out[] = db_error() ? 'Topic last-activity rebuild error: ' . db_error() : 'Rebuilt topic last-activity dates and topic counts from posts.';

    // Rebuild forum last-post fields and cached counts from posts/topics.
    $forumPostDt = corebb_perf_sql_date_expr('p2.`posttime`');
    $forumPostCountSet = corebb_perf_column_exists('forums', 'postcount') ? ", f.`postcount` = COALESCE(p.`post_count`, 0)" : '';
    db_run("UPDATE `forums` f
        LEFT JOIN (
            SELECT t.`boardid`, MAX({$forumPostDt}) AS `last_dt`, MAX(UNIX_TIMESTAMP({$forumPostDt})) AS `last_ts`, COUNT(*) AS `post_count`
            FROM `posts` p2
            INNER JOIN `topics` t ON t.`id` = p2.`threadid` AND t.`is_deleted` = 0
            WHERE p2.`is_deleted` = 0 AND p2.`posttime` <> '' AND {$forumPostDt} IS NOT NULL
            GROUP BY t.`boardid`
        ) p ON p.`boardid` = f.`id`
        SET f.`lastpstdate` = IF(p.`last_dt` IS NULL, '', DATE_FORMAT(p.`last_dt`, '%Y-%m-%d %H:%i:%s')),
            f.`lastpstdatets` = IF(p.`last_ts` IS NULL, '', p.`last_ts`)
            {$forumPostCountSet}");
    $out[] = db_error() ? 'Forum last-activity rebuild error: ' . db_error() : 'Rebuilt forum last-post dates from posts.';

    if (corebb_perf_column_exists('forums', 'topiccount')) {
        db_run("UPDATE `forums` f
            LEFT JOIN (
                SELECT t.`boardid`, COUNT(DISTINCT t.`id`) AS `topic_count`
                FROM `topics` t
                INNER JOIN `posts` p ON p.`threadid` = t.`id` AND p.`is_deleted` = 0
                WHERE t.`is_deleted` = 0
                GROUP BY t.`boardid`
            ) tc ON tc.`boardid` = f.`id`
            SET f.`topiccount` = COALESCE(tc.`topic_count`, 0)");
        $out[] = db_error() ? 'Forum topic-count rebuild error: ' . db_error() : 'Rebuilt forum topic counts.';
    }

    // Rebuild user post counts and visible last-post date from authored posts.
    $userSet = "u.`posts` = COALESCE(p.`post_count`, 0)";
    if (corebb_perf_column_exists('users', 'lastpost')) {
        $userSet .= ", u.`lastpost` = IF(p.`last_ts` IS NULL, '', p.`last_ts`)";
    }
    if (corebb_perf_column_exists('users', 'lastpstdate')) {
        $userSet .= ", u.`lastpstdate` = IF(p.`last_dt` IS NULL, '', DATE_FORMAT(p.`last_dt`, '%Y-%m-%d %H:%i:%s'))";
    }
    db_run("UPDATE `users` u
        LEFT JOIN (
            SELECT `posterid`, MAX({$postDt}) AS `last_dt`, MAX(UNIX_TIMESTAMP({$postDt})) AS `last_ts`, COUNT(*) AS `post_count`
            FROM `posts`
            WHERE `posterid` > 0 AND `is_deleted` = 0 AND `posttime` <> '' AND {$postDt} IS NOT NULL
            GROUP BY `posterid`
        ) p ON p.`posterid` = u.`id`
        SET {$userSet}");
    $out[] = db_error() ? 'User last-post rebuild error: ' . db_error() : 'Rebuilt user post counts and last-post dates.';

    corebb_perf_set_setting('perf_cache_ready', '1');
    corebb_perf_set_setting('last_activity_rebuilt_at', date('Y-m-d H:i:s'));
    corebb_perf_set_setting('perf_cache_rebuilt_at', date('Y-m-d H:i:s'));
    $out[] = 'Last-activity repair complete. Count cache remains enabled.';
    return $out;
}
