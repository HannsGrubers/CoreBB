<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
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
 |  admin_moderation_view_model.php  - Admin moderation  |
 |  tools: ban user, unban user, and unban requests.     |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';
require_once __DIR__ . '/../helpers/security.php';

const COREBB_ADMIN_MOD_REQUESTS_PER_PAGE = 20;
const COREBB_ADMIN_MOD_USER_RESULTS_LIMIT = 50;

/**
 * Usage: Check whether a moderation table exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_mod_table_exists(string $table): bool
{
    return db_exists('SHOW TABLES LIKE ?', [$table]);
}

/**
 * Usage: Check whether a moderation column exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $column Database column name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_mod_column_exists(string $table, string $column): bool
{
    $tableSafe = str_replace('`', '``', $table);
    return db_exists("SHOW COLUMNS FROM `{$tableSafe}` LIKE ?", [$column]);
}

/**
 * Usage: Quote and validate an identifier for moderation SQL.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $identifier User, table, or column identifier.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Add a moderation support column when it is missing.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $column Database column name.
 * @param string $definition SQL column definition.
 * @return void No return value.
 */
function corebb_admin_mod_add_column(string $table, string $column, string $definition): void
{
    if (corebb_admin_mod_column_exists($table, $column)) {
        return;
    }
    $tableSafe = corebb_admin_mod_identifier($table);
    $columnSafe = corebb_admin_mod_identifier($column);
    db_run("ALTER TABLE {$tableSafe} ADD {$columnSafe} {$definition}");
}

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_admin_mod_ensure_schema(): void
{
    corebb_admin_mod_add_column('users', 'ban_reason', 'TEXT NULL');
    corebb_admin_mod_add_column('users', 'banned_at', "VARCHAR(64) NOT NULL DEFAULT ''");
    corebb_admin_mod_add_column('users', 'banned_by', 'INT NOT NULL DEFAULT 0');
    corebb_admin_mod_add_column('users', 'unbanned_at', "VARCHAR(64) NOT NULL DEFAULT ''");
    corebb_admin_mod_add_column('users', 'unbanned_by', 'INT NOT NULL DEFAULT 0');

    db_run("CREATE TABLE IF NOT EXISTS `unban_requests` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `userid` INT NOT NULL DEFAULT 0,
        `username` VARCHAR(255) NOT NULL DEFAULT '',
        `contact_email` VARCHAR(255) NOT NULL DEFAULT '',
        `ip_address` VARCHAR(255) NOT NULL DEFAULT '',
        `request_text` TEXT NULL,
        `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
        `admin_userid` INT NOT NULL DEFAULT 0,
        `admin_note` TEXT NULL,
        `created_at` VARCHAR(64) NOT NULL DEFAULT '',
        `updated_at` VARCHAR(64) NOT NULL DEFAULT '',
        `resolved_at` VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `userid` (`userid`),
        KEY `status` (`status`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Usage: Return the current timestamp for moderation records.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Usage: Read the current client IP for moderation records.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_current_ip(): string
{
    $ip = corebb_security_client_ip();
    return substr($ip, 0, 255);
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_limit_text(string $value, int $maxBytes): string
{
    if ($maxBytes <= 0) {
        return '';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
    return substr($value, 0, $maxBytes);
}

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_token(): string
{
    return corebb_security_named_token('admin_moderation_token');
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_mod_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('admin_moderation_token', $post, 'admin_moderation_token');
}

/**
 * Usage: Convert a user status value into moderation display text.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $status Request/report status.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_status_text($status): string
{
    return ((string)$status === '2') ? 'Banned' : 'Active';
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_format_vn_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return strtolower(date('n/j g:ia', $timestamp));
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_format_full_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00' || $value === '1/1/0001 12:00:00 AM') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('n/j/Y g:i:s A', $timestamp);
}

/**
 * Usage: Resolve an IP address for moderation review.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $ip IP address.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_reverse_host(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return 'Unknown';
    }

    $host = @gethostbyaddr($ip);
    $host = is_string($host) ? trim($host) : '';
    if ($host === '' || $host === $ip) {
        return 'Unknown';
    }
    return $host;
}

/**
 * Usage: Convert a stored boolean flag into moderation display text.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_bool_text($value): string
{
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true) ? 'True' : 'False';
}

/**
 * Usage: Build an admin URL or query string.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_public_profile_url(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    return corebb_public_join_base_path('/profile/' . $userId . '/');
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_user_summary(array $user): array
{
    $level = (int)($user['accesslevel'] ?? 0);
    return [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'accesslevel' => $level,
        'level_name' => corebb_user_level_label($level),
        'posts' => (int)($user['posts'] ?? 0),
        'status' => (string)($user['status'] ?? ''),
        'status_text' => corebb_admin_mod_status_text($user['status'] ?? ''),
        'profile_url' => corebb_admin_mod_public_profile_url((int)($user['id'] ?? 0)),
        'last_ip' => (string)($user['lastip'] ?? ''),
        'public_email' => (string)($user['pubemail'] ?? ''),
        'private_email' => (string)($user['privemail'] ?? ''),
        'registered' => (string)($user['regdate'] ?? ''),
        'last_login' => (string)($user['lastlogindate'] ?? ''),
        'ban_reason' => (string)($user['ban_reason'] ?? ''),
        'banned_at' => (string)($user['banned_at'] ?? ''),
        'banned_at_vn' => corebb_admin_mod_format_vn_date((string)($user['banned_at'] ?? '')),
        'banned_by' => (int)($user['banned_by'] ?? 0),
        'banned_by_username' => (string)($user['banned_by_username'] ?? ''),
        'banned_by_profile_url' => ((int)($user['banned_by'] ?? 0) > 0) ? corebb_admin_mod_public_profile_url((int)($user['banned_by'] ?? 0)) : '',
        'unbanned_at' => (string)($user['unbanned_at'] ?? ''),
        'unbanned_by' => (int)($user['unbanned_by'] ?? 0),
    ];
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_admin_mod_fetch_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $select = corebb_admin_user_select_list();
    $row = db_one('SELECT ' . $select . ' FROM users WHERE id = ? LIMIT 1', [$userId]);
    return $row ?: null;
}

/**
 * Usage: Search users for ban/unban moderation forms.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $query Search query text.
 * @param string $filter Search filter key.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_search_users(string $query, string $filter = 'all'): array
{
    $query = corebb_admin_mod_limit_text(trim($query), 255);
    $params = [];
    $where = [];

    if ($filter === 'banned') {
        $where[] = "CAST(u.status AS CHAR) = '2'";
    } elseif ($filter === 'active') {
        $where[] = "(u.status IS NULL OR CAST(u.status AS CHAR) <> '2')";
    }

    if ($query !== '') {
        if (ctype_digit($query)) {
            $where[] = '(u.id = ? OR u.username LIKE ? OR u.pubemail LIKE ? OR u.privemail LIKE ? OR u.lastip LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = (int)$query;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        } else {
            $where[] = '(u.username LIKE ? OR u.pubemail LIKE ? OR u.privemail LIKE ? OR u.lastip LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    } elseif ($filter !== 'banned') {
        return [];
    }

    $userSelect = corebb_admin_user_select_list('u');
    $sql = 'SELECT ' . $userSelect . ', bu.username AS banned_by_username FROM users u LEFT JOIN users bu ON bu.id = u.banned_by';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY CASE WHEN CAST(u.status AS CHAR) = '2' THEN 0 ELSE 1 END, u.username ASC LIMIT " . COREBB_ADMIN_MOD_USER_RESULTS_LIMIT;

    $users = [];
    foreach (db_all($sql, $params) as $row) {
        $users[] = corebb_admin_mod_user_summary($row);
    }
    return $users;
}

/**
 * Usage: Search legacy-style banned users by username, IP, or host.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $username Username value.
 * @param string $ipAddress IP address filter.
 * @param string $hostAddress Host address filter.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_search_banned_users_vn(string $username, string $ipAddress, string $hostAddress): array
{
    $username = corebb_admin_mod_limit_text(trim($username), 255);
    $ipAddress = corebb_admin_mod_limit_text(trim($ipAddress), 255);
    $hostAddress = corebb_admin_mod_limit_text(trim($hostAddress), 255);

    $params = [];
    $where = ["CAST(u.status AS CHAR) = '2'"];

    if ($username !== '') {
        if (ctype_digit($username)) {
            $where[] = '(u.id = ? OR u.username LIKE ? OR u.pubemail LIKE ? OR u.privemail LIKE ?)';
            $like = '%' . $username . '%';
            $params[] = (int)$username;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        } else {
            $where[] = '(u.username LIKE ? OR u.pubemail LIKE ? OR u.privemail LIKE ?)';
            $like = '%' . $username . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }

    if ($ipAddress !== '') {
        $where[] = 'u.lastip LIKE ?';
        $params[] = '%' . $ipAddress . '%';
    }

    /*
     * The VNBoards page had a Host Address field. CoreBB currently only
     * stores a user's last IP, so this field is kept for layout compatibility and
     * searched against lastip as the closest available data source.
     */
    if ($hostAddress !== '') {
        $where[] = 'u.lastip LIKE ?';
        $params[] = '%' . $hostAddress . '%';
    }

    $userSelect = corebb_admin_user_select_list('u');
    $sql = 'SELECT ' . $userSelect . ', bu.username AS banned_by_username
            FROM users u
            LEFT JOIN users bu ON bu.id = u.banned_by
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY CASE WHEN u.banned_at IS NULL OR u.banned_at = \'\' THEN 1 ELSE 0 END, u.banned_at DESC, u.username ASC
            LIMIT ' . COREBB_ADMIN_MOD_USER_RESULTS_LIMIT;

    $users = [];
    foreach (db_all($sql, $params) as $row) {
        $users[] = corebb_admin_mod_user_summary($row);
    }
    return $users;
}

/**
 * Usage: Check whether this admin action is allowed.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $target Target user row.
 * @param string $verb Action verb used in messages.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_can_touch_user(array $viewer, array $target, string $verb): array
{
    $viewerId = (int)($viewer['id'] ?? 0);
    $viewerLevel = (int)($viewer['accesslevel'] ?? 0);
    $targetId = (int)($target['id'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);

    if ($targetId <= 0) {
        return [false, 'Unknown user.'];
    }
    if ($verb === 'ban' && $viewerId === $targetId) {
        return [false, 'You cannot ban yourself.'];
    }
    if ($targetLevel >= $viewerLevel) {
        return [false, 'You cannot moderate a user with equal or higher rights.'];
    }
    return [true, ''];
}

/**
 * Usage: Write an audit entry for this admin workflow.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param string $action Human-readable action message.
 * @return void No return value.
 */
function corebb_admin_mod_log(array $viewer, string $action): void
{
    {
        corebb_adminlog_entry((string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), $action);
    }
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param int $userId User id.
 * @param string $reason Moderation/admin reason text.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_ban_user(array $viewer, int $userId, string $reason): array
{
    corebb_admin_mod_ensure_schema();
    $target = corebb_admin_mod_fetch_user($userId);
    if (!$target) {
        return [false, 'Unknown user.'];
    }
    [$allowed, $error] = corebb_admin_mod_can_touch_user($viewer, $target, 'ban');
    if (!$allowed) {
        return [false, $error];
    }
    if ((string)($target['status'] ?? '') === '2') {
        return [false, 'That user is already banned.'];
    }

    $reason = corebb_admin_mod_limit_text(trim($reason), 65535);
    $now = corebb_admin_mod_now();
    $oldLevel = (int)($target['accesslevel'] ?? 0);
    $ok = db_run(
        "UPDATE users SET status = '2', accesslevel = 1, ban_reason = ?, banned_at = ?, banned_by = ?, unbanned_at = '', unbanned_by = 0 WHERE id = ?",
        [$reason, $now, (int)($viewer['id'] ?? 0), $userId]
    );
    if (!$ok) {
        return [false, 'Error banning user: ' . db_error()];
    }

    $logSuffix = $oldLevel > 1 ? ' and demoted from ' . corebb_user_level_label($oldLevel) . ' to User' : '';
    corebb_admin_mod_log($viewer, 'Banned user ' . (string)($target['username'] ?? $userId) . " ({$userId})" . $logSuffix . ($reason !== '' ? ': ' . $reason : ''));
    return [true, $oldLevel > 1 ? 'User banned and demoted to User.' : 'User banned.'];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param int $userId User id.
 * @param string $note Admin note or resolution text.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_unban_user(array $viewer, int $userId, string $note = ''): array
{
    corebb_admin_mod_ensure_schema();
    $target = corebb_admin_mod_fetch_user($userId);
    if (!$target) {
        return [false, 'Unknown user.'];
    }
    [$allowed, $error] = corebb_admin_mod_can_touch_user($viewer, $target, 'unban');
    if (!$allowed) {
        return [false, $error];
    }
    if ((string)($target['status'] ?? '') !== '2') {
        return [false, 'That user is not currently banned.'];
    }

    $now = corebb_admin_mod_now();
    $ok = db_run(
        "UPDATE users SET status = '0', unbanned_at = ?, unbanned_by = ? WHERE id = ?",
        [$now, (int)($viewer['id'] ?? 0), $userId]
    );
    if (!$ok) {
        return [false, 'Error unbanning user: ' . db_error()];
    }

    $log = 'Unbanned user ' . (string)($target['username'] ?? $userId) . " ({$userId})";
    $note = corebb_admin_mod_limit_text(trim($note), 65535);
    if ($note !== '') {
        $log .= ': ' . $note;
    }
    corebb_admin_mod_log($viewer, $log);
    return [true, 'User unbanned.'];
}

/**
 * Usage: Fetch records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $alias SQL table alias.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_mod_request_select_list(string $alias = 'r'): string
{
    return corebb_admin_select_columns('unban_requests', [
        'id', 'userid', 'username', 'contact_email', 'ip_address', 'request_text',
        'status', 'admin_userid', 'admin_note', 'created_at', 'updated_at',
        'resolved_at', 'verified', 'is_verified'
    ], $alias);
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $requestId Moderation request id.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_admin_mod_fetch_request(int $requestId): ?array
{
    $requestSelect = corebb_admin_mod_request_select_list('r');
    $userSelect = corebb_admin_select_columns('users', ['accesslevel', 'posts', 'status', 'lastip', 'regdate', 'lastlogindate', 'ban_reason', 'banned_at', 'banned_by'], 'u');
    // Keep the old template keys for user status distinct from the request status.
    $userSelect = str_replace('`status` AS `status`', '`status` AS `user_status`', $userSelect);
    $row = db_one('SELECT ' . $requestSelect . ', ' . $userSelect . ', bu.username AS banned_by_username FROM unban_requests r LEFT JOIN users u ON u.id = r.userid LEFT JOIN users bu ON bu.id = u.banned_by WHERE r.id = ? LIMIT 1', [$requestId]);
    return $row ?: null;
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_request_summary(array $row): array
{
    $userId = (int)($row['userid'] ?? 0);
    $username = (string)($row['username'] ?? '');
    $ip = trim((string)($row['ip_address'] ?? ''));
    if ($ip === '') {
        $ip = trim((string)($row['lastip'] ?? ''));
    }
    $bannedBy = (int)($row['banned_by'] ?? 0);
    $verifiedRaw = $row['verified'] ?? $row['is_verified'] ?? 0;

    return [
        'id' => (int)($row['id'] ?? 0),
        'userid' => $userId,
        'username' => $username,
        'contact_email' => (string)($row['contact_email'] ?? ''),
        'ip_address' => $ip,
        'host_address' => corebb_admin_mod_reverse_host($ip),
        'request_text' => (string)($row['request_text'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'admin_userid' => (int)($row['admin_userid'] ?? 0),
        'admin_note' => (string)($row['admin_note'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_at_vn' => corebb_admin_mod_format_full_date((string)($row['created_at'] ?? '')),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'resolved_at' => (string)($row['resolved_at'] ?? ''),
        'resolved_at_vn' => corebb_admin_mod_format_full_date((string)($row['resolved_at'] ?? '')),
        'user_status' => (string)($row['user_status'] ?? ''),
        'user_status_text' => corebb_admin_mod_status_text($row['user_status'] ?? ''),
        'accesslevel' => (int)($row['accesslevel'] ?? 0),
        'level_name' => corebb_user_level_label((int)($row['accesslevel'] ?? 0)),
        'posts' => (int)($row['posts'] ?? 0),
        'profile_url' => '/admin/?act=user_pages&userid=' . $userId,
        'notes_url' => '/admin/?act=admin_notes&userid=' . $userId,
        'unban_url' => '/admin/?act=moderation&mode=unban&username=' . rawurlencode($username) . '&ip_address=' . rawurlencode($ip) . '&host_address=' . rawurlencode(corebb_admin_mod_reverse_host($ip)),
        'host_lookup_url' => '/admin/?act=host_lookup&ip_address=' . rawurlencode($ip),
        'last_ip' => (string)($row['lastip'] ?? ''),
        'registered' => (string)($row['regdate'] ?? ''),
        'last_login' => (string)($row['lastlogindate'] ?? ''),
        'ban_reason' => (string)($row['ban_reason'] ?? ''),
        'banned_at' => (string)($row['banned_at'] ?? ''),
        'banned_at_vn_full' => corebb_admin_mod_format_full_date((string)($row['banned_at'] ?? '')),
        'banned_by' => $bannedBy,
        'banned_by_username' => (string)($row['banned_by_username'] ?? ''),
        'banned_by_url' => $bannedBy > 0 ? '/admin/?act=user_pages&userid=' . $bannedBy : '',
        'verified' => corebb_admin_mod_bool_text($verifiedRaw),
    ];
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_request_counts(): array
{
    $counts = ['pending' => 0, 'approved' => 0, 'denied' => 0, 'resolved' => 0, 'all' => 0];
    foreach (db_all('SELECT status, COUNT(*) AS c FROM unban_requests GROUP BY status') as $row) {
        $status = (string)($row['status'] ?? '');
        $count = (int)($row['c'] ?? 0);
        if (isset($counts[$status])) {
            $counts[$status] = $count;
        }
        if ($status !== 'pending') {
            $counts['resolved'] += $count;
        }
        $counts['all'] += $count;
    }
    return $counts;
}

/**
 * Usage: Fetch a paged list of unban requests.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $status Request/report status.
 * @param int $page Current page number.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_list_requests(string $status, int $page): array
{
    $status = in_array($status, ['pending', 'approved', 'denied', 'resolved', 'all'], true) ? $status : 'pending';
    $page = max(1, $page);
    $params = [];
    $where = '';
    if ($status === 'resolved') {
        $where = " WHERE r.status <> 'pending'";
    } elseif ($status !== 'all') {
        $where = ' WHERE r.status = ?';
        $params[] = $status;
    }

    $total = (int)db_value('SELECT COUNT(*) FROM unban_requests r' . $where, $params, 0);
    $totalPages = max(1, (int)ceil($total / COREBB_ADMIN_MOD_REQUESTS_PER_PAGE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * COREBB_ADMIN_MOD_REQUESTS_PER_PAGE;

    $requestSelect = corebb_admin_mod_request_select_list('r');
    $userSelect = corebb_admin_select_columns('users', ['accesslevel', 'posts', 'status', 'lastip', 'regdate', 'lastlogindate', 'ban_reason', 'banned_at', 'banned_by'], 'u');
    $userSelect = str_replace('`status` AS `status`', '`status` AS `user_status`', $userSelect);
    $sql = 'SELECT ' . $requestSelect . ', ' . $userSelect . ', bu.username AS banned_by_username
            FROM unban_requests r
            LEFT JOIN users u ON u.id = r.userid
            LEFT JOIN users bu ON bu.id = u.banned_by' . $where . '
            ORDER BY CASE WHEN r.status = \'pending\' THEN 0 ELSE 1 END, r.id DESC
            LIMIT ' . COREBB_ADMIN_MOD_REQUESTS_PER_PAGE . ' OFFSET ' . $offset;
    $items = [];
    foreach (db_all($sql, $params) as $row) {
        $items[] = corebb_admin_mod_request_summary($row);
    }
    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'status' => $status,
    ];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param int $requestId Moderation request id.
 * @param string $decision Request decision value.
 * @param string $note Admin note or resolution text.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_resolve_request(array $viewer, int $requestId, string $decision, string $note): array
{
    corebb_admin_mod_ensure_schema();
    $request = corebb_admin_mod_fetch_request($requestId);
    if (!$request) {
        return [false, 'Unknown unban request.'];
    }
    if (!in_array($decision, ['approve', 'deny'], true)) {
        return [false, 'Unknown request action.'];
    }
    if ((string)($request['status'] ?? '') !== 'pending') {
        return [false, 'That request is already resolved.'];
    }

    $userId = (int)($request['userid'] ?? 0);
    $newStatus = $decision === 'approve' ? 'approved' : 'denied';
    $note = corebb_admin_mod_limit_text(trim($note), 65535);
    $now = corebb_admin_mod_now();

    if ($decision === 'approve') {
        [$ok, $message] = corebb_admin_mod_unban_user($viewer, $userId, $note !== '' ? $note : 'Approved unban request #' . $requestId);
        if (!$ok) {
            return [false, $message];
        }
    }

    $ok = db_run(
        'UPDATE unban_requests SET status = ?, admin_userid = ?, admin_note = ?, updated_at = ?, resolved_at = ? WHERE id = ?',
        [$newStatus, (int)($viewer['id'] ?? 0), $note, $now, $now, $requestId]
    );
    if (!$ok) {
        return [false, 'Error updating unban request: ' . db_error()];
    }

    if ($decision === 'deny') {
        corebb_admin_mod_log($viewer, 'Denied unban request #' . $requestId . ' for user ' . (string)($request['username'] ?? $userId));
    } else {
        corebb_admin_mod_log($viewer, 'Approved unban request #' . $requestId . ' for user ' . (string)($request['username'] ?? $userId));
    }

    return [true, $decision === 'approve' ? 'Request approved and user unbanned.' : 'Request denied.'];
}

/**
 * Usage: Build and process the moderation admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_moderation_model(array $viewer, array $request, array $post): array
{
    corebb_admin_mod_ensure_schema();

    $model = corebb_admin_require_model_base($viewer, 'Moderation Tools', $request);
    $mode = (string)($request['mode'] ?? 'ban');
    if (!in_array($mode, ['ban', 'unban', 'requests'], true)) {
        $mode = 'ban';
    }
    $model['mode'] = $mode;
    $model['token'] = corebb_admin_mod_token();
    $model['query'] = trim((string)($request['q'] ?? ''));
    $model['unban_search'] = [
        'username' => '',
        'ip_address' => '',
        'host_address' => '',
    ];
    $respondedParam = strtolower((string)($request['responded'] ?? ''));
    $model['request_status'] = (string)($request['status'] ?? ($respondedParam === 'true' ? 'resolved' : 'pending'));
    $model['respond_request_id'] = (int)($request['respond'] ?? $request['request_id'] ?? 0);
    $model['respond_request'] = null;
    $model['confirm_user'] = null;
    $model['results'] = [];
    $model['requests'] = ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'status' => 'pending'];
    $model['request_counts'] = corebb_admin_mod_request_counts();

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = (string)($post['action'] ?? $request['action'] ?? '');

    if ($isPost && in_array($action, ['ban_confirm', 'ban_save', 'unban_confirm', 'unban_save', 'request_approve', 'request_deny'], true) && !corebb_admin_mod_token_ok($post)) {
        $model['errors'][] = 'Security token expired. Please reload the moderation page and try again.';
        $action = '';
    }

    if ($isPost && $action === 'ban_confirm') {
        $userId = (int)($post['userid'] ?? 0);
        $target = corebb_admin_mod_fetch_user($userId);
        if (!$target) {
            $model['errors'][] = 'Unknown user.';
        } else {
            [$allowed, $error] = corebb_admin_mod_can_touch_user($viewer, $target, 'ban');
            if (!$allowed) {
                $model['errors'][] = $error;
            } elseif ((string)($target['status'] ?? '') === '2') {
                $model['errors'][] = 'That user is already banned.';
            } else {
                $model['mode'] = 'ban';
                $model['confirm_user'] = corebb_admin_mod_user_summary($target);
                $model['confirm_action'] = 'ban';
            }
        }
    } elseif ($isPost && $action === 'ban_save') {
        $userId = (int)($post['userid'] ?? 0);
        $reason = (string)($post['reason'] ?? '');
        [$ok, $message] = corebb_admin_mod_ban_user($viewer, $userId, $reason);
        if ($ok) {
            $model['messages'][] = $message;
        } else {
            $model['errors'][] = $message;
        }
        $model['mode'] = 'ban';
    } elseif ($isPost && $action === 'unban_confirm') {
        $userId = (int)($post['userid'] ?? 0);
        $target = corebb_admin_mod_fetch_user($userId);
        if (!$target) {
            $model['errors'][] = 'Unknown user.';
        } else {
            [$allowed, $error] = corebb_admin_mod_can_touch_user($viewer, $target, 'unban');
            if (!$allowed) {
                $model['errors'][] = $error;
            } elseif ((string)($target['status'] ?? '') !== '2') {
                $model['errors'][] = 'That user is not currently banned.';
            } else {
                $model['mode'] = 'unban';
                $model['confirm_user'] = corebb_admin_mod_user_summary($target);
                $model['confirm_action'] = 'unban';
            }
        }
    } elseif ($isPost && $action === 'unban_save') {
        $userId = (int)($post['userid'] ?? 0);
        $note = (string)($post['note'] ?? '');
        [$ok, $message] = corebb_admin_mod_unban_user($viewer, $userId, $note);
        if ($ok) {
            $model['messages'][] = $message;
        } else {
            $model['errors'][] = $message;
        }
        $model['mode'] = 'unban';
    } elseif ($isPost && ($action === 'request_approve' || $action === 'request_deny')) {
        $requestId = (int)($post['request_id'] ?? 0);
        $note = (string)($post['admin_note'] ?? '');
        [$ok, $message] = corebb_admin_mod_resolve_request($viewer, $requestId, $action === 'request_approve' ? 'approve' : 'deny', $note);
        if ($ok) {
            $model['messages'][] = $message;
        } else {
            $model['errors'][] = $message;
        }
        $model['mode'] = 'requests';
        $mode = 'requests';
    }

    $mode = (string)($model['mode'] ?? $mode);

    if ($mode === 'ban') {
        $query = corebb_admin_mod_limit_text(trim((string)($request['q'] ?? $post['q'] ?? '')), 255);
        $model['query'] = $query;
        if ($query !== '') {
            $model['results'] = corebb_admin_mod_search_users($query, 'active');
            if (!$model['results']) {
                $model['messages'][] = 'No active users matched that search.';
            }
        }
    } elseif ($mode === 'unban') {
        $username = corebb_admin_mod_limit_text(trim((string)($request['username'] ?? $request['search_username'] ?? $post['username'] ?? $post['search_username'] ?? $request['q'] ?? $post['q'] ?? '')), 255);
        $ipAddress = corebb_admin_mod_limit_text(trim((string)($request['ip_address'] ?? $request['search_ip'] ?? $post['ip_address'] ?? $post['search_ip'] ?? '')), 255);
        $hostAddress = corebb_admin_mod_limit_text(trim((string)($request['host_address'] ?? $request['search_host'] ?? $post['host_address'] ?? $post['search_host'] ?? '')), 255);

        $model['query'] = trim(implode(' ', array_filter([$username, $ipAddress, $hostAddress], static fn($v) => $v !== '')));
        $model['unban_search'] = [
            'username' => $username,
            'ip_address' => $ipAddress,
            'host_address' => $hostAddress,
        ];
        $model['results'] = corebb_admin_mod_search_banned_users_vn($username, $ipAddress, $hostAddress);
        if (($username !== '' || $ipAddress !== '' || $hostAddress !== '') && !$model['results']) {
            $model['messages'][] = 'No banned users matched that search.';
        }
    } elseif ($mode === 'requests') {
        $respondedParam = strtolower((string)($request['responded'] ?? ''));
        $status = (string)($request['status'] ?? ($respondedParam === 'true' ? 'resolved' : ($model['request_status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'approved', 'denied', 'resolved', 'all'], true)) {
            $status = 'pending';
        }
        $page = max(1, (int)($request['p'] ?? $request['page'] ?? 1));
        $model['request_status'] = $status;
        $model['request_counts'] = corebb_admin_mod_request_counts();
        $model['requests'] = corebb_admin_mod_list_requests($status, $page);
        $isOld = in_array($status, ['approved', 'denied', 'resolved', 'all'], true);
        $base = '/admin/?act=moderation&mode=requests&' . ($isOld ? 'responded=True&' : '') . ($status !== 'pending' && !$isOld ? 'status=' . rawurlencode($status) . '&' : '') . 'page={page}';
        $model['requests_view'] = [
            'is_old' => $isOld,
            'title' => $isOld ? 'Old Unban Requests' : 'New Unban Requests',
            'toggle_href' => $isOld ? '/admin/?act=moderation&mode=requests' : '/admin/?act=moderation&mode=requests&responded=True',
            'toggle_text' => $isOld ? 'Click here for New Unban Requests' : 'Click here for Old Unban Requests',
        ];
        $model['requests']['pagination'] = corebb_pagination_model(
            (int)($model['requests']['total_pages'] ?? 1) > 1 ? $base : '',
            (int)($model['requests']['page'] ?? 1),
            (int)($model['requests']['total_pages'] ?? 1),
            'MainMenuLink'
        );

        $respondRequestId = (int)($request['respond'] ?? $request['request_id'] ?? $model['respond_request_id'] ?? 0);
        if ($respondRequestId > 0) {
            $respondRequest = corebb_admin_mod_fetch_request($respondRequestId);
            if ($respondRequest) {
                $model['respond_request'] = corebb_admin_mod_request_summary($respondRequest);
                $model['respond_request_id'] = $respondRequestId;
            }
        }
    }

    return $model;
}
