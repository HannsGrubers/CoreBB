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
 |  admin_action_log_view_model.php  - Admin Action      |
 |  Log.                                                 |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';

const COREBB_ADMIN_ACTION_LOG_PER_PAGE = 25;

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_action_log_ensure_schema(): void
{
    corebb_adminlogs_ensure_schema();
}

/**
 * Usage: Read and sanitize one action-log filter value.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $request Query/request values from admin.php.
 * @param string $key Request or filter key.
 * @param string $fallback Fallback value.
 * @return string Normalized or display-ready string.
 */
function corebb_action_log_filter_value(array $request, string $key, string $fallback = ''): string
{
    $input = $request[$key] ?? $request[$fallback] ?? '';
    $value = is_scalar($input) ? trim((string)$input) : '';
    $value = str_replace(["\r", "\n", "\t"], '', $value);
    return substr($value, 0, 255);
}

/**
 * Usage: Build the SQL fragment for an admin query.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @param array $params SQL parameter list, appended by reference when needed.
 * @return string Normalized or display-ready string.
 */
function corebb_action_log_build_where(array $filters, array &$params): string
{
    $where = [];
    $useridExpr = corebb_adminlogs_select_expr('userid', '0');
    $adminUsernameExpr = corebb_adminlogs_select_expr('admin_username', "''");
    $ipExpr = corebb_adminlogs_select_expr('ip_address', "''");
    $typeExpr = corebb_adminlogs_select_expr('action_type', "''");
    $actionExpr = corebb_adminlogs_select_expr('action', "''");

    if ($filters['susername'] !== '') {
        $like = '%' . $filters['susername'] . '%';
        $where[] = '(' . $adminUsernameExpr . ' LIKE ? OR u.username LIKE ? OR CAST(' . $useridExpr . ' AS CHAR) LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($filters['ip'] !== '') {
        $where[] = $ipExpr . ' LIKE ?';
        $params[] = '%' . $filters['ip'] . '%';
    }

    if ($filters['actiontype'] !== '') {
        $detailExpr = corebb_adminlogs_select_expr('description', "''");
        $where[] = '(' . $typeExpr . ' LIKE ? OR ' . $actionExpr . ' LIKE ? OR ' . $detailExpr . ' LIKE ?)';
        $params[] = '%' . $filters['actiontype'] . '%';
        $params[] = '%' . $filters['actiontype'] . '%';
        $params[] = '%' . $filters['actiontype'] . '%';
    }

    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @return int Numeric result for the caller.
 */
function corebb_action_log_count(array $filters): ?int
{
    $params = [];
    $where = corebb_action_log_build_where($filters, $params);
    $useridExpr = corebb_adminlogs_select_expr('userid', '0');
    $total = db_value(
        'SELECT COUNT(*) FROM adminlogs al LEFT JOIN users u ON u.id = ' . $useridExpr . $where,
        $params
    );
    return $total === null ? null : (int)$total;
}

/**
 * Usage: Fetch one paged set of admin-log rows with filters applied.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @param int $page Current page number.
 * @param int $perPage Rows per page.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_action_log_fetch(array $filters, int $page, int $perPage): array
{
    $params = [];
    $where = corebb_action_log_build_where($filters, $params);
    $offset = max(0, ($page - 1) * $perPage);
    $limit = max(1, min(100, $perPage));

    $idExpr = corebb_adminlogs_select_expr('id', '0');
    $useridExpr = corebb_adminlogs_select_expr('userid', '0');
    $userlevelExpr = corebb_adminlogs_select_expr('userlevel', "''");
    $actionExpr = corebb_adminlogs_select_expr('action', "''");
    $adminUsernameExpr = corebb_adminlogs_select_expr('admin_username', "''");
    $ipExpr = corebb_adminlogs_select_expr('ip_address', "''");
    $typeExpr = corebb_adminlogs_select_expr('action_type', "''");
    $descriptionExpr = corebb_adminlogs_select_expr('description', $actionExpr);
    $dateExpr = corebb_adminlogs_select_expr('date_performed', "''");
    $orderSql = corebb_adminlogs_order_sql();

    $queryRows = db_all(
        'SELECT ' . $idExpr . ' AS id, ' . $useridExpr . ' AS userid, ' . $userlevelExpr . ' AS userlevel, ' . $actionExpr . ' AS action, ' . $adminUsernameExpr . ' AS admin_username, ' . $ipExpr . ' AS ip_address, ' . $typeExpr . ' AS action_type, ' . $descriptionExpr . ' AS description, ' . $dateExpr . ' AS date_performed, u.username AS joined_username
         FROM adminlogs al
         LEFT JOIN users u ON u.id = ' . $useridExpr .
        $where .
        ' ORDER BY ' . $orderSql . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );

    $rows = [];
    foreach ($queryRows as $row) {
        $event = corebb_adminlog_display_row($row);
        $actor = $event['admin_username'];
        $rawId = trim((string)$row['userid']);
        $actorFilter = $actor === '#' . $rawId ? $rawId : $actor;
        $ip = trim((string)$row['ip_address']);
        $type = trim((string)$row['action_type']);
        $level = trim((string)$row['userlevel']);
        $storedName = trim((string)$row['admin_username']);
        $event['recorded_name'] = $storedName !== $actor && !ctype_digit($storedName) ? $storedName : '';
        $event['role'] = in_array($level,['1','2','3','4','5'],true) ? corebb_user_level_label((int)$level) : $level;
        $event['actor_filter_url'] = $actor === 'Unknown' && !$rawId ? '' : corebb_action_log_page_url(['susername'=>$actorFilter] + $filters, 1);
        $event['ip'] = $ip;
        $event['ip_filter_url'] = $ip !== '' ? corebb_action_log_page_url(['ip'=>$ip] + $filters, 1) : '';
        $event['type'] = $type ?: corebb_adminlog_action_type($event['action']);
        $event['type_filter_url'] = $type !== '' ? corebb_action_log_page_url(['actiontype'=>$type] + $filters, 1) : '';
        $rows[] = $event;
    }
    return $rows;
}

/**
 * Usage: Build an admin URL or query string.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @param int $page Current page number.
 * @return string Normalized or display-ready string.
 */
function corebb_action_log_page_url(array $filters, ?int $page = null): string
{
    $query = [];
    foreach (['susername', 'ip', 'actiontype'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $query[$key] = (string)$filters[$key];
        }
    }
    if ($page !== null) { $query['page'] = max(1, $page); }
    return '/admin/?' . http_build_query(['act'=>'action_log'] + $query);
}

/**
 * Usage: Build and process the action log admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_action_log_model(array $viewer, array $request, array $post): array
{
    if (corebb_admin_viewer_id($viewer) <= 0 || !corebb_admin_can_access_tool($viewer,'action_log')) {
        return ['errors'=>['You do not have access to the Admin Action Log.']];
    }
    corebb_action_log_ensure_schema();
    $filters = [
        'susername' => corebb_action_log_filter_value($request, 'susername', 'username'),
        'ip' => corebb_action_log_filter_value($request, 'ip', 'ipaddress'),
        'actiontype' => corebb_action_log_filter_value($request, 'actiontype', 'action_type'),
    ];

    $page = max(1, (int)($request['page'] ?? 1));
    $perPage = COREBB_ADMIN_ACTION_LOG_PER_PAGE;
    $total = corebb_action_log_count($filters);
    $model = ['filters'=>$filters, 'filtered'=>(bool)array_filter($filters,'strlen')];
    if ($total === null) { return $model + ['errors'=>['The action log could not be loaded.']]; }
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $rows = corebb_action_log_fetch($filters, $page, $perPage);
    if (db_error() !== '') { return $model + ['errors'=>['The action log could not be loaded.']]; }
    $pattern = corebb_action_log_page_url($filters) . '&page={page}';
    return $model + [
        'rows'=>$rows, 'total'=>$total, 'first'=>($page-1)*$perPage+1, 'last'=>($page-1)*$perPage+count($rows),
        'reload_url'=>corebb_action_log_page_url($filters,$page),
        'pagination'=>corebb_pagination_model($pattern,$page,$pages),
    ];
}
