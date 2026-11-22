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
 |  admin_latest_users_view_model.php  - Latest Users    |
 |  moderation tool.                                     |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/admin_moderation_view_model.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';

const COREBB_ADMIN_LATEST_USERS_PER_PAGE = 50;

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_latest_users_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0' || $value === '0000-00-00 00:00:00' || $value === '1/1/0001 12:00:00 AM') {
        return '';
    }

    $month = corebb_account_month_label($value);
    if ($month !== null) { return $month; }
    $timestamp = ctype_digit($value) ? (int)$value : strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('n/j/Y g:i A', $timestamp);
}

/**
 * Classify accounts identically for filter counts, paginated selection and display.
 * Older user tables may lack either status or approval.
 */
function corebb_admin_latest_users_state_sql(): string
{
    $columns = corebb_admin_table_columns('users');
    $status = isset($columns['status']) ? 'u.`status`' : "''";
    $approved = isset($columns['approved']) ? 'u.`approved`' : '1';
    return "CASE WHEN CAST($status AS CHAR) = '2' THEN 'banned'
                 WHEN COALESCE(CAST($approved AS CHAR), '') NOT IN ('', '1') THEN 'pending'
                 ELSE 'active' END";
}

/**
 * Usage: Build the SQL fragment for an admin query.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_latest_users_archive_where(): array
{
    $columns = corebb_admin_table_columns('users');
    $where = [];
    $params = [];
    $hasArchiveMarker = false;

    if (isset($columns['is_archive_user'])) {
        $where[] = '(u.`is_archive_user` IS NULL OR CAST(u.`is_archive_user` AS UNSIGNED) = 0)';
        $hasArchiveMarker = true;
    }

    if (isset($columns['legacy_source'])) {
        $where[] = "(u.`legacy_source` IS NULL OR u.`legacy_source` NOT IN ('vn_archive', 'vnboards'))";
        $hasArchiveMarker = true;
    }

    if (!$hasArchiveMarker) {
        // Older imports commonly appended _legacy to archive-only accounts.
        $where[] = "u.`username` NOT LIKE ? ESCAPE '!'";
        $params[] = '%!_legacy';
    }

    return [$where, $params];
}

/**
 * Usage: Build the optional SELECT fragment for latest-user columns.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_latest_users_optional_select(bool $includeIp): string
{
    $columns = corebb_admin_table_columns('users');
    $wanted = [
        'id' => 'id',
        'username' => 'username',
        'accesslevel' => 'accesslevel',
        'posts' => 'posts',
        'regdate' => 'regdate',
        'lastlogindate' => 'lastlogindate',
        'lastpstdate' => 'lastpstdate',
        'lastip' => 'lastip',
        'status' => 'status',
        'approved' => 'approved',
    ];

    $parts = [];
    if (!$includeIp) { unset($wanted['lastip']); }
    foreach ($wanted as $column => $alias) {
        if (isset($columns[$column])) {
            $parts[] = 'u.`' . str_replace('`', '``', $column) . '` AS `' . str_replace('`', '``', $alias) . '`';
        } else {
            $parts[] = "'' AS `" . str_replace('`', '``', $alias) . '`';
        }
    }

    return implode(', ', $parts);
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_latest_users_summary(array $row): array
{
    $id = (int)($row['id'] ?? 0);
    $level = (int)($row['accesslevel'] ?? 0);

    return [
        'id' => $id,
        'username' => (string)($row['username'] ?? ''),
        'public_profile_url' => '/profile/' . $id . '/',
        'user_pages_url' => '/admin/?act=user_pages&userid=' . $id,
        'admin_notes_url' => '/admin/?act=admin_notes&userid=' . $id,
        'ip_check_url' => '/admin/?act=user_ip_check&userid=' . $id,
        'accesslevel' => $level,
        'level_name' => corebb_user_level_label($level),
        'posts' => (int)($row['posts'] ?? 0),
        'registered' => (string)($row['regdate'] ?? ''),
        'registered_display' => corebb_admin_latest_users_format_date((string)($row['regdate'] ?? '')),
        'last_login' => (string)($row['lastlogindate'] ?? ''),
        'last_login_display' => corebb_admin_latest_users_format_date((string)($row['lastlogindate'] ?? '')),
        'last_post' => (string)($row['lastpstdate'] ?? ''),
        'last_post_display' => corebb_admin_latest_users_format_date((string)($row['lastpstdate'] ?? '')),
        'last_ip' => (string)($row['lastip'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'status_text' => ucfirst($row['account_state']),
    ];
}

/**
 * Usage: Build and process the latest users admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_latest_users_model(array $viewer, array $request): array
{
    $page = max(1, (int)($request['page'] ?? 1));
    $perPage = COREBB_ADMIN_LATEST_USERS_PER_PAGE;

    [$archiveWhere, $archiveParams] = corebb_admin_latest_users_archive_where();
    $whereSql = $archiveWhere ? (' WHERE ' . implode(' AND ', $archiveWhere)) : '';

    $stateSql = corebb_admin_latest_users_state_sql();
    $counts = ['all'=>0, 'active'=>0, 'pending'=>0, 'banned'=>0];
    foreach (db_all("SELECT $stateSql AS account_state, COUNT(*) AS quantity FROM users u" . $whereSql . ' GROUP BY account_state', $archiveParams) as $group) {
        $counts[$group['account_state']] = (int)$group['quantity'];
        $counts['all'] += (int)$group['quantity'];
    }
    $filter = $request['status'] ?? 'all';
    if (!is_string($filter) || !isset($counts[$filter])) { $filter = 'all'; }
    if ($filter !== 'all') {
        $archiveWhere[] = "$stateSql = ?";
        $archiveParams[] = $filter;
        $whereSql = ' WHERE ' . implode(' AND ', $archiveWhere);
    }
    $total = $counts[$filter];
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $access = [];
    foreach (['user_pages', 'admin_notes', 'user_ip_check', 'moderation_ban', 'moderation_unban'] as $tool) {
        $access[$tool] = corebb_admin_can_access_tool($viewer, $tool);
    }

    $rows = db_all(
        'SELECT ' . corebb_admin_latest_users_optional_select($access['user_ip_check']) . ", $stateSql AS account_state" .
        ' FROM users u' . $whereSql .
        ' ORDER BY u.`id` DESC LIMIT ' . (int)$offset . ', ' . (int)$perPage,
        $archiveParams
    );

    $users = [];
    foreach ($rows as $row) {
        $user = corebb_admin_latest_users_summary($row);
        $verb = $user['status_text'] === 'Banned' ? 'unban' : 'ban';
        if ($access['moderation_' . $verb] && corebb_admin_mod_can_touch_user($viewer, $row, $verb)[0]) {
            $param = $verb === 'unban' ? 'username' : 'q';
            $user['moderation_url'] = '/admin/?act=moderation&mode=' . $verb . '&' . $param . '=' . rawurlencode($user['username']);
        }
        $users[] = $user;
    }

    return [
        'access' => $access,
        'filter' => $filter,
        'counts' => $counts,
        'users' => $users,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'page_numbers' => corebb_compact_page_sequence($page, $totalPages),
    ];
}
