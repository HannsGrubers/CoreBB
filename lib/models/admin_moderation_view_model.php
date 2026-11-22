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
        $like = '%' . $query . '%';
        $matches = ['u.username LIKE ?', 'u.pubemail LIKE ?', 'u.privemail LIKE ?', 'u.lastip LIKE ?'];
        $params = [$like, $like, $like, $like];
        if (ctype_digit($query)) {
            $matches[] = 'u.id = ?';
            $params[] = (int)$query;
        } elseif (filter_var(corebb_admin_display_ip($query), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $matches[] = 'u.lastip = ?';
            $params[] = str_contains($query, '-') ? corebb_admin_display_ip($query) : str_replace('.', '-', $query);
        }
        $where[] = '(' . implode(' OR ', $matches) . ')';
    } elseif ($filter !== 'banned') {
        return [];
    }

    $userSelect = corebb_admin_select_columns('users', ['id','username','status','accesslevel','posts','lastip'], 'u');
    $sql = 'SELECT ' . $userSelect . ' FROM users u';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ';
    if ($query !== '') {
        if (ctype_digit($query)) {
            $sql .= 'CASE WHEN u.id=? THEN 0 ELSE 1 END, ';
            $params[] = (int)$query;
        }
        $sql .= 'CASE WHEN u.username=? THEN 0 ELSE 1 END, ';
        $params[] = $query;
    }
    $sql .= 'u.username, u.id LIMIT ' . COREBB_ADMIN_MOD_USER_RESULTS_LIMIT;

    $users = [];
    foreach (db_all($sql, $params) as $row) {
        $users[] = corebb_admin_mod_user_summary($row);
    }
    return $users;
}

/** Load one page of banned accounts, applying the same filters to the count and list. */
function corebb_admin_mod_banned_accounts(string $member, string $address, int $page, bool $includeIp): array
{
    $params = [];
    $where = ["CAST(u.status AS CHAR)='2'"];
    if ($member !== '') {
        $match = ['u.username LIKE ?', 'u.pubemail LIKE ?', 'u.privemail LIKE ?'];
        $params = array_fill(0,3,'%' . $member . '%');
        if (ctype_digit($member)) {
            $match[] = 'u.id=?';
            $params[] = (int)$member;
        }
        $where[] = '(' . implode(' OR ', $match) . ')';
    }
    if ($address !== '') {
        $ip = corebb_admin_display_ip($address);
        if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
            $where[] = 'u.lastip IN (?,?)';
            array_push($params,$ip,str_replace('.','-',$ip));
        } else {
            $where[] = 'u.lastip LIKE ?';
            $params[] = '%' . $address . '%';
        }
    }
    $whereSql = implode(' AND ',$where);
    $total = db_value("SELECT COUNT(*) FROM users u WHERE $whereSql",$params);
    if ($total === null) { return ['errors'=>['Could not load banned accounts.']]; }
    $perPage = COREBB_ADMIN_MOD_USER_RESULTS_LIMIT;
    $pages = max(1,(int)ceil($total / $perPage));
    $page = max(1,min($pages,$page));
    $offset = ($page-1)*$perPage;
    $fields = 'u.id,u.username,u.accesslevel,u.banned_at,u.banned_by,bu.username AS staff_name';
    if ($includeIp) { $fields .= ',u.lastip'; }
    $rows = db_all("SELECT $fields FROM users u LEFT JOIN users bu ON bu.id=u.banned_by
        WHERE $whereSql ORDER BY CASE WHEN u.banned_at IS NULL OR u.banned_at='' THEN 1 ELSE 0 END,
        u.banned_at DESC,u.username,u.id LIMIT $perPage OFFSET $offset",$params);
    if (db_error() !== '') { return ['errors'=>['Could not load banned accounts.']]; }
    return ['results'=>$rows,'total'=>(int)$total,'pages'=>$pages,'page'=>$page,'first'=>$offset+1,'last'=>$offset+count($rows)];
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
function corebb_admin_mod_ban_user(array $viewer, int $userId, string $reason, ?int $reviewedLevel = null): array
{
    corebb_admin_mod_ensure_schema();
    corebb_admin_tool_permissions_ensure_schema();
    $actorId = corebb_admin_viewer_id($viewer);
    $reason = trim($reason);
    if (strlen($reason) > 65535) { return [false, 'Shorten the ban reason before saving.']; }
    if (!db_begin()) { return [false, 'Could not start the ban.']; }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $accounts = array_column(db_all('SELECT id,username,accesslevel,status FROM users WHERE id IN (?,?) ORDER BY id' . $lock, [$actorId,$userId]), null, 'id');
        $actor = $accounts[$actorId] ?? null;
        $target = $accounts[$userId] ?? null;
        corebb_admin_user_granted_tool_keys($actorId, true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor,'moderation_ban')) {
            return [false, 'You do not have access to Ban User.'];
        }
        if (!$target) { return [false, 'Member not found.']; }
        [$allowed,$error] = corebb_admin_mod_can_touch_user($actor,$target,'ban');
        if (!$allowed) { return [false,$error]; }
        if ((string)$target['status'] === '2') { return [false, 'This member is already banned.']; }
        $oldLevel = (int)$target['accesslevel'];
        if ($reviewedLevel !== null && $oldLevel !== $reviewedLevel) {
            return [false, 'The member’s role changed. Review the ban again.'];
        }
        $ok = db_run("UPDATE users SET status='2', accesslevel=1, ban_reason=?, banned_at=?, banned_by=?, unbanned_at='', unbanned_by=0 WHERE id=?",
            [$reason, corebb_admin_mod_now(), $actorId, $userId]);
        if (!$ok || !db_commit()) { return [false, 'Could not save the ban. Please try again.']; }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    $demotion = $oldLevel > 1 ? ' and demoted from ' . corebb_user_level_label($oldLevel) . ' to User' : '';
    corebb_adminlog_viewer($actor,'Banned user ' . $target['username'] . " ({$userId})" . $demotion . ($reason !== '' ? ': ' . $reason : ''), 'ban_user');
    return [true, $target['username'] . ' was banned' . $demotion . '.'];
}

function corebb_admin_mod_lock_accounts(int $actorId,int $userId,string $lock): array
{
    return array_column(db_all('SELECT id,username,status,accesslevel,banned_at,banned_by,ban_reason
        FROM users WHERE id IN (?,?) ORDER BY id' . $lock,[$actorId,$userId]),null,'id');
}

/** Called inside the transaction owning the locked staff and member records. */
function corebb_admin_mod_lift_locked_ban(array $actor,?array $target,?string $revision): array
{
    if (!$target || (string)$target['status'] !== '2') { return [false,'This account has no active ban.']; }
    [$allowed,$error] = corebb_admin_mod_can_touch_user($actor,$target,'unban');
    if (!$allowed) { return [false,$error]; }
    if ($revision !== null && $revision !== corebb_admin_mod_ban_revision($target)) { return [false,'This ban changed. Review the current ban before continuing.']; }
    $ok = db_run("UPDATE users SET status='0',unbanned_at=?,unbanned_by=? WHERE id=? AND status='2'",[corebb_admin_mod_now(),$actor['id'],$target['id']]);
    return $ok && corebb_db_last_affected_rows()===1 ? [true,''] : [false,'Could not lift the ban. Please try again.'];
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
function corebb_admin_mod_unban_user(array $viewer, int $userId, string $note = '', ?string $revision = null, string $tool = 'moderation_unban'): array
{
    corebb_admin_mod_ensure_schema();
    corebb_admin_tool_permissions_ensure_schema();
    $actorId = corebb_admin_viewer_id($viewer);
    $note = trim($note);
    if (strlen($note)>65535) { return [false,'Shorten the note before saving.']; }
    if (!db_begin()) { return [false,'Could not start the unban.']; }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $accounts = corebb_admin_mod_lock_accounts($actorId,$userId,$lock);
        $actor = $accounts[$actorId] ?? null;
        $target = $accounts[$userId] ?? null;
        corebb_admin_user_granted_tool_keys($actorId,true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor,$tool)) { return [false,'You no longer have access to this action.']; }
        [$ok,$message] = corebb_admin_mod_lift_locked_ban($actor,$target,$revision);
        if (!$ok) { return [false,$message]; }
        if (!db_commit()) { return [false,'Could not lift the ban. Please try again.']; }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    corebb_adminlog_viewer($actor,'Unbanned user ' . $target['username'] . " ({$userId})" . ($note !== '' ? ': ' . $note : ''),'unban_user');
    return [true,'Ban lifted for ' . $target['username'] . '.'];
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
    $row = db_one('SELECT ' . $requestSelect . ', ' . $userSelect . ', bu.username AS banned_by_username, staff.username AS resolved_by_name, u.id AS account_id FROM unban_requests r LEFT JOIN users u ON u.id = r.userid LEFT JOIN users bu ON bu.id = u.banned_by LEFT JOIN users staff ON staff.id=r.admin_userid WHERE r.id = ? LIMIT 1', [$requestId]);
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

    $total = db_value('SELECT COUNT(*) FROM unban_requests r' . $where, $params);
    if ($total === null) { return ['error'=>'Could not load unban requests.']; }
    $total = (int)$total;
    $totalPages = max(1, (int)ceil($total / COREBB_ADMIN_MOD_REQUESTS_PER_PAGE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * COREBB_ADMIN_MOD_REQUESTS_PER_PAGE;

    $items = db_all("SELECT r.id,r.userid,r.username,r.status,r.created_at,r.resolved_at FROM unban_requests r$where
        ORDER BY CASE WHEN r.status='pending' THEN 0 ELSE 1 END, r.id DESC LIMIT " . COREBB_ADMIN_MOD_REQUESTS_PER_PAGE . ' OFFSET ' . $offset,$params);
    if (db_error() !== '') { return ['error'=>'Could not load unban requests.']; }
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
function corebb_admin_mod_resolve_request(array $viewer, int $requestId, string $decision, string $note, ?string $revision=null, ?string $banRevision=null): array
{
    if (!in_array($decision,['approve','deny'],true)) { return [false,'Choose a decision.']; }
    $note=trim($note);
    if (strlen($note)>65535) { return [false,'Shorten the response before saving.']; }
    corebb_admin_mod_ensure_schema();
    corebb_admin_tool_permissions_ensure_schema();
    if (!db_begin()) { return [false,'Could not start the decision.']; }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $request = db_one('SELECT * FROM unban_requests WHERE id=?' . $lock,[$requestId]);
        if (!$request || $request['status'] !== 'pending') { return [false,'This request is no longer pending.']; }
        $actorId=corebb_admin_viewer_id($viewer);
        $userId=(int)$request['userid'];
        $accounts=corebb_admin_mod_lock_accounts($actorId,$userId,$lock);
        $actor=$accounts[$actorId] ?? null;
        corebb_admin_user_granted_tool_keys($actorId,true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor,'moderation_requests')) {
            return [false,'You no longer have permission to decide unban requests.'];
        }
        if ($revision !== null && $revision !== corebb_admin_appeal_revision($request)) { return [false,'The appeal changed. Review the latest request before responding.']; }
        if ($decision==='approve') {
            [$ok,$error]=corebb_admin_mod_lift_locked_ban($actor,$accounts[$userId] ?? null,$banRevision);
            if (!$ok) { return [false,$error]; }
        }
        $status=$decision==='approve' ? 'approved' : 'denied';
        $now=corebb_admin_mod_now();
        if (!db_run("UPDATE unban_requests SET status=?,admin_userid=?,admin_note=?,updated_at=?,resolved_at=? WHERE id=? AND status='pending'",
            [$status,$actorId,$note,$now,$now,$requestId]) || corebb_db_last_affected_rows()!==1 || !db_commit()) {
            return [false,'Could not save the decision. Please try again.'];
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    if ($decision==='approve') {
        corebb_adminlog_viewer($actor,'Unbanned user ' . $accounts[$userId]['username'] . ' (#' . $userId . ') via request #' . $requestId,'unban_user');
    }
    corebb_adminlog_viewer($actor,ucfirst($status) . ' unban request #' . $requestId . ' for user #' . $userId,'unban_request_response');
    return [true, $decision==='approve' ? 'Request approved and account ban lifted.' : 'Request denied.'];
}

function corebb_admin_appeal_revision(array $row): string
{
    return hash('sha256',serialize(array_map(static fn($key)=>(string)($row[$key] ?? ''),
        ['id','userid','request_text','contact_email','updated_at','verified','is_verified'])));
}

function corebb_admin_appeals_workspace(array $model,array $viewer,array $query,array $post,string $action,bool $isPost): array
{
    $filters=['pending'=>'Pending','approved'=>'Approved','denied'=>'Denied','resolved'=>'Resolved','all'=>'All'];
    $status=$model['request_status'];
    if (!isset($filters[$status])) { $status='pending'; }
    $page=max(1,(int)($query['p'] ?? $query['page'] ?? 1));
    $base='/admin/?act=moderation&mode=requests';
    $filtered=$base . '&status=' . $status;
    $model['queue_url']=$filtered . '&page=' . $page;
    $model['request_status']=$status;
    $model['statuses']=$filters;
    $model['can_ban']=corebb_admin_can_access_tool($viewer,'moderation_ban');
    $id=(int)($query['respond'] ?? $query['request_id'] ?? 0);
    $reviewForm=isset($query['respond']) || isset($query['request_id']);
    if ($isPost && in_array($action,['request_approve','request_deny'],true)) {
        $posted=filter_var($post['request_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$posted || !is_string($post['admin_note'] ?? '')
            || ($reviewForm && ($id!==$posted || !is_string($post['revision'] ?? null) || !is_string($post['ban_revision'] ?? null)))) {
            $model['errors'][]='Reopen the request before submitting a decision.';
            return $model;
        }
        $id=$posted;
        $model['draft_response']=$post['admin_note'] ?? '';
        [$ok,$message]=corebb_admin_mod_resolve_request($viewer,$id,$action==='request_approve'?'approve':'deny',$model['draft_response'],
            $reviewForm ? $post['revision'] : null, $reviewForm ? $post['ban_revision'] : null);
        $model[$ok?'messages':'errors'][]=$message;
        if ($ok) { $model['decision_saved']=true; return $model; }
    }
    if ($id>0) {
        $row=corebb_admin_mod_fetch_request($id);
        if (!$row) { $model['errors'][]='Request not found.'; return $model; }
        $memberId=(int)$row['userid'];
        $ban=['id'=>$memberId,'status'=>$row['user_status'] ?? null] + $row;
        $canIp=corebb_admin_can_access_tool($viewer,'user_ip_check');
        $canHost=corebb_admin_can_access_tool($viewer,'host_lookup');
        $ip=($canIp || $canHost) ? corebb_admin_display_ip((string)(($row['ip_address'] ?? '') ?: ($row['lastip'] ?? ''))) : '';
        $model['appeal']=[
            'id'=>$id,'userid'=>$memberId,'username'=>$row['username'] ?: 'User #' . $memberId,
            'status'=>$row['status'],'text'=>$row['request_text'],'created'=>$row['created_at'],
            'verified'=>isset($row['verified']) || isset($row['is_verified']) ? corebb_admin_mod_bool_text($row['verified'] ?? $row['is_verified']) : 'Not recorded',
            'banned_by'=>$row['banned_by_username'] ?: (!empty($ban['banned_by']) ? 'User #' . $ban['banned_by'] : ''),
            'ban_date'=>$ban['banned_at'] ?? '', 'ban_reason'=>$ban['ban_reason'] ?? '',
            'ip'=>$ip,'can_host'=>$canHost,'can_notes'=>corebb_admin_can_access_tool($viewer,'admin_notes'),
            'member_url'=>corebb_admin_can_access_tool($viewer,'user_pages') ? '/admin/?act=user_pages&userid=' . $memberId : '/profile/' . $memberId . '/',
            'response'=>$row['admin_note'],'resolved'=>$row['resolved_at'],
            'reviewer'=>$row['resolved_by_name'] ?: ($row['admin_userid'] ? 'User #' . $row['admin_userid'] : ''),
            'can_approve'=>$row['account_id'] && (string)$ban['status']==='2' && corebb_admin_mod_can_touch_user($viewer,$ban,'unban')[0],
            'revision'=>corebb_admin_appeal_revision($row),'ban_revision'=>corebb_admin_mod_ban_revision($ban),
        ];
        return $model;
    }
    $model['request_counts']=corebb_admin_mod_request_counts();
    $list=corebb_admin_mod_list_requests($status,$page);
    if (isset($list['error'])) { $model['errors'][]=$list['error']; return $model; }
    $model['queue_url']=$filtered . '&page=' . $list['page'];
    $list['pagination']=corebb_pagination_model($list['total_pages']>1 ? $filtered . '&page={page}' : '',$list['page'],$list['total_pages']);
    $model['requests']=$list;
    return $model;
}

/** Identify the ban and role that staff saw on the review screen. */
function corebb_admin_mod_ban_revision(array $target): string
{
    return hash('sha256', serialize(array_map(static fn($key) => (string)($target[$key] ?? ''),
        ['id','status','accesslevel','banned_at','banned_by','ban_reason'])));
}

/** Build the account review and retain the current search when navigating back to the list. */
function corebb_admin_unban_workspace(array $model, array $viewer, array $request, array $post, string $action, bool $isPost): array
{
    $input = $request + $post;
    $text = static fn($v): string => is_scalar($v) ? corebb_admin_mod_limit_text(trim((string)$v),255) : '';
    $member = $text($input['username'] ?? $input['search_username'] ?? $input['q'] ?? '');
    $address = $text($input['ip_address'] ?? $input['search_ip'] ?? $input['ip'] ?? '');
    if ($address === '') { $address = $text($input['host_address'] ?? $input['search_host'] ?? ''); }
    $address = corebb_admin_display_ip($address);
    $page = max(1,(int)($input['page'] ?? 1));
    $params = array_filter(['username'=>$member,'ip_address'=>$address], 'strlen');
    $base = '/admin/?act=moderation&mode=unban' . ($params ? '&' . http_build_query($params) : '');
    $model['back_url'] = $base . ($page>1 ? '&page=' . $page : '');
    $model['member_filter'] = $member;
    $model['address_filter'] = $address;
    $model['can_ban'] = corebb_admin_can_access_tool($viewer,'moderation_ban');
    $model['can_ip'] = corebb_admin_can_access_tool($viewer,'user_ip_check');
    if ($isPost && $action === 'unban_save') {
        $id = filter_var($post['userid'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !is_string($post['note'] ?? '') || (isset($request['review']) && ((string)$request['review'] !== (string)$id || !is_string($post['revision'] ?? null)))) {
            $model['errors'][] = 'Reopen the ban before confirming.';
            return $model;
        }
        [$ok,$message] = corebb_admin_mod_unban_user($viewer,$id,$post['note'] ?? '',is_string($post['revision'] ?? null) ? $post['revision'] : null);
        $model[$ok ? 'messages' : 'errors'][] = $message;
        if ($ok) { $model['unban_saved'] = true; return $model; }
    }
    if (isset($request['review']) || ($isPost && $action === 'unban_confirm')) {
        $id = (int)($request['review'] ?? $post['userid'] ?? 0);
        $target = db_one('SELECT u.id,u.username,u.accesslevel,u.status,u.ban_reason,u.banned_at,u.banned_by,bu.username AS staff_name
            FROM users u LEFT JOIN users bu ON bu.id=u.banned_by WHERE u.id=?',[$id]);
        if (!$target || (string)$target['status'] !== '2') { $model['errors'][] = 'This account has no active ban.'; return $model; }
        [, $target['blocked']] = corebb_admin_mod_can_touch_user($viewer,$target,'unban');
        $target['profile_url'] = corebb_admin_mod_public_profile_url($id);
        $target['level_name'] = corebb_user_level_label((int)$target['accesslevel']);
        $model['revision'] = corebb_admin_mod_ban_revision($target);
        $model['confirm_user'] = $target;
        $model['confirm_action'] = 'unban';
        return $model;
    }
    $list = corebb_admin_mod_banned_accounts($member,$address,$page,$model['can_ip']);
    if (isset($list['errors'])) { $model['errors'] = array_merge($model['errors'],$list['errors']); return $model; }
    $model = array_replace($model,$list);
    $model['back_url'] = $base . ($model['page']>1 ? '&page=' . $model['page'] : '');
    foreach ($model['results'] as &$row) {
        $row['profile_url'] = corebb_admin_mod_public_profile_url((int)$row['id']);
        if ($model['can_ip']) { $row['last_ip'] = corebb_admin_display_ip((string)$row['lastip']); unset($row['lastip']); }
    }
    unset($row);
    $model['pagination'] = corebb_pagination_model($model['pages']>1 ? $base . '&page={page}' : '',$model['page'],$model['pages']);
    return $model;
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
    $mode = (string)($request['mode'] ?? 'ban');
    if (!in_array($mode, ['ban', 'unban', 'requests'], true)) {
        $mode = 'ban';
    }
    if (corebb_admin_viewer_id($viewer) <= 0 || !corebb_admin_can_access_tool($viewer,'moderation_' . $mode)) {
        return ['mode'=>$mode, 'errors'=>['You do not have access to this moderation tool.']];
    }
    corebb_admin_mod_ensure_schema();
    $model = ['messages'=>[], 'errors'=>[]];
    $model['can_unban'] = corebb_admin_can_access_tool($viewer,'moderation_unban');
    $model['can_requests'] = corebb_admin_can_access_tool($viewer,'moderation_requests');
    if ($mode === 'ban') {
        $model['can_ip'] = corebb_admin_can_access_tool($viewer,'user_ip_check');
        $model['result_limit'] = COREBB_ADMIN_MOD_USER_RESULTS_LIMIT;
    }
    $model['mode'] = $mode;
    $model['token'] = corebb_admin_mod_token();
    $model['query'] = trim((string)($request['q'] ?? ''));
    $respondedParam = strtolower((string)($request['responded'] ?? ''));
    $model['request_status'] = (string)($request['status'] ?? ($respondedParam === 'true' ? 'resolved' : 'pending'));
    $model['confirm_user'] = null;
    $model['results'] = [];

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = (string)($post['action'] ?? $request['action'] ?? '');

    $actions = ['ban'=>['ban_confirm','ban_save'], 'unban'=>['unban_confirm','unban_save'], 'requests'=>['request_approve','request_deny']];
    if ($isPost && ($mode === 'requests' || $action !== '') && !in_array($action,$actions[$mode],true)) {
        $model['errors'][] = 'This action is not available in the selected tool.';
        return $model;
    }
    if ($isPost && $action !== '' && (!corebb_security_csrf_valid($post) || !corebb_admin_mod_token_ok($post))) {
        $model['errors'][] = 'Security token expired. Please reload the moderation page and try again.';
        return $model;
    }
    if ($mode === 'requests') {
        return corebb_admin_appeals_workspace($model,$viewer,$request,$post,$action,$isPost);
    }
    if ($mode === 'unban') {
        return corebb_admin_unban_workspace($model,$viewer,$request,$post,$action,$isPost);
    }

    if (($isPost && $action === 'ban_confirm') || (!$isPost && $mode === 'ban' && isset($request['review']))) {
        $userId = (int)($isPost ? ($post['userid'] ?? 0) : $request['review']);
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
                $model['confirm_user'] = corebb_admin_user_summary($target);
                $model['confirm_action'] = 'ban';
            }
        }
    } elseif ($isPost && $action === 'ban_save') {
        $userId = (int)($post['userid'] ?? 0);
        if (!is_string($post['reason'] ?? '') || (isset($request['review']) && (string)$request['review'] !== (string)$userId)) {
            $model['errors'][] = 'Select the member again before confirming the ban.';
            return $model;
        }
        $reason = $post['reason'] ?? '';
        $level = isset($post['reviewed_level']) ? (int)$post['reviewed_level'] : null;
        [$ok, $message] = corebb_admin_mod_ban_user($viewer, $userId, $reason, $level);
        if ($ok) {
            $model['messages'][] = $message;
            $model['ban_saved'] = true;
            return $model;
        } else {
            $model['errors'][] = $message;
        }
        $model['mode'] = 'ban';
    }

    $mode = (string)($model['mode'] ?? $mode);

    if ($mode === 'ban') {
        $query = corebb_admin_mod_limit_text(trim((string)($request['q'] ?? $post['q'] ?? '')), 255);
        $model['query'] = $query;
        if ($query !== '' && !$model['confirm_user']) {
            $model['results'] = corebb_admin_mod_search_users($query, 'active');
            foreach ($model['results'] as &$user) {
                [, $user['blocked']] = corebb_admin_mod_can_touch_user($viewer,$user,'ban');
                if ($model['can_ip']) {
                    $user['last_ip'] = corebb_admin_display_ip($user['last_ip']);
                } else {
                    unset($user['last_ip']);
                }
            }
            unset($user);
            if (!$model['results']) {
                $model['messages'][] = 'No active users matched that search.';
            }
        }
    }

    return $model;
}
