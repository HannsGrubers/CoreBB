<?php
require_once __DIR__ . '/auth_password_helpers.php';
/**
 * Legacy VNBoards SQL dump importer helpers.
 *
 * Designed for phpMyAdmin-style INSERT dumps. It streams rows instead of loading
 * the whole SQL file, because posts.sql can be hundreds of megabytes.
 */

function wb_vn_import_usage(): string
{
    return <<<TXT
VNBoards archive importer

Dry-run / preview:
  php tools/vn_import_cli.php --users=/path/users.sql --topics=/path/topics.sql --posts=/path/posts.sql --registration-dates=/path/registration_dates.sql --dry-run --preview-posts=2

Real import:
  php tools/vn_import_cli.php --users=/path/users.sql --topics=/path/topics.sql --posts=/path/posts.sql --registration-dates=/path/registration_dates.sql --run

Useful options:
  --target-board-id=123        Import all topics/posts into one existing forum board instead of creating one forum per legacy board id.
  --archive-category="Name"    Category name to create/use when not using --target-board-id. Default: VNBoards Archive
  --forum-prefix="VN "         Prefix for automatically created forum names. Default: VN Archive
  --limit-users=N              Stop after N user rows, useful for testing.
  --limit-topics=N             Stop after N topic rows, useful for testing.
  --limit-posts=N              Stop after N post rows, useful for testing.
  --preview-posts=N            In dry-run, print N converted post bodies.

TXT;
}

function wb_vn_starts_with(string $s, string $prefix): bool
{
    return substr($s, 0, strlen($prefix)) === $prefix;
}

function wb_vn_format_ts($timestamp): string
{
    $timestamp = (int)$timestamp;
    if ($timestamp <= 0) {
        return '';
    }
    return date('Y-n-j H:i:s', $timestamp);
}

function wb_vn_synthetic_post_ts($legacyPostId): string
{
    // The crawl's posts table does not include a real date column. Use a stable
    // synthetic timestamp so imported archive rows have deterministic ordering.
    // The original legacy id is preserved in posts.legacy_post_id.
    $base = strtotime('2000-01-01 00:00:00');
    $offset = max(0, (int)$legacyPostId);
    return date('Y-n-j H:i:s', $base + $offset);
}

function wb_vn_boolish($value): int
{
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'banned'], true) ? 1 : 0;
}

function wb_vn_norm_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return $name === '' ? 'Unknown' : $name;
}

function wb_vn_legacy_username(string $legacyName): string
{
    $base = wb_vn_norm_name($legacyName);
    if (substr($base, -7) !== '_legacy') {
        $base .= '_legacy';
    }
    if (strlen($base) > 240) {
        $base = substr($base, 0, 240);
    }
    return $base;
}

function wb_vn_decode_sql_string(string $s): string
{
    // Decode the common MySQL dump escapes used by phpMyAdmin.
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($ch === '\\' && $i + 1 < $len) {
            $n = $s[++$i];
            switch ($n) {
                case '0': $out .= "\0"; break;
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'b': $out .= "\x08"; break;
                case 'Z': $out .= "\x1A"; break;
                default: $out .= $n; break;
            }
        } else {
            $out .= $ch;
        }
    }
    return $out;
}

function wb_vn_parse_sql_row(string $row): array
{
    $values = [];
    $token = '';
    $inQuote = false;
    $len = strlen($row);

    for ($i = 0; $i < $len; $i++) {
        $ch = $row[$i];
        if ($inQuote) {
            if ($ch === '\\' && $i + 1 < $len) {
                $token .= $ch . $row[++$i];
                continue;
            }
            if ($ch === "'" && $i + 1 < $len && $row[$i + 1] === "'") {
                $token .= "'";
                $i++;
                continue;
            }
            if ($ch === "'" ) {
                $inQuote = false;
                continue;
            }
            $token .= $ch;
            continue;
        }

        if ($ch === "'") {
            $inQuote = true;
            continue;
        }
        if ($ch === ',') {
            $values[] = wb_vn_sql_value($token);
            $token = '';
            continue;
        }
        $token .= $ch;
    }
    $values[] = wb_vn_sql_value($token);
    return $values;
}

function wb_vn_sql_value(string $token)
{
    $token = trim($token);
    if (strcasecmp($token, 'NULL') === 0) {
        return null;
    }
    // Numeric strings are left as strings unless integer-looking; PDO will coerce.
    if (preg_match('/^-?\d+$/', $token)) {
        return (int)$token;
    }
    return wb_vn_decode_sql_string($token);
}


function wb_vn_default_columns_for_table(string $table): array
{
    $table = strtolower($table);
    $defaults = [
        'users' => ['db_id','user_id','user_name','remote_user_id','banned','actual_post_count','virtual_post_count','title','signature','date_account_added','last_login_date','last_post_date'],
        'topics' => ['topic_id','vn_topic_id','author','topic_date','topic_count','board_id','title'],
        'posts' => ['post_id','user_name','user_id','message','topic_id','board_id'],
        'registration_dates' => ['db_id','username','reg_date','user_id'],
    ];
    return $defaults[$table] ?? [];
}

function wb_vn_extract_create_table_columns_from_statement(string $stmt): array
{
    $cols = [];
    if (!preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([^`\s(]+)`?\s*\((.*)\)\s*ENGINE/is', $stmt, $m)) {
        if (!preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([^`\s(]+)`?\s*\((.*)\)\s*;/is', $stmt, $m)) {
            return [];
        }
    }
    $body = $m[2];
    foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '`') { continue; }
        if (preg_match('/^`([^`]+)`\s+/i', $line, $cm)) {
            $cols[] = $cm[1];
        }
    }
    return $cols;
}

function wb_vn_get_table_columns_from_create(string $path, string $table): array
{
    static $cache = [];
    $key = $path . '|' . strtolower($table);
    if (array_key_exists($key, $cache)) { return $cache[$key]; }
    $cache[$key] = [];
    if (!is_file($path)) { return []; }
    $fh = fopen($path, 'rb');
    if (!$fh) { return []; }
    $capture = false;
    $stmt = '';
    while (($line = fgets($fh)) !== false) {
        if (!$capture) {
            if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?' . preg_quote($table, '/') . '`?/i', $line)) {
                $capture = true;
                $stmt = $line;
                if (strpos($line, ';') !== false) { break; }
            }
            continue;
        }
        $stmt .= $line;
        if (strpos($line, ';') !== false) { break; }
    }
    fclose($fh);
    if ($stmt !== '') {
        $cache[$key] = wb_vn_extract_create_table_columns_from_statement($stmt);
    }
    return $cache[$key];
}

function wb_vn_extract_insert_table_and_columns(string $line): array
{
    if (!preg_match('/INSERT\s+INTO\s+`?([^`\s(]+)`?\s*(?:\((.*?)\))?\s*VALUES/is', $line, $m)) {
        return ['', []];
    }
    $table = $m[1] ?? '';
    $colsText = $m[2] ?? '';
    $cols = [];
    if ($colsText !== '') {
        preg_match_all('/`([^`]+)`|\b([A-Za-z_][A-Za-z0-9_]*)\b/', $colsText, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $cols[] = $match[1] !== '' ? $match[1] : $match[2];
        }
    }
    return [$table, $cols];
}

function wb_vn_extract_column_names(string $line): array
{
    [, $cols] = wb_vn_extract_insert_table_and_columns($line);
    return $cols;
}

function wb_vn_extract_rows_from_text(string $text, string &$carry, bool &$statementEnded): array
{
    $text = $carry . $text;
    $carry = '';
    $statementEnded = false;
    $rows = [];
    $row = '';
    $inQuote = false;
    $depth = 0;
    $started = false;
    $len = strlen($text);

    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];

        if ($inQuote) {
            $row .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $row .= $text[++$i];
                continue;
            }
            if ($ch === "'" && $i + 1 < $len && $text[$i + 1] === "'") {
                $row .= $text[++$i];
                continue;
            }
            if ($ch === "'") {
                $inQuote = false;
            }
            continue;
        }

        if ($ch === "'") {
            if ($started) { $row .= $ch; }
            $inQuote = true;
            continue;
        }

        if ($ch === '(') {
            if (!$started) {
                $started = true;
                $depth = 1;
                $row = '';
                continue;
            }
            $depth++;
            $row .= $ch;
            continue;
        }

        if ($ch === ')' && $started) {
            $depth--;
            if ($depth === 0) {
                $rows[] = $row;
                $started = false;
                $row = '';
                continue;
            }
            $row .= $ch;
            continue;
        }

        if ($ch === ';' && !$started) {
            $statementEnded = true;
            // Ignore anything after the statement terminator on this line.
            break;
        }

        if ($started) {
            $row .= $ch;
        }
    }

    if ($started) {
        $carry = '(' . $row;
    }

    return $rows;
}

function wb_vn_each_insert_row(string $path, string $table, callable $callback, int $limit = 0): int
{
    if (!is_file($path)) {
        throw new RuntimeException("File not found: {$path}");
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException("Could not open file: {$path}");
    }

    $count = 0;
    $active = false;
    $columns = [];
    $carry = '';
    $createColumns = [];

    while (($line = fgets($fh)) !== false) {
        if (!$active) {
            if (!preg_match('/INSERT\s+INTO\s+`?' . preg_quote($table, '/') . '`?/i', $line)) {
                continue;
            }
            $columns = wb_vn_extract_column_names($line);
            if (!$columns) {
                // Some phpMyAdmin exports use INSERT INTO `posts` VALUES (...) with no
                // column list. In that case, infer columns from CREATE TABLE first,
                // then from known VNBoards dump layouts.
                if (!$createColumns) {
                    $createColumns = wb_vn_get_table_columns_from_create($path, $table);
                }
                $columns = $createColumns ?: wb_vn_default_columns_for_table($table);
            }
            if (!$columns) {
                continue;
            }
            $active = true;
            $pos = stripos($line, 'VALUES');
            $line = $pos === false ? '' : substr($line, $pos + 6);
        }

        $ended = false;
        $rows = wb_vn_extract_rows_from_text($line, $carry, $ended);
        foreach ($rows as $rowText) {
            $vals = wb_vn_parse_sql_row($rowText);
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = $vals[$i] ?? null;
            }
            $callback($row, $count + 1);
            $count++;
            if ($limit > 0 && $count >= $limit) {
                fclose($fh);
                return $count;
            }
        }

        if ($ended) {
            $active = false;
            $columns = [];
            $carry = '';
        }
    }

    fclose($fh);
    return $count;
}

function wb_vn_html_to_bbcode(?string $html): string
{
    $text = (string)$html;
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Drop common crawler wrapper spans but preserve their contents.
    $text = preg_replace('~</?span\b[^>]*>~i', '', $text) ?? $text;

    // Links first, before remaining tags are stripped.
    $text = preg_replace_callback('~<a\b[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)</a>~is', function ($m) {
        $url = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = trim(wb_vn_html_to_plain($m[3]));
        if ($label === '') { $label = $url; }
        return '[link=' . $url . ']' . $label . '[/link]';
    }, $text) ?? $text;

    // Images. This preserves them using the forum's existing [image=URL] syntax.
    $text = preg_replace_callback('~<img\b[^>]*src=("|\')([^"\']+)\1[^>]*>~is', function ($m) {
        $url = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '[image=' . $url . ']';
    }, $text) ?? $text;

    $pairs = [
        '~<(b|strong)\b[^>]*>~i' => '[b]', '~</(b|strong)>~i' => '[/b]',
        '~<(i|em)\b[^>]*>~i' => '[i]', '~</(i|em)>~i' => '[/i]',
        '~<u\b[^>]*>~i' => '[u]', '~</u>~i' => '[/u]',
        '~<blockquote\b[^>]*>~i' => '[quote]', '~</blockquote>~i' => '[/quote]',
    ];
    foreach ($pairs as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }

    $text = preg_replace('~<font\b[^>]*color=("|\')?([#A-Za-z0-9]+)\1?[^>]*>~i', '[color=$2]', $text) ?? $text;
    $text = preg_replace('~</font>~i', '[/color]', $text) ?? $text;

    // HTML layout tags to real newlines. The crawl often stores lines as \r<br />,
    // which would otherwise become double-spaced. Collapse that pair first.
    $text = preg_replace('~\n[ \t]*<br\s*/?>~i', "\n", $text) ?? $text;
    $text = preg_replace('~<br\s*/?>[ \t]*\n~i', "\n", $text) ?? $text;
    $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
    $text = preg_replace('~</(p|div|tr|li)>~i', "\n", $text) ?? $text;
    $text = preg_replace('~<(p|div|tr|li)\b[^>]*>~i', '', $text) ?? $text;

    // Remove anything else HTML-shaped and decode entities last.
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Tidy crawl indentation/noise without destroying intentional blank lines.
    $text = preg_replace("~^[ \t]+~m", "", $text) ?? $text;
    $text = preg_replace("~[ \t]+\n~", "\n", $text) ?? $text;
    $text = preg_replace("~\n{4,}~", "\n\n\n", $text) ?? $text;
    return trim($text);
}

function wb_vn_html_to_plain(?string $html): string
{
    $text = (string)$html;
    $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($text);
}


function wb_vn_preview_user_import_row(array $row): array
{
    $legacyName = wb_vn_norm_name((string)($row['user_name'] ?? $row['username'] ?? 'Unknown'));
    $title = wb_vn_html_to_plain((string)($row['title'] ?? ''));
    if ($title === '(no title)') { $title = ''; }
    $signature = wb_vn_html_to_bbcode((string)($row['signature'] ?? ''));
    if (trim($signature) === '(none)') { $signature = ''; }

    return [
        'legacy_user_id' => (int)($row['user_id'] ?? 0),
        'source_username' => $legacyName,
        'imported_username' => wb_vn_legacy_username($legacyName),
        'banned' => (string)($row['banned'] ?? ''),
        'title_converted' => $title,
        'signature_converted' => $signature,
        'registered_raw' => (string)($row['date_account_added'] ?? ''),
        'last_login_raw' => (string)($row['last_login_date'] ?? ''),
        'last_post_raw' => (string)($row['last_post_date'] ?? ''),
    ];
}

function wb_vn_find_insert_table_names(string $path, int $limit = 20): array
{
    $names = [];
    if (!is_file($path)) { return $names; }
    $fh = fopen($path, 'rb');
    if (!$fh) { return $names; }
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/INSERT\s+INTO\s+`?([^`\s(]+)`?/i', $line, $m)) {
            $names[$m[1]] = true;
            if (count($names) >= $limit) { break; }
        }
    }
    fclose($fh);
    return array_keys($names);
}


function wb_vn_first_insert_debug(string $path, string $table): array
{
    $debug = [
        'file_size' => is_file($path) ? filesize($path) : 0,
        'first_insert_line_found' => false,
        'first_insert_has_column_list' => false,
        'create_table_columns' => wb_vn_get_table_columns_from_create($path, $table),
        'default_columns' => wb_vn_default_columns_for_table($table),
        'insert_prefix' => '',
    ];
    if (!is_file($path)) { return $debug; }
    $fh = fopen($path, 'rb');
    if (!$fh) { return $debug; }
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/INSERT\s+INTO\s+`?' . preg_quote($table, '/') . '`?/i', $line)) {
            $debug['first_insert_line_found'] = true;
            [, $cols] = wb_vn_extract_insert_table_and_columns($line);
            $debug['first_insert_has_column_list'] = !empty($cols);
            $debug['insert_prefix'] = substr(trim($line), 0, 300);
            break;
        }
    }
    fclose($fh);
    return $debug;
}

function wb_vn_sql_definition_is_safe(string $definition): bool
{
    return preg_match('/(;|--|#|\/\*|\*\/)/', $definition) !== 1;
}

function wb_vn_column_exists(PDO $pdo, string $table, string $column): bool
{
    return db_exists(
        'SELECT 1
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1',
        [$table, $column],
        $pdo
    );
}

function wb_vn_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!db_identifier_is_safe($table) || !db_identifier_is_safe($column) || !wb_vn_sql_definition_is_safe($definition)) {
        throw new InvalidArgumentException('Unsafe VN import schema change requested.');
    }
    if (!wb_vn_column_exists($pdo, $table, $column)) {
        db_run('ALTER TABLE ' . db_quote_identifier($table) . ' ADD COLUMN ' . db_quote_identifier($column) . ' ' . $definition, [], $pdo);
    }
}


function wb_vn_index_exists(PDO $pdo, string $table, string $index): bool
{
    return db_exists(
        'SELECT 1
           FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
          LIMIT 1',
        [$table, $index],
        $pdo
    );
}

function wb_vn_add_index(PDO $pdo, string $table, string $index, string $column): void
{
    if (!db_identifier_is_safe($table) || !db_identifier_is_safe($index) || !db_identifier_is_safe($column)) {
        throw new InvalidArgumentException('Unsafe VN import index change requested.');
    }
    if (!wb_vn_index_exists($pdo, $table, $index)) {
        db_run('CREATE INDEX ' . db_quote_identifier($index) . ' ON ' . db_quote_identifier($table) . ' (' . db_quote_identifier($column) . ')', [], $pdo);
    }
}

function wb_vn_import_ensure_schema(PDO $pdo): void
{
    @db_run("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL DEFAULT ''", [], $pdo);
    $userCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_user_id' => "INT NOT NULL DEFAULT 0",
        'legacy_remote_user_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_username' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'legacy_imported_at' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'status' => "INT NOT NULL DEFAULT 0",
        'style' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'signature' => "TEXT NULL",
        'iconid' => "INT NOT NULL DEFAULT 0",
    ];
    foreach ($userCols as $col => $def) { wb_vn_add_column($pdo, 'users', $col, $def); }

    $topicCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_topic_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_board_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'locked' => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($topicCols as $col => $def) { wb_vn_add_column($pdo, 'topics', $col, $def); }

    $postCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_post_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_topic_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_board_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'postip' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    foreach ($postCols as $col => $def) { wb_vn_add_column($pdo, 'posts', $col, $def); }

    $forumCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_board_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
    ];
    foreach ($forumCols as $col => $def) { wb_vn_add_column($pdo, 'forums', $col, $def); }

    $boardCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
    ];
    foreach ($boardCols as $col => $def) { wb_vn_add_column($pdo, 'boards', $col, $def); }

    wb_vn_add_index($pdo, 'users', 'idx_users_legacy_user_id', 'legacy_user_id');
    wb_vn_add_index($pdo, 'topics', 'idx_topics_legacy_topic_id', 'legacy_topic_id');
    wb_vn_add_index($pdo, 'posts', 'idx_posts_legacy_post_id', 'legacy_post_id');
}

function wb_vn_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $row = db_one($sql, $params, $pdo);
    return is_array($row) ? $row : null;
}

function wb_vn_find_or_create_archive_category(PDO $pdo, string $name): int
{
    $row = wb_vn_fetch_one($pdo, 'SELECT id FROM boards WHERE name = ? LIMIT 1', [$name]);
    if ($row) { return (int)$row['id']; }
    db_run("INSERT INTO boards (name, legacy_source) VALUES (?, 'vnboards')", [$name], $pdo);
    return db_insert_id($pdo);
}

function wb_vn_find_or_create_forum(PDO $pdo, int $categoryId, string $legacyBoardId, string $prefix): int
{
    $row = wb_vn_fetch_one($pdo, "SELECT id FROM forums WHERE legacy_source = 'vnboards' AND legacy_board_id = ? LIMIT 1", [$legacyBoardId]);
    if ($row) { return (int)$row['id']; }

    $posRow = wb_vn_fetch_one($pdo, 'SELECT COALESCE(MAX(position),0) + 1 AS nextpos FROM forums WHERE categoryid = ?', [$categoryId]);
    $position = (int)($posRow['nextpos'] ?? 1);
    $name = trim($prefix . ' ' . $legacyBoardId);
    db_run("INSERT INTO forums (categoryid, name, description, position, edittimer, lastpstdate, lastpstdatets, legacy_source, legacy_board_id) VALUES (?, ?, ?, ?, '0', '', '', 'vnboards', ?)", [$categoryId, $name, 'Imported VNBoards archive board ' . $legacyBoardId, $position, $legacyBoardId], $pdo);
    return db_insert_id($pdo);
}

function wb_vn_find_or_create_legacy_user(PDO $pdo, array $row, array $regDatesByUserId = [], array $regDatesByName = []): int
{
    $legacyId = (int)($row['user_id'] ?? 0);
    $legacyName = wb_vn_norm_name((string)($row['user_name'] ?? $row['username'] ?? 'Unknown'));

    if ($legacyId > 0) {
        $existing = wb_vn_fetch_one($pdo, "SELECT id FROM users WHERE legacy_source = 'vnboards' AND legacy_user_id = ? LIMIT 1", [$legacyId]);
        if ($existing) { return (int)$existing['id']; }
    }

    $username = wb_vn_legacy_username($legacyName);
    $baseUsername = $username;
    $suffix = 2;
    while (wb_vn_fetch_one($pdo, 'SELECT id FROM users WHERE username = ? LIMIT 1', [$username])) {
        $username = substr($baseUsername, 0, 235) . $suffix;
        $suffix++;
    }

    $regTs = 0;
    if ($legacyId > 0 && isset($regDatesByUserId[$legacyId])) {
        $regTs = (int)$regDatesByUserId[$legacyId];
    } elseif (isset($regDatesByName[strtolower($legacyName)])) {
        $regTs = (int)$regDatesByName[strtolower($legacyName)];
    }

    $regdate = $regTs > 0 ? wb_vn_format_ts($regTs) : (string)($row['date_account_added'] ?? '');
    $lastlogin = (string)($row['last_login_date'] ?? '');
    $lastpost = (string)($row['last_post_date'] ?? '');
    $title = wb_vn_html_to_plain((string)($row['title'] ?? ''));
    if ($title === '(no title)') { $title = ''; }
    $signature = wb_vn_html_to_bbcode((string)($row['signature'] ?? ''));
    if (trim($signature) === '(none)') { $signature = ''; }
    $posts = (int)($row['actual_post_count'] ?? $row['virtual_post_count'] ?? 0);
    $status = wb_vn_boolish($row['banned'] ?? '') ? 2 : 0;
    $now = date('Y-n-j H:i:s');

    db_run("INSERT INTO users
        (username, password, accesslevel, posts, regdate, lastlogindate, lastpstdate, ThreadPages, BoardPages, userstyle, status, style, title, signature, legacy_source, legacy_user_id, legacy_remote_user_id, legacy_username, legacy_imported_at)
        VALUES (?, ?, 0, ?, ?, ?, ?, 25, 25, '', ?, '', ?, ?, 'vnboards', ?, ?, ?, ?)", [
        $username,
        wb_auth_password_hash(wb_auth_random_token(32)),
        $posts,
        $regdate,
        $lastlogin,
        $lastpost,
        $status,
        $title,
        $signature,
        $legacyId,
        (int)($row['remote_user_id'] ?? 0),
        $legacyName,
        $now,
    ], $pdo);
    return db_insert_id($pdo);
}

function wb_vn_create_fallback_user(PDO $pdo, string $legacyName): int
{
    return wb_vn_find_or_create_legacy_user($pdo, [
        'user_id' => 0,
        'user_name' => $legacyName,
        'banned' => 'False',
        'actual_post_count' => 0,
        'virtual_post_count' => 0,
        'title' => '',
        'signature' => '',
    ]);
}

function wb_vn_insert_topic(PDO $pdo, array $row, int $forumId, int $posterId): int
{
    $legacyTopicId = (int)($row['vn_topic_id'] ?? $row['topic_id'] ?? 0);
    if ($legacyTopicId > 0) {
        $existing = wb_vn_fetch_one($pdo, "SELECT id FROM topics WHERE legacy_source = 'vnboards' AND legacy_topic_id = ? LIMIT 1", [$legacyTopicId]);
        if ($existing) { return (int)$existing['id']; }
    }

    $title = html_entity_decode((string)($row['title'] ?? 'Untitled archived topic'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = trim(strip_tags($title));
    if ($title === '') { $title = 'Untitled archived topic'; }

    $time = (int)($row['topic_date'] ?? 0);
    $when = $time > 0 ? wb_vn_format_ts($time) : wb_vn_synthetic_post_ts($legacyTopicId ?: (int)($row['topic_id'] ?? 0));
    $legacyBoardId = (string)($row['board_id'] ?? '');

    db_run("INSERT INTO topics (boardid, title, body, posterid, lastpost, time, sticky, locked, legacy_source, legacy_topic_id, legacy_board_id)
        VALUES (?, ?, '', ?, ?, ?, 0, 0, 'vnboards', ?, ?)", [$forumId, $title, $posterId, $when, $when, $legacyTopicId, $legacyBoardId], $pdo);
    return db_insert_id($pdo);
}

function wb_vn_insert_fallback_topic(PDO $pdo, int $legacyTopicId, string $legacyBoardId, int $forumId, int $posterId): int
{
    $existing = wb_vn_fetch_one($pdo, "SELECT id FROM topics WHERE legacy_source = 'vnboards' AND legacy_topic_id = ? LIMIT 1", [$legacyTopicId]);
    if ($existing) { return (int)$existing['id']; }
    $when = wb_vn_synthetic_post_ts($legacyTopicId);
    $title = 'Archived VN Topic ' . $legacyTopicId;
    db_run("INSERT INTO topics (boardid, title, body, posterid, lastpost, time, sticky, locked, legacy_source, legacy_topic_id, legacy_board_id)
        VALUES (?, ?, '', ?, ?, ?, 0, 0, 'vnboards', ?, ?)", [$forumId, $title, $posterId, $when, $when, $legacyTopicId, $legacyBoardId], $pdo);
    return db_insert_id($pdo);
}

function wb_vn_insert_post(PDO $pdo, array $row, int $topicId, int $forumId, int $posterId): int
{
    $legacyPostId = (int)($row['post_id'] ?? 0);
    if ($legacyPostId > 0) {
        $existing = wb_vn_fetch_one($pdo, "SELECT id FROM posts WHERE legacy_source = 'vnboards' AND legacy_post_id = ? LIMIT 1", [$legacyPostId]);
        if ($existing) { return (int)$existing['id']; }
    }

    $legacyTopicId = (int)($row['topic_id'] ?? 0);
    $legacyBoardId = (string)($row['board_id'] ?? '');
    $body = wb_vn_html_to_bbcode((string)($row['message'] ?? ''));
    if ($body === '') { $body = '(empty archived post)'; }
    $when = wb_vn_synthetic_post_ts($legacyPostId ?: $legacyTopicId);
    $ptd = date('m/d/y', strtotime($when));
    $title = 'Archived post';

    db_run("INSERT INTO posts (posterid, title, body, author, threadid, boardid, ptd, posttime, posttimeraw, postip, legacy_source, legacy_post_id, legacy_topic_id, legacy_board_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'vnboards', ?, ?, ?)", [$posterId, $title, $body, '', $topicId, $forumId, $ptd, $when, $when, $legacyPostId, $legacyTopicId, $legacyBoardId], $pdo);
    $postId = db_insert_id($pdo);

    db_run('UPDATE topics SET lastpost = ?, time = ? WHERE id = ? AND (lastpost = "" OR lastpost <= ?)', [$when, $when, $topicId, $when], $pdo);
    db_run('UPDATE forums SET lastpstdate = ?, lastpstdatets = ? WHERE id = ? AND (lastpstdatets = "" OR lastpstdatets <= ?)', [$when, $when, $forumId, $when], $pdo);

    return $postId;
}
