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
    if ($value === '' || $value === '0000-00-00 00:00:00' || $value === '1/1/0001 12:00:00 AM') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('n/j/Y g:i A', $timestamp);
}

/**
 * Usage: Convert stored user status fields into display text.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $status Request/report status.
 * @param mixed $approved Approved state.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_latest_users_status_text($status, $approved = null): string
{
    if ((string)$status === '2') {
        return 'Banned';
    }

    if ($approved !== null && (string)$approved !== '' && (string)$approved !== '1') {
        return 'Pending';
    }

    return 'Active';
}

/**
 * Usage: Build the SQL fragment for an admin query.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_latest_users_archive_where(): array
{
    $columns = function_exists('corebb_admin_table_columns') ? corebb_admin_table_columns('users') : [];
    $where = [];
    $params = [];
    $hasArchiveMarker = false;

    if (isset($columns['is_archive_user'])) {
        $where[] = '(u.`is_archive_user` IS NULL OR CAST(u.`is_archive_user` AS UNSIGNED) = 0)';
        $hasArchiveMarker = true;
    }

    if (isset($columns['legacy_source'])) {
        $where[] = "(u.`legacy_source` IS NULL OR u.`legacy_source` = '' OR u.`legacy_source` <> 'vn_archive')";
        $hasArchiveMarker = true;
    }

    if (!$hasArchiveMarker) {
        // Older imports commonly appended _legacy to archive-only accounts.
        $where[] = "u.`username` NOT LIKE ? ESCAPE '\\\\'";
        $params[] = '%\\_legacy';
    }

    return [$where, $params];
}

/**
 * Usage: Build the optional SELECT fragment for latest-user columns.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_latest_users_optional_select(): string
{
    $columns = function_exists('corebb_admin_table_columns') ? corebb_admin_table_columns('users') : [];
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
        'ban_url' => '/admin/?act=moderation&mode=ban&q=' . rawurlencode((string)($row['username'] ?? '')),
        'accesslevel' => $level,
        'level_name' => function_exists('LoadUserLevel') ? LoadUserLevel($level) : (string)$level,
        'posts' => (int)($row['posts'] ?? 0),
        'registered' => (string)($row['regdate'] ?? ''),
        'registered_display' => corebb_admin_latest_users_format_date((string)($row['regdate'] ?? '')),
        'last_login' => (string)($row['lastlogindate'] ?? ''),
        'last_login_display' => corebb_admin_latest_users_format_date((string)($row['lastlogindate'] ?? '')),
        'last_post' => (string)($row['lastpstdate'] ?? ''),
        'last_post_display' => corebb_admin_latest_users_format_date((string)($row['lastpstdate'] ?? '')),
        'last_ip' => (string)($row['lastip'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'status_text' => corebb_admin_latest_users_status_text($row['status'] ?? '', $row['approved'] ?? null),
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
    $offset = ($page - 1) * $perPage;

    [$archiveWhere, $archiveParams] = corebb_admin_latest_users_archive_where();
    $whereSql = $archiveWhere ? (' WHERE ' . implode(' AND ', $archiveWhere)) : '';

    $total = (int)db_value('SELECT COUNT(*) FROM users u' . $whereSql, $archiveParams, 0);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $rows = db_all(
        'SELECT ' . corebb_admin_latest_users_optional_select() .
        ' FROM users u' . $whereSql .
        ' ORDER BY u.`id` DESC LIMIT ' . (int)$offset . ', ' . (int)$perPage,
        $archiveParams
    );

    $users = [];
    foreach ($rows as $row) {
        $users[] = corebb_admin_latest_users_summary($row);
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'title' => 'Latest Users',
        'users' => $users,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'message' => (string)($request['msg'] ?? ''),
    ];
}