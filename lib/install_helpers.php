<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  install_helpers.php - Fresh-install bootstrap        |
 |  helpers for public releases.                         |
 +-------------------------------------------------------+*/

if (!defined('COREBB_INSTALL_HELPERS_LOADED')) {
    define('COREBB_INSTALL_HELPERS_LOADED', true);
}

require_once __DIR__ . '/../core/version.php';

/**
 * Usage: Find the application root from this helper file.
 * Referenced by: installer path helpers.
 *
 * @return string Absolute application root.
 */
function corebb_install_root(): string
{
    return dirname(__DIR__);
}

/**
 * Usage: Normalize filesystem paths before comparing install locations.
 * Referenced by: installer document-root and private-config helpers.
 *
 * @param string $path Filesystem path.
 * @return string Normalized path without a trailing slash.
 */
function corebb_install_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?: $path;
    return rtrim($path, '/');
}

/**
 * Usage: Resolve the current vhost document root for secure private path math.
 * Referenced by: installer private-base and URL helpers.
 *
 * @param array<string, mixed> $server Server/request data.
 * @return string Document root, or an empty string when unavailable.
 */
function corebb_install_document_root(array $server): string
{
    $docRoot = trim((string)($server['DOCUMENT_ROOT'] ?? getenv('DOCUMENT_ROOT') ?: ''));
    if ($docRoot === '') {
        return '';
    }
    $resolved = realpath($docRoot);
    return corebb_install_normalize_path($resolved !== false ? $resolved : $docRoot);
}

/**
 * Usage: Determine the forum's public base path for a root or subdirectory install.
 * Referenced by: installer URL defaults and links.
 *
 * @param array<string, mixed> $server Server/request data.
 * @return string Base path with no trailing slash, or an empty string at domain root.
 */
function corebb_install_public_base_path(array $server): string
{
    $requestPath = (string)(parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    if ($requestPath !== '') {
        $requestPath = '/' . trim($requestPath, '/');
        if (preg_match('~^(.*)/install/?$~i', $requestPath, $match)) {
            return rtrim((string)$match[1], '/');
        }
    }

    $script = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? ''));
    $script = '/' . trim($script, '/');
    foreach (['/controllers/install.php', '/install.php'] as $suffix) {
        if (str_ends_with($script, $suffix)) {
            return rtrim(substr($script, 0, -1 * strlen($suffix)), '/');
        }
    }

    return '';
}

/**
 * Usage: Build a root-relative installer/forum URL under the detected base path.
 * Referenced by: installer template model.
 *
 * @param array<string, mixed> $server Server/request data.
 * @param string $path Path relative to the forum root.
 * @return string Root-relative URL.
 */
function corebb_install_public_path(array $server, string $path = ''): string
{
    $base = corebb_install_public_base_path($server);
    return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
}

/**
 * Usage: Build a stable private-config instance key for this forum install.
 * Referenced by: installer config path and generated config metadata.
 *
 * @param array<string, mixed> $server Server/request data.
 * @return string Instance key such as corebb_net_forum.
 */
function corebb_install_instance_key(array $server): string
{
    $forced = strtolower(trim((string)(getenv('COREBB_INSTANCE') ?: '')));
    if ($forced !== '' && preg_match('/^[a-z0-9_-]{1,80}$/', $forced)) {
        return $forced;
    }

    $host = strtolower(trim((string)($server['HTTP_HOST'] ?? getenv('HTTP_HOST') ?: '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $base = trim(corebb_install_public_base_path($server), '/');
    $seed = trim($host . ($base !== '' ? '_' . str_replace('/', '_', $base) : ''), '_');
    if ($seed === '') {
        $seed = basename(corebb_install_root());
    }

    $key = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $seed), '_'));
    return $key !== '' ? substr($key, 0, 80) : 'default';
}

/**
 * Usage: Choose a private config base outside the public document root when possible.
 * Referenced by: installer config path helpers.
 *
 * @param array<string, mixed> $server Server/request data.
 * @return string Absolute private base directory.
 */
function corebb_install_private_base_dir(array $server): string
{
    $forcedBase = trim((string)(getenv('COREBB_PRIVATE_BASE_DIR') ?: ''));
    if ($forcedBase !== '') {
        return corebb_install_normalize_path($forcedBase);
    }

    $root = corebb_install_normalize_path(corebb_install_root());
    $rootPublicHtmlPos = stripos($root, '/public_html');
    if ($rootPublicHtmlPos !== false) {
        return substr($root, 0, $rootPublicHtmlPos) . DIRECTORY_SEPARATOR . 'corebb_private';
    }

    $docRoot = corebb_install_document_root($server);
    if ($docRoot !== '') {
        $docPublicHtmlPos = stripos($docRoot, '/public_html');
        if ($docPublicHtmlPos !== false) {
            return substr($docRoot, 0, $docPublicHtmlPos) . DIRECTORY_SEPARATOR . 'corebb_private';
        }

        return dirname($docRoot) . DIRECTORY_SEPARATOR . 'corebb_private';
    }

    return dirname(corebb_install_root()) . DIRECTORY_SEPARATOR . 'corebb_private';
}

/**
 * Usage: Decide whether legacy shared private config paths apply to this install.
 * Referenced by: installer config candidate helper.
 *
 * @param array<string, mixed> $server Server/request data.
 * @return bool True for root installs and explicit legacy overrides.
 */
function corebb_install_use_legacy_private_path(array $server): bool
{
    $forced = strtolower(trim((string)(getenv('COREBB_ALLOW_LEGACY_PRIVATE_CONFIG') ?: '')));
    if (in_array($forced, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    $docRoot = corebb_install_document_root($server);
    return $docRoot === '' || $docRoot === corebb_install_normalize_path(corebb_install_root());
}

/**
 * Usage: Build the CSRF token used by the installer form.
 * Referenced by: corebb_install_model() and corebb_install_token_ok().
 *
 * @return string Current installer token.
 */
function corebb_install_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['corebb_install_token']) || !is_string($_SESSION['corebb_install_token'])) {
        $_SESSION['corebb_install_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['corebb_install_token'];
}

/**
 * Usage: Validate an installer form submission token.
 * Referenced by: corebb_install_model().
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @return bool True when the token matches.
 */
function corebb_install_token_ok(array $post): bool
{
    $token = (string)($post['install_token'] ?? '');
    return $token !== '' && hash_equals(corebb_install_token(), $token);
}

/**
 * Usage: Resolve the likely public base URL for the current installer request.
 * Referenced by: corebb_install_default_form().
 *
 * @param array<string, mixed> $server Server/request data.
 * @return string Absolute public URL without a trailing slash.
 */
function corebb_install_detect_public_url(array $server): string
{
    $https = strtolower((string)($server['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = trim((string)($server['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . rtrim($host !== '' ? $host : 'localhost', '/') . corebb_install_public_base_path($server);
}

/**
 * Usage: Build the empty installer form with sensible release defaults.
 * Referenced by: corebb_install_model().
 *
 * @param array<string, mixed> $server Server/request data.
 * @return array<string, string> Default form values.
 */
function corebb_install_default_form(array $server): array
{
    $url = corebb_install_detect_public_url($server);
    return [
        'db_host' => 'localhost',
        'db_name' => 'corebb',
        'db_user' => '',
        'site_name' => 'CoreBB',
        'site_url' => $url,
        'board_name' => 'CoreBB Forums',
        'board_url' => $url,
        'admin_username' => '',
        'admin_email' => '',
    ];
}

/**
 * Usage: Rebuild installer form values after a submission.
 * Referenced by: corebb_install_model().
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @param array<string, mixed> $server Server/request data.
 * @return array<string, string> Sanitized form values safe to re-display.
 */
function corebb_install_form_from_post(array $post, array $server): array
{
    $form = corebb_install_default_form($server);
    foreach (array_keys($form) as $key) {
        if (array_key_exists($key, $post)) {
            $form[$key] = trim((string)$post[$key]);
        }
    }
    return $form;
}

/**
 * Usage: List private/local config files that mean the installer should lock.
 * Referenced by: corebb_install_existing_config_files().
 *
 * @return array<int, string> Candidate config file paths.
 */
function corebb_install_config_candidates(array $server = []): array
{
    $root = corebb_install_root();
    $privateBase = corebb_install_private_base_dir($server);
    $instanceBase = $privateBase . DIRECTORY_SEPARATOR . corebb_install_instance_key($server);
    $paths = [
        $instanceBase . DIRECTORY_SEPARATOR . 'config.local.php',
        $instanceBase . DIRECTORY_SEPARATOR . 'config.live.php',
        $instanceBase . DIRECTORY_SEPARATOR . 'config.staging.php',
        $root . DIRECTORY_SEPARATOR . 'config.local.php',
    ];

    if (corebb_install_use_legacy_private_path($server)) {
        $paths[] = $privateBase . DIRECTORY_SEPARATOR . 'config.local.php';
        $paths[] = $privateBase . DIRECTORY_SEPARATOR . 'config.live.php';
        $paths[] = $privateBase . DIRECTORY_SEPARATOR . 'config.staging.php';
    }

    return $paths;
}

/**
 * Usage: Detect whether an install/private config already exists.
 * Referenced by: corebb_install_model().
 *
 * @return array<int, string> Existing config files.
 */
function corebb_install_existing_config_files(array $server = []): array
{
    return array_values(array_filter(corebb_install_config_candidates($server), static fn (string $path): bool => is_file($path)));
}

/**
 * Usage: Return the preferred private config path for new local installs.
 * Referenced by: corebb_install_write_config().
 *
 * @return string Absolute target path.
 */
function corebb_install_preferred_config_path(array $server = []): string
{
    return corebb_install_private_base_dir($server)
        . DIRECTORY_SEPARATOR . corebb_install_instance_key($server)
        . DIRECTORY_SEPARATOR . 'config.local.php';
}

/**
 * Usage: Split a database host string into host and optional port.
 * Referenced by: corebb_install_pdo().
 *
 * @param string $host Hostname or host:port.
 * @return array{0: string, 1: int|null} Host and optional port.
 */
function corebb_install_parse_host(string $host): array
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
 * Usage: Open a PDO connection for installer setup.
 * Referenced by: corebb_install_connect_database().
 *
 * @param string $host Hostname or host:port.
 * @param string $username Database username.
 * @param string $password Database password.
 * @param string|null $database Optional database/schema name.
 * @return PDO Connected PDO instance.
 */
function corebb_install_pdo(string $host, string $username, string $password, ?string $database = null): PDO
{
    [$hostOnly, $port] = corebb_install_parse_host($host);
    $dsn = 'mysql:host=' . $hostOnly . ($port ? ';port=' . $port : '') . ($database ? ';dbname=' . $database : '') . ';charset=utf8mb4';
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/**
 * Usage: Quote a database identifier after strict validation.
 * Referenced by: database creation and schema checks.
 *
 * @param string $identifier Database/table identifier.
 * @return string Backtick-quoted identifier.
 */
function corebb_install_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Database names may only contain letters, numbers, and underscores.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Connect to the requested database, creating it when allowed.
 * Referenced by: corebb_install_run().
 *
 * @param array<string, string> $form Installer form values.
 * @param string $password Database password.
 * @return PDO Connected PDO using the requested database.
 */
function corebb_install_connect_database(array $form, string $password): PDO
{
    $host = trim($form['db_host'] ?? '');
    $database = trim($form['db_name'] ?? '');
    $username = trim($form['db_user'] ?? '');

    try {
        return corebb_install_pdo($host, $username, $password, $database);
    } catch (Throwable $firstError) {
        $serverPdo = corebb_install_pdo($host, $username, $password, null);
        $serverPdo->exec('CREATE DATABASE IF NOT EXISTS ' . corebb_install_identifier($database)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $serverPdo->exec('USE ' . corebb_install_identifier($database));
        return $serverPdo;
    }
}

/**
 * Usage: Return table names already present in the selected database.
 * Referenced by: corebb_install_run().
 *
 * @param PDO $pdo Installer database connection.
 * @return array<int, string> Existing table names.
 */
function corebb_install_existing_tables(PDO $pdo): array
{
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    return array_map(static fn (array $row): string => (string)($row[0] ?? ''), $rows ?: []);
}

/**
 * Usage: Split the install schema into executable SQL statements.
 * Referenced by: corebb_install_apply_schema().
 *
 * @param string $sql Schema SQL.
 * @return array<int, string> Individual statements.
 */
function corebb_install_split_sql(string $sql): array
{
    // Remove standalone export comments before splitting so semicolons inside
    // notes such as "schema; structure only" are not treated as SQL endings.
    $sql = preg_replace('/^\s*(?:--|#).*$/m', '', $sql) ?: $sql;

    $statements = [];
    $current = '';
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
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim($current);
            $current = '';
            if ($statement !== '') {
                $statements[] = rtrim($statement, ';');
            }
        }
    }

    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}

/**
 * Usage: Create all CoreBB tables from the bundled fresh-install schema.
 * Referenced by: corebb_install_run().
 *
 * @param PDO $pdo Installer database connection.
 * @return int Number of SQL statements executed.
 */
function corebb_install_apply_schema(PDO $pdo): int
{
    $path = corebb_install_root() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'install_schema.sql';
    if (!is_file($path)) {
        throw new RuntimeException('Install schema file is missing.');
    }

    $count = 0;
    foreach (corebb_install_split_sql((string)file_get_contents($path)) as $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }
    return $count;
}

/**
 * Usage: Insert the required system settings for a usable fresh forum.
 * Referenced by: corebb_install_seed_database().
 *
 * @param PDO $pdo Installer database connection.
 * @return void
 */
function corebb_install_seed_settings(PDO $pdo): void
{
    $settings = [
        [1, 'defaultstyle', 'style_vn_eol.css'],
        [null, 'theme_vn_eol', '1'],
        [null, 'encaseboards', '1'],
        [null, 'showbasicstats', '1'],
        [null, 'allowguests', '1'],
        [null, 'customtitles', '1'],
        [null, 'quickreply', '1'],
        [null, 'markupcode', '1'],
        [null, 'maintenancemode', '0'],
        [null, 'maintenancesubject', 'Boards Offline'],
        [null, 'maintenancemessage', 'The boards are temporarily unavailable.'],
        [null, 'terms_of_service', 'Be excellent to each other. Update these rules from the admin panel before launch.'],
        [null, 'installed_version', COREBB_VERSION],
        [null, 'schema_version', (string)COREBB_SCHEMA_VERSION],
        [null, 'last_update_check_at', ''],
        [null, 'last_update_check_status', 'never'],
        [null, 'last_update_manifest', ''],
        [null, 'last_successful_update_check_at', ''],
        [null, 'last_update_check_error', ''],
        [null, 'update_manifest_signature_status', 'unsigned'],
        [null, 'rate_limit_login_enabled', '1'],
        [null, 'rate_limit_login_ip_10m_max', '8'],
        [null, 'rate_limit_login_ip_10m_window', '600'],
        [null, 'rate_limit_login_ip_1h_max', '25'],
        [null, 'rate_limit_login_ip_1h_window', '3600'],
        [null, 'rate_limit_login_user_ip_15m_max', '6'],
        [null, 'rate_limit_login_user_ip_15m_window', '900'],
        [null, 'rate_limit_pm_enabled', '1'],
        [null, 'rate_limit_pm_user_10m_max', '10'],
        [null, 'rate_limit_pm_user_10m_window', '600'],
        [null, 'rate_limit_pm_user_1h_max', '40'],
        [null, 'rate_limit_pm_user_1h_window', '3600'],
        [null, 'rate_limit_post_enabled', '1'],
        [null, 'rate_limit_post_user_20s_max', '1'],
        [null, 'rate_limit_post_user_20s_window', '20'],
        [null, 'rate_limit_post_user_10m_max', '15'],
        [null, 'rate_limit_post_user_10m_window', '600'],
        [null, 'rate_limit_post_user_1h_max', '60'],
        [null, 'rate_limit_post_user_1h_window', '3600'],
        [null, 'rate_limit_report_enabled', '1'],
        [null, 'rate_limit_report_user_10m_max', '5'],
        [null, 'rate_limit_report_user_10m_window', '600'],
        [null, 'rate_limit_report_user_1d_max', '25'],
        [null, 'rate_limit_report_user_1d_window', '86400'],
        [null, 'rate_limit_report_ip_1h_max', '30'],
        [null, 'rate_limit_report_ip_1h_window', '3600'],
    ];

    $withId = $pdo->prepare('INSERT INTO systemsettings (id, name, setting) VALUES (?, ?, ?)');
    $withoutId = $pdo->prepare('INSERT INTO systemsettings (name, setting) VALUES (?, ?)');
    foreach ($settings as [$id, $name, $setting]) {
        if ($id !== null) {
            $withId->execute([$id, $name, $setting]);
            continue;
        }
        $withoutId->execute([$name, $setting]);
    }
}

/**
 * Usage: Return the date formats required by CoreBB topic/post/forum columns.
 * Referenced by: corebb_install_seed_bootstrap_topic().
 *
 * @return array{vn_date: string, unix: int, short_date: string}
 */
function corebb_install_seed_now_values(): array
{
    $unix = time();
    return [
        'vn_date' => date('Y-n-j H:i:s', $unix),
        'unix' => $unix,
        'short_date' => date('m/d/y', $unix),
    ];
}

/**
 * Usage: Build the first visible forum post for fresh installs.
 * Referenced by: corebb_install_seed_bootstrap_topic().
 *
 * @return string BBCode/plain-text starter instructions.
 */
function corebb_install_bootstrap_post_body(): string
{
    return "[b]First step: configure mail services in Admin > Mail Services.[/b]\n\n"
        . "Email verification, password recovery, and future notification mail rely on outbound mail being configured for this forum instance.\n\n"
        . "Basic launch checklist:\n"
        . "1. Configure Mail Services and send a test email.\n"
        . "2. Review System Settings, Terms of Service, and the public board style.\n"
        . "3. Rename or reorganize the default category and board from Manage Boards.\n"
        . "4. Create any staff accounts and assign only the admin tools they need.\n"
        . "5. Make a database backup before inviting users.\n\n"
        . "You can edit or delete this starter topic after setup.";
}

/**
 * Usage: Seed a sticky bootstrap topic into the first public board.
 * Referenced by: corebb_install_seed_database().
 *
 * @param PDO $pdo Installer database connection.
 * @param int $forumId First forum/board id.
 * @param int $adminId First administrator user id.
 * @param string $adminUsername First administrator username.
 * @return int Created topic id.
 */
function corebb_install_seed_bootstrap_topic(PDO $pdo, int $forumId, int $adminId, string $adminUsername): int
{
    $now = corebb_install_seed_now_values();
    $title = 'Start Here: Configure Your New CoreBB Forum';
    $body = corebb_install_bootstrap_post_body();

    $pdo->prepare('INSERT INTO topics (boardid, title, body, posterid, lastpost, time, sticky) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$forumId, $title, $body, $adminId, $now['vn_date'], (string)$now['unix']]);
    $topicId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO posts (posterid, title, body, author, threadid, boardid, ptd, posttime, posttimeraw, postip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$adminId, $title, $body, $adminUsername, $topicId, $forumId, $now['short_date'], $now['vn_date'], (string)$now['unix'], '']);

    $pdo->prepare('UPDATE topics SET lastpost = ?, now = ?, postcount = 1, replycount = 0 WHERE id = ?')
        ->execute([$now['vn_date'], (string)$now['unix'], $topicId]);
    $pdo->prepare('UPDATE forums SET lastpstdate = ?, lastpstdatets = ?, threadid = ?, topiccount = 1, postcount = 1 WHERE id = ?')
        ->execute([$now['vn_date'], (string)$now['unix'], $topicId, $forumId]);
    $pdo->prepare('UPDATE users SET posts = 1, lastpost = ?, lastpstdate = ? WHERE id = ?')
        ->execute([(string)$now['unix'], $now['vn_date'], $adminId]);

    return $topicId;
}

/**
 * Usage: Seed default styles, one category, one board, and the first admin.
 * Referenced by: corebb_install_run().
 *
 * @param PDO $pdo Installer database connection.
 * @param array<string, string> $form Installer form values.
 * @param string $adminPassword Plaintext admin password from validated form.
 * @return array<string, mixed> Seed result details.
 */
function corebb_install_seed_database(PDO $pdo, array $form, string $adminPassword): array
{
    require_once corebb_install_root() . '/lib/auth_password_helpers.php';

    $pdo->beginTransaction();
    try {
        corebb_install_seed_settings($pdo);

        $pdo->prepare('INSERT INTO systemstyles (id, name, file) VALUES (?, ?, ?)')
            ->execute([1, 'CoreBB VN EOL', 'style_vn_eol.css']);
        $pdo->prepare('INSERT INTO systemstyles (id, name, file) VALUES (?, ?, ?)')
            ->execute([2, 'CoreBB Modern 1', 'style_modern.css']);
        $pdo->prepare('INSERT INTO systemstyles (id, name, file) VALUES (?, ?, ?)')
            ->execute([3, 'CoreBB Modern 2', 'style_modern_2.css']);

        $pdo->prepare('INSERT INTO boards (name, private, secure_archive, position, default_open) VALUES (?, 0, 0, 1, 1)')
            ->execute(['Community']);
        $categoryId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO forums (categoryid, name, description, edittimer, position, private, secure_archive) VALUES (?, ?, ?, ?, 1, 0, 0)')
            ->execute([$categoryId, 'General Discussion', 'Your first public discussion board.', '0']);
        $forumId = (int)$pdo->lastInsertId();

        $hash = corebb_auth_password_hash($adminPassword);
        $pdo->prepare(
            'INSERT INTO users (username, password, regdate, accesslevel, privemail, ThreadPages, BoardPages, posts, approved, status) '
            . 'VALUES (?, ?, ?, 5, ?, 25, 25, 0, 1, ?)'
        )->execute([
            $form['admin_username'],
            $hash,
            date('M y'),
            $form['admin_email'],
            '0',
        ]);
        $adminId = (int)$pdo->lastInsertId();

        $topicId = corebb_install_seed_bootstrap_topic($pdo, $forumId, $adminId, (string)$form['admin_username']);

        $pdo->prepare('INSERT INTO adminlogs (userid, userlevel, admin_username, action_type, description, date_performed) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$adminId, '5', $form['admin_username'], 'install', 'Installed CoreBB and created the first administrator.']);

        $pdo->commit();
        return ['admin_id' => $adminId, 'category_id' => $categoryId, 'forum_id' => $forumId, 'topic_id' => $topicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Usage: Build the private config file contents for a completed install.
 * Referenced by: corebb_install_write_config() and manual config fallback.
 *
 * @param array<string, string> $form Installer form values.
 * @param string $dbPassword Database password.
 * @return string PHP config file contents.
 */
function corebb_install_config_contents(array $form, string $dbPassword, array $server = []): string
{
    $lines = [
        '<?php',
        '/* CoreBB private configuration generated by the installer. */',
        'define(' . var_export('COREBB_INSTANCE', true) . ', ' . var_export(corebb_install_instance_key($server), true) . ');',
        '$MySQL_Host = ' . var_export($form['db_host'], true) . ';',
        '$MySQL_User = ' . var_export($form['db_user'], true) . ';',
        '$MySQL_Pass = ' . var_export($dbPassword, true) . ';',
        '$MySQL_Database = ' . var_export($form['db_name'], true) . ';',
        '$SiteName = ' . var_export($form['site_name'], true) . ';',
        '$SiteURL = ' . var_export(rtrim($form['site_url'], '/'), true) . ';',
        '$BoardName = ' . var_export($form['board_name'], true) . ';',
        '$BoardURL = ' . var_export(rtrim($form['board_url'], '/'), true) . ';',
        '$CookieDomain = ' . var_export('', true) . ';',
        '$SQLPrefix = ' . var_export('', true) . ';',
        '$BoardLockdown = ' . var_export('0', true) . ';',
        '$ShortPHP = ' . var_export('.php', true) . ';',
        '$GoogleClientID = ' . var_export('', true) . ';',
        '',
    ];
    return implode("\n", $lines);
}

/**
 * Usage: Persist the generated private config file after database setup succeeds.
 * Referenced by: corebb_install_run().
 *
 * @param array<string, string> $form Installer form values.
 * @param string $dbPassword Database password.
 * @return array{written: bool, path: string, contents: string, error: string} Config write result.
 */
function corebb_install_write_config(array $form, string $dbPassword, array $server = []): array
{
    $contents = corebb_install_config_contents($form, $dbPassword, $server);
    $targets = [
        corebb_install_preferred_config_path($server),
        corebb_install_root() . DIRECTORY_SEPARATOR . 'config.local.php',
    ];
    $lastError = '';

    foreach ($targets as $target) {
        if (is_file($target)) {
            return ['written' => false, 'path' => $target, 'contents' => $contents, 'error' => 'Config file already exists.'];
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $lastError = 'Unable to create config directory: ' . $dir;
            continue;
        }
        if (@file_put_contents($target, $contents, LOCK_EX) === false) {
            $lastError = 'Unable to write config file: ' . $target;
            continue;
        }
        @chmod($target, 0600);
        return ['written' => true, 'path' => $target, 'contents' => '', 'error' => ''];
    }

    return ['written' => false, 'path' => $targets[0], 'contents' => $contents, 'error' => $lastError ?: 'Unable to write config file.'];
}

/**
 * Usage: Validate installer input before touching the database.
 * Referenced by: corebb_install_model().
 *
 * @param array<string, string> $form Sanitized form values.
 * @param string $dbPassword Database password.
 * @param string $adminPassword Admin password.
 * @param string $adminPasswordConfirm Admin password confirmation.
 * @return array<int, string> Validation errors.
 */
function corebb_install_validate(array $form, string $dbPassword, string $adminPassword, string $adminPasswordConfirm): array
{
    $errors = [];
    foreach (['db_host' => 'Database host', 'db_name' => 'Database name', 'db_user' => 'Database user', 'site_name' => 'Site name', 'site_url' => 'Site URL', 'board_name' => 'Forum name', 'board_url' => 'Forum URL'] as $key => $label) {
        if (trim($form[$key] ?? '') === '') {
            $errors[] = $label . ' is required.';
        }
    }
    try {
        corebb_install_identifier((string)($form['db_name'] ?? ''));
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    foreach (['site_url' => 'Site URL', 'board_url' => 'Forum URL'] as $key => $label) {
        if (!filter_var($form[$key] ?? '', FILTER_VALIDATE_URL)) {
            $errors[] = $label . ' must be a valid URL.';
        }
    }
    if (!preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $form['admin_username'] ?? '')) {
        $errors[] = 'Admin username must be 3-20 characters and use letters, numbers, spaces, underscores, or hyphens.';
    }
    if (!filter_var($form['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email must be valid.';
    }
    if (strlen($adminPassword) < 10) {
        $errors[] = 'Admin password must be at least 10 characters.';
    }
    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'Admin password confirmation does not match.';
    }

    return $errors;
}

/**
 * Usage: Run the full installer workflow after validation passes.
 * Referenced by: corebb_install_model().
 *
 * @param array<string, string> $form Sanitized form values.
 * @param string $dbPassword Database password.
 * @param string $adminPassword First administrator password.
 * @return array<string, mixed> Install result.
 */
function corebb_install_run(array $form, string $dbPassword, string $adminPassword, array $server = []): array
{
    $pdo = corebb_install_connect_database($form, $dbPassword);
    $existingTables = corebb_install_existing_tables($pdo);
    if ($existingTables) {
        throw new RuntimeException('The selected database is not empty. Use an empty database for a fresh CoreBB install.');
    }

    $schemaStatements = corebb_install_apply_schema($pdo);
    $seed = corebb_install_seed_database($pdo, $form, $adminPassword);
    $config = corebb_install_write_config($form, $dbPassword, $server);

    return [
        'schema_statements' => $schemaStatements,
        'seed' => $seed,
        'config' => $config,
    ];
}

/**
 * Usage: Build and process the installer page model.
 * Referenced by: controllers/install.php.
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @param array<string, mixed> $server Server/request data.
 * @return array<string, mixed> Template model.
 */
function corebb_install_model(array $post, array $server): array
{
    $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
    $form = $method === 'POST' ? corebb_install_form_from_post($post, $server) : corebb_install_default_form($server);
    $model = [
        'locked' => false,
        'existing_configs' => corebb_install_existing_config_files($server),
        'instance_key' => corebb_install_instance_key($server),
        'install_url' => corebb_install_public_path($server, 'install/'),
        'forum_url' => corebb_install_public_path($server),
        'admin_url' => corebb_install_public_path($server, 'admin/'),
        'preferred_config_path' => corebb_install_preferred_config_path($server),
        'token' => corebb_install_token(),
        'form' => $form,
        'errors' => [],
        'messages' => [],
        'result' => null,
        'requirements' => [
            ['label' => 'PHP 8.1+', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>=')],
            ['label' => 'PDO MySQL extension', 'ok' => extension_loaded('pdo_mysql')],
            ['label' => 'Install schema present', 'ok' => is_file(corebb_install_root() . '/lib/install_schema.sql')],
            ['label' => 'Random bytes available', 'ok' => function_exists('random_bytes')],
        ],
    ];

    if ($model['existing_configs']) {
        $model['locked'] = true;
        $model['messages'][] = 'Installer locked because a CoreBB private/local config file already exists.';
        return $model;
    }

    if ($method !== 'POST') {
        return $model;
    }
    if (!corebb_install_token_ok($post)) {
        $model['errors'][] = 'Installer session expired. Reload the page and try again.';
        return $model;
    }

    $dbPassword = (string)($post['db_pass'] ?? '');
    $adminPassword = (string)($post['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($post['admin_password_confirm'] ?? '');
    $model['errors'] = corebb_install_validate($form, $dbPassword, $adminPassword, $adminPasswordConfirm);
    foreach ($model['requirements'] as $requirement) {
        if (empty($requirement['ok'])) {
            $model['errors'][] = 'Requirement failed: ' . $requirement['label'];
        }
    }
    if ($model['errors']) {
        return $model;
    }

    try {
        $model['result'] = corebb_install_run($form, $dbPassword, $adminPassword, $server);
        $model['messages'][] = 'CoreBB installation completed.';
        if (!empty($model['result']['config']['written'])) {
            $model['messages'][] = 'Private config written to: ' . (string)$model['result']['config']['path'];
        } else {
            $model['errors'][] = 'Database installed, but config could not be written: ' . (string)$model['result']['config']['error'];
        }
    } catch (Throwable $e) {
        $model['errors'][] = $e->getMessage();
    }

    return $model;
}
