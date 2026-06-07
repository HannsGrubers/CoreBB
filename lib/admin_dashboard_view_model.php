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
 |  admin_dashboard_view_model.php  - Admin dashboard    |
 |  data loader.                                         |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';

/**
 * Usage: Count rows in a table for dashboard totals.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @return int Numeric result for the caller.
 */
function corebb_admin_dashboard_count_table(string $table): int
{
    $allowed = [
        'users' => '`users`',
        'forums' => '`forums`',
        'boards' => '`boards`',
        'topics' => '`topics`',
        'posts' => '`posts`',
        'privatemessages' => '`privatemessages`',
        'blogs_posts' => '`blogs_posts`',
        'adminlogs' => '`adminlogs`',
    ];
    if(!isset($allowed[$table])){
        return 0;
    }

    try{
        return (int)db_value('SELECT COUNT(*) FROM ' . $allowed[$table], [], 0);
    }
    catch(Throwable $e){
        return 0;
    }
}

/**
 * Usage: Fetch a small row set for dashboard panels.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $sql SQL query text.
 * @param array $params SQL parameter list, appended by reference when needed.
 * @param int $limit Maximum rows to return.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_rows(string $sql, array $params = [], int $limit = 20): array
{
    $rows = [];
    try{
        foreach (db_all($sql, $params) as $row) {
            if (count($rows) >= $limit) { break; }
            $rows[] = $row;
        }
    }
    catch(Throwable $e){
        /* Keep dashboard usable even if an optional table/column is absent. */
    }
    return $rows;
}

/**
 * Usage: Fetch active admin-level users for the dashboard.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_admins(): array
{
    return corebb_admin_dashboard_rows("SELECT id, username, accesslevel FROM users WHERE accesslevel >= ? ORDER BY username ASC", [5], 500);
}

/**
 * Usage: Build the actor display model for a dashboard log row.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_log_actor(array $row): array
{
    $rawUserId = trim((string)($row['userid'] ?? ''));
    $userId = ($rawUserId !== '' && ctype_digit($rawUserId)) ? (int)$rawUserId : 0;
    $storedUsername = trim((string)($row['admin_username'] ?? ''));
    $joinedUsername = trim((string)($row['joined_username'] ?? ''));

    /*
     * Prefer the live users.username joined by userid. Some upgraded/legacy
     * adminlogs rows had admin_username backfilled with the numeric actor id,
     * and audit views intentionally show plain usernames without VIP styling.
     */
    if ($userId > 0 && $joinedUsername !== '') {
        $username = $joinedUsername;
    } elseif ($storedUsername !== '' && !ctype_digit($storedUsername)) {
        $username = $storedUsername;
    } elseif ($rawUserId !== '' && !ctype_digit($rawUserId)) {
        $username = $rawUserId;
    } elseif ($storedUsername !== '') {
        $username = $storedUsername;
    } elseif ($userId > 0) {
        $username = '#' . $userId;
    } else {
        $username = 'Unknown';
    }

    return [
        'username' => $username,
        'url' => '/admin/?act=action_log&susername=' . rawurlencode($username),
    ];
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_dashboard_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return 'Not recorded';
    }

    if (preg_match('/^\d{9,11}$/', $value) === 1) {
        $timestamp = (int)$value;
    } else {
        $timestamp = strtotime($value);
    }

    if ($timestamp === false || $timestamp <= 0) {
        return $value;
    }

    return date('n/j/Y g:i:s A', $timestamp);
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_normalize_log_row(array $row): array
{
    $actor = corebb_admin_dashboard_log_actor($row);
    $action = trim((string)($row['description'] ?? ''));
    if ($action === '') {
        $action = trim((string)($row['action'] ?? ''));
    }
    if ($action === '') {
        $action = '[No action description]';
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'userid' => (int)($row['userid'] ?? 0),
        'userlevel' => (string)($row['userlevel'] ?? ''),
        'admin_username' => (string)$actor['username'],
        'admin_url' => (string)$actor['url'],
        'action' => $action,
        'action_type' => (string)($row['action_type'] ?? ''),
        'ip_address' => (string)($row['ip_address'] ?? ''),
        'date_performed' => (string)($row['date_performed'] ?? ''),
        'date_vn' => corebb_admin_dashboard_format_date((string)($row['date_performed'] ?? '')),
    ];
}

/**
 * Usage: Fetch recent admin actions for a dashboard panel.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param ?int $level Optional access level to filter by.
 * @param int $limit Maximum rows to return.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_logs(?int $level = null, int $limit = 20): array
{
    if (function_exists('corebb_adminlogs_ensure_schema')) {
        corebb_adminlogs_ensure_schema();
    }

    $limit = max(1, min(100, $limit));
    $params = [];

    $idExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('id', '0') : 'al.id';
    $useridExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('userid', '0') : 'al.userid';
    $userlevelExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('userlevel', "''") : 'al.userlevel';
    $actionExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('action', "''") : 'al.action';
    $adminUsernameExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('admin_username', "''") : 'al.admin_username';
    $ipExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('ip_address', "''") : 'al.ip_address';
    $typeExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('action_type', "''") : 'al.action_type';
    $descriptionExpr = function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('description', $actionExpr) : 'al.description';
    $dateExpr = function_exists('corebb_adminlogs_effective_date_expr') ? corebb_adminlogs_effective_date_expr('al') : (function_exists('corebb_adminlogs_select_expr') ? corebb_adminlogs_select_expr('date_performed', "''") : 'al.date_performed');
    $orderSql = function_exists('corebb_adminlogs_order_sql') ? corebb_adminlogs_order_sql() : 'al.id DESC';

    $where = '';
    if($level !== null){
        $where = ' WHERE ' . $userlevelExpr . ' = ?';
        $params[] = (string)(int)$level;
    }

    $rows = corebb_admin_dashboard_rows(
        'SELECT ' . $idExpr . ' AS id, ' . $useridExpr . ' AS userid, ' . $userlevelExpr . ' AS userlevel, ' . $actionExpr . ' AS action, ' . $adminUsernameExpr . ' AS admin_username, ' . $ipExpr . ' AS ip_address, ' . $typeExpr . ' AS action_type, ' . $descriptionExpr . ' AS description, ' . $dateExpr . ' AS date_performed, u.username AS joined_username
         FROM adminlogs al
         LEFT JOIN users u ON u.id = ' . $useridExpr .
        $where .
        ' ORDER BY ' . $orderSql . ' LIMIT ' . $limit,
        $params,
        $limit
    );

    return array_map('corebb_admin_dashboard_normalize_log_row', $rows);
}

/**
 * Usage: Build a dashboard panel title for an access level.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $accessLevel User access level.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_title(int $accessLevel): array
{
    if($accessLevel >= 5){
        return [
            'title' => 'Administration Area',
            'body' => 'From here you can control every aspect of your forums.',
        ];
    }
    if($accessLevel >= 4){
        return [
            'title' => 'Management Area',
            'body' => 'From here you can control forum management functions, except Administrator-only tools.',
        ];
    }
    if($accessLevel >= 3){
        return [
            'title' => 'Moderation Area',
            'body' => 'From here you can use the available moderator functions.',
        ];
    }
    return [
        'title' => 'VIP Area',
        'body' => 'From here you can use the available VIP functions.',
    ];
}

/**
 * Usage: Build and process the dashboard admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_model(array $viewer): array
{
    $accessLevel = (int)($viewer['accesslevel'] ?? 0);
    $title = corebb_admin_dashboard_title($accessLevel);
    if($accessLevel < 2 && function_exists('corebb_admin_user_granted_tool_keys') && corebb_admin_user_granted_tool_keys((int)($viewer['id'] ?? 0))){
        $title = [
            'title' => 'Special Access Area',
            'body' => 'From here you can use the administration tools specifically granted to your account.',
        ];
    }

    $stats = [
        'Users' => corebb_admin_dashboard_count_table('users'),
        'Categories' => corebb_admin_dashboard_count_table('boards'),
        'Boards' => corebb_admin_dashboard_count_table('forums'),
        'Topics' => corebb_admin_dashboard_count_table('topics'),
        'Posts' => corebb_admin_dashboard_count_table('posts'),
        'Private Messages' => corebb_admin_dashboard_count_table('privatemessages'),
        'Blog Entries' => corebb_admin_dashboard_count_table('blogs_posts'),
    ];

    $logSections = [];
    $dashboardLogLimit = 5;
    if($accessLevel >= 5){
        $logSections[] = ['title' => 'Showing Latest 5 Administrator Actions', 'rows' => corebb_admin_dashboard_logs(5, $dashboardLogLimit)];
        $logSections[] = ['title' => 'Showing Latest 5 Manager Actions', 'rows' => corebb_admin_dashboard_logs(4, $dashboardLogLimit)];
        $logSections[] = ['title' => 'Showing Latest 5 Moderator Actions', 'rows' => corebb_admin_dashboard_logs(3, $dashboardLogLimit)];
    }
    elseif($accessLevel >= 4){
        $logSections[] = ['title' => 'Showing Latest 5 Manager Actions', 'rows' => corebb_admin_dashboard_logs(4, $dashboardLogLimit)];
        $logSections[] = ['title' => 'Showing Latest 5 Moderator Actions', 'rows' => corebb_admin_dashboard_logs(3, $dashboardLogLimit)];
    }
    elseif($accessLevel >= 3){
        $logSections[] = ['title' => 'Showing Latest 5 Moderator Actions', 'rows' => corebb_admin_dashboard_logs(3, $dashboardLogLimit)];
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => $accessLevel,
        'title' => $title['title'],
        'body' => $title['body'],
        'admins' => corebb_admin_dashboard_admins(),
        'stats' => $stats,
        'log_sections' => $logSections,
        'message' => '',
    ];
}
