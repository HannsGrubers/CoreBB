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
require_once __DIR__ . '/../helpers/corebb_update_helpers.php';
require_once __DIR__ . '/../helpers/admin_log_helpers.php';

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
 * Usage: Fetch recent admin actions for a dashboard panel.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $level Staff level already authorized for this dashboard.
 * @param int $limit Maximum rows to return.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_dashboard_logs(int $level, int $limit = 5): array
{
    corebb_adminlogs_ensure_schema();

    $limit = max(1, min(100, $limit));

    $idExpr = corebb_adminlogs_select_expr('id', '0');
    $useridExpr = corebb_adminlogs_select_expr('userid', '0');
    $userlevelExpr = corebb_adminlogs_select_expr('userlevel', "''");
    $actionExpr = corebb_adminlogs_select_expr('action', "''");
    $adminUsernameExpr = corebb_adminlogs_select_expr('admin_username', "''");
    $typeExpr = corebb_adminlogs_select_expr('action_type', "''");
    $descriptionExpr = corebb_adminlogs_select_expr('description', $actionExpr);
    $dateExpr = corebb_adminlogs_select_expr('date_performed', "''");
    $orderSql = corebb_adminlogs_order_sql();

    $rows = corebb_admin_dashboard_rows(
        'SELECT ' . $idExpr . ' AS id, ' . $useridExpr . ' AS userid, ' . $userlevelExpr . ' AS userlevel, ' . $actionExpr . ' AS action, ' . $adminUsernameExpr . ' AS admin_username, ' . $typeExpr . ' AS action_type, ' . $descriptionExpr . ' AS description, ' . $dateExpr . ' AS date_performed, u.username AS joined_username
         FROM adminlogs al
         LEFT JOIN users u ON u.id = ' . $useridExpr . ' WHERE ' . $userlevelExpr . ' = ? ORDER BY ' . $orderSql . ' LIMIT ' . $limit,
        [(string)$level],
        $limit
    );

    return array_map('corebb_adminlog_display_row', $rows);
}

/** Each dashboard role sees the same five most recent events per permitted staff level. */
function corebb_admin_dashboard_activity_roles(int $accessLevel): array
{
    return array_filter([5 => 'Administrator', 4 => 'Manager', 3 => 'Moderator'],
        static fn(int $level): bool => $level <= $accessLevel, ARRAY_FILTER_USE_KEY);
}

function corebb_admin_dashboard_model(array $viewer): array
{
    $accessLevel = (int)($viewer['accesslevel'] ?? 0);
    $stats = [
        'Users' => corebb_admin_dashboard_count_table('users'),
        'Categories' => corebb_admin_dashboard_count_table('boards'),
        'Boards' => corebb_admin_dashboard_count_table('forums'),
        'Topics' => corebb_admin_dashboard_count_table('topics'),
        'Posts' => corebb_admin_dashboard_count_table('posts'),
        'Private Messages' => corebb_admin_dashboard_count_table('privatemessages'),
        'Blog Entries' => corebb_admin_dashboard_count_table('blogs_posts'),
    ];


    $roles = corebb_admin_dashboard_activity_roles($accessLevel);
    $activity = [];
    foreach (array_keys($roles) as $level) {
        array_push($activity, ...corebb_admin_dashboard_logs($level));
    }
    usort($activity, static fn(array $a, array $b): int =>
        ($b['id'] <=> $a['id']) ?: strcmp($b['date_performed'], $a['date_performed']));

    return [
        'admins' => $accessLevel >= 4 ? corebb_admin_dashboard_admins() : [],
        'show_staff' => $accessLevel >= 4,
        'stats' => $stats,
        'update_status' => $accessLevel >= 5 ? corebb_update_status() : null,
        'activity_roles' => $roles,
        'activity' => $activity,
        'can_view_log' => corebb_admin_can_access_tool($viewer, 'action_log'),
        'can_view_history' => corebb_admin_can_access_tool($viewer, 'version_history'),
        'message' => '',
    ];
}
