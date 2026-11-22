<?php
require_once __DIR__ . '/../helpers/corebb_date_helpers.php';
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
 |  admin_mod_requests_view_model.php  - VN-style Mod    |
 |  Post Requests / Moderate Message helpers.            |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/rate_limit_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';
require_once __DIR__ . '/../helpers/corebb_url_helpers.php';
require_once __DIR__ . '/../helpers/corebb_route_helpers.php';
require_once __DIR__ . '/../helpers/admin_board_filter_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';

/**
 * Usage: Quote and validate an identifier for mod-request SQL.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $identifier User, table, or column identifier.
 * @return string Normalized or display-ready string.
 */
function corebb_mod_requests_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Normalized or display-ready string.
 */
function corebb_mod_request_limit_text(string $value, int $maxBytes): string
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
 * Usage: Build a public topic URL for mod-request storage and display.
 * Referenced by: report submission, request listings, and moderate-message views.
 *
 * @param int $topicId Topic id containing the reported post.
 * @param int $boardId Optional board id to help route helpers build the canonical URL.
 * @param int $postId Optional post id anchor.
 * @return string Public topic URL or an empty string for invalid topics.
 */
function corebb_mod_request_thread_url(int $topicId, int $boardId = 0, int $postId = 0): string
{
    if ($topicId <= 0) {
        return '';
    }

    return corebb_thread_url($topicId, $boardId, 1, '', $postId);
}

/**
 * Usage: Normalize a saved mod-request URL before rendering it.
 * Referenced by: corebb_admin_mod_requests_model().
 *
 * @param string $url Stored request URL.
 * @param int $topicId Fallback topic id.
 * @param int $boardId Fallback board id.
 * @param int $postId Fallback post id.
 * @return string Public URL for the reported post.
 */
function corebb_mod_request_display_url(string $url, int $topicId, int $boardId = 0, int $postId = 0): string
{
    $url = trim($url);
    if (preg_match('/^thread\.php\?id=(\d+)(?:&brd=(\d+))?(?:&p=\d+)?(?:#post(\d+))?$/', $url, $matches)) {
        return corebb_mod_request_thread_url((int)$matches[1], (int)($matches[2] ?? 0), (int)($matches[3] ?? $postId));
    }
    if (preg_match('~^(?:controllers/)?forum\.php\?~i', $url)) {
        $parts = parse_url($url);
        parse_str((string)($parts['query'] ?? ''), $params);
        $topicId = (int)($params['id'] ?? $topicId);
        $boardId = (int)($params['brd'] ?? $params['boardid'] ?? $boardId);
        $postId = (int)($params['post'] ?? $postId);
        return corebb_mod_request_thread_url($topicId, $boardId, $postId);
    }
    if ($url !== '') {
        return corebb_public_join_base_path($url);
    }
    return corebb_mod_request_thread_url($topicId, $boardId, $postId);
}

/**
 * Usage: Check whether a mod-request table exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_mod_requests_table_exists(string $table): bool
{
    corebb_mod_requests_identifier($table);
    return db_exists('SHOW TABLES LIKE ?', [$table]);
}

/**
 * Usage: Check whether a mod-request column exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $column Database column name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_mod_requests_column_exists(string $table, string $column): bool
{
    $tableSafe = corebb_mod_requests_identifier($table);
    return db_exists("SHOW COLUMNS FROM {$tableSafe} LIKE ?", [$column]);
}

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_mod_requests_ensure_schema(): void
{
    db_run("CREATE TABLE IF NOT EXISTS `mod_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `postid` INT(11) NOT NULL DEFAULT 0,
        `topicid` INT(11) NOT NULL DEFAULT 0,
        `boardid` INT(11) NOT NULL DEFAULT 0,
        `reporterid` INT(11) NOT NULL DEFAULT 0,
        `reported_userid` INT(11) NOT NULL DEFAULT 0,
        `reason_type` VARCHAR(64) NOT NULL DEFAULT '',
        `comments` TEXT NULL,
        `severity` TINYINT(3) NOT NULL DEFAULT 1,
        `url` VARCHAR(255) NOT NULL DEFAULT '',
        `status` VARCHAR(32) NOT NULL DEFAULT 'new',
        `created_at` VARCHAR(64) NOT NULL DEFAULT '',
        `created_ip` VARCHAR(64) NOT NULL DEFAULT '',
        `handled_by` INT(11) NOT NULL DEFAULT 0,
        `handled_at` VARCHAR(64) NOT NULL DEFAULT '',
        `handler_note` TEXT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_mod_requests_status` (`status`),
        KEY `idx_mod_requests_postid` (`postid`),
        KEY `idx_mod_requests_reporterid` (`reporterid`),
        KEY `idx_mod_requests_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'postid' => "ALTER TABLE `mod_requests` ADD `postid` INT(11) NOT NULL DEFAULT 0",
        'topicid' => "ALTER TABLE `mod_requests` ADD `topicid` INT(11) NOT NULL DEFAULT 0",
        'boardid' => "ALTER TABLE `mod_requests` ADD `boardid` INT(11) NOT NULL DEFAULT 0",
        'reporterid' => "ALTER TABLE `mod_requests` ADD `reporterid` INT(11) NOT NULL DEFAULT 0",
        'reported_userid' => "ALTER TABLE `mod_requests` ADD `reported_userid` INT(11) NOT NULL DEFAULT 0",
        'reason_type' => "ALTER TABLE `mod_requests` ADD `reason_type` VARCHAR(64) NOT NULL DEFAULT ''",
        'comments' => "ALTER TABLE `mod_requests` ADD `comments` TEXT NULL",
        'severity' => "ALTER TABLE `mod_requests` ADD `severity` TINYINT(3) NOT NULL DEFAULT 1",
        'url' => "ALTER TABLE `mod_requests` ADD `url` VARCHAR(255) NOT NULL DEFAULT ''",
        'status' => "ALTER TABLE `mod_requests` ADD `status` VARCHAR(32) NOT NULL DEFAULT 'new'",
        'created_at' => "ALTER TABLE `mod_requests` ADD `created_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'created_ip' => "ALTER TABLE `mod_requests` ADD `created_ip` VARCHAR(64) NOT NULL DEFAULT ''",
        'handled_by' => "ALTER TABLE `mod_requests` ADD `handled_by` INT(11) NOT NULL DEFAULT 0",
        'handled_at' => "ALTER TABLE `mod_requests` ADD `handled_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'handler_note' => "ALTER TABLE `mod_requests` ADD `handler_note` TEXT NULL",
    ];

    foreach ($columns as $column => $sql) {
        if (!corebb_mod_requests_column_exists('mod_requests', $column)) {
            db_run($sql);
        }
    }
}

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_mod_request_token(): string
{
    return corebb_security_named_token('corebb_mod_request_token', 16, 'wb_mod_request_token');
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_mod_request_check_token(array $post): bool
{
    return corebb_security_named_token_valid('corebb_mod_request_token', $post, 'mod_request_token', 'wb_mod_request_token');
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $postId Post id.
 * @param bool $includeDeleted Whether deleted rows may be returned.
 * @return array|false Data row when found, otherwise false.
 */
function corebb_mod_request_fetch_post(int $postId, bool $includeDeleted = false): array|false
{
    corebb_mod_ensure_schema();
    $where = 'p.id = ?';
    if (!$includeDeleted) {
        $where .= ' AND p.is_deleted = 0';
    }
    return db_one(
        'SELECT p.id, p.posterid, p.title, p.body, p.author, p.threadid, p.boardid, p.ptd, p.posttime, p.posttimeraw, p.postip, p.is_deleted, p.deleted_at, p.deleted_by, p.delete_reason,
                u.username AS author_username, u.lastip AS user_ip, u.id AS author_userid, u.accesslevel AS author_accesslevel, f.name AS board_name, t.title AS topic_title
         FROM posts p
         LEFT JOIN users u ON u.id = p.posterid
         LEFT JOIN forums f ON f.id = p.boardid
         LEFT JOIN topics t ON t.id = p.threadid AND t.boardid=p.boardid
         WHERE ' . $where . ' LIMIT 1',
        [$postId]
    );
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $requestId Moderation request id.
 * @return array|false Data row when found, otherwise false.
 */
function corebb_mod_request_fetch_request(int $requestId,array $viewer): array|false
{
    corebb_mod_requests_ensure_schema();
    [$visible,$params]=corebb_private_sql_visible_board_clause('f','b',(int)$viewer['id'],(int)$viewer['accesslevel']);
    return db_one(
        'SELECT mr.id, mr.postid, mr.topicid, mr.boardid, mr.reporterid, mr.reported_userid, mr.reason_type, mr.comments, mr.severity, mr.status, mr.created_at, mr.handled_by, mr.handled_at, mr.handler_note,
                reporter.username AS reporter_username, f.name AS board_name, handler.username AS handler_username
         FROM mod_requests mr
         LEFT JOIN users handler ON handler.id=mr.handled_by
         LEFT JOIN users reporter ON reporter.id = mr.reporterid
         JOIN forums f ON f.id = mr.boardid
         LEFT JOIN boards b ON b.id=f.categoryid
         WHERE mr.id = ? AND (' . $visible . ') LIMIT 1',
        [$requestId,...$params]
    );
}

/**
 * Usage: Convert a mod-request status into display text.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $status Request/report status.
 * @return string Normalized or display-ready string.
 */
function corebb_mod_requests_status_label(string $status): string
{
    return match ($status) {
        'resolved' => 'Resolved',
        'ignored' => 'Closed without removal',
        'removed' => 'Removed',
        'banned' => 'Banned',
        default => 'New',
    };
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @return int Numeric result for the caller.
 */
function corebb_mod_requests_new_count(array $viewer): int
{
    corebb_mod_requests_ensure_schema();
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b', (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0));
    return (int)db_value("SELECT COUNT(*) FROM mod_requests mr JOIN forums f ON f.id = mr.boardid LEFT JOIN boards b ON b.id = f.categoryid WHERE mr.status = 'new' AND " . $visibleSql, $visibleParams, 0);
}

/**
 * Usage: Write an audit entry for this admin workflow.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param string $action Human-readable action message.
 * @param string $type Action type key.
 * @param string $description Optional action description.
 * @return void No return value.
 */
function corebb_mod_requests_log(array $viewer, string $action, string $type = 'mod_request', string $description = ''): void
{
    {
        corebb_adminlog_entry((string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), $action, $type, $description !== '' ? $description : $action);
    }
}

/** Bind queue transitions to the reviewed board, post and current state. */
function corebb_mod_report_change(array $viewer,array $report,string $status,string $note): bool
{
    [$visible,$params]=corebb_private_sql_visible_board_clause('f','b',(int)$viewer['id'],(int)$viewer['accesslevel']);
    $reopen=$status==='new';
    $samePost=$status==='removed' ? ' AND EXISTS (SELECT 1 FROM posts p WHERE p.id=mod_requests.postid AND p.threadid=mod_requests.topicid AND p.boardid=mod_requests.boardid)' : '';
    return db_run("UPDATE mod_requests SET status=?,handled_by=?,handled_at=?,handler_note=? WHERE id=? AND postid=? AND topicid=? AND boardid=?
        AND status=? AND EXISTS (SELECT 1 FROM forums f LEFT JOIN boards b ON b.id=f.categoryid WHERE f.id=mod_requests.boardid AND ($visible))$samePost",
        [$status,$reopen?0:$viewer['id'],$reopen?'':date('Y-m-d H:i:s'),$note,$report['id'],$report['postid'],$report['topicid'],$report['boardid'],$report['status'],...$params])
        && corebb_db_last_affected_rows()===1;
}

function corebb_mod_report_decision(array $viewer,array $report,string $action,string $note): array
{
    $reopen=$action==='reopen_request';
    if ($reopen===($report['status']==='new')) { return [false,'The report has already changed. Refresh the queue.']; }
    if (strlen($note)>65535) { return [false,'Shorten the handling note before saving.']; }
    if (!corebb_mod_report_change($viewer,$report,$reopen?'new':'ignored',$reopen?'':trim($note))) {
        return [false,'Could not update the report. Refresh the queue.'];
    }
    corebb_mod_requests_log($viewer,($reopen?'Reopened':'Closed').' mod request '.$report['id'],$reopen?'mod_request_reopened':'mod_request_closed');
    return [true,$reopen?'Report reopened.':'Report closed without removing content.'];
}

/**
 * Usage: Build and process the report post admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_report_post_model(array $viewer, array $get, array $post): array
{
    corebb_mod_requests_ensure_schema();
    $postId = (int)($get['post'] ?? $get['id'] ?? $post['postid'] ?? 0);
    $message = [];
    $errors = [];

    if (!corebb_load_logged_in_user()) {
        return ['missing' => true, 'message' => 'You must be logged in to contact the moderators.'];
    }

    $postRow = corebb_mod_request_fetch_post($postId);
    if ($postRow && !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
        $postRow = false;
    }
    if (!$postRow) {
        return ['missing' => true, 'message' => 'Unknown message ID.'];
    }

    $reporterId = (int)($viewer['id'] ?? 0);
    if ($reporterId <= 0) {
        return ['missing' => true, 'message' => 'You must be logged in to contact the moderators.'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!corebb_mod_request_check_token($post)) {
            $errors[] = 'Invalid request token. Please try again.';
        } else {
            $reason = trim((string)($post['reason_type'] ?? 'TOS_Violation'));
            $allowed = ['TOS_Violation', 'Language', 'Spamming', 'Trolling', 'Harassment', 'Personal_Attack', 'Other'];
            if (!in_array($reason, $allowed, true)) {
                $reason = 'Other';
            }
            $severity = max(1, min(5, (int)($post['severity'] ?? 1)));
            $comments = corebb_mod_request_limit_text(trim((string)($post['comments'] ?? '')), 65535);
            if ($comments === '') {
                $errors[] = 'Please enter a brief reason for the report.';
            }

            $dupeRow = false;
            if (empty($errors)) {
                $dupeRow = db_one("SELECT id FROM mod_requests WHERE postid = ? AND reporterid = ? AND status = 'new' LIMIT 1", [$postId, $reporterId]);
                if ($dupeRow) {
                    $message[] = 'You have already submitted a moderator request for this message.';
                }
            }

            if (!$dupeRow && empty($errors)) {
                $rate = corebb_rate_limit_report_submit($viewer);
                if (empty($rate['allowed'])) {
                    $errors[] = corebb_rate_limit_message($rate, 'post reports');
                }
            }

            if (!$dupeRow && empty($errors)) {
                $topicId = (int)($postRow['threadid'] ?? 0);
                $boardId = (int)($postRow['boardid'] ?? 0);
                $reportedUserId = (int)($postRow['posterid'] ?? 0);
                $url = corebb_mod_request_thread_url($topicId, $boardId, $postId);
                db_run(
                    'INSERT INTO mod_requests (postid, topicid, boardid, reporterid, reported_userid, reason_type, comments, severity, url, status, created_at, created_ip)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$postId, $topicId, $boardId, $reporterId, $reportedUserId, $reason, $comments, $severity, $url, 'new', date('Y-m-d H:i:s'), corebb_mod_current_ip()]
                );
                $message[] = 'Your moderator request has been submitted.';
            }
        }
    }

    $topicId = (int)($postRow['threadid'] ?? 0);
    $boardId = (int)($postRow['boardid'] ?? 0);
    $threadUrl = corebb_mod_request_thread_url($topicId, $boardId, $postId);
    $reportUrl = corebb_public_join_base_path('/report-message/' . $postId . '/');

    return [
        'missing' => false,
        'messages' => $message,
        'errors' => $errors,
        'token' => corebb_mod_request_token(),
        'report_url' => $reportUrl,
        'thread_url' => $threadUrl,
        'post' => [
            'id' => $postId,
            'title' => (string)($postRow['title'] ?? ''),
            'body' => (string)($postRow['body'] ?? ''),
            'body_excerpt' => function_exists('mb_substr') ? mb_substr((string)($postRow['body'] ?? ''), 0, 1200, 'UTF-8') : substr((string)($postRow['body'] ?? ''), 0, 1200),
            'posttime' => (string)($postRow['posttime'] ?? ''),
            'threadid' => $topicId,
            'boardid' => $boardId,
            'author_id' => (int)($postRow['posterid'] ?? 0),
            'author_username' => (string)($postRow['author_username'] ?? 'Unknown'),
            'board_name' => (string)($postRow['board_name'] ?? ''),
            'topic_title' => (string)($postRow['topic_title'] ?? ''),
        ],
    ];
}

/**
 * Usage: Build and process the mod requests admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_mod_requests_model(array $viewer, array $get, array $post): array
{
    if (!corebb_admin_viewer_id($viewer) || !corebb_admin_can_access_tool($viewer,'mod_requests')) { return ['errors'=>['You do not have access to Mod Post Requests.']]; }
    corebb_mod_requests_ensure_schema();
    $messages = [];
    $errors = [];
    $filterContext = corebb_admin_board_filter_context($viewer, array_merge($post, $get));
    $selectedBoardId = (int)$filterContext['selected_board_id'];

    $old = (string)($get['old'] ?? $get['view'] ?? '') === 'yes' || (string)($get['view'] ?? '') === 'old';
    $severity = corebb_mod_request_limit_text(trim((string)($get['severity'] ?? $get['txtSeverityFilter'] ?? $post['txtSeverityFilter'] ?? '')), 8);
    $urlFilter = corebb_mod_request_limit_text(trim((string)($get['url'] ?? $get['txtUrlFilter'] ?? $post['txtUrlFilter'] ?? '')), 255);
    $page = max(1, (int)($get['page'] ?? 1));
    $perPage = 25;
    if (corebb_admin_board_filter_id($get+$post)!==$selectedBoardId || ($severity!=='' && !ctype_digit($severity))) {
        return ['errors'=>['Choose a visible board and numeric severity.'],'board_options'=>$filterContext['board_options'],'old'=>$old];
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        $action=$post['action'] ?? '';
        if (!corebb_security_csrf_valid($post) || !corebb_mod_request_check_token($post) || !in_array($action,['close_request','reopen_request'],true)) {
            $errors[]='Reopen the report before making changes.';
        } else {
            $report=corebb_mod_request_fetch_request((int)($post['request_id'] ?? 0),$viewer);
            if (!$report || ($selectedBoardId && (int)$report['boardid']!==$selectedBoardId)) {
                $errors[]='That report is unavailable.';
            } else {
                [$ok,$notice]=corebb_mod_report_decision($viewer,$report,$action,(string)($post['handler_note'] ?? ''));
                if ($ok) { $messages[]=$notice; } else { $errors[]=$notice; }
            }
        }
    }

    $where = [];
    $params = [];
    if ($old) {
        $where[] = "mr.status <> 'new'";
    } else {
        $where[] = "mr.status = 'new'";
    }
    if ($severity !== '' && ctype_digit($severity)) {
        $where[] = 'mr.severity = ?';
        $params[] = (int)$severity;
    }
    if ($urlFilter !== '') {
        $where[] = 'mr.url LIKE ?';
        $params[] = '%' . $urlFilter . '%';
    }
    if ($selectedBoardId > 0) {
        $where[] = 'mr.boardid = ?';
        $params[] = $selectedBoardId;
    }
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b', (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0));
    $where[] = $visibleSql;
    $params = array_merge($params, $visibleParams);
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $total = db_value("SELECT COUNT(*) FROM mod_requests mr JOIN forums f ON f.id = mr.boardid LEFT JOIN boards b ON b.id = f.categoryid {$whereSql}", $params);
    if ($total === null) { return ['errors'=>['Could not load the report queue.']]; }
    $total = (int)$total;
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = [];
    $sql = "SELECT mr.id, mr.postid, mr.reporterid, mr.reason_type, mr.severity, mr.status, mr.created_at,
                   reporter.username AS reporter_username, p.title AS post_title,
                   f.name AS board_name
            FROM mod_requests mr
            LEFT JOIN users reporter ON reporter.id = mr.reporterid
            LEFT JOIN posts p ON p.id = mr.postid AND p.boardid=mr.boardid AND p.threadid=mr.topicid
            JOIN forums f ON f.id = mr.boardid
            LEFT JOIN boards b ON b.id = f.categoryid
            {$whereSql}
            ORDER BY CAST(mr.severity AS UNSIGNED) DESC, mr.id DESC
            LIMIT {$offset}, {$perPage}";
    $records = db_all($sql,$params);
    if (db_error() !== '') { return ['errors'=>['Could not load the report queue.']]; }
    foreach ($records as $row) {
        $row['_status_label'] = corebb_mod_requests_status_label((string)($row['status'] ?? 'new'));
        $row['_moderate_url'] = '/admin/?act=moderate_message&mes=' . (int)($row['postid'] ?? 0) . '&req=' . (int)($row['id'] ?? 0);
        $row['_post_title_label'] = (string)($row['post_title'] ?? '') !== '' ? (string)$row['post_title'] : 'Report on post #' . (int)$row['postid'];
        $row['_reporter_label'] = (string)($row['reporter_username'] ?? '') !== '' ? (string)$row['reporter_username'] : '#' . (int)($row['reporterid'] ?? 0);
        $row['_reason_label'] = str_replace('_', ' ', (string)($row['reason_type'] ?? ''));
        $rows[] = $row;
    }

    $baseParams = ['act' => 'mod_requests'];
    if ($old) {
        $baseParams['old'] = 'yes';
    }
    if ($severity !== '') {
        $baseParams['severity'] = $severity;
    }
    if ($urlFilter !== '') {
        $baseParams['url'] = $urlFilter;
    }
    if ($selectedBoardId > 0) {
        $baseParams['boardid'] = $selectedBoardId;
    }
    $base = '/admin/?' . http_build_query($baseParams, '', '&', PHP_QUERY_RFC3986) . '&page={page}';
    $actionParams = $baseParams;
    $actionParams['page'] = $page;
    $actionUrl = '/admin/?' . http_build_query($actionParams, '', '&', PHP_QUERY_RFC3986);
    $context=$actionParams;
    unset($context['act']);
    foreach ($rows as &$item) { $item['_moderate_url'].='&'.http_build_query($context); }
    unset($item);
    $newLinkParams = $baseParams;
    unset($newLinkParams['old']);
    $newRequestsUrl = '/admin/?' . http_build_query($newLinkParams, '', '&', PHP_QUERY_RFC3986);
    $oldLinkParams = $baseParams;
    $oldLinkParams['old'] = 'yes';
    $oldRequestsUrl = '/admin/?' . http_build_query($oldLinkParams, '', '&', PHP_QUERY_RFC3986);

    return [
        'saved' => (bool)$messages && !$errors,
        'messages' => $messages,
        'errors' => $errors,
        'token' => corebb_mod_request_token(),
        'old' => $old,
        'severity' => $severity,
        'url_filter' => $urlFilter,
        'selected_board_id' => $selectedBoardId,
        'selected_board_label' => (string)$filterContext['selected_board_label'],
        'board_options' => $filterContext['board_options'],
        'action_url' => $actionUrl,
        'new_requests_url' => $newRequestsUrl,
        'old_requests_url' => $oldRequestsUrl,
        'requests' => $rows,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'pagination' => corebb_pagination_model($totalPages > 1 ? $base : '', $page, $totalPages, 'BoardRowBLink'),
    ];
}

/**
 * Usage: Build and process the moderate message admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_moderate_message_model(array $viewer,array $get,array $post): array
{
    if (!corebb_admin_viewer_id($viewer) || !corebb_admin_can_access_tool($viewer,'mod_requests')) {
        return ['missing'=>true,'errors'=>['You do not have access to Mod Post Requests.']];
    }
    corebb_mod_requests_ensure_schema();
    $id=(int)($get['req'] ?? $post['request_id'] ?? 0);
    $postId=(int)($get['mes'] ?? $get['post'] ?? $post['postid'] ?? 0);
    $params=array_intersect_key($get,array_flip(['boardid','severity','url','old','page']));
    $params=array_filter($params,'is_scalar');
    $suffix=$params?'&'.http_build_query($params):'';
    $m=['queue_url'=>'/admin/?act=mod_requests'.$suffix,'token'=>corebb_mod_request_token(),'errors'=>[]];
    $report=$id>0 ? corebb_mod_request_fetch_request($id,$viewer) : null;
    if ($id && (!$report || ($postId && $postId!==(int)$report['postid']))) {
        return $m+['missing'=>true,'message'=>'This report is unavailable.'];
    }
    if ($report) { $postId=(int)$report['postid']; }
    $row=$postId>0 ? corebb_mod_request_fetch_post($postId,true) : null;
    if ($row && (!corebb_private_user_can_view_board_id((int)$row['boardid'],(int)$viewer['id'],(int)$viewer['accesslevel'])
        || ($report && ((int)$report['boardid']!==(int)$row['boardid'] || (int)$report['topicid']!==(int)$row['threadid'])))) { $row=null; }
    if (!$row && !$report) { return $m+['missing'=>true,'message'=>'This message is unavailable.']; }
    $active=$row && !$row['is_deleted'] && (!$report || $report['status']==='new');
    $topicId=(int)($row['threadid'] ?? 0);
    $boardId=(int)($row['boardid'] ?? 0);
    $author=(int)($row['posterid'] ?? 0);
    $topic=$active ? corebb_mod_get_topic($topicId,true) : null;
    $write=$active && $topic && !$topic['is_deleted'] && (int)$topic['boardid']===$boardId
        && corebb_secure_archive_user_can_write_board_id($boardId,(int)$viewer['accesslevel']);
    $first=$write ? (int)db_value('SELECT MIN(id) FROM posts WHERE threadid=? AND is_deleted=0',[$topicId]) : 0;
    $canPost=$write && $first>0 && $first!==$postId && corebb_mod_author_rank_error($author)==='';
    $canTopic=$write && corebb_mod_topic_rank_error($topicId)==='' && ($first!==$postId || corebb_mod_author_rank_error($author)==='');
    $m['action_url']='/admin/?act=moderate_message&mes='.$postId.($id?'&req='.$id:'').$suffix;
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        $action=$post['action'] ?? '';
        $m['draft_note']=is_string($post['handler_note'] ?? '') ? trim($post['handler_note'] ?? '') : '';
        if (!is_string($post['handler_note'] ?? '') || strlen($m['draft_note'])>65535) {
            $m['errors'][]='Enter a shorter handling note.';
        } elseif (!corebb_security_csrf_valid($post) || !corebb_mod_request_check_token($post)
            || (isset($post['postid']) && (int)$post['postid']!==$postId)
            || (isset($post['request_id']) && (int)$post['request_id']!==$id)) {
            $m['errors'][]='Reopen the review before making changes.';
        } elseif ($report && in_array($action,['close_request','reopen_request'],true)) {
            [$ok,$message]=corebb_mod_report_decision($viewer,$report,$action,$m['draft_note']);
            if ($ok) { return $m+['saved'=>true,'messages'=>[$message],'return_url'=>$m['queue_url']]; }
            $m['errors'][]=$message;
        } elseif (($canPost && $action==='remove_message') || ($canTopic && $action==='remove_topic')) {
            $note=$m['draft_note']!=='' ? $m['draft_note'] : ($action==='remove_topic'?'Topic removed.':'Message removed.');
            $transition=$report ? static fn():bool => corebb_mod_report_change($viewer,$report,'removed',$note) : null;
            $result=$action==='remove_topic' ? corebb_mod_remove_topic($topicId,'Removed from Mod Requests',$transition)
                : corebb_mod_remove_post($postId,'',$transition,false);
            if ($result['ok']) {
                if ($action==='remove_topic') { corebb_mod_requests_log($viewer,"Moved topic {$topicId} to deleted-posts bin",'remove_topic'); }
                return $m+['saved'=>true,'messages'=>[$result['message']],'return_url'=>$m['queue_url']];
            }
            $m['errors'][]='Could not complete the removal. Refresh the report and check its status.';
        } else {
            $m['errors'][]='That action is unavailable for this message or report.';
        }
    }
    $access=[];
    foreach (['user_pages','admin_notes','user_ip_check','host_lookup','moderation_ban'] as $tool) {
        $access[$tool]=corebb_admin_can_access_tool($viewer,$tool);
    }
    if ($report) {
        $m['report']=[
            'id'=>$id,'status'=>$report['status'],'label'=>corebb_mod_requests_status_label($report['status']),
            'reporter'=>$report['reporter_username'] ?: 'User #'.$report['reporterid'],'reporterid'=>(int)$report['reporterid'],
            'reason'=>str_replace('_',' ',$report['reason_type']),'comments'=>$report['comments'],'severity'=>$report['severity'],
            'created'=>$report['created_at'],'handled'=>$report['handled_at'],'note'=>$report['handler_note'],
            'handler'=>$report['handler_username'] ?: ($report['handled_by']?'User #'.$report['handled_by']:''),
            'board'=>$report['board_name'],'postid'=>$postId,'reported_userid'=>(int)$report['reported_userid'],
        ];
    }
    if ($row) {
        $network=$access['user_ip_check'] || $access['host_lookup'];
        $access['moderation_ban'] = $access['moderation_ban'] && $row['author_userid']
            && $author !== (int)$viewer['id'] && (int)$row['author_accesslevel'] < (int)$viewer['accesslevel'];
        $m['post']=[
            'id'=>$postId,'author_id'=>$author,'author'=>$row['author_username'] ?: ($row['author'] ?: 'Unknown author'),
            'title'=>$row['title'] ?: 'Untitled message','body'=>$row['body'],'level'=>(int)$row['author_accesslevel'],
            'date'=>convert_to_vndate((string)$row['posttime']),'deleted'=>(bool)$row['is_deleted'],
            'board'=>$row['board_name'],'topic'=>$row['topic_title'],
            'thread_url'=>corebb_notifications_reply_target_url($topicId,$boardId,$postId,(string)$row['board_name']),
            'user_ip'=>$network?corebb_admin_display_ip((string)$row['user_ip']):'',
            'post_ip'=>$network?corebb_admin_display_ip((string)$row['postip']):'',
        ];
    }
    return $m+['access'=>$access,'can_edit'=>$write && (int)$viewer['accesslevel']>=3 && corebb_mod_author_rank_error($author)==='',
        'can_remove_post'=>$canPost,'can_remove_topic'=>$canTopic,'opening_post'=>$first>0 && $first===$postId];
}
