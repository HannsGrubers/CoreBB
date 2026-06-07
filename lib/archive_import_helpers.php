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
 |  archive_import_helpers.php  - VN archive database    |
 |  -> CoreBB importer helpers.                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/auth_password_helpers.php';

function wb_archive_import_now(): string
{
    return date('Y-n-j H:i:s');
}


function wb_archive_import_substr(string $text, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($text, $start) : mb_substr($text, $start, $length);
    }
    return $length === null ? substr($text, $start) : substr($text, $start, $length);
}

function wb_archive_import_strtolower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}

function wb_archive_import_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wb_archive_import_table_exists(string $table): bool
{
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$table]
    );
}

function wb_archive_import_column_exists(string $table, string $column): bool
{
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$table, $column]
    );
}

function wb_archive_import_index_exists(string $table, string $index): bool
{
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        [$table, $index]
    );
}

function wb_archive_import_safe_definition(string $definition): bool
{
    return preg_match('/(;|--|#|\/\*|\*\/)/', $definition) !== 1;
}

function wb_archive_import_add_column(string $table, string $column, string $definition): void
{
    if (!db_identifier_is_safe($table) || !db_identifier_is_safe($column) || !wb_archive_import_safe_definition($definition)) {
        throw new InvalidArgumentException('Unsafe archive import schema change requested.');
    }
    if (!wb_archive_import_column_exists($table, $column)) {
        db_run('ALTER TABLE ' . db_quote_identifier($table) . ' ADD COLUMN ' . db_quote_identifier($column) . ' ' . $definition);
    }
}

function wb_archive_import_add_index(string $table, string $index, array $columns): void
{
    if (!db_identifier_is_safe($table) || !db_identifier_is_safe($index)) {
        throw new InvalidArgumentException('Unsafe archive import index requested.');
    }
    $quoted = [];
    foreach ($columns as $column) {
        if (!db_identifier_is_safe((string)$column)) {
            throw new InvalidArgumentException('Unsafe archive import index column requested.');
        }
        $quoted[] = db_quote_identifier((string)$column);
    }
    if (!wb_archive_import_index_exists($table, $index)) {
        db_run('CREATE INDEX ' . db_quote_identifier($index) . ' ON ' . db_quote_identifier($table) . ' (' . implode(',', $quoted) . ')');
    }
}

function wb_archive_import_ensure_schema(): array
{
    wb_auth_ensure_schema();
    $messages = [];

    @db_run("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL DEFAULT ''");

    $userCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_user_id' => "INT NOT NULL DEFAULT 0",
        'legacy_identity_key' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'legacy_remote_user_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_username' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'legacy_imported_at' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_original_post_count' => "INT NOT NULL DEFAULT 0",
        'is_archive_user' => "TINYINT(1) NOT NULL DEFAULT 0",
        'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'sig1' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'sig2' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'sig3' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'sig4' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'sig5' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'signature' => "TEXT NULL",
        'status' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'approved' => "TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($userCols as $col => $def) { wb_archive_import_add_column('users', $col, $def); }

    $boardCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'secure_archive' => "TINYINT(1) NOT NULL DEFAULT 0",
        'private' => "TINYINT(1) NOT NULL DEFAULT 0",
        'position' => "INT NOT NULL DEFAULT 0",
    ];
    foreach ($boardCols as $col => $def) { wb_archive_import_add_column('boards', $col, $def); }

    $forumCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_board_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_category_id' => "INT NOT NULL DEFAULT 0",
        'legacy_category_title' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'private' => "TINYINT(1) NOT NULL DEFAULT 0",
        'secure_archive' => "TINYINT(1) NOT NULL DEFAULT 0",
        'topiccount' => "INT NOT NULL DEFAULT 0",
        'postcount' => "INT NOT NULL DEFAULT 0",
    ];
    foreach ($forumCols as $col => $def) { wb_archive_import_add_column('forums', $col, $def); }

    $topicCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_topic_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_vn_topic_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_board_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_original_reply_count' => "INT NOT NULL DEFAULT 0",
        'replycount' => "INT NOT NULL DEFAULT 0",
        'postcount' => "INT NOT NULL DEFAULT 0",
        'locked' => "TINYINT(1) NOT NULL DEFAULT 0",
        'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($topicCols as $col => $def) { wb_archive_import_add_column('topics', $col, $def); }

    $postCols = [
        'legacy_source' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'legacy_post_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_topic_id' => "BIGINT NOT NULL DEFAULT 0",
        'legacy_board_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'postip' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($postCols as $col => $def) { wb_archive_import_add_column('posts', $col, $def); }

    @db_run("CREATE TABLE IF NOT EXISTS archive_import_log (
        id INT NOT NULL AUTO_INCREMENT,
        run_key VARCHAR(64) NOT NULL DEFAULT 'vn_archive',
        level VARCHAR(16) NOT NULL DEFAULT 'info',
        source_table VARCHAR(64) NOT NULL DEFAULT '',
        source_id VARCHAR(64) NOT NULL DEFAULT '',
        message TEXT NULL,
        created_at VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY idx_archive_import_log_run (run_key, id),
        KEY idx_archive_import_log_source (source_table, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    wb_archive_import_add_index('users', 'idx_users_archive_identity', ['legacy_source', 'legacy_identity_key']);
    wb_archive_import_add_index('users', 'idx_users_archive_flag', ['is_archive_user']);
    wb_archive_import_add_index('forums', 'idx_forums_archive_board', ['legacy_source', 'legacy_board_id']);
    wb_archive_import_add_index('topics', 'idx_topics_archive_topic', ['legacy_source', 'legacy_topic_id']);
    wb_archive_import_add_index('topics', 'idx_topics_archive_vn_topic', ['legacy_vn_topic_id']);
    wb_archive_import_add_index('posts', 'idx_posts_archive_post', ['legacy_source', 'legacy_post_id']);

    $messages[] = 'Archive import schema/check columns are ready.';
    return $messages;
}

function wb_archive_import_log(string $level, string $sourceTable, string $sourceId, string $message): void
{
    if (!wb_archive_import_table_exists('archive_import_log')) {
        return;
    }
    db_run(
        'INSERT INTO archive_import_log (run_key, level, source_table, source_id, message, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['vn_archive', $level, $sourceTable, $sourceId, $message, wb_archive_import_now()]
    );
}

function wb_archive_import_get_setting(string $key, string $default = ''): string
{
    return (string)db_value('SELECT setting FROM systemsettings WHERE name = ? ORDER BY id DESC LIMIT 1', [$key], $default);
}

function wb_archive_import_set_setting(string $key, string $value): void
{
    $row = db_one('SELECT id FROM systemsettings WHERE name = ? ORDER BY id DESC LIMIT 1', [$key]);
    if ($row) {
        db_run('UPDATE systemsettings SET setting = ? WHERE id = ?', [$value, (int)$row['id']]);
    } else {
        db_run('INSERT INTO systemsettings (name, setting) VALUES (?, ?)', [$key, $value]);
    }
}

function wb_archive_import_reset_progress(): void
{
    foreach (['users_last_id', 'topics_last_id', 'posts_last_id'] as $key) {
        wb_archive_import_set_setting('archive_import_' . $key, '0');
    }
}

function wb_archive_import_archive_config_loaded(): bool
{
    return defined('WB_ARCHIVE_DB_HOST') && defined('WB_ARCHIVE_DB_NAME') && defined('WB_ARCHIVE_DB_USER') && defined('WB_ARCHIVE_DB_PASS');
}

function wb_archive_import_source_pdo(): PDO
{
    if (!wb_archive_import_archive_config_loaded()) {
        throw new RuntimeException('Archive DB config not loaded. Add archive DB settings to /corebb_private/config.live.php or /corebb_private/config.staging.php.');
    }

    $host = (string)WB_ARCHIVE_DB_HOST;
    $port = null;
    if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        [$hostPart, $portPart] = explode(':', $host, 2);
        if ($portPart !== '' && ctype_digit($portPart)) {
            $host = $hostPart;
            $port = (int)$portPart;
        }
    }

    $charset = defined('WB_ARCHIVE_DB_CHARSET') ? (string)WB_ARCHIVE_DB_CHARSET : 'utf8mb4';
    $dsn = 'mysql:host=' . $host . ($port ? ';port=' . $port : '') . ';dbname=' . (string)WB_ARCHIVE_DB_NAME . ';charset=' . $charset;
    return new PDO($dsn, (string)WB_ARCHIVE_DB_USER, (string)WB_ARCHIVE_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

function wb_archive_import_source_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wb_archive_import_source_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $rows = wb_archive_import_source_all($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function wb_archive_import_source_value(PDO $pdo, string $sql, array $params = [], $default = null)
{
    $stmt = $pdo->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $value = $stmt->fetchColumn(0);
    return $value === false ? $default : $value;
}

function wb_archive_import_clean_text($value, int $maxLen = 255): string
{
    $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? $text);
    if ($text === '') {
        return '';
    }
    return wb_archive_import_substr($text, 0, $maxLen);
}

function wb_archive_import_normalize_username(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = str_replace("\xc2\xa0", ' ', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    return $name === '' ? 'Unknown' : $name;
}

function wb_archive_import_identity_key(int $legacyUserId, string $legacyName): string
{
    if ($legacyUserId > 0) {
        return 'vnid:' . $legacyUserId;
    }
    $normalized = wb_archive_import_strtolower(wb_archive_import_normalize_username($legacyName));
    return 'name:' . sha1($normalized);
}

function wb_archive_import_unique_username(string $legacyName, string $identityKey): string
{
    $base = wb_archive_import_normalize_username($legacyName);
    $base = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? $base);
    if ($base === '') { $base = 'Archive User'; }
    $base = wb_archive_import_substr($base, 0, 220);

    $existing = db_one("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND legacy_identity_key = ? LIMIT 1", [$identityKey]);
    if ($existing) {
        $row = db_one('SELECT username FROM users WHERE id = ? LIMIT 1', [(int)$existing['id']]);
        return (string)($row['username'] ?? $base);
    }

    $candidate = $base;
    if (db_exists('SELECT 1 FROM users WHERE username = ? LIMIT 1', [$candidate])) {
        $candidate = wb_archive_import_substr($base, 0, 210) . ' [Archive]';
    }
    $suffix = 2;
    while (db_exists('SELECT 1 FROM users WHERE username = ? LIMIT 1', [$candidate])) {
        $candidate = wb_archive_import_substr($base, 0, 205) . ' [Archive ' . $suffix . ']';
        $suffix++;
    }
    return $candidate;
}

function wb_archive_import_date_string($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0') {
        return '';
    }
    if (ctype_digit($raw)) {
        $ts = (int)$raw;
        return $ts > 0 ? date('Y-n-j H:i:s', $ts) : '';
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-n-j H:i:s', $ts) : wb_archive_import_substr($raw, 0, 64);
}

function wb_archive_import_format_ts(int $timestamp): string
{
    return $timestamp > 0 ? date('Y-n-j H:i:s', $timestamp) : '2000-1-1 00:00:00';
}

function wb_archive_import_safe_url(string $url, bool $allowRelative = true): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '';
    if ($url === '' || strlen($url) > 2048) {
        return '';
    }
    if (preg_match('~^(javascript|data|vbscript):~i', $url)) {
        return '';
    }
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    if ($allowRelative && preg_match('~^/[A-Za-z0-9_./%?&=+:#;,@~-]+$~', $url) && strpos($url, '..') === false) {
        return $url;
    }
    return '';
}


function wb_archive_import_face_name_file_map(): array
{
    return [
        'thinking' => '33.gif',
        'monkey' => '38.gif',
        'tired' => '31.gif',
        'confused' => '6.gif',
        'hugs' => '60.gif',
        'not_talking' => '28.gif',
        'alien_1' => '47.gif',
        'nerd' => '22.gif',
        'alien_2' => '48.gif',
        'money_eyes' => '53.gif',
        'beatup' => '56.gif',
        'shock' => '11.gif',
        'cry' => '17.gif',
        'dancing' => '59.gif',
        'plain' => '19.gif',
        'kiss' => '10.gif',
        'blush' => '8.gif',
        'idea' => '45.gif',
        'angel' => '21.gif',
        'praying' => '51.gif',
        'angry' => '12.gif',
        'liarliar' => '55.gif',
        'worried' => '15.gif',
        'rolling_eyes' => '25.gif',
        'skull' => '46.gif',
        'frustrated' => '49.gif',
        'raised_brow' => '20.gif',
        'love' => '7.gif',
        'shame_on_you' => '58.gif',
        'coffee' => '44.gif',
        'sick' => '26.gif',
        'silly' => '30.gif',
        'talk_hand' => '23.gif',
        'drooling' => '32.gif',
        'rose' => '40.gif',
        'tongue' => '9.gif',
        'grin' => '4.gif',
        'sad' => '2.gif',
        'doh!' => '34.gif',
        'wink' => '3.gif',
        'mischief' => '13.gif',
        'chicken' => '39.gif',
        'applause' => '35.gif',
        'clown' => '29.gif',
        'batting' => '5.gif',
        'laugh' => '18.gif',
        'devil' => '16.gif',
        'cowboy' => '50.gif',
        'pig' => '36.gif',
        'whistling' => '54.gif',
        'pumpkin' => '43.gif',
        'flag' => '42.gif',
        'cool' => '14.gif',
        'cow' => '37.gif',
        'hypnotized' => '52.gif',
        'happy' => '1.gif',
        'shhh' => '27.gif',
        'peace' => '57.gif',
        'good_luck' => '41.gif',
        'sleep' => '24.gif',
    ];
}

function wb_archive_import_face_file_name_map(): array
{
    $byFile = [];
    foreach (wb_archive_import_face_name_file_map() as $name => $file) {
        $byFile[strtolower($file)] = $name;
    }
    return $byFile;
}

function wb_archive_import_legacy_boardface_url_to_face_name(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '';
    if ($url === '') {
        return '';
    }

    $file = '';
    if (preg_match('~^(?:https?:)?//[^\s/]+/boardfaces/([1-9]|[1-5][0-9]|60)\.gif(?:[?#].*)?$~i', $url, $m)) {
        $file = $m[1] . '.gif';
    } elseif (preg_match('~^/images/faces/([1-9]|[1-5][0-9]|60)\.gif(?:[?#].*)?$~i', $url, $m)) {
        $file = $m[1] . '.gif';
    }

    if ($file === '') {
        return '';
    }

    $byFile = wb_archive_import_face_file_name_map();
    return $byFile[strtolower($file)] ?? '';
}

function wb_archive_import_image_url_to_bbcode(string $url): string
{
    // Boardfaces are legacy VN emoticons, not user images. Convert them to the
    // native [face_name] tag before normal URL validation so schemeless legacy
    // URLs like //media.ign.com/boardfaces/9.gif are still recognized.
    $faceName = wb_archive_import_legacy_boardface_url_to_face_name($url);
    if ($faceName !== '') {
        return '[face_' . $faceName . ']';
    }

    $safeUrl = wb_archive_import_safe_url($url);
    if ($safeUrl === '') {
        return '';
    }

    return '[image=' . $safeUrl . ']';
}

function wb_archive_import_replace_legacy_boardface_image_tags(string $text): string
{
    if (stripos($text, '[image=') === false && stripos($text, '[image ') === false) {
        return $text;
    }

    return preg_replace_callback('~\[image\s*=\s*([^\]\s]{1,2048})\]~i', static function ($m): string {
        $faceName = wb_archive_import_legacy_boardface_url_to_face_name((string)$m[1]);
        if ($faceName === '') {
            return $m[0];
        }
        return '[face_' . $faceName . ']';
    }, $text) ?? $text;
}


function wb_archive_import_clean_quote_attribution(string $attribution): string
{
    $attribution = html_entity_decode($attribution, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $attribution = preg_replace('~\[/?(?:b|i|u|strong|em)\]~i', '', $attribution) ?? $attribution;
    $attribution = preg_replace('~\[/?color(?:=[^\]]*)?\]~i', '', $attribution) ?? $attribution;
    $attribution = strip_tags($attribution);
    $attribution = preg_replace('/[\x00-\x1F\x7F\[\]]+/', ' ', $attribution) ?? $attribution;
    $attribution = preg_replace('/\s+/', ' ', $attribution) ?? $attribution;
    $attribution = trim($attribution);
    if ($attribution === '') {
        return '';
    }
    return wb_archive_import_substr($attribution, 0, 100);
}

function wb_archive_import_extract_legacy_quote_prefix(string $body): ?array
{
    $patterns = [
        '~^\s*\[b\]\s*([^\[\]\r\n]{1,100})\s*\[/b\]\s*posted:\s*~i',
        '~^\s*([^\[\]\r\n]{1,100})\s+posted:\s*~i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $author = wb_archive_import_clean_quote_attribution((string)$m[1][0]);
        if ($author === '') {
            continue;
        }
        $prefixLen = strlen((string)$m[0][0]);
        return [
            'attribution' => $author,
            'body' => ltrim(substr($body, $prefixLen)),
        ];
    }

    return null;
}

function wb_archive_import_normalize_legacy_quote_markup(string $text, int $depth = 0): string
{
    if ($text === '' || $depth > 25 || stripos($text, '[quote') === false) {
        return $text;
    }

    $output = '';
    $offset = 0;
    $openPattern = '~\[quote(?:=([^\]\r\n]{0,100}))?\]~i';
    $tokenPattern = '~\[quote(?:=[^\]\r\n]{0,100})?\]|\[/quote\]~i';

    while (preg_match($openPattern, $text, $open, PREG_OFFSET_CAPTURE, $offset)) {
        $openText = (string)$open[0][0];
        $openPos = (int)$open[0][1];
        $attribution = isset($open[1][0]) ? (string)$open[1][0] : '';
        $innerStart = $openPos + strlen($openText);

        $output .= substr($text, $offset, $openPos - $offset);

        $searchPos = $innerStart;
        $quoteDepth = 1;
        $closePos = null;
        $closeLen = 0;

        while (preg_match($tokenPattern, $text, $token, PREG_OFFSET_CAPTURE, $searchPos)) {
            $tokenText = (string)$token[0][0];
            $tokenPos = (int)$token[0][1];
            $searchPos = $tokenPos + strlen($tokenText);

            if (stripos($tokenText, '[/quote]') === 0) {
                $quoteDepth--;
                if ($quoteDepth === 0) {
                    $closePos = $tokenPos;
                    $closeLen = strlen($tokenText);
                    break;
                }
            } else {
                $quoteDepth++;
            }
        }

        if ($closePos === null) {
            $output .= substr($text, $openPos);
            return $output;
        }

        $inner = substr($text, $innerStart, $closePos - $innerStart);
        $inner = wb_archive_import_normalize_legacy_quote_markup($inner, $depth + 1);
        $cleanAttribution = wb_archive_import_clean_quote_attribution($attribution);

        if ($cleanAttribution === '') {
            $legacy = wb_archive_import_extract_legacy_quote_prefix($inner);
            if (is_array($legacy)) {
                $cleanAttribution = (string)$legacy['attribution'];
                $inner = (string)$legacy['body'];
            }
        }

        $output .= $cleanAttribution !== ''
            ? '[quote=' . $cleanAttribution . ']' . $inner . '[/quote]'
            : '[quote]' . $inner . '[/quote]';
        $offset = $closePos + $closeLen;
    }

    $output .= substr($text, $offset);
    return $output;
}

function wb_archive_import_extract_message_html(string $html): string
{
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    if (trim($html) === '') {
        return '';
    }

    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!doctype html><html><body><div id="__wb_archive_root">' . $html . '</div></body></html>';
        $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[contains(@id,"bcMessageBody")]');
        if ($nodes && $nodes->length > 0) {
            $node = $nodes->item(0);
            $out = '';
            foreach ($node->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }
            return $out;
        }
    }

    if (preg_match('~<span\b[^>]*id=("|\')[^"\']*bcMessageBody[^"\']*\1[^>]*>(.*)</span>~is', $html, $m)) {
        return (string)$m[2];
    }
    return $html;
}

function wb_archive_import_dom_children_to_bbcode(DOMNode $node, string $mode): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= wb_archive_import_dom_to_bbcode($child, $mode);
    }
    return $out;
}

function wb_archive_import_dom_color_attr(DOMElement $node): string
{
    $color = '';
    if ($node->hasAttribute('color')) {
        $color = trim($node->getAttribute('color'));
    }
    if ($color === '' && $node->hasAttribute('style')) {
        $style = $node->getAttribute('style');
        if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $m)) {
            $color = trim($m[1]);
        }
    }
    $color = trim($color, " \t\n\r\0\x0B\"'");
    if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $color) || preg_match('/^[a-z]{3,20}$/i', $color)) {
        return $color;
    }
    return '';
}

function wb_archive_import_dom_to_bbcode(DOMNode $node, string $mode): string
{
    if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
        return (string)$node->nodeValue;
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }

    /** @var DOMElement $node */
    $tag = strtolower($node->nodeName);
    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'meta', 'link'], true)) {
        return '';
    }

    if ($tag === 'br') {
        return $mode === 'title' ? '[BR]' : "\n";
    }

    $children = wb_archive_import_dom_children_to_bbcode($node, $mode);

    if (in_array($tag, ['b', 'strong'], true)) { return '[b]' . $children . '[/b]'; }
    if (in_array($tag, ['i', 'em'], true)) { return '[i]' . $children . '[/i]'; }
    if ($tag === 'u') { return '[u]' . $children . '[/u]'; }

    $color = wb_archive_import_dom_color_attr($node);
    if ($color !== '' && in_array($tag, ['font', 'span'], true)) {
        return '[color=' . $color . ']' . $children . '[/color]';
    }

    if ($mode !== 'title') {
        if ($tag === 'a' && $node->hasAttribute('href')) {
            $url = wb_archive_import_safe_url($node->getAttribute('href'));
            if ($url !== '') {
                $label = trim($children) !== '' ? $children : $url;
                return '[link=' . $url . ']' . $label . '[/link]';
            }
            return $children;
        }
        if ($tag === 'img' && $node->hasAttribute('src')) {
            $bbcode = wb_archive_import_image_url_to_bbcode($node->getAttribute('src'));
            if ($bbcode !== '') {
                return $bbcode;
            }
            $alt = $node->hasAttribute('alt') ? trim($node->getAttribute('alt')) : '';
            return $alt !== '' ? $alt : '';
        }
        if ($tag === 'blockquote') {
            return "\n[quote]" . trim($children) . "[/quote]\n";
        }
        if (in_array($tag, ['li'], true)) {
            return "\n[*] " . trim($children) . "\n";
        }
        if (in_array($tag, ['p', 'div', 'tr', 'table', 'tbody', 'thead', 'tfoot', 'ul', 'ol'], true)) {
            return "\n" . trim($children) . "\n";
        }
    }

    return $children;
}

function wb_archive_import_html_to_bbcode(?string $html, string $mode = 'post'): string
{
    $html = (string)$html;
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    $html = preg_replace('~<!--.*?-->~s', '', $html) ?? $html;

    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body><div id="__wb_archive_convert">' . $html . '</div></body></html>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[@id="__wb_archive_convert"]');
        $root = ($nodes && $nodes->length > 0) ? $nodes->item(0) : null;
        $out = $root ? wb_archive_import_dom_children_to_bbcode($root, $mode) : '';
    } else {
        $out = preg_replace('~<(script|style|iframe|object|embed|form|input|button|select|textarea)\b.*?</\1>~is', '', $html) ?? $html;
        if ($mode !== 'title') {
            $out = preg_replace_callback('~<a\b[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)</a>~is', static function ($m): string {
                $url = wb_archive_import_safe_url((string)$m[2]);
                $label = trim(strip_tags((string)$m[3]));
                if ($url === '') { return $label; }
                if ($label === '') { $label = $url; }
                return '[link=' . $url . ']' . $label . '[/link]';
            }, $out) ?? $out;
            $out = preg_replace_callback('~<img\b[^>]*src=("|\')([^"\']+)\1[^>]*>~is', static function ($m): string {
                return wb_archive_import_image_url_to_bbcode((string)$m[2]);
            }, $out) ?? $out;
            $out = preg_replace('~<blockquote\b[^>]*>~i', "\n[quote]", $out) ?? $out;
            $out = preg_replace('~</blockquote>~i', "[/quote]\n", $out) ?? $out;
        }
        $out = preg_replace_callback('~<font\b[^>]*color\s*=\s*("|\')?([^"\' >]+)\1?[^>]*>(.*?)</font>~is', static function ($m): string {
            $color = trim((string)$m[2]);
            if (!preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $color) && !preg_match('/^[a-z]{3,20}$/i', $color)) { return (string)$m[3]; }
            return '[color=' . $color . ']' . $m[3] . '[/color]';
        }, $out) ?? $out;
        $out = preg_replace_callback('~<span\b[^>]*style\s*=\s*("|\')[^"\']*color\s*:\s*([^;"\']+)[^"\']*\1[^>]*>(.*?)</span>~is', static function ($m): string {
            $color = trim((string)$m[2]);
            if (!preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $color) && !preg_match('/^[a-z]{3,20}$/i', $color)) { return (string)$m[3]; }
            return '[color=' . $color . ']' . $m[3] . '[/color]';
        }, $out) ?? $out;
        $out = preg_replace('~<br\s*/?>~i', $mode === 'title' ? '[BR]' : "\n", $out) ?? $out;
        $out = preg_replace('~<(b|strong)\b[^>]*>(.*?)</\1>~is', '[b]$2[/b]', $out) ?? $out;
        $out = preg_replace('~<(i|em)\b[^>]*>(.*?)</\1>~is', '[i]$2[/i]', $out) ?? $out;
        $out = preg_replace('~<u\b[^>]*>(.*?)</u>~is', '[u]$1[/u]', $out) ?? $out;
        $out = preg_replace('~</(p|div|tr|li)>~i', "\n", $out) ?? $out;
        $out = preg_replace('~<(p|div|tr|li)\b[^>]*>~i', '', $out) ?? $out;
        $out = strip_tags($out);
    }

    $out = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = wb_archive_import_replace_legacy_boardface_image_tags($out);
    $out = wb_archive_import_normalize_legacy_quote_markup($out);
    $out = str_replace("\xc2\xa0", ' ', $out);
    $out = preg_replace("~[ \t]+\n~", "\n", $out) ?? $out;
    $out = preg_replace("~\n[ \t]+~", "\n", $out) ?? $out;
    $out = preg_replace("~\n{4,}~", "\n\n\n", $out) ?? $out;
    $out = trim($out);

    if ($mode === 'title') {
        // User titles live in a compact author column. Keep converted title BBCode
        // short and do not let malformed imported HTML make enormous titles.
        $out = preg_replace('/\s*\[BR\]\s*/i', '[BR]', $out) ?? $out;
        $out = wb_archive_import_substr($out, 0, 255);
    }

    return $out;
}

function wb_archive_import_user_title_to_bbcode(?string $html): string
{
    $title = wb_archive_import_html_to_bbcode($html, 'title');
    if (trim($title) === '(no title)') { return ''; }
    return $title;
}

function wb_archive_import_signature_to_bbcode(?string $html): string
{
    $sig = wb_archive_import_html_to_bbcode($html, 'post');
    if (trim($sig) === '(none)') { return ''; }
    return $sig;
}

function wb_archive_import_strlen(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

function wb_archive_import_signature_fields_from_text(?string $signature): array
{
    $signature = trim((string)$signature);
    $fields = array_fill(1, 5, '');
    $meta = [
        'source_lines' => 0,
        'stored_lines' => 0,
        'dropped_lines' => 0,
        'truncated_lines' => 0,
    ];

    if ($signature === '' || strtolower($signature) === '(none)') {
        return ['fields' => $fields, 'meta' => $meta];
    }

    // The live forum signature editor stores signatures as discrete sig1-sig5
    // rows. The archive conversion originally stored one combined text blob in
    // users.signature, so split that blob back into native line fields here.
    $normalized = preg_replace('/\s*\[BR\]\s*/i', "\n", $signature) ?? $signature;
    $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);
    $normalized = preg_replace("~[ \t]+\n~", "\n", $normalized) ?? $normalized;
    $normalized = preg_replace("~\n[ \t]+~", "\n", $normalized) ?? $normalized;

    $rawLines = preg_split('/\n+/', $normalized) ?: [];
    $lines = [];
    foreach ($rawLines as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line === '$') {
            continue;
        }
        $lines[] = $line;
    }

    $meta['source_lines'] = count($lines);

    $slot = 1;
    foreach ($lines as $line) {
        if ($slot > 5) {
            $meta['dropped_lines']++;
            continue;
        }
        if (wb_archive_import_strlen($line) > 250) {
            $line = wb_archive_import_substr($line, 0, 250);
            $meta['truncated_lines']++;
        }
        $fields[$slot] = $line;
        $meta['stored_lines']++;
        $slot++;
    }

    return ['fields' => $fields, 'meta' => $meta];
}

function wb_archive_import_repair_archive_signatures_batch(int $limit): array
{
    wb_archive_import_ensure_schema();
    $limit = max(1, min(10000, $limit));
    $lastId = (int)wb_archive_import_get_setting('archive_import_signature_repair_last_id', '0');

    $rows = db_all(
        "SELECT id, username, signature FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND id > ? AND COALESCE(signature, '') <> '' ORDER BY id ASC LIMIT " . $limit,
        [$lastId]
    );

    $processed = 0;
    $withLines = 0;
    $truncated = 0;
    $dropped = 0;
    $lastSeen = $lastId;

    db_begin();
    try {
        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $split = wb_archive_import_signature_fields_from_text((string)($row['signature'] ?? ''));
            $fields = $split['fields'];
            $meta = $split['meta'];

            db_run(
                'UPDATE users SET sig1 = ?, sig2 = ?, sig3 = ?, sig4 = ?, sig5 = ? WHERE id = ?',
                [$fields[1], $fields[2], $fields[3], $fields[4], $fields[5], $userId]
            );

            $processed++;
            if ((int)$meta['stored_lines'] > 0) { $withLines++; }
            $truncated += (int)$meta['truncated_lines'];
            $dropped += (int)$meta['dropped_lines'];
            $lastSeen = max($lastSeen, $userId);
        }

        wb_archive_import_set_setting('archive_import_signature_repair_last_id', (string)$lastSeen);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    $remaining = (int)db_value(
        "SELECT COUNT(*) FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND id > ? AND COALESCE(signature, '') <> ''",
        [$lastSeen],
        0
    );

    $messages = [];
    $messages[] = 'Processed ' . number_format($processed) . ' archive signatures into sig1-sig5. Signatures with stored lines: ' . number_format($withLines) . '.';
    if ($truncated > 0) {
        $messages[] = 'Truncated ' . number_format($truncated) . ' signature lines to fit the native 250-character line limit.';
    }
    if ($dropped > 0) {
        $messages[] = 'Dropped ' . number_format($dropped) . ' extra signature lines after sig5. The full combined signature column was preserved as backup/fallback.';
    }
    $messages[] = 'Remaining archive signatures after this batch: ' . number_format($remaining) . '.';
    if ($remaining <= 0) {
        $messages[] = 'Archive signature repair finished. The full users.signature value was preserved; display now prefers sig1-sig5 when present.';
    }
    return $messages;
}

function wb_archive_import_reset_signature_repair_progress(): void
{
    wb_archive_import_set_setting('archive_import_signature_repair_last_id', '0');
}

function wb_archive_import_clean_archive_signature_placeholders(): array
{
    wb_archive_import_ensure_schema();

    $total = 0;
    $byField = [];

    db_begin();
    try {
        for ($i = 1; $i <= 5; $i++) {
            $field = 'sig' . $i;
            if (!wb_archive_import_column_exists('users', $field)) {
                continue;
            }

            db_run(
                "UPDATE users SET `" . $field . "` = '' WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND `" . $field . "` = ?",
                ['$']
            );
            $affected = (int)($GLOBALS['WB_DB_LAST_AFFECTED_ROWS'] ?? 0);
            $byField[$field] = $affected;
            $total += $affected;
        }
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    $messages = [];
    $messages[] = 'Cleaned ' . number_format($total) . ' archive signature placeholder field values.';
    foreach ($byField as $field => $affected) {
        if ($affected > 0) {
            $messages[] = $field . ': replaced ' . number_format($affected) . ' placeholder values with empty strings.';
        }
    }
    if ($total === 0) {
        $messages[] = 'No archive signature placeholder values were found.';
    }
    return $messages;
}


function wb_archive_import_message_to_bbcode(?string $html): string
{
    $inner = wb_archive_import_extract_message_html((string)$html);
    $body = wb_archive_import_html_to_bbcode($inner, 'post');
    return $body === '' ? '(empty archived post)' : $body;
}

function wb_archive_import_find_or_create_archive_category(): int
{
    $row = db_one("SELECT id FROM boards WHERE name = 'Archive' AND legacy_source = 'vn_archive' LIMIT 1");
    if ($row) {
        db_run('UPDATE boards SET private = 0, secure_archive = 1 WHERE id = ?', [(int)$row['id']]);
        return (int)$row['id'];
    }

    $existing = db_one("SELECT id FROM boards WHERE name = 'Archive' LIMIT 1");
    if ($existing) {
        db_run("UPDATE boards SET private = 0, secure_archive = 1, legacy_source = 'vn_archive' WHERE id = ?", [(int)$existing['id']]);
        return (int)$existing['id'];
    }

    $position = (int)db_value('SELECT COALESCE(MAX(position), 0) + 1 FROM boards', [], 1);
    db_run("INSERT INTO boards (name, private, secure_archive, position, legacy_source) VALUES ('Archive', 0, 1, ?, 'vn_archive')", [$position]);
    return db_insert_id();
}

function wb_archive_import_structure(PDO $source): array
{
    wb_archive_import_ensure_schema();
    $categoryId = wb_archive_import_find_or_create_archive_category();
    $rows = wb_archive_import_source_all($source, 'SELECT cl.*, c.category_title FROM crawl_list cl LEFT JOIN crawl_list_categories c ON c.category_id = cl.category_id ORDER BY cl.category_id ASC, cl.crawl_id ASC');

    $created = 0;
    $updated = 0;
    foreach ($rows as $row) {
        $legacyBoardId = trim((string)($row['board_id'] ?? ''));
        if ($legacyBoardId === '') { continue; }
        $name = wb_archive_import_clean_text($row['Description'] ?? '', 255);
        if ($name === '') { $name = wb_archive_import_clean_text($row['current_title'] ?? '', 255); }
        if ($name === '') { $name = 'Archive Board ' . $legacyBoardId; }
        $description = wb_archive_import_clean_text($row['category_title'] ?? '', 255);
        $legacyCategoryId = (int)($row['category_id'] ?? 0);
        $legacyCategoryTitle = $description;

        $existing = db_one("SELECT id FROM forums WHERE legacy_source = 'vn_archive' AND legacy_board_id = ? LIMIT 1", [$legacyBoardId]);
        if ($existing) {
            db_run(
                'UPDATE forums SET categoryid = ?, name = ?, description = ?, private = 0, secure_archive = 1, legacy_category_id = ?, legacy_category_title = ? WHERE id = ?',
                [$categoryId, $name, $description, $legacyCategoryId, $legacyCategoryTitle, (int)$existing['id']]
            );
            $updated++;
            continue;
        }

        $position = (int)db_value('SELECT COALESCE(MAX(position),0)+1 FROM forums WHERE categoryid = ?', [$categoryId], 1);
        db_run(
            "INSERT INTO forums (categoryid, name, description, position, edittimer, lastpstdate, lastpstdatets, ptd, threadid, private, secure_archive, legacy_source, legacy_board_id, legacy_category_id, legacy_category_title, topiccount, postcount)
             VALUES (?, ?, ?, ?, '0', '', '', '', 0, 0, 1, 'vn_archive', ?, ?, ?, 0, 0)",
            [$categoryId, $name, $description, $position, $legacyBoardId, $legacyCategoryId, $legacyCategoryTitle]
        );
        $created++;
    }

    return ['created' => $created, 'updated' => $updated, 'total_source_boards' => count($rows), 'archive_category_id' => $categoryId];
}

function wb_archive_import_forum_id_for_legacy_board(string $legacyBoardId): int
{
    return (int)db_value("SELECT id FROM forums WHERE legacy_source = 'vn_archive' AND legacy_board_id = ? LIMIT 1", [$legacyBoardId], 0);
}

function wb_archive_import_source_topic_for_post_ref(PDO $source, int $postTopicRef): ?array
{
    if ($postTopicRef <= 0) {
        return null;
    }

    // Most archive rows were expected to reference topics.topic_id, but some
    // crawls store the original VNBoards topic id in posts.topic_id instead.
    // Try the archive topic id first, then fall back to vn_topic_id.
    $topic = wb_archive_import_source_one($source, 'SELECT * FROM topics WHERE topic_id = ? LIMIT 1', [$postTopicRef]);
    if ($topic) {
        return $topic;
    }

    return wb_archive_import_source_one($source, 'SELECT * FROM topics WHERE vn_topic_id = ? LIMIT 1', [$postTopicRef]);
}

function wb_archive_import_target_topic_for_post_ref(int $postTopicRef): ?array
{
    if ($postTopicRef <= 0) {
        return null;
    }

    // Match either the archive topic id or the original VNBoards topic id.
    return db_one(
        "SELECT id, boardid FROM topics WHERE legacy_source = 'vn_archive' AND (legacy_topic_id = ? OR legacy_vn_topic_id = ?) LIMIT 1",
        [$postTopicRef, $postTopicRef]
    ) ?: null;
}

function wb_archive_import_find_user_by_identity(string $identityKey): int
{
    return (int)db_value("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND legacy_identity_key = ? LIMIT 1", [$identityKey], 0);
}

function wb_archive_import_find_user_by_archive_values(int $legacyUserId, string $legacyName): int
{
    $identityKey = wb_archive_import_identity_key($legacyUserId, $legacyName);
    $id = wb_archive_import_find_user_by_identity($identityKey);
    if ($id > 0) { return $id; }
    if ($legacyUserId > 0) {
        return (int)db_value("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND legacy_user_id = ? LIMIT 1", [$legacyUserId], 0);
    }
    return 0;
}



function wb_archive_import_count_value(string $sql, array $params = []): int
{
    return (int)db_value($sql, $params, 0);
}

function wb_archive_import_reset_quote_repair_progress(): void
{
    wb_archive_import_set_setting('archive_quote_repair_last_post_id', '0');
}

function wb_archive_import_repair_archive_quotes_batch(int $limit = 1000): array
{
    wb_archive_import_ensure_schema();
    $limit = max(1, min(5000, $limit));
    $lastId = (int)wb_archive_import_get_setting('archive_quote_repair_last_post_id', '0');

    $likeQuote = '%[quote]%';
    $likePosted = '%posted:%';

    $posts = db_all(
        "SELECT id, body FROM posts
         WHERE legacy_source = 'vn_archive'
           AND id > ?
           AND body LIKE ?
           AND body LIKE ?
         ORDER BY id ASC
         LIMIT " . $limit,
        [$lastId, $likeQuote, $likePosted]
    );

    $processedPosts = 0;
    $updatedPosts = 0;
    $lastSeen = $lastId;

    foreach ($posts as $row) {
        $processedPosts++;
        $id = (int)($row['id'] ?? 0);
        if ($id > $lastSeen) {
            $lastSeen = $id;
        }

        $body = (string)($row['body'] ?? '');
        $newBody = wb_archive_import_normalize_legacy_quote_markup($body);
        if ($newBody !== $body) {
            db_run("UPDATE posts SET body = ? WHERE id = ? AND legacy_source = 'vn_archive'", [$newBody, $id]);
            $updatedPosts++;
        }
    }

    if ($lastSeen !== $lastId) {
        wb_archive_import_set_setting('archive_quote_repair_last_post_id', (string)$lastSeen);
    }

    $remainingPosts = wb_archive_import_count_value(
        "SELECT COUNT(*) FROM posts
         WHERE legacy_source = 'vn_archive'
           AND id > ?
           AND body LIKE ?
           AND body LIKE ?",
        [$lastSeen, $likeQuote, $likePosted]
    );

    $totalLikelyRemaining = wb_archive_import_count_value(
        "SELECT COUNT(*) FROM posts
         WHERE legacy_source = 'vn_archive'
           AND body LIKE ?
           AND body LIKE ?",
        [$likeQuote, $likePosted]
    );

    wb_archive_import_log(
        'info',
        'posts',
        'quote_repair',
        'Quote repair batch processed posts=' . $processedPosts . ', updated posts=' . $updatedPosts . ', remaining after cursor=' . $remainingPosts . ', likely matching total=' . $totalLikelyRemaining
    );

    return [
        'processed_posts' => $processedPosts,
        'updated_posts' => $updatedPosts,
        'remaining_posts' => $remainingPosts,
        'likely_matching_total' => $totalLikelyRemaining,
        'last_post_id' => $lastSeen,
    ];
}

function wb_archive_import_reset_boardface_repair_progress(): void
{
    wb_archive_import_set_setting('archive_boardface_repair_last_post_id', '0');
}

function wb_archive_import_repair_archive_boardfaces_batch(int $limit = 1000): array
{
    wb_archive_import_ensure_schema();
    $limit = max(1, min(5000, $limit));
    $lastId = (int)wb_archive_import_get_setting('archive_boardface_repair_last_post_id', '0');

    $likeBoardfaces = '%[image=%/boardfaces/%';
    $likeLocalFaces = '%[image=%/images/faces/%';

    $posts = db_all(
        "SELECT id, body FROM posts
         WHERE legacy_source = 'vn_archive'
           AND id > ?
           AND (body LIKE ? OR body LIKE ?)
         ORDER BY id ASC
         LIMIT " . $limit,
        [$lastId, $likeBoardfaces, $likeLocalFaces]
    );

    $processedPosts = 0;
    $updatedPosts = 0;
    $lastSeen = $lastId;
    foreach ($posts as $row) {
        $processedPosts++;
        $id = (int)($row['id'] ?? 0);
        if ($id > $lastSeen) {
            $lastSeen = $id;
        }

        $body = (string)($row['body'] ?? '');
        $newBody = wb_archive_import_replace_legacy_boardface_image_tags($body);
        if ($newBody !== $body) {
            db_run("UPDATE posts SET body = ? WHERE id = ? AND legacy_source = 'vn_archive'", [$newBody, $id]);
            $updatedPosts++;
        }
    }

    if ($lastSeen !== $lastId) {
        wb_archive_import_set_setting('archive_boardface_repair_last_post_id', (string)$lastSeen);
    }

    $remainingPosts = wb_archive_import_count_value(
        "SELECT COUNT(*) FROM posts
         WHERE legacy_source = 'vn_archive'
           AND id > ?
           AND (body LIKE ? OR body LIKE ?)",
        [$lastSeen, $likeBoardfaces, $likeLocalFaces]
    );

    $signatureFields = ['signature', 'sig1', 'sig2', 'sig3', 'sig4', 'sig5'];
    $where = [];
    $params = [];
    foreach ($signatureFields as $field) {
        $where[] = db_quote_identifier($field) . ' LIKE ?';
        $params[] = $likeBoardfaces;
        $where[] = db_quote_identifier($field) . ' LIKE ?';
        $params[] = $likeLocalFaces;
    }
    $users = db_all(
        "SELECT id, signature, sig1, sig2, sig3, sig4, sig5 FROM users
         WHERE legacy_source = 'vn_archive' AND (" . implode(' OR ', $where) . ")
         ORDER BY id ASC",
        $params
    );

    $processedUsers = 0;
    $updatedUsers = 0;
    foreach ($users as $row) {
        $processedUsers++;
        $sets = [];
        $updateParams = [];
        foreach ($signatureFields as $field) {
            $old = (string)($row[$field] ?? '');
            $new = wb_archive_import_replace_legacy_boardface_image_tags($old);
            if ($new !== $old) {
                $sets[] = db_quote_identifier($field) . ' = ?';
                $updateParams[] = $new;
            }
        }
        if ($sets) {
            $updateParams[] = (int)($row['id'] ?? 0);
            db_run("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ? AND legacy_source = 'vn_archive'", $updateParams);
            $updatedUsers++;
        }
    }

    wb_archive_import_log(
        'info',
        'posts',
        'boardface_repair',
        'Boardface repair batch processed posts=' . $processedPosts . ', updated posts=' . $updatedPosts . ', updated users=' . $updatedUsers . ', remaining posts=' . $remainingPosts
    );

    return [
        'processed_posts' => $processedPosts,
        'updated_posts' => $updatedPosts,
        'remaining_posts' => $remainingPosts,
        'last_post_id' => $lastSeen,
        'processed_users' => $processedUsers,
        'updated_users' => $updatedUsers,
    ];
}

function wb_archive_import_upsert_user(array $row, bool $placeholder = false): int
{
    $legacyUserId = (int)($row['user_id'] ?? 0);
    $legacyName = wb_archive_import_normalize_username((string)($row['user_name'] ?? $row['username'] ?? 'Unknown'));
    $identityKey = wb_archive_import_identity_key($legacyUserId, $legacyName);
    $existing = wb_archive_import_find_user_by_identity($identityKey);
    if ($existing <= 0 && $legacyUserId > 0) {
        $existing = (int)db_value("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND legacy_user_id = ? LIMIT 1", [$legacyUserId], 0);
    }

    $posts = (int)($row['actual_post_count'] ?? 0);
    $title = wb_archive_import_user_title_to_bbcode((string)($row['title'] ?? ''));
    $signature = wb_archive_import_signature_to_bbcode((string)($row['signature'] ?? ''));
    $signatureFields = wb_archive_import_signature_fields_from_text($signature)['fields'];
    $regdate = wb_archive_import_date_string($row['date_account_added'] ?? '');
    $lastlogin = wb_archive_import_date_string($row['last_login_date'] ?? '');
    $lastpost = wb_archive_import_date_string($row['last_post_date'] ?? '');
    $status = strtolower(trim((string)($row['banned'] ?? '')));
    $statusValue = in_array($status, ['1', 'true', 'yes', 'banned'], true) ? '2' : '0';
    $now = wb_archive_import_now();

    if ($existing > 0) {
        db_run(
            'UPDATE users SET posts = ?, title = ?, sig1 = ?, sig2 = ?, sig3 = ?, sig4 = ?, sig5 = ?, signature = ?, regdate = ?, lastlogindate = ?, lastpstdate = ?, status = ?, legacy_user_id = ?, legacy_identity_key = ?, legacy_username = ?, legacy_imported_at = ?, legacy_original_post_count = ?, is_archive_user = 1 WHERE id = ?',
            [$posts, $title, $signatureFields[1], $signatureFields[2], $signatureFields[3], $signatureFields[4], $signatureFields[5], $signature, $regdate, $lastlogin, $lastpost, $statusValue, $legacyUserId, $identityKey, $legacyName, $now, $posts, $existing]
        );
        return $existing;
    }

    $username = wb_archive_import_unique_username($legacyName, $identityKey);
    db_run(
        "INSERT INTO users
         (username, password, accesslevel, posts, regdate, lastlogindate, lastpstdate, ThreadPages, BoardPages, userstyle, status, style, title, sig1, sig2, sig3, sig4, sig5, signature, legacy_source, legacy_user_id, legacy_identity_key, legacy_remote_user_id, legacy_username, legacy_imported_at, legacy_original_post_count, is_archive_user, approved)
         VALUES (?, ?, 1, ?, ?, ?, ?, 25, 25, '', ?, '', ?, ?, ?, ?, ?, ?, ?, 'vn_archive', ?, ?, ?, ?, ?, ?, 1, 1)",
        [
            $username,
            wb_auth_password_hash(wb_auth_random_token(32)),
            $posts,
            $regdate,
            $lastlogin,
            $lastpost,
            $statusValue,
            $title,
            $signatureFields[1],
            $signatureFields[2],
            $signatureFields[3],
            $signatureFields[4],
            $signatureFields[5],
            $signature,
            $legacyUserId,
            $identityKey,
            (int)($row['remote_user_id'] ?? 0),
            $legacyName,
            $now,
            $posts,
        ]
    );
    $id = db_insert_id();
    if ($placeholder) {
        wb_archive_import_log('warn', 'users', $identityKey, 'Created placeholder archive user for post author ' . $legacyName);
    }
    return $id;
}

function wb_archive_import_placeholder_user(string $legacyName, int $legacyUserId = 0): int
{
    return wb_archive_import_upsert_user([
        'user_id' => $legacyUserId,
        'user_name' => $legacyName,
        'actual_post_count' => 0,
        'title' => '',
        'signature' => '',
        'banned' => '',
    ], true);
}

function wb_archive_import_users_batch(PDO $source, int $limit): array
{
    wb_archive_import_ensure_schema();
    $lastId = (int)wb_archive_import_get_setting('archive_import_users_last_id', '0');
    $limit = max(1, min(5000, $limit));
    $rows = wb_archive_import_source_all($source, 'SELECT * FROM users WHERE db_id > ? ORDER BY db_id ASC LIMIT ' . $limit, [$lastId]);
    $imported = 0;
    $lastSeen = $lastId;
    db_begin();
    try {
        foreach ($rows as $row) {
            wb_archive_import_upsert_user($row, false);
            $lastSeen = max($lastSeen, (int)($row['db_id'] ?? 0));
            $imported++;
        }
        wb_archive_import_set_setting('archive_import_users_last_id', (string)$lastSeen);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return ['imported' => $imported, 'last_id' => $lastSeen, 'remaining_hint' => count($rows) >= $limit ? 'more' : 'done'];
}

function wb_archive_import_topic_author_id(PDO $source, array $topic): int
{
    // Topic import must not create or update archive users. User import is a
    // separate stage, and post import can create carefully logged placeholders
    // only for actual post authors. During topic import we only resolve an
    // already-imported archive user when possible. If the author cannot be
    // matched cleanly, leave posterid as 0; the post/count rebuild can later
    // derive topic authorship from the first imported post.
    $author = wb_archive_import_normalize_username((string)($topic['author'] ?? ''));
    if ($author === '' || $author === 'Unknown') {
        return 0;
    }

    $sourceUser = wb_archive_import_source_one($source, 'SELECT * FROM users WHERE user_name = ? LIMIT 1', [$author]);
    if ($sourceUser) {
        $legacyUserId = (int)($sourceUser['user_id'] ?? 0);
        $identityKey = wb_archive_import_identity_key($legacyUserId, (string)($sourceUser['user_name'] ?? $author));
        $id = wb_archive_import_find_user_by_identity($identityKey);
        if ($id > 0) {
            return $id;
        }
        if ($legacyUserId > 0) {
            $id = (int)db_value("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND legacy_user_id = ? LIMIT 1", [$legacyUserId], 0);
            if ($id > 0) {
                return $id;
            }
        }
    }

    $id = (int)db_value(
        "SELECT id FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND legacy_username = ? LIMIT 1",
        [$author],
        0
    );
    if ($id > 0) {
        return $id;
    }

    return (int)db_value(
        "SELECT id FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND username = ? LIMIT 1",
        [$author],
        0
    );
}

function wb_archive_import_insert_or_update_topic(PDO $source, array $row): int
{
    $archiveTopicId = (int)($row['topic_id'] ?? 0);
    $vnTopicId = (int)($row['vn_topic_id'] ?? 0);
    if ($archiveTopicId <= 0) { return 0; }
    $existing = (int)db_value("SELECT id FROM topics WHERE legacy_source = 'vn_archive' AND legacy_topic_id = ? LIMIT 1", [$archiveTopicId], 0);
    $forumId = wb_archive_import_forum_id_for_legacy_board((string)($row['board_id'] ?? ''));
    if ($forumId <= 0) {
        wb_archive_import_log('error', 'topics', (string)$archiveTopicId, 'Topic points to archive board_id not present in imported forums: ' . (string)($row['board_id'] ?? ''));
        return 0;
    }
    $posterId = wb_archive_import_topic_author_id($source, $row);
    $title = wb_archive_import_clean_text($row['title'] ?? '', 255);
    if ($title === '') { $title = 'Untitled archived topic'; }
    $topicTs = (int)($row['topic_date'] ?? 0);
    $when = wb_archive_import_format_ts($topicTs);
    $legacyBoardId = (string)($row['board_id'] ?? '');
    $originalReplyCount = (int)($row['topic_count'] ?? 0);

    if ($existing > 0) {
        db_run(
            'UPDATE topics SET boardid = ?, title = ?, posterid = ?, lastpost = IF(lastpost = "", ?, lastpost), time = ?, locked = 1, legacy_vn_topic_id = ?, legacy_board_id = ?, legacy_original_reply_count = ? WHERE id = ?',
            [$forumId, $title, $posterId, $when, $when, $vnTopicId, $legacyBoardId, $originalReplyCount, $existing]
        );
        return $existing;
    }

    db_run(
        "INSERT INTO topics (boardid, title, body, posterid, lastpost, now, time, sticky, locked, legacy_source, legacy_topic_id, legacy_vn_topic_id, legacy_board_id, legacy_original_reply_count, replycount, postcount, is_deleted)
         VALUES (?, ?, '', ?, ?, ?, ?, 0, 1, 'vn_archive', ?, ?, ?, ?, 0, 0, 0)",
        [$forumId, $title, $posterId, $when, $when, $when, $archiveTopicId, $vnTopicId, $legacyBoardId, $originalReplyCount]
    );
    return db_insert_id();
}

function wb_archive_import_topics_batch(PDO $source, int $limit): array
{
    wb_archive_import_ensure_schema();
    $lastId = (int)wb_archive_import_get_setting('archive_import_topics_last_id', '0');
    $limit = max(1, min(5000, $limit));
    $rows = wb_archive_import_source_all($source, 'SELECT * FROM topics WHERE topic_id > ? ORDER BY topic_id ASC LIMIT ' . $limit, [$lastId]);
    $imported = 0;
    $skipped = 0;
    $lastSeen = $lastId;
    db_begin();
    try {
        foreach ($rows as $row) {
            $id = wb_archive_import_insert_or_update_topic($source, $row);
            $id > 0 ? $imported++ : $skipped++;
            $lastSeen = max($lastSeen, (int)($row['topic_id'] ?? 0));
        }
        wb_archive_import_set_setting('archive_import_topics_last_id', (string)$lastSeen);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return ['imported' => $imported, 'skipped' => $skipped, 'last_id' => $lastSeen, 'remaining_hint' => count($rows) >= $limit ? 'more' : 'done'];
}

function wb_archive_import_post_author_id(PDO $source, array $post): int
{
    $legacyUserId = (int)($post['user_id'] ?? 0);
    $legacyName = wb_archive_import_normalize_username((string)($post['user_name'] ?? 'Unknown'));
    $existing = wb_archive_import_find_user_by_archive_values($legacyUserId, $legacyName);
    if ($existing > 0) { return $existing; }
    if ($legacyUserId > 0) {
        $sourceUser = wb_archive_import_source_one($source, 'SELECT * FROM users WHERE user_id = ? LIMIT 1', [$legacyUserId]);
        if ($sourceUser) { return wb_archive_import_upsert_user($sourceUser, false); }
    }
    $sourceUser = wb_archive_import_source_one($source, 'SELECT * FROM users WHERE user_name = ? LIMIT 1', [$legacyName]);
    if ($sourceUser) { return wb_archive_import_upsert_user($sourceUser, false); }
    return wb_archive_import_placeholder_user($legacyName, $legacyUserId);
}

function wb_archive_import_post_time(PDO $source, int $archiveTopicId, int $archivePostId, int $topicTs): string
{
    $firstPost = (int)wb_archive_import_source_value($source, 'SELECT MIN(post_id) FROM posts WHERE topic_id = ?', [$archiveTopicId], $archivePostId);
    $offset = max(0, $archivePostId - $firstPost);
    $base = $topicTs > 0 ? $topicTs : strtotime('2000-01-01 00:00:00');
    return wb_archive_import_format_ts($base + $offset);
}

function wb_archive_import_insert_or_update_post(PDO $source, array $row): int
{
    $archivePostId = (int)($row['post_id'] ?? 0);
    $archiveTopicId = (int)($row['topic_id'] ?? 0);
    if ($archivePostId <= 0 || $archiveTopicId <= 0) { return 0; }
    $existing = (int)db_value("SELECT id FROM posts WHERE legacy_source = 'vn_archive' AND legacy_post_id = ? LIMIT 1", [$archivePostId], 0);

    $targetTopic = wb_archive_import_target_topic_for_post_ref($archiveTopicId);
    $sourceTopic = wb_archive_import_source_topic_for_post_ref($source, $archiveTopicId);
    if (!$targetTopic && $sourceTopic) {
        $topicId = wb_archive_import_insert_or_update_topic($source, $sourceTopic);
        $targetTopic = $topicId > 0 ? db_one('SELECT id, boardid FROM topics WHERE id = ? LIMIT 1', [$topicId]) : false;
    }
    if (!$targetTopic) {
        wb_archive_import_log('error', 'posts', (string)$archivePostId, 'Post points to archive topic reference not present in imported topics or VN topic IDs: ' . $archiveTopicId);
        return 0;
    }

    $topicId = (int)$targetTopic['id'];
    $forumId = (int)$targetTopic['boardid'];
    $posterId = wb_archive_import_post_author_id($source, $row);
    $body = wb_archive_import_message_to_bbcode((string)($row['message'] ?? ''));
    $legacyBoardId = (string)($row['board_id'] ?? '');
    $topicTs = (int)($sourceTopic['topic_date'] ?? 0);
    $when = wb_archive_import_post_time($source, $archiveTopicId, $archivePostId, $topicTs);
    $ptd = date('m/d/y', strtotime($when));
    $author = wb_archive_import_normalize_username((string)($row['user_name'] ?? ''));
    $postTitle = $sourceTopic ? wb_archive_import_clean_text($sourceTopic['title'] ?? '', 255) : 'Archived post';
    if ($postTitle === '') { $postTitle = 'Archived post'; }

    if ($existing > 0) {
        db_run(
            'UPDATE posts SET posterid = ?, title = ?, body = ?, author = ?, threadid = ?, boardid = ?, ptd = ?, posttime = ?, posttimeraw = ?, legacy_topic_id = ?, legacy_board_id = ?, is_deleted = 0 WHERE id = ?',
            [$posterId, $postTitle, $body, $author, $topicId, $forumId, $ptd, $when, $when, $archiveTopicId, $legacyBoardId, $existing]
        );
        return $existing;
    }

    db_run(
        "INSERT INTO posts (posterid, title, body, author, threadid, boardid, ptd, posttime, posttimeraw, postip, legacy_source, legacy_post_id, legacy_topic_id, legacy_board_id, editcount, is_deleted)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'vn_archive', ?, ?, ?, 0, 0)",
        [$posterId, $postTitle, $body, $author, $topicId, $forumId, $ptd, $when, $when, $archivePostId, $archiveTopicId, $legacyBoardId]
    );
    return db_insert_id();
}

function wb_archive_import_posts_batch(PDO $source, int $limit): array
{
    wb_archive_import_ensure_schema();
    $lastId = (int)wb_archive_import_get_setting('archive_import_posts_last_id', '0');
    $limit = max(1, min(2500, $limit));
    $rows = wb_archive_import_source_all($source, 'SELECT * FROM posts WHERE post_id > ? ORDER BY post_id ASC LIMIT ' . $limit, [$lastId]);
    $imported = 0;
    $skipped = 0;
    $lastSeen = $lastId;
    db_begin();
    try {
        foreach ($rows as $row) {
            $id = wb_archive_import_insert_or_update_post($source, $row);
            $id > 0 ? $imported++ : $skipped++;
            $lastSeen = max($lastSeen, (int)($row['post_id'] ?? 0));
        }
        wb_archive_import_set_setting('archive_import_posts_last_id', (string)$lastSeen);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return ['imported' => $imported, 'skipped' => $skipped, 'last_id' => $lastSeen, 'remaining_hint' => count($rows) >= $limit ? 'more' : 'done'];
}

function wb_archive_import_rebuild_counts(): array
{
    $messages = [];
    $forums = db_all("SELECT id FROM forums WHERE legacy_source = 'vn_archive' ORDER BY id ASC");
    foreach ($forums as $forum) {
        $forumId = (int)$forum['id'];
        $topicCount = (int)db_value('SELECT COUNT(*) FROM topics WHERE boardid = ? AND is_deleted = 0', [$forumId], 0);
        $postCount = (int)db_value('SELECT COUNT(*) FROM posts WHERE boardid = ? AND is_deleted = 0', [$forumId], 0);
        $lastPost = db_one('SELECT posttime, posttimeraw, threadid FROM posts WHERE boardid = ? AND is_deleted = 0 ORDER BY posttimeraw DESC, id DESC LIMIT 1', [$forumId]);
        db_run('UPDATE forums SET topiccount = ?, postcount = ?, lastpstdate = ?, lastpstdatets = ?, threadid = ? WHERE id = ?', [
            $topicCount,
            $postCount,
            (string)($lastPost['posttime'] ?? ''),
            (string)($lastPost['posttimeraw'] ?? ''),
            (int)($lastPost['threadid'] ?? 0),
            $forumId,
        ]);
    }

    $topics = db_all("SELECT id FROM topics WHERE legacy_source = 'vn_archive' ORDER BY id ASC");
    foreach ($topics as $topic) {
        $topicId = (int)$topic['id'];
        $postCount = (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0', [$topicId], 0);
        $lastPost = db_one('SELECT posttime, posttimeraw FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY posttimeraw DESC, id DESC LIMIT 1', [$topicId]);
        db_run('UPDATE topics SET postcount = ?, replycount = ?, lastpost = IF(? = "", lastpost, ?), time = IF(? = "", time, ?) WHERE id = ?', [
            $postCount,
            max(0, $postCount - 1),
            (string)($lastPost['posttime'] ?? ''),
            (string)($lastPost['posttime'] ?? ''),
            (string)($lastPost['posttimeraw'] ?? ''),
            (string)($lastPost['posttimeraw'] ?? ''),
            $topicId,
        ]);
    }

    $users = db_all("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 ORDER BY id ASC");
    foreach ($users as $user) {
        $userId = (int)$user['id'];
        $postCount = (int)db_value('SELECT COUNT(*) FROM posts WHERE posterid = ? AND is_deleted = 0', [$userId], 0);
        $lastPost = (string)db_value('SELECT posttime FROM posts WHERE posterid = ? AND is_deleted = 0 ORDER BY posttimeraw DESC, id DESC LIMIT 1', [$userId], '');
        db_run('UPDATE users SET posts = ?, lastpstdate = IF(? = "", lastpstdate, ?) WHERE id = ?', [$postCount, $lastPost, $lastPost, $userId]);
    }

    $messages[] = 'Rebuilt archive forum/topic/user counts from actually imported posts.';
    return $messages;
}



function wb_archive_import_topic_first_post(int $topicId)
{
    return db_one(
        "SELECT id, posterid, author, title, body, posttime, posttimeraw, legacy_post_id FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1",
        [$topicId]
    );
}

function wb_archive_import_topic_last_post(int $topicId)
{
    return db_one(
        "SELECT id, posterid, author, title, body, posttime, posttimeraw, legacy_post_id FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1",
        [$topicId]
    );
}

function wb_archive_import_user_id_for_author_name(string $author): int
{
    $author = wb_archive_import_normalize_username($author);
    if ($author === '' || $author === 'Unknown') {
        return 0;
    }
    $id = (int)db_value(
        "SELECT id FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND username = ? LIMIT 1",
        [$author],
        0
    );
    if ($id > 0) { return $id; }
    return (int)db_value(
        "SELECT id FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND legacy_username = ? LIMIT 1",
        [$author],
        0
    );
}

function wb_archive_import_archive_topic_diagnostics(int $limit = 100): array
{
    wb_archive_import_ensure_schema();
    $limit = max(1, min(500, $limit));

    $summary = [];
    $summary['archive_topics_total'] = (int)db_value("SELECT COUNT(*) FROM topics WHERE legacy_source = 'vn_archive' AND is_deleted = 0", [], 0);
    $summary['archive_topics_with_posts'] = (int)db_value("SELECT COUNT(DISTINCT t.id) FROM topics t INNER JOIN posts p ON p.threadid = t.id AND p.is_deleted = 0 WHERE t.legacy_source = 'vn_archive' AND t.is_deleted = 0", [], 0);
    $summary['archive_topics_zero_posts'] = (int)db_value("SELECT COUNT(*) FROM topics t WHERE t.legacy_source = 'vn_archive' AND t.is_deleted = 0 AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.threadid = t.id AND p.is_deleted = 0 LIMIT 1)", [], 0);
    $summary['topics_with_posts_blank_or_missing_topic_author'] = (int)db_value(
        "SELECT COUNT(*)
           FROM topics t
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND EXISTS (SELECT 1 FROM posts p WHERE p.threadid = t.id AND p.is_deleted = 0 LIMIT 1)
            AND (t.posterid <= 0 OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = t.posterid LIMIT 1))",
        [],
        0
    );
    $summary['topics_with_posts_default_or_blank_start_time'] = (int)db_value(
        "SELECT COUNT(*)
           FROM topics t
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND EXISTS (SELECT 1 FROM posts p WHERE p.threadid = t.id AND p.is_deleted = 0 LIMIT 1)
            AND (COALESCE(t.time, '') = '' OR t.time = '2000-1-1 00:00:00' OR COALESCE(t.now, '') = '' OR t.now = '2000-1-1 00:00:00')",
        [],
        0
    );

    $vnDuplicateGroups = db_all(
        "SELECT t.legacy_vn_topic_id AS duplicate_key,
                MIN(t.title) AS sample_title,
                MIN(t.boardid) AS sample_boardid,
                COUNT(*) AS copies,
                SUM(CASE WHEN COALESCE(pc.post_count, 0) > 0 THEN 1 ELSE 0 END) AS topics_with_posts,
                SUM(CASE WHEN COALESCE(pc.post_count, 0) = 0 THEN 1 ELSE 0 END) AS zero_post_topics,
                SUM(COALESCE(pc.post_count, 0)) AS total_posts
           FROM topics t
      LEFT JOIN (SELECT threadid, COUNT(*) AS post_count FROM posts WHERE is_deleted = 0 GROUP BY threadid) pc ON pc.threadid = t.id
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND t.legacy_vn_topic_id > 0
       GROUP BY t.legacy_vn_topic_id
         HAVING COUNT(*) > 1
       ORDER BY COUNT(*) DESC, SUM(COALESCE(pc.post_count, 0)) DESC
          LIMIT " . $limit
    );

    $summary['duplicate_vn_topic_id_groups_sampled'] = count($vnDuplicateGroups);
    $summary['duplicate_vn_topic_id_groups_total'] = (int)db_value(
        "SELECT COUNT(*) FROM (
            SELECT legacy_vn_topic_id
              FROM topics
             WHERE legacy_source = 'vn_archive'
               AND is_deleted = 0
               AND legacy_vn_topic_id > 0
          GROUP BY legacy_vn_topic_id
            HAVING COUNT(*) > 1
        ) d",
        [],
        0
    );

    $safeDuplicateStubGroups = 0;
    $safeDuplicateStubTopics = 0;
    $allZeroDuplicateGroups = 0;
    $multiPostDuplicateGroups = 0;
    foreach ($vnDuplicateGroups as $group) {
        $withPosts = (int)($group['topics_with_posts'] ?? 0);
        $zeroPosts = (int)($group['zero_post_topics'] ?? 0);
        if ($withPosts >= 1 && $zeroPosts >= 1) {
            $safeDuplicateStubGroups++;
            $safeDuplicateStubTopics += $zeroPosts;
        }
        if ($withPosts === 0 && $zeroPosts > 1) {
            $allZeroDuplicateGroups++;
        }
        if ($withPosts > 1) {
            $multiPostDuplicateGroups++;
        }
    }
    $summary['sampled_duplicate_groups_with_posted_copy_and_empty_stubs'] = $safeDuplicateStubGroups;
    $summary['sampled_zero_post_duplicate_stubs_safe_to_remove'] = $safeDuplicateStubTopics;
    $summary['sampled_duplicate_groups_all_zero_post_stubs'] = $allZeroDuplicateGroups;
    $summary['sampled_duplicate_groups_with_multiple_posted_copies'] = $multiPostDuplicateGroups;

    $titleDuplicateGroups = db_all(
        "SELECT t.boardid,
                MIN(f.name) AS board_name,
                t.title,
                COUNT(*) AS copies,
                SUM(CASE WHEN COALESCE(pc.post_count, 0) > 0 THEN 1 ELSE 0 END) AS topics_with_posts,
                SUM(CASE WHEN COALESCE(pc.post_count, 0) = 0 THEN 1 ELSE 0 END) AS zero_post_topics,
                SUM(COALESCE(pc.post_count, 0)) AS total_posts
           FROM topics t
      LEFT JOIN forums f ON f.id = t.boardid
      LEFT JOIN (SELECT threadid, COUNT(*) AS post_count FROM posts WHERE is_deleted = 0 GROUP BY threadid) pc ON pc.threadid = t.id
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND t.title <> ''
       GROUP BY t.boardid, t.title
         HAVING COUNT(*) > 1
       ORDER BY COUNT(*) DESC, SUM(COALESCE(pc.post_count, 0)) DESC
          LIMIT " . $limit
    );

    $blankAuthorSamples = db_all(
        "SELECT t.id, t.boardid, f.name AS board_name, t.title, t.posterid AS topic_posterid,
                t.legacy_topic_id, t.legacy_vn_topic_id, t.time, t.lastpost,
                p.id AS first_post_id, p.posterid AS first_post_posterid, p.author AS first_post_author, p.legacy_post_id AS first_legacy_post_id
           FROM topics t
      LEFT JOIN forums f ON f.id = t.boardid
      LEFT JOIN posts p ON p.id = (SELECT p2.id FROM posts p2 WHERE p2.threadid = t.id AND p2.is_deleted = 0 ORDER BY p2.id ASC LIMIT 1)
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND EXISTS (SELECT 1 FROM posts px WHERE px.threadid = t.id AND px.is_deleted = 0 LIMIT 1)
            AND (t.posterid <= 0 OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = t.posterid LIMIT 1))
       ORDER BY t.id ASC
          LIMIT " . $limit
    );

    $zeroPostSamples = db_all(
        "SELECT t.id, t.boardid, f.name AS board_name, t.title, t.posterid, t.legacy_topic_id, t.legacy_vn_topic_id, t.time, t.lastpost
           FROM topics t
      LEFT JOIN forums f ON f.id = t.boardid
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.threadid = t.id AND p.is_deleted = 0 LIMIT 1)
       ORDER BY t.boardid ASC, t.title ASC, t.id ASC
          LIMIT " . $limit
    );

    return [
        'summary' => $summary,
        'duplicate_vn_topic_id_groups' => $vnDuplicateGroups,
        'duplicate_title_groups' => $titleDuplicateGroups,
        'blank_author_samples' => $blankAuthorSamples,
        'zero_post_samples' => $zeroPostSamples,
    ];
}

function wb_archive_import_repair_archive_topic_metadata_batch(int $limit = 1000): array
{
    wb_archive_import_ensure_schema();
    $limit = max(1, min(5000, $limit));
    $lastId = (int)wb_archive_import_get_setting('archive_topic_metadata_repair_last_id', '0');
    $topics = db_all("SELECT id FROM topics WHERE legacy_source = 'vn_archive' AND id > ? ORDER BY id ASC LIMIT " . $limit, [$lastId]);

    $processed = 0;
    $repaired = 0;
    $zeroPostTopics = 0;
    $lastSeen = $lastId;

    db_begin();
    try {
        foreach ($topics as $topic) {
            $topicId = (int)($topic['id'] ?? 0);
            if ($topicId <= 0) { continue; }
            $lastSeen = max($lastSeen, $topicId);
            $processed++;

            $postCount = (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0', [$topicId], 0);
            if ($postCount <= 0) {
                db_run('UPDATE topics SET postcount = 0, replycount = 0 WHERE id = ?', [$topicId]);
                $zeroPostTopics++;
                continue;
            }

            $firstPost = wb_archive_import_topic_first_post($topicId);
            $lastPost = wb_archive_import_topic_last_post($topicId);
            if (!$firstPost || !$lastPost) {
                $zeroPostTopics++;
                continue;
            }

            $starterId = (int)($firstPost['posterid'] ?? 0);
            if ($starterId <= 0) {
                $starterId = wb_archive_import_user_id_for_author_name((string)($firstPost['author'] ?? ''));
            }

            $firstTime = (string)(($firstPost['posttimeraw'] ?? '') !== '' ? $firstPost['posttimeraw'] : ($firstPost['posttime'] ?? ''));
            $lastTime = (string)(($lastPost['posttimeraw'] ?? '') !== '' ? $lastPost['posttimeraw'] : ($lastPost['posttime'] ?? ''));
            $firstDisplay = (string)($firstPost['posttime'] ?? $firstTime);
            $lastDisplay = (string)($lastPost['posttime'] ?? $lastTime);
            if ($firstTime === '') { $firstTime = $firstDisplay; }
            if ($lastTime === '') { $lastTime = $lastDisplay; }

            db_run(
                "UPDATE topics
                    SET posterid = CASE WHEN ? > 0 THEN ? ELSE posterid END,
                        postcount = ?,
                        replycount = ?,
                        now = CASE WHEN ? <> '' THEN ? ELSE now END,
                        time = CASE WHEN ? <> '' THEN ? ELSE time END,
                        lastpost = CASE WHEN ? <> '' THEN ? ELSE lastpost END
                  WHERE id = ?",
                [
                    $starterId,
                    $starterId,
                    $postCount,
                    max(0, $postCount - 1),
                    $firstDisplay,
                    $firstDisplay,
                    $firstTime,
                    $firstTime,
                    $lastTime,
                    $lastTime,
                    $topicId,
                ]
            );
            $repaired++;
        }
        wb_archive_import_set_setting('archive_topic_metadata_repair_last_id', (string)$lastSeen);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    $remaining = (int)db_value("SELECT COUNT(*) FROM topics WHERE legacy_source = 'vn_archive' AND id > ?", [$lastSeen], 0);
    return [
        'processed' => $processed,
        'repaired' => $repaired,
        'zero_post_topics' => $zeroPostTopics,
        'last_id' => $lastSeen,
        'remaining' => $remaining,
    ];
}

function wb_archive_import_reset_topic_metadata_repair_progress(): void
{
    wb_archive_import_set_setting('archive_topic_metadata_repair_last_id', '0');
}

function wb_archive_import_delete_zero_post_duplicate_topics_by_vn_id(int $limit = 500): array
{
    wb_archive_import_ensure_schema();
    $limit = max(1, min(5000, $limit));

    $rows = db_all(
        "SELECT t.id, t.legacy_vn_topic_id, t.title
           FROM topics t
      LEFT JOIN (SELECT threadid, COUNT(*) AS post_count FROM posts WHERE is_deleted = 0 GROUP BY threadid) pc ON pc.threadid = t.id
          INNER JOIN (
                SELECT t2.legacy_vn_topic_id
                  FROM topics t2
             LEFT JOIN (SELECT threadid, COUNT(*) AS post_count FROM posts WHERE is_deleted = 0 GROUP BY threadid) pc2 ON pc2.threadid = t2.id
                 WHERE t2.legacy_source = 'vn_archive'
                   AND t2.is_deleted = 0
                   AND t2.legacy_vn_topic_id > 0
              GROUP BY t2.legacy_vn_topic_id
                HAVING COUNT(*) > 1
                   AND SUM(CASE WHEN COALESCE(pc2.post_count, 0) > 0 THEN 1 ELSE 0 END) >= 1
                   AND SUM(CASE WHEN COALESCE(pc2.post_count, 0) = 0 THEN 1 ELSE 0 END) >= 1
          ) d ON d.legacy_vn_topic_id = t.legacy_vn_topic_id
          WHERE t.legacy_source = 'vn_archive'
            AND t.is_deleted = 0
            AND COALESCE(pc.post_count, 0) = 0
       ORDER BY t.legacy_vn_topic_id ASC, t.id ASC
          LIMIT " . $limit
    );

    $ids = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows), static fn($id) => $id > 0));
    if (!$ids) {
        return ['deleted' => 0, 'groups_touched' => 0, 'remaining_hint' => 'done'];
    }

    $groups = [];
    foreach ($rows as $row) {
        $key = (string)($row['legacy_vn_topic_id'] ?? '0');
        $groups[$key] = true;
    }

    db_begin();
    try {
        foreach (array_chunk($ids, 250) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            db_run("DELETE FROM topics WHERE legacy_source = 'vn_archive' AND id IN (" . $placeholders . ')', $chunk);
        }
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    return [
        'deleted' => count($ids),
        'groups_touched' => count($groups),
        'remaining_hint' => count($rows) >= $limit ? 'more' : 'done',
    ];
}


function wb_archive_import_source_post_refs_for_username(PDO $source, string $username): int
{
    if ($username === '') {
        return 0;
    }
    return (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM posts WHERE user_name = ?', [$username], 0);
}

function wb_archive_import_target_archive_username_for_identity(string $identityKey): string
{
    return (string)db_value("SELECT username FROM users WHERE legacy_source = 'vn_archive' AND legacy_identity_key = ? LIMIT 1", [$identityKey], '');
}


function wb_archive_import_source_identity_lookup(PDO $source): array
{
    $rows = wb_archive_import_source_all($source, 'SELECT user_id, user_name FROM users ORDER BY db_id ASC');
    $identityKeys = [];
    $legacyIds = [];
    foreach ($rows as $row) {
        $legacyId = (int)($row['user_id'] ?? 0);
        $name = wb_archive_import_normalize_username((string)($row['user_name'] ?? ''));
        $identityKeys[wb_archive_import_identity_key($legacyId, $name)] = true;
        if ($legacyId > 0) {
            $legacyIds[$legacyId] = true;
        }
    }
    return ['identity_keys' => $identityKeys, 'legacy_ids' => $legacyIds, 'source_rows' => count($rows), 'unique_identities' => count($identityKeys)];
}

function wb_archive_import_count_refs_for_ids(string $table, string $column, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    if (!$ids) {
        return [];
    }
    if (!db_identifier_is_safe($table) || !db_identifier_is_safe($column)) {
        throw new InvalidArgumentException('Unsafe archive ref-count request.');
    }
    $counts = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $rows = db_all('SELECT ' . db_quote_identifier($column) . ' AS ref_id, COUNT(*) AS total FROM ' . db_quote_identifier($table) . ' WHERE ' . db_quote_identifier($column) . ' IN (' . $placeholders . ') GROUP BY ' . db_quote_identifier($column), $chunk);
        foreach ($rows as $row) {
            $counts[(int)$row['ref_id']] = (int)$row['total'];
        }
    }
    return $counts;
}

function wb_archive_import_topic_created_user_candidates(PDO $source, int $limit = 100): array
{
    wb_archive_import_ensure_schema();
    $limit = max(10, min(500, $limit));
    $lookup = wb_archive_import_source_identity_lookup($source);
    $targets = db_all("SELECT id, username, legacy_user_id, legacy_identity_key, legacy_username, legacy_original_post_count FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 ORDER BY id ASC");

    $extras = [];
    foreach ($targets as $target) {
        $idKey = (string)($target['legacy_identity_key'] ?? '');
        $legacyId = (int)($target['legacy_user_id'] ?? 0);
        $matchesSource = false;
        if ($idKey !== '' && isset($lookup['identity_keys'][$idKey])) {
            $matchesSource = true;
        }
        if (!$matchesSource && $legacyId > 0 && isset($lookup['legacy_ids'][$legacyId])) {
            $matchesSource = true;
        }
        if (!$matchesSource) {
            $extras[] = [
                'id' => (int)($target['id'] ?? 0),
                'username' => (string)($target['username'] ?? ''),
                'legacy_user_id' => $legacyId,
                'legacy_identity_key' => $idKey,
                'legacy_username' => (string)($target['legacy_username'] ?? ''),
                'legacy_original_post_count' => (int)($target['legacy_original_post_count'] ?? 0),
            ];
        }
    }

    $extraIds = array_map(static fn($row) => (int)$row['id'], $extras);
    $topicRefs = wb_archive_import_count_refs_for_ids('topics', 'posterid', $extraIds);
    $postRefs = wb_archive_import_count_refs_for_ids('posts', 'posterid', $extraIds);

    $deletable = 0;
    $blockedByPosts = 0;
    foreach ($extras as $i => $row) {
        $id = (int)$row['id'];
        $extras[$i]['archive_topic_refs'] = (int)($topicRefs[$id] ?? 0);
        $extras[$i]['archive_post_refs'] = (int)($postRefs[$id] ?? 0);
        if (($postRefs[$id] ?? 0) > 0) {
            $blockedByPosts++;
        } else {
            $deletable++;
        }
    }

    return [
        'summary' => [
            'source_user_rows' => (int)($lookup['source_rows'] ?? 0),
            'source_unique_identities' => (int)($lookup['unique_identities'] ?? 0),
            'target_archive_users' => count($targets),
            'target_users_not_in_source_users' => count($extras),
            'safe_to_delete_no_post_refs' => $deletable,
            'blocked_has_post_refs' => $blockedByPosts,
        ],
        'candidates' => array_slice($extras, 0, $limit),
        'candidate_ids' => $extraIds,
    ];
}

function wb_archive_import_cleanup_topic_created_users(PDO $source): array
{
    $diag = wb_archive_import_topic_created_user_candidates($source, 500);
    $ids = array_values(array_unique(array_map('intval', $diag['candidate_ids'] ?? [])));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    if (!$ids) {
        return ['deleted' => 0, 'topic_refs_cleared' => 0, 'blocked_has_post_refs' => 0];
    }

    $postRefs = wb_archive_import_count_refs_for_ids('posts', 'posterid', $ids);
    $deleteIds = [];
    $blocked = 0;
    foreach ($ids as $id) {
        if (($postRefs[$id] ?? 0) > 0) {
            $blocked++;
        } else {
            $deleteIds[] = $id;
        }
    }
    if (!$deleteIds) {
        return ['deleted' => 0, 'topic_refs_cleared' => 0, 'blocked_has_post_refs' => $blocked];
    }

    $deleted = 0;
    $topicRefsCleared = 0;
    db_begin();
    try {
        foreach (array_chunk($deleteIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $ok = db_run("UPDATE topics SET posterid = 0 WHERE legacy_source = 'vn_archive' AND posterid IN (" . $placeholders . ')', $chunk);
            if (!$ok) { throw new RuntimeException('Failed to clear topic poster references for placeholder cleanup.'); }
            $topicRefsCleared += (int)($GLOBALS['WB_DB_LAST_AFFECTED_ROWS'] ?? 0);

            $ok = db_run("DELETE FROM users WHERE legacy_source = 'vn_archive' AND is_archive_user = 1 AND id IN (" . $placeholders . ')', $chunk);
            if (!$ok) { throw new RuntimeException('Failed to delete archive user placeholders.'); }
            $deleted += (int)($GLOBALS['WB_DB_LAST_AFFECTED_ROWS'] ?? 0);
        }
        wb_archive_import_log('warn', 'users', 'topic_placeholder_cleanup', 'Removed ' . $deleted . ' topic-created archive user placeholders and cleared ' . $topicRefsCleared . ' topic poster references.');
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    return ['deleted' => $deleted, 'topic_refs_cleared' => $topicRefsCleared, 'blocked_has_post_refs' => $blocked];
}


function wb_archive_import_post_author_placeholder_diagnostics(PDO $source, int $limit = 100): array
{
    $diag = wb_archive_import_topic_created_user_candidates($source, $limit);
    $summary = $diag['summary'] ?? [];
    $summary['post_only_author_users'] = (int)($summary['target_users_not_in_source_users'] ?? 0);
    $summary['post_only_author_users_with_imported_post_refs'] = (int)($summary['blocked_has_post_refs'] ?? 0);
    $summary['post_only_author_users_without_imported_post_refs'] = (int)($summary['safe_to_delete_no_post_refs'] ?? 0);
    $diag['summary'] = $summary;
    return $diag;
}

function wb_archive_import_user_diagnostics(PDO $source, int $limit = 100): array
{
    wb_archive_import_ensure_schema();
    $limit = max(10, min(500, $limit));
    $rows = wb_archive_import_source_all($source, 'SELECT db_id, user_id, user_name, actual_post_count, title FROM users ORDER BY db_id ASC');

    $groups = [];
    $blankRows = [];
    foreach ($rows as $row) {
        $legacyId = (int)($row['user_id'] ?? 0);
        $rawName = (string)($row['user_name'] ?? '');
        $cleanName = wb_archive_import_normalize_username($rawName);
        $identityKey = wb_archive_import_identity_key($legacyId, $cleanName);
        $detail = [
            'db_id' => (int)($row['db_id'] ?? 0),
            'legacy_user_id' => $legacyId,
            'raw_user_name' => $rawName,
            'user_name' => $cleanName,
            'identity_key' => $identityKey,
            'actual_post_count' => (int)($row['actual_post_count'] ?? 0),
        ];
        $groups[$identityKey][] = $detail;
        $rawDecoded = html_entity_decode($rawName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rawDecoded = str_replace("\xc2\xa0", ' ', $rawDecoded);
        if (trim((string)preg_replace('/\s+/u', ' ', strip_tags($rawDecoded))) === '') {
            $blankRows[] = $detail;
        }
    }

    $missingGroups = [];
    $collisionGroups = [];
    $matchedSourceRows = 0;
    $missingSourceRows = 0;
    $representedByDuplicateIdentity = 0;

    foreach ($groups as $identityKey => $items) {
        $targetId = wb_archive_import_find_user_by_identity($identityKey);
        if ($targetId <= 0) {
            $first = $items[0] ?? [];
            $legacyId = (int)($first['legacy_user_id'] ?? 0);
            if ($legacyId > 0) {
                $targetId = (int)db_value("SELECT id FROM users WHERE legacy_source = 'vn_archive' AND legacy_user_id = ? LIMIT 1", [$legacyId], 0);
            }
        }

        if ($targetId > 0) {
            $matchedSourceRows += count($items);
        } else {
            $missingSourceRows += count($items);
            if (count($missingGroups) < $limit) {
                $sample = $items[0];
                $sample['source_posts_using_name'] = wb_archive_import_source_post_refs_for_username($source, (string)$sample['raw_user_name']);
                $missingGroups[] = $sample;
            }
        }

        if (count($items) > 1) {
            $representedByDuplicateIdentity += count($items) - 1;
            if (count($collisionGroups) < $limit) {
                $sampleRows = [];
                foreach (array_slice($items, 0, 8) as $item) {
                    $item['source_posts_using_name'] = wb_archive_import_source_post_refs_for_username($source, (string)$item['raw_user_name']);
                    $sampleRows[] = $item;
                }
                $collisionGroups[] = [
                    'identity_key' => $identityKey,
                    'source_rows' => count($items),
                    'target_user_id' => $targetId,
                    'target_username' => wb_archive_import_target_archive_username_for_identity($identityKey),
                    'rows' => $sampleRows,
                ];
            }
        }
    }

    $blankSamples = [];
    foreach (array_slice($blankRows, 0, $limit) as $row) {
        $row['source_posts_using_name'] = wb_archive_import_source_post_refs_for_username($source, (string)$row['raw_user_name']);
        $blankSamples[] = $row;
    }

    $targetArchiveUsers = (int)db_value("SELECT COUNT(*) FROM users WHERE legacy_source = 'vn_archive'", [], 0);
    $sourceRows = count($rows);
    $uniqueIdentities = count($groups);

    return [
        'summary' => [
            'source_user_rows' => $sourceRows,
            'unique_source_identities' => $uniqueIdentities,
            'target_archive_users' => $targetArchiveUsers,
            'matched_source_rows' => $matchedSourceRows,
            'missing_source_rows' => $missingSourceRows,
            'duplicate_identity_groups' => count(array_filter($groups, static fn($items) => count($items) > 1)),
            'source_rows_collapsed_by_duplicate_identity' => $representedByDuplicateIdentity,
            'blank_source_username_rows' => count($blankRows),
            'target_minus_unique_source_identities' => $targetArchiveUsers - $uniqueIdentities,
        ],
        'missing' => $missingGroups,
        'collisions' => $collisionGroups,
        'blank_usernames' => $blankSamples,
    ];
}

function wb_archive_import_stats(PDO $source): array
{
    $sourceCounts = [];
    foreach (['crawl_list', 'crawl_list_categories', 'users', 'topics', 'posts'] as $table) {
        $sourceCounts[$table] = (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM ' . $table, [], 0);
    }
    return [
        'source' => $sourceCounts,
        'target' => [
            'archive_users' => (int)db_value("SELECT COUNT(*) FROM users WHERE legacy_source = 'vn_archive'", [], 0),
            'archive_boards' => (int)db_value("SELECT COUNT(*) FROM forums WHERE legacy_source = 'vn_archive'", [], 0),
            'archive_topics' => (int)db_value("SELECT COUNT(*) FROM topics WHERE legacy_source = 'vn_archive'", [], 0),
            'archive_posts' => (int)db_value("SELECT COUNT(*) FROM posts WHERE legacy_source = 'vn_archive'", [], 0),
            'log_errors' => wb_archive_import_table_exists('archive_import_log') ? (int)db_value("SELECT COUNT(*) FROM archive_import_log WHERE run_key = 'vn_archive' AND level = 'error'", [], 0) : 0,
            'log_warnings' => wb_archive_import_table_exists('archive_import_log') ? (int)db_value("SELECT COUNT(*) FROM archive_import_log WHERE run_key = 'vn_archive' AND level = 'warn'", [], 0) : 0,
        ],
        'progress' => [
            'users_last_id' => wb_archive_import_get_setting('archive_import_users_last_id', '0'),
            'topics_last_id' => wb_archive_import_get_setting('archive_import_topics_last_id', '0'),
            'posts_last_id' => wb_archive_import_get_setting('archive_import_posts_last_id', '0'),
        ],
    ];
}

function wb_archive_import_preview(PDO $source): array
{
    $samples = [];
    $samples['boards'] = wb_archive_import_source_all($source, 'SELECT cl.board_id, cl.Description, cl.current_title, c.category_title FROM crawl_list cl LEFT JOIN crawl_list_categories c ON c.category_id = cl.category_id ORDER BY cl.category_id ASC, cl.crawl_id ASC LIMIT 10');
    $samples['users'] = [];
    foreach (wb_archive_import_source_all($source, 'SELECT * FROM users ORDER BY db_id ASC LIMIT 10') as $row) {
        $samples['users'][] = [
            'legacy_user_id' => (int)($row['user_id'] ?? 0),
            'user_name' => wb_archive_import_normalize_username((string)($row['user_name'] ?? '')),
            'identity_key' => wb_archive_import_identity_key((int)($row['user_id'] ?? 0), (string)($row['user_name'] ?? '')),
            'title_bbcode' => wb_archive_import_user_title_to_bbcode((string)($row['title'] ?? '')),
            'signature_bbcode' => wb_archive_import_signature_to_bbcode((string)($row['signature'] ?? '')),
        ];
    }
    $samples['posts'] = [];
    foreach (wb_archive_import_source_all($source, 'SELECT * FROM posts ORDER BY post_id ASC LIMIT 10') as $row) {
        $samples['posts'][] = [
            'post_id' => (int)($row['post_id'] ?? 0),
            'topic_id' => (int)($row['topic_id'] ?? 0),
            'user_name' => wb_archive_import_normalize_username((string)($row['user_name'] ?? '')),
            'converted' => wb_archive_import_substr(wb_archive_import_message_to_bbcode((string)($row['message'] ?? '')), 0, 1000),
        ];
    }
    $samples['relationship_issues'] = [
        'topics_missing_crawl_list_board' => (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM topics t LEFT JOIN crawl_list cl ON cl.board_id = t.board_id WHERE cl.board_id IS NULL', [], 0),
        'posts_match_topics_by_archive_topic_id' => (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM posts p INNER JOIN topics t ON t.topic_id = p.topic_id', [], 0),
        'posts_match_topics_by_vn_topic_id' => (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM posts p INNER JOIN topics t ON t.vn_topic_id = p.topic_id', [], 0),
        'posts_missing_topic' => (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM posts p LEFT JOIN topics t1 ON t1.topic_id = p.topic_id LEFT JOIN topics t2 ON t2.vn_topic_id = p.topic_id WHERE t1.topic_id IS NULL AND t2.topic_id IS NULL', [], 0),
        'posts_using_username_fallback' => (int)wb_archive_import_source_value($source, 'SELECT COUNT(*) FROM posts WHERE user_id = 0 OR user_id IS NULL', [], 0),
    ];
    return $samples;
}
