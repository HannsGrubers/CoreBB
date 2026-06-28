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
 |  admin_user_ip_check_view_model.php  - Admin User IP  |
 |  Check.                                               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';

const COREBB_ADMIN_USER_IP_CHECK_LIMIT = 250;

/**
 * Usage: Check whether a user-IP lookup column exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $column Database column name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_user_ip_check_column_exists(string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    return isset(corebb_admin_table_columns($table)[$column]);
}

/**
 * Usage: Check whether a user-IP lookup table exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_user_ip_check_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $db = corebb_db_connection_name();
    if ($db === '') {
        return false;
    }
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
        [$db, $table]
    );
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_user_ip_check_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00' || $value === '1/1/0001 12:00:00 AM') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('n/j/Y g:i:s A', $ts);
}

/**
 * Usage: Normalize an IP value for user-IP display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $ip IP address.
 * @return string Normalized or display-ready string.
 */
function corebb_user_ip_check_display_ip(string $ip): string
{
    $ip = trim($ip);
    if (preg_match('/^\d{1,3}(?:-\d{1,3}){3}$/', $ip)) {
        return str_replace('-', '.', $ip);
    }
    return $ip;
}

/**
 * Usage: Find the target user for the IP check tool.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_ip_check_find_user(array $request, array $post): array
{
    $username = trim((string)(
        $request['username']
        ?? $request['usr']
        ?? $request['name']
        ?? $post['username']
        ?? $post['usr']
        ?? ''
    ));

    $userId = trim((string)(
        $request['userid']
        ?? $request['uid']
        ?? $request['usrid']
        ?? $post['userid']
        ?? $post['uid']
        ?? $post['usrid']
        ?? ''
    ));

    $selected = null;
    if ($userId !== '' && ctype_digit($userId)) {
        $selected = corebb_admin_find_user($userId);
    }
    if (!$selected && $username !== '') {
        $selected = corebb_admin_find_user($username);
    }

    return [$username, $userId, $selected];
}

/**
 * Usage: Return a plain link model for user rows in IP-related admin tools.
 * Referenced by: corebb_user_ip_check_user_summary() and selected user headers.
 *
 * @param array $user User row being displayed or edited.
 * @return array{label: string, url: string, target: string}
 */
function corebb_user_ip_check_user_link_model(array $user): array
{
    $id = (int)($user['id'] ?? 0);
    $username = (string)($user['username'] ?? '');
    $label = $username !== '' ? $username : ($id > 0 ? '#' . $id : 'Unknown');

    return [
        'label' => $label,
        'url' => $id > 0 ? '/admin/?act=user_pages&userid=' . $id : '#',
        'target' => '_top',
    ];
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @param string $searchedIp IP address originally searched.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_ip_check_user_summary(array $row, string $searchedIp): array
{
    $id = (int)($row['id'] ?? 0);
    $username = (string)($row['username'] ?? '');
    $lastIp = trim((string)($row['lastip'] ?? ''));
    $displayIp = $lastIp !== '' ? $lastIp : $searchedIp;
    $isBanned = ((string)($row['status'] ?? '') === '2');

    return [
        'id' => $id,
        'username' => $username,
        'user_link' => corebb_user_ip_check_user_link_model($row),
        'banned' => $isBanned ? 'True' : 'False',
        'banned_raw' => $isBanned,
        'ip_address' => $displayIp,
        'ip_address_display' => corebb_user_ip_check_display_ip($displayIp),
        'notes_url' => '/admin/?act=admin_notes&userid=' . $id,
        'ban_url' => '/admin/?act=moderation&mode=ban&q=' . rawurlencode($username) . '&ip=' . rawurlencode($searchedIp),
        'unban_url' => '/admin/?act=moderation&mode=unban&username=' . rawurlencode($username) . '&ip=' . rawurlencode($searchedIp),
        'user_page_url' => '/admin/?act=user_pages&userid=' . $id,
        'last_login_date' => corebb_user_ip_check_format_date((string)($row['lastlogindate'] ?? '')),
        'last_post_date' => corebb_user_ip_check_format_date((string)($row['lastpstdate'] ?? $row['lastpost'] ?? '')),
        'date_added' => corebb_user_ip_check_format_date((string)($row['regdate'] ?? '')),
        'raw' => $row,
    ];
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $ip IP address.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_ip_check_fetch_users(string $ip): array
{
    $ip = substr(trim($ip), 0, 64);
    if ($ip === '') {
        return [];
    }

    $params = [];
    $whereParts = [];

    if (corebb_user_ip_check_column_exists('users', 'lastip')) {
        $whereParts[] = 'u.lastip = ?';
        $params[] = $ip;
    }

    if (corebb_user_ip_check_table_exists('posts') && corebb_user_ip_check_column_exists('posts', 'postip')) {
        $whereParts[] = 'u.id IN (SELECT DISTINCT p.posterid FROM posts p WHERE p.postip = ? AND p.posterid > 0)';
        $params[] = $ip;
    }

    if (!$whereParts) {
        return [];
    }

    $sql = 'SELECT DISTINCT ' . corebb_admin_user_select_list('u') . ' FROM users u WHERE (' . implode(' OR ', $whereParts) . ') ORDER BY u.username ASC LIMIT ' . COREBB_ADMIN_USER_IP_CHECK_LIMIT;
    $users = [];
    foreach (db_all($sql, $params) as $row) {
        $users[] = corebb_user_ip_check_user_summary($row, $ip);
    }
    return $users;
}

/**
 * Usage: Build and process the user ip check admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_user_ip_check_model(array $viewer, array $request, array $post): array
{
    $model = corebb_admin_require_model_base($viewer, 'User IP Check', $request);
    $model['search'] = [
        'username' => '',
        'userid' => '',
        'ip' => '',
    ];
    $model['selected_user'] = null;
    $model['selected_ip'] = '';
    $model['rows'] = [];
    $model['result_limit'] = COREBB_ADMIN_USER_IP_CHECK_LIMIT;

    [$username, $userId, $selectedUser] = corebb_user_ip_check_find_user($request, $post);
    $ip = substr(trim((string)(
        $request['ip']
        ?? $request['ip_address']
        ?? $request['ipaddress']
        ?? $post['ip']
        ?? $post['ip_address']
        ?? $post['ipaddress']
        ?? ''
    )), 0, 64);

    if ($selectedUser) {
        $model['selected_user'] = [
            'id' => (int)($selectedUser['id'] ?? 0),
            'username' => (string)($selectedUser['username'] ?? ''),
            'user_link' => corebb_user_ip_check_user_link_model($selectedUser),
            'lastip' => (string)($selectedUser['lastip'] ?? ''),
        ];
        if ($ip === '') {
            $ip = trim((string)($selectedUser['lastip'] ?? ''));
        }
        $username = (string)($selectedUser['username'] ?? $username);
        $userId = (string)($selectedUser['id'] ?? $userId);
    } elseif ($username !== '' || $userId !== '') {
        $model['errors'][] = 'No user with the requested name or ID exists.';
    }

    $model['search'] = [
        'username' => $username,
        'userid' => $userId,
        'ip' => $ip,
    ];
    $model['selected_ip'] = $ip;
    $model['selected_ip_display'] = corebb_user_ip_check_display_ip($ip);

    if ($ip !== '') {
        $model['rows'] = corebb_user_ip_check_fetch_users($ip);
        if ((int)($viewer['accesslevel'] ?? 0) >= 3  && empty($model['errors'])) {
            $target = $selectedUser ? (' for user #' . (int)($selectedUser['id'] ?? 0) . ' (' . (string)($selectedUser['username'] ?? 'Unknown') . ')') : '';
            corebb_adminlog_viewer($viewer, 'User IP Check: ' . $ip . $target, 'user_ip_check');
        }
    }

    if ($ip === '' && ($username !== '' || $userId !== '') && $selectedUser) {
        $model['errors'][] = 'The selected user does not have an IP address recorded.';
    }

    return $model;
}
