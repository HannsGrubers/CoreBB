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
 |  admin_pm_moderation_view_model.php  -                |
 |  Admin/moderator private-message moderation tools.    |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/pm_helpers.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';

/**
 * Usage: Escape private-message moderation text for display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_pm_mod_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Normalized or display-ready string.
 */
function corebb_pm_mod_limit_text(string $value, int $maxBytes): string
{
    $value = trim($value);
    if ($maxBytes > 0 && strlen($value) > $maxBytes) {
        return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxBytes, 'UTF-8') : substr($value, 0, $maxBytes);
    }
    return $value;
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
function corebb_pm_mod_log(array $viewer, string $action, string $type = 'pm_moderation', string $description = ''): void
{
    {
        corebb_adminlog_entry((string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), $action, $type, $description !== '' ? $description : $action);
    }
}

/**
 * Usage: Load a PM participant access level.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return int Numeric result for the caller.
 */
function corebb_pm_mod_user_level(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    return (int)db_value('SELECT accesslevel FROM users WHERE id = ? LIMIT 1', [$userId], 0);
}

/**
 * Usage: Check whether this admin action is allowed.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $pm Private-message row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_pm_mod_can_act_on_pm(array $viewer, array $pm): array
{
    $viewerLevel = (int)($viewer['accesslevel'] ?? 0);
    if ($viewerLevel >= 5) {
        return [true, ''];
    }
    if ($viewerLevel < 3) {
        return [false, 'Only moderators and above can moderate reported private messages.'];
    }

    $senderLevel = isset($pm['sender_accesslevel']) ? (int)$pm['sender_accesslevel'] : corebb_pm_mod_user_level((int)($pm['senderid'] ?? 0));
    $receiverLevel = isset($pm['receiver_accesslevel']) ? (int)$pm['receiver_accesslevel'] : corebb_pm_mod_user_level((int)($pm['recieveid'] ?? 0));
    if ($senderLevel >= $viewerLevel || $receiverLevel >= $viewerLevel) {
        return [false, 'You cannot moderate private messages involving an equal-or-higher-ranked user.'];
    }
    return [true, ''];
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $reportId Private-message report id.
 * @return array|false Data row when found, otherwise false.
 */
function corebb_pm_mod_fetch_report(int $reportId): array|false
{
    corebb_pm_ensure_moderation_schema();
    if ($reportId <= 0) {
        return false;
    }
    $deletedColumns = corebb_pm_deleted_select_columns('pm');
    $deletedByJoin = corebb_pm_deleted_by_supported() ? 'LEFT JOIN users deleter ON deleter.id = pm.deleted_by' : '';
    $deletedByUsername = corebb_pm_deleted_by_supported() ? 'deleter.username AS deleted_by_username' : "'' AS deleted_by_username";
    return db_one(
        "SELECT pr.id, pr.pmid, pr.reporterid, pr.reported_userid, pr.senderid, pr.recieveid, pr.reason_type, pr.comments, pr.severity, pr.status, pr.created_at, pr.created_ip, pr.handled_by, pr.handled_at, pr.handler_note,
                reporter.username AS reporter_username, reported.username AS reported_username,
                sender.username AS sender_username, sender.accesslevel AS sender_accesslevel,
                receiver.username AS receiver_username, receiver.accesslevel AS receiver_accesslevel,
                handler.username AS handler_username,
                pm.title, pm.message, pm.markread, pm.datesent, {$deletedColumns},
                {$deletedByUsername}
           FROM pm_reports pr
           LEFT JOIN users reporter ON reporter.id = pr.reporterid
           LEFT JOIN users reported ON reported.id = pr.reported_userid
           LEFT JOIN users sender ON sender.id = pr.senderid
           LEFT JOIN users receiver ON receiver.id = pr.recieveid
           LEFT JOIN users handler ON handler.id = pr.handled_by
           LEFT JOIN privatemessages pm ON pm.id = pr.pmid
           {$deletedByJoin}
          WHERE pr.id = ? LIMIT 1",
        [$reportId]
    );
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $pmId Private-message id.
 * @return array|false Data row when found, otherwise false.
 */
function corebb_pm_mod_fetch_pm(int $pmId): array|false
{
    corebb_pm_ensure_moderation_schema();
    if ($pmId <= 0) {
        return false;
    }
    $deletedColumns = corebb_pm_deleted_select_columns('pm');
    $deletedByJoin = corebb_pm_deleted_by_supported() ? 'LEFT JOIN users deleter ON deleter.id = pm.deleted_by' : '';
    $deletedByUsername = corebb_pm_deleted_by_supported() ? 'deleter.username AS deleted_by_username' : "'' AS deleted_by_username";
    return db_one(
        "SELECT pm.id, pm.senderid, pm.recieveid, pm.title, pm.message, pm.markread, pm.datesent, {$deletedColumns},
                sender.username AS sender_username, sender.accesslevel AS sender_accesslevel,
                receiver.username AS receiver_username, receiver.accesslevel AS receiver_accesslevel,
                {$deletedByUsername}
           FROM privatemessages pm
           LEFT JOIN users sender ON sender.id = pm.senderid
           LEFT JOIN users receiver ON receiver.id = pm.recieveid
           {$deletedByJoin}
          WHERE pm.id = ? LIMIT 1",
        [$pmId]
    );
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $reportId Private-message report id.
 * @param int $viewerId Viewer user id.
 * @param string $status Request/report status.
 * @param string $note Admin note or resolution text.
 * @return void No return value.
 */
function corebb_pm_mod_close_report(int $reportId, int $viewerId, string $status, string $note = ''): void
{
    corebb_pm_ensure_moderation_schema();
    if ($reportId <= 0 || $viewerId <= 0) {
        return;
    }
    $allowed = ['new', 'ignored', 'deleted', 'resolved'];
    if (!in_array($status, $allowed, true)) {
        $status = 'ignored';
    }
    db_run(
        'UPDATE pm_reports SET status = ?, handled_by = ?, handled_at = ?, handler_note = ? WHERE id = ?',
        [$status, $viewerId, date('Y-m-d H:i:s'), corebb_pm_mod_limit_text($note, 65535), $reportId]
    );
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $pmId Private-message id.
 * @param array $viewer Current admin user row.
 * @param string $reason Moderation/admin reason text.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_pm_mod_soft_delete_pm(int $pmId, array $viewer, string $reason = ''): array
{
    corebb_pm_ensure_moderation_schema();
    if (!corebb_pm_soft_delete_supported()) {
        return ['ok' => false, 'message' => 'Private-message moderation storage is not ready yet. Refresh the admin page once, then try again.'];
    }
    $pm = corebb_pm_mod_fetch_pm($pmId);
    if (!$pm) {
        return ['ok' => false, 'message' => 'Unknown private message ID.'];
    }
    [$canAct, $reasonBlocked] = corebb_pm_mod_can_act_on_pm($viewer, $pm);
    if (!$canAct) {
        return ['ok' => false, 'message' => $reasonBlocked];
    }
    if ((int)($pm['is_deleted'] ?? 0) === 1) {
        return ['ok' => true, 'message' => 'Private message is already deleted.'];
    }
    $viewerId = (int)($viewer['id'] ?? 0);
    $reason = corebb_pm_mod_limit_text($reason !== '' ? $reason : 'Deleted by staff moderation.', 65535);
    $ok = db_run(
        'UPDATE privatemessages SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ?',
        [date('Y-m-d H:i:s'), $viewerId, $reason, $pmId]
    );
    if (!$ok) {
        return ['ok' => false, 'message' => 'Error deleting private message: ' . db_error()];
    }
    db_run(
        "UPDATE pm_reports SET status = 'deleted', handled_by = ?, handled_at = ?, handler_note = ? WHERE pmid = ? AND status = 'new'",
        [$viewerId, date('Y-m-d H:i:s'), $reason, $pmId]
    );
    corebb_pm_mod_log($viewer, "Deleted private message {$pmId}", 'pm_delete', "Private message {$pmId} moved to moderation-deleted state");
    return ['ok' => true, 'message' => 'Private message deleted from user mailboxes. It remains visible to administrators.'];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $pmId Private-message id.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_pm_mod_restore_pm(int $pmId, array $viewer): array
{
    corebb_pm_ensure_moderation_schema();
    if ((int)($viewer['accesslevel'] ?? 0) < 5) {
        return ['ok' => false, 'message' => 'Only administrators can restore deleted private messages.'];
    }
    if (!corebb_pm_soft_delete_supported()) {
        return ['ok' => false, 'message' => 'Private-message moderation storage is not ready yet. Refresh the admin page once, then try again.'];
    }
    $pm = corebb_pm_mod_fetch_pm($pmId);
    if (!$pm) {
        return ['ok' => false, 'message' => 'Unknown private message ID.'];
    }
    if ((int)($pm['is_deleted'] ?? 0) === 0) {
        return ['ok' => true, 'message' => 'Private message is already active.'];
    }
    if (!db_run("UPDATE privatemessages SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = '' WHERE id = ?", [$pmId])) {
        return ['ok' => false, 'message' => 'Error restoring private message: ' . db_error()];
    }
    corebb_pm_mod_log($viewer, "Restored private message {$pmId}", 'pm_restore', "Private message {$pmId} restored to user mailboxes");
    return ['ok' => true, 'message' => 'Private message restored.'];
}

/**
 * Usage: Add display links and labels to one PM report row.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $row Database row being normalized for display.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_pm_mod_enrich_report_row(array $row): array
{
    $row['_status_label'] = match ((string)($row['status'] ?? 'new')) {
        'ignored' => 'Cancelled',
        'deleted' => 'Deleted',
        'resolved' => 'Resolved',
        default => 'New',
    };
    $row['_moderate_url'] = '/admin/?act=pm_reports&report=' . (int)($row['id'] ?? 0);
    $row['_sender_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['sender_username'] ?? $row['senderid'] ?? ''));
    $row['_receiver_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['receiver_username'] ?? $row['recieveid'] ?? ''));
    $row['_reporter_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['reporter_username'] ?? $row['reporterid'] ?? ''));
    return $row;
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return int Numeric result for the caller.
 */
function corebb_pm_reports_new_count(): int
{
    corebb_pm_ensure_moderation_schema();
    return (int)db_value("SELECT COUNT(*) FROM pm_reports WHERE status = 'new'", [], 0);
}

/**
 * Usage: Build and process the pm reports admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_pm_reports_model(array $viewer, array $get, array $post): array
{
    corebb_pm_ensure_moderation_schema();
    $messages = [];
    $errors = [];
    $viewerId = (int)($viewer['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($post['action'] ?? '');
        $reportId = (int)($post['report_id'] ?? 0);
        $note = corebb_pm_mod_limit_text((string)($post['handler_note'] ?? ''), 65535);
        $report = corebb_pm_mod_fetch_report($reportId);
        if ($viewerId <= 0) {
            $errors[] = 'Unknown admin user.';
        } elseif (!$report) {
            $errors[] = 'Unknown PM report ID.';
        } elseif ($action === 'cancel_report') {
            corebb_pm_mod_close_report($reportId, $viewerId, 'ignored', $note !== '' ? $note : 'Report cancelled.');
            corebb_pm_mod_log($viewer, "Cancelled private message report {$reportId}", 'pm_report_cancelled');
            $messages[] = 'PM report cancelled.';
        } elseif ($action === 'delete_pm') {
            $result = corebb_pm_mod_soft_delete_pm((int)($report['pmid'] ?? 0), $viewer, $note !== '' ? $note : 'Deleted from reported private message queue.');
            if (!empty($result['ok'])) {
                corebb_pm_mod_close_report($reportId, $viewerId, 'deleted', $note !== '' ? $note : 'Private message deleted.');
                $messages[] = (string)$result['message'];
            } else {
                $errors[] = (string)$result['message'];
            }
        }
    }

    $old = (string)($get['old'] ?? '') === 'yes';
    $reportId = (int)($get['report'] ?? 0);
    $page = max(1, (int)($get['page'] ?? 1));
    $perPage = 25;

    $detail = $reportId > 0 ? corebb_pm_mod_fetch_report($reportId) : false;
    if ($detail) {
        $detail = corebb_pm_mod_enrich_report_row($detail);
        [$canAct, $blockReason] = corebb_pm_mod_can_act_on_pm($viewer, $detail);
        $detail['_can_act'] = $canAct;
        $detail['_block_reason'] = $blockReason;
    }

    $where = $old ? "pr.status <> 'new'" : "pr.status = 'new'";
    $total = (int)db_value("SELECT COUNT(*) FROM pm_reports pr WHERE {$where}", [], 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = [];
    $deletedColumns = corebb_pm_deleted_select_columns('pm');
    $sql = "SELECT pr.id, pr.pmid, pr.reporterid, pr.reported_userid, pr.senderid, pr.recieveid, pr.reason_type, pr.comments, pr.severity, pr.status, pr.created_at, pr.created_ip, pr.handled_by, pr.handled_at, pr.handler_note,
                   reporter.username AS reporter_username, sender.username AS sender_username, receiver.username AS receiver_username,
                   pm.title, pm.message, {$deletedColumns}, pm.datesent
              FROM pm_reports pr
              LEFT JOIN users reporter ON reporter.id = pr.reporterid
              LEFT JOIN users sender ON sender.id = pr.senderid
              LEFT JOIN users receiver ON receiver.id = pr.recieveid
              LEFT JOIN privatemessages pm ON pm.id = pr.pmid
             WHERE {$where}
             ORDER BY CAST(pr.severity AS UNSIGNED) DESC, pr.id DESC
             LIMIT {$offset}, {$perPage}";
    foreach (db_all($sql) as $row) {
        $rows[] = corebb_pm_mod_enrich_report_row($row);
    }

    $base = '/admin/?act=pm_reports&' . ($old ? 'old=yes&' : '') . 'page={page}';
    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'messages' => $messages,
        'errors' => $errors,
        'old' => $old,
        'detail' => $detail ?: null,
        'requests' => $rows,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'pagination' => corebb_pagination_model($totalPages > 1 ? $base : '', $page, $totalPages, 'BoardRowBLink'),
    ];
}

/**
 * Usage: Build and process the pm history admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_pm_history_model(array $viewer, array $get, array $post): array
{
    corebb_pm_ensure_moderation_schema();
    $messages = [];
    $errors = [];
    $viewerId = (int)($viewer['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($post['action'] ?? '');
        $pmId = (int)($post['pmid'] ?? 0);
        if ($viewerId <= 0) {
            $errors[] = 'Unknown admin user.';
        } elseif ($pmId <= 0) {
            $errors[] = 'Unknown private message ID.';
        } elseif ($action === 'delete_pm') {
            $result = corebb_pm_mod_soft_delete_pm($pmId, $viewer, corebb_pm_mod_limit_text((string)($post['delete_reason'] ?? 'Deleted by administrator from PM History.'), 65535));
            if (!empty($result['ok'])) {
                $messages[] = (string)$result['message'];
            } else {
                $errors[] = (string)$result['message'];
            }
        } elseif ($action === 'restore_pm') {
            $result = corebb_pm_mod_restore_pm($pmId, $viewer);
            if (!empty($result['ok'])) {
                $messages[] = (string)$result['message'];
            } else {
                $errors[] = (string)$result['message'];
            }
        }
    }

    $q = corebb_pm_mod_limit_text((string)($get['q'] ?? ''), 255);
    $showDeleted = (string)($get['deleted'] ?? '') === 'yes';
    $page = max(1, (int)($get['page'] ?? 1));
    $perPage = 30;
    $where = [];
    $params = [];
    if (!$showDeleted) {
        $where[] = corebb_pm_visible_condition('pm');
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = '(pm.id = ? OR pm.senderid = ? OR pm.recieveid = ?)';
            $params[] = (int)$q;
            $params[] = (int)$q;
            $params[] = (int)$q;
        } else {
            $where[] = '(sender.username LIKE ? OR receiver.username LIKE ? OR pm.title LIKE ? OR pm.message LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)db_value("SELECT COUNT(*) FROM privatemessages pm LEFT JOIN users sender ON sender.id = pm.senderid LEFT JOIN users receiver ON receiver.id = pm.recieveid {$whereSql}", $params, 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = [];
    $deletedColumns = corebb_pm_deleted_select_columns('pm');
    $deletedByJoin = corebb_pm_deleted_by_supported() ? 'LEFT JOIN users deleter ON deleter.id = pm.deleted_by' : '';
    $deletedByUsername = corebb_pm_deleted_by_supported() ? 'deleter.username AS deleted_by_username' : "'' AS deleted_by_username";
    $sql = "SELECT pm.id, pm.senderid, pm.recieveid, pm.title, pm.message, pm.markread, pm.datesent, {$deletedColumns},
                   sender.username AS sender_username, sender.accesslevel AS sender_accesslevel,
                   receiver.username AS receiver_username, receiver.accesslevel AS receiver_accesslevel,
                   {$deletedByUsername}
              FROM privatemessages pm
              LEFT JOIN users sender ON sender.id = pm.senderid
              LEFT JOIN users receiver ON receiver.id = pm.recieveid
              {$deletedByJoin}
              {$whereSql}
             ORDER BY pm.id DESC
             LIMIT {$offset}, {$perPage}";
    foreach (db_all($sql, $params) as $row) {
        $row['_sender_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['sender_username'] ?? $row['senderid'] ?? ''));
        $row['_receiver_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['receiver_username'] ?? $row['recieveid'] ?? ''));
        [$canAct, $blockReason] = corebb_pm_mod_can_act_on_pm($viewer, $row);
        $row['_can_act'] = $canAct;
        $row['_block_reason'] = $blockReason;
        $rows[] = $row;
    }

    $base = '/admin/?act=pm_history&deleted=' . ($showDeleted ? 'yes' : 'no') . '&q=' . rawurlencode($q) . '&page={page}';
    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'messages' => $messages,
        'errors' => $errors,
        'q' => $q,
        'show_deleted' => $showDeleted,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'pagination' => corebb_pagination_model($totalPages > 1 ? $base : '', $page, $totalPages, 'BoardRowBLink'),
    ];
}
?>
