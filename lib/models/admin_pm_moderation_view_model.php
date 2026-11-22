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
        "SELECT pr.id, pr.pmid, pr.reporterid, pr.senderid, pr.recieveid, pr.reason_type, pr.comments, pr.severity, pr.status, pr.created_at, pr.handled_by, pr.handled_at, pr.handler_note,
                reporter.username AS reporter_username,
                sender.username AS sender_username, sender.accesslevel AS sender_accesslevel,
                receiver.username AS receiver_username, receiver.accesslevel AS receiver_accesslevel,
                handler.username AS handler_username,
                pm.id AS message_id, pm.title, pm.message, pm.datesent, {$deletedColumns},
                {$deletedByUsername}
           FROM pm_reports pr
           LEFT JOIN users reporter ON reporter.id = pr.reporterid
           LEFT JOIN users sender ON sender.id = pr.senderid
           LEFT JOIN users receiver ON receiver.id = pr.recieveid
           LEFT JOIN users handler ON handler.id = pr.handled_by
           LEFT JOIN privatemessages pm ON pm.id = pr.pmid AND pm.senderid = pr.senderid AND pm.recieveid = pr.recieveid
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
 * @param array $report Report as reviewed, including the PM and its participants.
 * @param int $viewerId Viewer user id.
 * @param string $status Request/report status.
 * @param string $note Admin note or resolution text.
 * @return bool Whether this open report was closed by the caller.
 */
function corebb_pm_mod_close_report(array $report, int $viewerId, string $status, string $note): bool
{
    return db_run(
        "UPDATE pm_reports SET status = ?, handled_by = ?, handled_at = ?, handler_note = ?
         WHERE id = ? AND pmid = ? AND senderid = ? AND recieveid = ? AND status = 'new'",
        [$status, $viewerId, date('Y-m-d H:i:s'), $note, $report['id'], $report['pmid'], $report['senderid'], $report['recieveid']]
    ) && corebb_db_last_affected_rows() === 1;
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
function corebb_pm_mod_soft_delete_pm(int $pmId, array $viewer, string $reason = '', ?array $report = null): array
{
    if ($report !== null && $pmId !== (int)$report['pmid']) {
        return ['ok' => false, 'message' => 'The reported message is unavailable.'];
    }
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
        return ['ok' => $report === null, 'message' => 'Private message is already deleted.'];
    }
    $viewerId = (int)($viewer['id'] ?? 0);
    $reason = corebb_pm_mod_limit_text($reason !== '' ? $reason : 'Deleted by staff moderation.', 65535);
    $failed = ['ok' => false, 'message' => 'Could not complete the removal. Refresh the page to check its current status.'];
    if (!db_begin()) {
        return $failed;
    }
    $sender = $report['senderid'] ?? $pm['senderid'];
    $recipient = $report['recieveid'] ?? $pm['recieveid'];
    $now = date('Y-m-d H:i:s');
    if (!db_run('UPDATE privatemessages SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ?
                 WHERE id = ? AND senderid = ? AND recieveid = ? AND is_deleted = 0',
                [$now, $viewerId, $reason, $pmId, $sender, $recipient])
        || corebb_db_last_affected_rows() !== 1
        || ($report !== null && !corebb_pm_mod_close_report($report, $viewerId, 'deleted', $reason))
        || !db_run("UPDATE pm_reports SET status = 'deleted', handled_by = ?, handled_at = ?, handler_note = ?
                    WHERE pmid = ? AND senderid = ? AND recieveid = ? AND status = 'new'",
                   [$viewerId, $now, $reason, $pmId, $sender, $recipient])
        || !db_commit()) {
        db_rollback();
        return $failed;
    }
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
    if (!db_run("UPDATE privatemessages SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = ''
                 WHERE id = ? AND is_deleted = 1", [$pmId]) || corebb_db_last_affected_rows() !== 1) {
        return ['ok' => false, 'message' => 'Could not restore the message. Refresh to check its current status.'];
    }
    corebb_pm_mod_log($viewer, "Restored private message {$pmId}", 'pm_restore', "Private message {$pmId} restored to user mailboxes");
    return ['ok' => true, 'message' => 'Private message restored.'];
}

function corebb_pm_report_outcome(string $status): string
{
    return match ($status) {
        'ignored' => 'Closed without removal',
        'deleted' => 'Message removed',
        'resolved' => 'Resolved',
        default => 'Open',
    };
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
    if (empty($viewer['id']) || !corebb_admin_can_access_tool($viewer, 'pm_reports')) {
        return ['errors' => ['You do not have access to Private Message Reports.']];
    }
    corebb_pm_ensure_moderation_schema();
    $old = ($get['old'] ?? '') === 'yes';
    $page = max(1, (int)($get['page'] ?? 1));
    $base = '/admin/?act=pm_reports' . ($old ? '&old=yes' : '');
    $back = $base . ($page > 1 ? '&page=' . $page : '');
    $model = ['queue_url' => $back, 'old' => $old, 'errors' => []];
    $posted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (isset($get['report']) || $posted) {
        $id = (int)($get['report'] ?? $post['report_id'] ?? 0);
        $report = corebb_pm_mod_fetch_report($id);
        if (!$report) {
            $model['errors'][] = 'This report is unavailable.';
            return $model;
        }
        [$canAct, $reason] = corebb_pm_mod_can_act_on_pm($viewer, $report);
        $canRemove = $canAct && $report['message_id'] && !$report['is_deleted'] && corebb_pm_soft_delete_supported();
        $note = $post['handler_note'] ?? '';
        if ($posted) {
            $action = $post['action'] ?? '';
            if (!corebb_security_csrf_valid($post) || $id !== (int)($post['report_id'] ?? 0)) {
                $model['errors'][] = 'Open the report again before submitting.';
            } elseif (!$canAct || $report['status'] !== 'new') {
                $model['errors'][] = $reason ?: 'This report has already been handled.';
            } elseif (!is_string($note) || strlen($note) > 65535) {
                $model['errors'][] = 'Shorten the staff note before saving.';
            } elseif ($action === 'cancel_report') {
                if (corebb_pm_mod_close_report($report, (int)$viewer['id'], 'ignored', trim($note))) {
                    corebb_pm_mod_log($viewer, "Closed private message report {$id}", 'pm_report_cancelled');
                    return $model + ['saved' => true, 'messages' => ['Report closed without removing the message.']];
                }
                $model['errors'][] = 'Could not close the report. Refresh it to check its current status.';
            } elseif ($action === 'delete_pm' && $canRemove) {
                $result = corebb_pm_mod_soft_delete_pm((int)$report['pmid'], $viewer, trim($note), $report);
                if ($result['ok']) {
                    return $model + ['saved' => true, 'messages' => [$result['message']]];
                }
                $model['errors'][] = $result['message'];
            } else {
                $model['errors'][] = 'Choose an available decision.';
            }
        }
        return $model + [
            'detail' => $report, 'outcome' => corebb_pm_report_outcome($report['status']),
            'can_act' => $canAct, 'can_remove' => $canRemove, 'blocked_reason' => $reason,
            'can_view_member' => corebb_admin_can_access_tool($viewer, 'user_pages'),
            'action_url' => $back . '&report=' . $id, 'draft_note' => is_string($note) ? $note : '',
        ];
    }

    $where = $old ? "pr.status <> 'new'" : "pr.status = 'new'";
    $total = db_value("SELECT COUNT(*) FROM pm_reports pr WHERE {$where}");
    if ($total === null) {
        $model['errors'][] = 'Could not load the report queue.';
        return $model;
    }
    $perPage = 25;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $rows = db_all("SELECT pr.id, pr.pmid, pr.reporterid, pr.reason_type, pr.severity, pr.status, pr.created_at,
                           reporter.username AS reporter_username, pm.title
                    FROM pm_reports pr
                    LEFT JOIN users reporter ON reporter.id = pr.reporterid
                    LEFT JOIN privatemessages pm ON pm.id = pr.pmid AND pm.senderid = pr.senderid AND pm.recieveid = pr.recieveid
                    WHERE {$where} ORDER BY pr.severity DESC, pr.id DESC LIMIT {$perPage} OFFSET {$offset}");
    if (db_error() !== '') {
        $model['errors'][] = 'Could not load the report queue.';
        return $model;
    }
    foreach ($rows as &$row) {
        $row['detail_url'] = $base . ($page > 1 ? '&page=' . $page : '') . '&report=' . $row['id'];
        $row['outcome'] = corebb_pm_report_outcome($row['status']);
    }
    unset($row);
    return $model + [
        'requests' => $rows, 'total' => (int)$total, 'first' => $offset + 1, 'last' => $offset + count($rows),
        'pagination' => corebb_pagination_model($pages > 1 ? $base . '&page={page}' : '', $page, $pages, 'BoardRowBLink'),
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
    if (empty($viewer['id']) || !corebb_admin_can_access_tool($viewer, 'pm_history')) {
        return ['errors' => ['You do not have access to Private Message History.']];
    }
    corebb_pm_ensure_moderation_schema();
    $q = is_string($get['q'] ?? null) ? corebb_pm_mod_limit_text($get['q'], 255) : '';
    $scope = in_array($get['deleted'] ?? '', ['yes', 'only'], true) ? $get['deleted'] : 'no';
    $page = max(1, (int)($get['page'] ?? 1));
    $base = '/admin/?act=pm_history' . ($scope !== 'no' ? '&deleted=' . $scope : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
    $queue = $base . ($page > 1 ? '&page=' . $page : '');
    $model = ['queue_url' => $queue, 'q' => $q, 'scope' => $scope, 'errors' => [],
              'can_view_reports' => corebb_admin_can_access_tool($viewer, 'pm_reports')];
    $posted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $supportsRemoval = corebb_pm_soft_delete_supported();
    if (isset($get['pmid']) || $posted) {
        $id = (int)($get['pmid'] ?? $post['pmid'] ?? 0);
        $pm = corebb_pm_mod_fetch_pm($id);
        if (!$pm) {
            $model['errors'][] = 'This private message is unavailable.';
            return $model;
        }
        [$canModerate, $blocked] = corebb_pm_mod_can_act_on_pm($viewer, $pm);
        $removed = (bool)$pm['is_deleted'];
        $canRemove = $supportsRemoval && !$removed && $canModerate;
        $canRestore = $supportsRemoval && $removed && corebb_admin_viewer_level($viewer) >= 5;
        $reason = $post['delete_reason'] ?? '';
        if ($posted) {
            $action = $post['action'] ?? '';
            if (!corebb_security_csrf_valid($post) || (int)($post['pmid'] ?? 0) !== $id) {
                $model['errors'][] = 'Open the message again before submitting.';
            } elseif ($action === 'delete_pm' && $canRemove && (!is_string($reason) || strlen($reason) > 65535)) {
                $model['errors'][] = 'Shorten the removal reason before saving.';
            } else {
                $result = match (true) {
                    $action === 'delete_pm' && $canRemove => corebb_pm_mod_soft_delete_pm($id, $viewer, trim($reason)),
                    $action === 'restore_pm' && $canRestore => corebb_pm_mod_restore_pm($id, $viewer),
                    default => ['ok' => false, 'message' => 'That action is unavailable for this message.'],
                };
                if ($result['ok']) {
                    return $model + ['saved' => true, 'messages' => [$result['message']]];
                }
                $model['errors'][] = $result['message'];
            }
        }
        return $model + [
            'detail' => $pm, 'action_url' => $queue . '&pmid=' . $id,
            'can_view_member' => corebb_admin_can_access_tool($viewer, 'user_pages'),
            'can_remove' => $canRemove, 'can_restore' => $canRestore,
            'blocked' => !$supportsRemoval ? 'Mailbox moderation is unavailable on this installation.'
                : ($removed ? 'Only administrators can restore removed messages.' : $blocked),
            'draft_reason' => is_string($reason) ? $reason : '',
        ];
    }

    $where = [];
    $params = [];
    if ($scope === 'only') {
        $where[] = $supportsRemoval ? 'pm.is_deleted = 1' : '1 = 0';
    } elseif ($scope === 'no' && $supportsRemoval) {
        $where[] = 'COALESCE(pm.is_deleted, 0) <> 1';
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = '(pm.id = ? OR pm.senderid = ? OR pm.recieveid = ?)';
            $params = array_fill(0, 3, (int)$q);
        } else {
            $where[] = '(sender.username LIKE ? OR receiver.username LIKE ? OR pm.title LIKE ? OR pm.message LIKE ?)';
            $params = array_fill(0, 4, '%' . $q . '%');
        }
    }
    $from = 'FROM privatemessages pm LEFT JOIN users sender ON sender.id = pm.senderid
             LEFT JOIN users receiver ON receiver.id = pm.recieveid';
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = db_value("SELECT COUNT(*) {$from}{$whereSql}", $params);
    if ($total === null) {
        $model['errors'][] = 'Could not load private message history.';
        return $model;
    }
    $perPage = 30;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $removedColumn = $supportsRemoval ? 'pm.is_deleted' : '0';
    $rows = db_all("SELECT pm.id, pm.senderid, pm.recieveid, pm.title, pm.markread, pm.datesent, {$removedColumn} AS is_deleted,
                           sender.username AS sender_username, receiver.username AS receiver_username
                    {$from}{$whereSql} ORDER BY pm.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);
    if (db_error() !== '') {
        $model['errors'][] = 'Could not load private message history.';
        return $model;
    }
    return $model + [
        'rows' => $rows, 'total' => (int)$total, 'first' => $offset + 1, 'last' => $offset + count($rows),
        'detail_base' => $base . ($page > 1 ? '&page=' . $page : '') . '&pmid=',
        'pagination' => corebb_pagination_model($pages > 1 ? $base . '&page={page}' : '', $page, $pages, 'BoardRowBLink'),
    ];
}
