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
    $value = trim((string)($request[$key] ?? $request[$fallback] ?? ''));
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
        $where[] = '(' . $typeExpr . ' LIKE ? OR ' . $actionExpr . ' LIKE ?)';
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
function corebb_action_log_count(array $filters): int
{
    $params = [];
    $where = corebb_action_log_build_where($filters, $params);
    $useridExpr = corebb_adminlogs_select_expr('userid', '0');
    return (int)db_value(
        'SELECT COUNT(*) FROM adminlogs al LEFT JOIN users u ON u.id = ' . $useridExpr . $where,
        $params,
        0
    );
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_action_log_format_date(string $value): string
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
 * Usage: Resolve the display name for the admin who performed an action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @return string Normalized or display-ready string.
 */
function corebb_action_log_actor_name(array $row): string
{
    $rawUserId = trim((string)($row['userid'] ?? ''));
    $userId = ($rawUserId !== '' && ctype_digit($rawUserId)) ? (int)$rawUserId : 0;
    $storedUsername = trim((string)($row['admin_username'] ?? ''));
    $joinedUsername = trim((string)($row['joined_username'] ?? ''));

    if ($userId > 0 && $joinedUsername !== '') {
        return $joinedUsername;
    }
    if ($storedUsername !== '' && !ctype_digit($storedUsername)) {
        return $storedUsername;
    }
    if ($rawUserId !== '' && !ctype_digit($rawUserId)) {
        return $rawUserId;
    }
    if ($storedUsername !== '') {
        return $storedUsername;
    }
    return $userId > 0 ? '#' . $userId : 'Unknown';
}

/**
 * Usage: Return a plain actor link model for the audit table.
 * Referenced by: corebb_action_log_fetch().
 *
 * @param array $row Database row being normalized for display.
 * @return array{label: string, url: string, target: string}
 */
function corebb_action_log_actor_link_model(array $row): array
{
    $username = corebb_action_log_actor_name($row);
    return [
        'label' => $username,
        'url' => '/admin/?act=action_log&susername=' . rawurlencode($username),
        'target' => '_top',
    ];
}

/**
 * Usage: Build a one-field action-log filter URL.
 * Referenced by: corebb_action_log_fetch() and Twig row links.
 *
 * @param string $key Request or filter key.
 * @param string $value Raw value to normalize.
 * @return string Admin action-log URL with a reset page.
 */
function corebb_action_log_filter_url(string $key, string $value): string
{
    $query = [
        'susername' => '',
        'ip' => '',
        'actiontype' => '',
        'page' => 1,
    ];
    if (array_key_exists($key, $query)) {
        $query[$key] = $value;
    }
    return '/admin/?act=action_log&' . http_build_query($query);
}

/**
 * Usage: Prepare windowed pager links for the action log.
 * Referenced by: corebb_admin_action_log_model().
 *
 * @param array $filters List filter values.
 * @param int $page Current page number.
 * @param int $totalPages Total page count after filters are applied.
 * @return array<string, mixed> Pager link data for Twig.
 */
function corebb_action_log_pager_model(array $filters, int $page, int $totalPages): array
{
    $start = max(1, $page - 5);
    $end = min($totalPages, $start + 9);
    $start = max(1, $end - 9);

    $pageLinks = [];
    for ($i = $start; $i <= $end; $i++) {
        $pageLinks[] = [
            'page' => $i,
            'url' => corebb_action_log_page_url($filters, $i),
            'current' => $i === $page,
        ];
    }

    $jumpOptions = [];
    for ($jump = 5; $jump <= $totalPages; $jump += 5) {
        $jumpOptions[] = ['page' => $jump, 'url' => corebb_action_log_page_url($filters, $jump)];
    }
    if ($totalPages > 1 && $totalPages % 5 !== 0) {
        $jumpOptions[] = ['page' => $totalPages, 'url' => corebb_action_log_page_url($filters, $totalPages)];
    }

    return [
        'has_pages' => $totalPages > 1,
        'page' => $page,
        'total_pages' => $totalPages,
        'first_url' => corebb_action_log_page_url($filters, 1),
        'previous_url' => $page > 1 ? corebb_action_log_page_url($filters, $page - 1) : '',
        'next_url' => $page < $totalPages ? corebb_action_log_page_url($filters, $page + 1) : '',
        'last_url' => corebb_action_log_page_url($filters, $totalPages),
        'reload_url' => corebb_action_log_page_url($filters, $page),
        'page_links' => $pageLinks,
        'jump_options' => $jumpOptions,
    ];
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
        $description = trim((string)($row['description'] ?? ''));
        if ($description === '') {
            $description = (string)($row['action'] ?? '');
        }

        $actionType = trim((string)($row['action_type'] ?? ''));
        if ($actionType === '') {
            $actionType = corebb_adminlog_action_type($description);
        }

        $ip = trim((string)($row['ip_address'] ?? ''));
        if ($ip === '') {
            $ip = 'Unknown';
        }

        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'userid' => (int)($row['userid'] ?? 0),
            'raw_userid' => (string)($row['userid'] ?? ''),
            'userlevel' => (string)($row['userlevel'] ?? ''),
            'admin_username' => corebb_action_log_actor_name($row),
            'admin_link' => corebb_action_log_actor_link_model($row),
            'ip_address' => $ip,
            'ip_filter_url' => corebb_action_log_filter_url('ip', $ip),
            'action_type' => $actionType,
            'action_type_filter_url' => corebb_action_log_filter_url('actiontype', $actionType),
            'description' => $description,
            'date_performed' => (string)($row['date_performed'] ?? ''),
            'date_vn' => corebb_action_log_format_date((string)($row['date_performed'] ?? '')),
        ];
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
function corebb_action_log_page_url(array $filters, int $page): string
{
    $query = [];
    foreach (['susername', 'ip', 'actiontype'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $query[$key] = (string)$filters[$key];
        }
    }
    $query['page'] = max(1, $page);
    return '/admin/?act=action_log&' . http_build_query($query);
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
    corebb_action_log_ensure_schema();

    $model = corebb_admin_require_model_base($viewer, 'Admin Action Log', $request);
    $filters = [
        'susername' => corebb_action_log_filter_value($request, 'susername', 'username'),
        'ip' => corebb_action_log_filter_value($request, 'ip', 'ipaddress'),
        'actiontype' => corebb_action_log_filter_value($request, 'actiontype', 'action_type'),
    ];

    $page = max(1, (int)($request['page'] ?? 1));
    $perPage = COREBB_ADMIN_ACTION_LOG_PER_PAGE;
    $total = corebb_action_log_count($filters);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $model['filters'] = $filters;
    $model['page'] = $page;
    $model['per_page'] = $perPage;
    $model['total'] = $total;
    $model['total_pages'] = $totalPages;
    $model['rows'] = corebb_action_log_fetch($filters, $page, $perPage);
    $model['pagination'] = corebb_action_log_pager_model($filters, $page, $totalPages);

    return $model;
}
