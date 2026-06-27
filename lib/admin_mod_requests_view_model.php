<?php
require_once __DIR__ . '/corebb_date_helpers.php';
require_once __DIR__ . '/admin_log_helpers.php';
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

require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/rate_limit_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/corebb_route_helpers.php';
require_once __DIR__ . '/admin_board_filter_helpers.php';

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
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['corebb_mod_request_token'])) {
        if (!empty($_SESSION['wb_mod_request_token']) && is_string($_SESSION['wb_mod_request_token'])) {
            $_SESSION['corebb_mod_request_token'] = (string)$_SESSION['wb_mod_request_token'];
        } else {
            $_SESSION['corebb_mod_request_token'] = bin2hex(random_bytes(16));
        }
    }
    return (string)$_SESSION['corebb_mod_request_token'];
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
    $token = (string)($post['mod_request_token'] ?? '');
    return $token !== '' && hash_equals(corebb_mod_request_token(), $token);
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
         LEFT JOIN topics t ON t.id = p.threadid
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
function corebb_mod_request_fetch_request(int $requestId): array|false
{
    corebb_mod_requests_ensure_schema();
    return db_one(
        'SELECT mr.id, mr.postid, mr.topicid, mr.boardid, mr.reporterid, mr.reported_userid, mr.reason_type, mr.comments, mr.severity, mr.url, mr.status, mr.created_at, mr.created_ip, mr.handled_by, mr.handled_at, mr.handler_note,
                reporter.username AS reporter_username, author.username AS author_username, author.lastip AS author_lastip, author.accesslevel AS author_accesslevel,
                p.title AS post_title, p.body AS post_body, p.posttime AS post_time, p.posttimeraw AS post_time_raw,
                p.postip AS post_ip, p.posterid AS post_author_id, p.threadid AS post_topic_id, p.boardid AS post_board_id,
                f.name AS board_name, t.title AS topic_title
         FROM mod_requests mr
         LEFT JOIN users reporter ON reporter.id = mr.reporterid
         LEFT JOIN users author ON author.id = mr.reported_userid
         LEFT JOIN posts p ON p.id = mr.postid
         LEFT JOIN forums f ON f.id = mr.boardid
         LEFT JOIN topics t ON t.id = mr.topicid
         WHERE mr.id = ? LIMIT 1',
        [$requestId]
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
        'ignored' => 'Ignored',
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
    return (int)db_value("SELECT COUNT(*) FROM mod_requests mr LEFT JOIN forums f ON f.id = mr.boardid LEFT JOIN boards b ON b.id = f.categoryid WHERE mr.status = 'new' AND " . $visibleSql, $visibleParams, 0);
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

/**
 * Usage: Close a moderation request with reviewer metadata.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $requestId Moderation request id.
 * @param int $viewerId Viewer user id.
 * @param string $status Request/report status.
 * @param string $note Admin note or resolution text.
 * @return void No return value.
 */
function corebb_mod_requests_close(int $requestId, int $viewerId, string $status, string $note = ''): void
{
    corebb_mod_requests_ensure_schema();
    if ($requestId <= 0 || $viewerId <= 0) {
        return;
    }
    $allowed = ['new', 'resolved', 'ignored', 'removed', 'banned'];
    if (!in_array($status, $allowed, true)) {
        $status = 'ignored';
    }
    $note = corebb_mod_request_limit_text(trim($note), 65535);
    db_run(
        'UPDATE mod_requests SET status = ?, handled_by = ?, handled_at = ?, handler_note = ? WHERE id = ?',
        [$status, $viewerId, date('Y-m-d H:i:s'), $note, $requestId]
    );
}

/**
 * Usage: Move a moderation request back to pending.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $requestId Moderation request id.
 * @return void No return value.
 */
function corebb_mod_requests_reopen(int $requestId): void
{
    corebb_mod_requests_ensure_schema();
    if ($requestId <= 0) {
        return;
    }
    db_run(
        "UPDATE mod_requests SET status = 'new', handled_by = 0, handled_at = '', handler_note = '' WHERE id = ?",
        [$requestId]
    );
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $topicId Topic id.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_mod_requests_remove_topic(int $topicId, array $viewer): array
{
    $result = corebb_mod_remove_topic($topicId, 'Removed from Mod Requests');
    if (!empty($result['ok'])) {
        corebb_mod_requests_log($viewer, "Moved topic {$topicId} to deleted-posts bin", 'remove_topic', "Topic {$topicId} moved to deleted-posts bin from Mod Requests");
    }
    return $result;
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
    corebb_mod_requests_ensure_schema();
    $messages = [];
    $errors = [];
    $viewerId = (int)($viewer['id'] ?? 0);
    $filterContext = corebb_admin_board_filter_context($viewer, array_merge($post, $get));
    $selectedBoardId = (int)$filterContext['selected_board_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($post['action'] ?? '');
        $requestId = (int)($post['request_id'] ?? 0);
        if (!corebb_mod_request_check_token($post)) {
            $errors[] = 'Invalid request token. Please try again.';
        } elseif ($viewerId <= 0) {
            $errors[] = 'Unknown admin user.';
        } elseif ($requestId <= 0) {
            $errors[] = 'Unknown request ID.';
        } elseif ($action === 'close_request') {
            corebb_mod_requests_close($requestId, $viewerId, 'ignored', corebb_mod_request_limit_text((string)($post['handler_note'] ?? ''), 65535));
            corebb_mod_requests_log($viewer, "Closed mod request {$requestId}", 'mod_request_closed');
            $messages[] = 'Mod request closed.';
        } elseif ($action === 'reopen_request') {
            corebb_mod_requests_reopen($requestId);
            corebb_mod_requests_log($viewer, "Reopened mod request {$requestId}", 'mod_request_reopened');
            $messages[] = 'Mod request reopened.';
        }
    }

    $old = (string)($get['old'] ?? $get['view'] ?? '') === 'yes' || (string)($get['view'] ?? '') === 'old';
    $severity = corebb_mod_request_limit_text(trim((string)($get['severity'] ?? $get['txtSeverityFilter'] ?? $post['txtSeverityFilter'] ?? '')), 8);
    $urlFilter = corebb_mod_request_limit_text(trim((string)($get['url'] ?? $get['txtUrlFilter'] ?? $post['txtUrlFilter'] ?? '')), 255);
    $page = max(1, (int)($get['page'] ?? 1));
    $perPage = 25;

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

    $total = (int)db_value("SELECT COUNT(*) FROM mod_requests mr LEFT JOIN forums f ON f.id = mr.boardid LEFT JOIN boards b ON b.id = f.categoryid {$whereSql}", $params, 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = [];
    $sql = "SELECT mr.id, mr.postid, mr.topicid, mr.boardid, mr.reporterid, mr.reported_userid, mr.reason_type, mr.comments, mr.severity, mr.url, mr.status, mr.created_at, mr.created_ip, mr.handled_by, mr.handled_at, mr.handler_note,
                   reporter.username AS reporter_username, author.username AS author_username,
                   p.title AS post_title, p.body AS post_body, p.posttime AS post_time,
                   f.name AS board_name
            FROM mod_requests mr
            LEFT JOIN users reporter ON reporter.id = mr.reporterid
            LEFT JOIN users author ON author.id = mr.reported_userid
            LEFT JOIN posts p ON p.id = mr.postid
            LEFT JOIN forums f ON f.id = mr.boardid
            LEFT JOIN boards b ON b.id = f.categoryid
            {$whereSql}
            ORDER BY CAST(mr.severity AS UNSIGNED) DESC, mr.id DESC
            LIMIT {$offset}, {$perPage}";
    foreach (db_all($sql, $params) as $row) {
        $row['_status_label'] = corebb_mod_requests_status_label((string)($row['status'] ?? 'new'));
        $row['_moderate_url'] = '/admin/?act=moderate_message&mes=' . (int)($row['postid'] ?? 0) . '&req=' . (int)($row['id'] ?? 0);
        $row['_thread_url'] = corebb_mod_request_display_url(
            (string)($row['url'] ?? ''),
            (int)($row['topicid'] ?? 0),
            (int)($row['boardid'] ?? 0),
            (int)($row['postid'] ?? 0)
        );
        $row['_user_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['author_username'] ?? $row['reported_userid'] ?? ''));
        $row['_reporter_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['reporter_username'] ?? $row['reporterid'] ?? ''));
        $row['_post_title_label'] = (string)($row['post_title'] ?? '') !== '' ? (string)$row['post_title'] : 'View Message';
        $row['_reporter_label'] = (string)($row['reporter_username'] ?? '') !== '' ? (string)$row['reporter_username'] : '#' . (int)($row['reporterid'] ?? 0);
        $row['_author_label'] = (string)($row['author_username'] ?? '') !== '' ? (string)$row['author_username'] : '#' . (int)($row['reported_userid'] ?? 0);
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
    $pageParams = $baseParams;
    $pageParams['page'] = '{page}';
    $base = '/admin/?' . http_build_query($pageParams, '', '&', PHP_QUERY_RFC3986);
    $actionParams = $baseParams;
    $actionParams['page'] = $page;
    $actionUrl = '/admin/?' . http_build_query($actionParams, '', '&', PHP_QUERY_RFC3986);
    $newLinkParams = $baseParams;
    unset($newLinkParams['old']);
    $newRequestsUrl = '/admin/?' . http_build_query($newLinkParams, '', '&', PHP_QUERY_RFC3986);
    $oldLinkParams = $baseParams;
    $oldLinkParams['old'] = 'yes';
    $oldRequestsUrl = '/admin/?' . http_build_query($oldLinkParams, '', '&', PHP_QUERY_RFC3986);

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
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
function corebb_admin_moderate_message_model(array $viewer, array $get, array $post): array
{
    corebb_mod_requests_ensure_schema();
    $messages = [];
    $errors = [];
    $viewerId = (int)($viewer['id'] ?? 0);
    $requestId = (int)($get['req'] ?? $post['request_id'] ?? 0);
    $postId = (int)($get['mes'] ?? $get['post'] ?? $post['postid'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($post['action'] ?? '');
        if (!corebb_mod_request_check_token($post)) {
            $errors[] = 'Invalid request token. Please try again.';
        } elseif ($viewerId <= 0) {
            $errors[] = 'Unknown admin user.';
        } elseif ($action === 'close_request' && $requestId > 0) {
            corebb_mod_requests_close($requestId, $viewerId, 'ignored', corebb_mod_request_limit_text((string)($post['handler_note'] ?? ''), 65535));
            corebb_mod_requests_log($viewer, "Closed mod request {$requestId}", 'mod_request_closed');
            $messages[] = 'Mod request closed.';
        } elseif ($action === 'remove_message' && $postId > 0) {
            $postRow = corebb_mod_request_fetch_post($postId);
            if (!$postRow || !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
                $errors[] = 'Unknown message ID.';
                $result = ['message' => 'Unknown message ID.'];
            } else {
                $result = corebb_mod_remove_post($postId);
            }
            if ($requestId > 0 && empty($errors)) {
                corebb_mod_requests_close($requestId, $viewerId, 'removed', 'Message removed.');
            }
            $messages[] = (string)($result['message'] ?? 'Message removed.');
        } elseif ($action === 'remove_topic' && $postId > 0) {
            $postRow = corebb_mod_request_fetch_post($postId);
            if (!$postRow || !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
                $errors[] = 'Unknown message ID.';
            } else {
                $topicId = (int)($postRow['threadid'] ?? 0);
                $result = corebb_mod_requests_remove_topic($topicId, $viewer);
                if ($requestId > 0 && !empty($result['ok'])) {
                    corebb_mod_requests_close($requestId, $viewerId, 'removed', 'Topic removed.');
                }
                $messages[] = (string)($result['message'] ?? 'Topic removed.');
            }
        }
    }

    $requestRow = $requestId > 0 ? corebb_mod_request_fetch_request($requestId) : false;
    if ($requestRow && $postId <= 0) {
        $postId = (int)($requestRow['postid'] ?? 0);
    }
    $postRow = corebb_mod_request_fetch_post($postId);

    if (!$postRow && $requestRow) {
        $postRow = [
            'id' => (int)($requestRow['postid'] ?? 0),
            'threadid' => (int)($requestRow['topicid'] ?? 0),
            'boardid' => (int)($requestRow['boardid'] ?? 0),
            'posterid' => (int)($requestRow['reported_userid'] ?? 0),
            'author_username' => (string)($requestRow['author_username'] ?? 'Unknown'),
            'author_accesslevel' => (int)($requestRow['author_accesslevel'] ?? 0),
            'user_ip' => (string)($requestRow['author_lastip'] ?? ''),
            'postip' => '',
            'title' => (string)($requestRow['post_title'] ?? '[Message removed]'),
            'body' => (string)($requestRow['post_body'] ?? ''),
            'posttime' => (string)($requestRow['post_time'] ?? ''),
        ];
    }

    if ($postRow && !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
        $postRow = false;
    }

    if (!$postRow) {
        return [
            'viewer' => $viewer,
            'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
            'missing' => true,
            'messages' => $messages,
            'errors' => $errors,
            'message' => 'Unknown message ID.',
            'token' => corebb_mod_request_token(),
        ];
    }

    $authorId = (int)($postRow['posterid'] ?? $postRow['author_userid'] ?? 0);
    $authorName = trim((string)($postRow['author_username'] ?? $postRow['author'] ?? ''));
    $userIp = (string)($postRow['user_ip'] ?? '');
    $messageIp = (string)($postRow['postip'] ?? '');
    $topicId = (int)($postRow['threadid'] ?? 0);
    $threadUrl = corebb_mod_request_thread_url($topicId, (int)($postRow['boardid'] ?? 0), $postId);
    $actionUrl = '/admin/?act=moderate_message&mes=' . $postId . ($requestId > 0 ? '&req=' . $requestId : '');
    $preparedRequest = null;
    if (is_array($requestRow)) {
        $reporterId = (int)($requestRow['reporterid'] ?? 0);
        $reporterName = trim((string)($requestRow['reporter_username'] ?? ''));
        $preparedRequest = $requestRow;
        $preparedRequest['_reporter_label'] = $reporterName !== '' ? $reporterName : '#' . $reporterId;
        $preparedRequest['_reporter_url'] = '/admin/?act=user_pages&usr=' . rawurlencode($reporterName !== '' ? $reporterName : (string)$reporterId);
        $preparedRequest['_reason_label'] = str_replace('_', ' ', (string)($requestRow['reason_type'] ?? ''));
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'missing' => false,
        'messages' => $messages,
        'errors' => $errors,
        'token' => corebb_mod_request_token(),
        'request_id' => $requestId,
        'request' => $preparedRequest,
        'action_url' => $actionUrl,
        'can_close_request' => $requestId > 0 && is_array($requestRow) && (string)($requestRow['status'] ?? 'new') === 'new',
        'post' => [
            'id' => $postId,
            'topicid' => $topicId,
            'boardid' => (int)($postRow['boardid'] ?? 0),
            'author_id' => $authorId,
            'author_username' => $authorName !== '' ? $authorName : 'Unknown',
            'author_accesslevel' => (int)($postRow['author_accesslevel'] ?? 0),
            'author_url' => '/admin/?act=user_pages&usr=' . rawurlencode($authorName !== '' ? $authorName : (string)$authorId),
            'title' => (string)($postRow['title'] ?? ''),
            'body' => (string)($postRow['body'] ?? ''),
            'posttime' => (string)($postRow['posttime'] ?? ''),
            'posttime_label' => ($postRow['posttime'] ?? '') !== '' ? convert_to_vndate((string)$postRow['posttime']) : (string)($postRow['posttime'] ?? ''),
            'user_ip' => $userIp,
            'post_ip' => $messageIp,
            'thread_url' => $threadUrl,
            'admin_notes_url' => '/admin/?act=admin_notes&uid=' . $authorId,
            'user_page_url' => '/admin/?act=user_pages&usr=' . rawurlencode((string)($postRow['author_username'] ?? $authorId)),
            'user_ip_check_url' => '/admin/?act=user_ip_check&ip=' . rawurlencode($userIp),
            'user_host_lookup_url' => '/admin/?act=host_lookup&ip_address=' . rawurlencode($userIp),
            'post_ip_check_url' => '/admin/?act=user_ip_check&ip=' . rawurlencode($messageIp),
            'post_host_lookup_url' => '/admin/?act=host_lookup&ip_address=' . rawurlencode($messageIp),
            'ban_url' => '/admin/?act=moderation&mode=ban&q=' . rawurlencode((string)($postRow['author_username'] ?? $authorId)),
            'edit_url' => '/post/edit/' . $postId . '/?mod=1',
        ],
    ];
}
?>
