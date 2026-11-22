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
 |  admin_contact_mods_view_model.php  - Admin Contact   |
 |  Mods inbox view-model.                               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/contact_mods_helpers.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';

/** Save the decision before recording a successful staff action. */
function corebb_admin_contact_mods_decide(array $viewer, array $request, array $post): array
{
    $id = (int)$request['id'];
    $viewerId = (int)$viewer['id'];
    $action = $post['action'] ?? '';
    $conflict = 'The request could not be updated. Refresh it to check its current status.';

    switch ($action) {
        case 'send_reply':
            $text = $post['response'] ?? '';
            if (!is_string($text) || strlen($text) > 65535) {
                return [false, 'Shorten the reply before sending.'];
            }
            $reply = corebb_contact_mods_clean_text($text, 65535);
            if ($reply === '') {
                return [false, 'Enter a reply to send to the member.'];
            }
            if ($request['status'] !== 'new' || !db_begin()) {
                return [false, $conflict];
            }
            $sent = corebb_contact_mods_send_reply_pm($viewer, $request, $reply);
            if (!$sent['ok']) {
                db_rollback();
                return [false, $sent['message']];
            }
            if (empty($sent['pm_id']) || !corebb_contact_mods_close($id, $viewerId, 'responded', $reply, $sent['pm_id']) || !db_commit()) {
                db_rollback();
                return [false, $conflict];
            }
            corebb_contact_mods_log($viewer, "Responded to Contact Mods request {$id}", 'contact_mods_responded');
            return [true, $sent['message']];

        case 'close_request':
            $note = $post['handler_note'] ?? '';
            if (!is_string($note) || strlen($note) > 65535) {
                return [false, 'Shorten the staff note before saving.'];
            }
            if (!corebb_contact_mods_close($id, $viewerId, 'ignored', corebb_contact_mods_clean_text($note, 65535))) {
                return [false, $conflict];
            }
            corebb_contact_mods_log($viewer, "Closed Contact Mods request {$id}", 'contact_mods_closed');
            return [true, 'Request closed without a reply.'];

        case 'reopen_request':
            if (!corebb_contact_mods_reopen($id)) {
                return [false, $conflict];
            }
            corebb_contact_mods_log($viewer, "Reopened Contact Mods request {$id}", 'contact_mods_reopened');
            return [true, 'Request reopened.'];

        default:
            return [false, 'Choose an available action.'];
    }
}

function corebb_admin_contact_mods_model(array $viewer, array $get, array $post): array
{
    if (empty($viewer['id']) || !corebb_admin_can_access_tool($viewer, 'contact_mods')) {
        return ['errors' => ['You do not have access to the Contact Mods Inbox.']];
    }
    corebb_contact_mods_ensure_schema();
    $old = ($get['old'] ?? '') === 'yes' || in_array($get['view'] ?? '', ['old', 'yes'], true);
    $page = max(1, (int)($get['page'] ?? 1));
    $queue = '/admin/?act=contact_mods' . ($old ? '&old=yes' : '');
    $back = $queue . ($page > 1 ? '&page=' . $page : '');
    $model = ['old' => $old, 'queue_url' => $back, 'errors' => []];
    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $id = (int)($get['request'] ?? ($posted ? ($post['request_id'] ?? 0) : 0));

    if ($id || $posted || isset($get['request'])) {
        $request = corebb_contact_mods_fetch_request($id);
        if (!$request) {
            $model['errors'][] = 'This request is unavailable.';
            return $model;
        }
        if ($posted) {
            if ((int)($post['request_id'] ?? 0) !== $id || !corebb_security_csrf_valid($post) || !corebb_contact_mods_check_token($post)) {
                $model['errors'][] = 'Open the request again before submitting.';
            } else {
                [$saved, $message] = corebb_admin_contact_mods_decide($viewer, $request, $post);
                if ($saved) {
                    return $model + ['saved' => true, 'messages' => [$message]];
                }
                $model['errors'][] = $message;
                // A second moderator may have handled the request while this form was open.
                $request = corebb_contact_mods_fetch_request($id);
                if (!$request) {
                    return $model;
                }
            }
        }
        $model['can_view_member'] = corebb_admin_can_access_tool($viewer, 'user_pages') && !empty($request['requester_id']);
        $model['submission_ip'] = corebb_admin_can_access_tool($viewer, 'user_ip_check')
            ? corebb_admin_display_ip((string)$request['created_ip']) : '';
        unset($request['created_ip']);
        $model['status_label'] = corebb_contact_mods_status_label((string)$request['status']);
        $model['detail'] = $request;
        $model['action_url'] = $back . '&request=' . $id;
        $model['token'] = corebb_contact_mods_token();
        $model['draft_reply'] = is_string($post['response'] ?? null) ? $post['response'] : '';
        $model['draft_note'] = is_string($post['handler_note'] ?? null) ? $post['handler_note'] : '';
        $model['editing_note'] = ($post['action'] ?? '') === 'close_request';
        return $model;
    }

    $where = $old ? "cmr.status <> 'new'" : "cmr.status = 'new'";
    $total = db_value("SELECT COUNT(*) FROM contact_mod_requests cmr WHERE {$where}");
    if ($total === null) {
        $model['errors'][] = 'Could not load the inbox.';
        return $model;
    }
    $perPage = 25;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $rows = db_all("SELECT cmr.id, cmr.subject, cmr.userid, cmr.status, cmr.created_at,
                           SUBSTR(cmr.message, 1, 140) AS preview, requester.username AS requester_username
                    FROM contact_mod_requests cmr LEFT JOIN users requester ON requester.id = cmr.userid
                    WHERE {$where} ORDER BY cmr.id DESC LIMIT {$perPage} OFFSET {$offset}");
    if (db_error() !== '') {
        $model['errors'][] = 'Could not load the inbox.';
        return $model;
    }
    $detailBase = $queue . ($page > 1 ? '&page=' . $page : '') . '&request=';
    foreach ($rows as &$row) {
        $row['detail_url'] = $detailBase . (int)$row['id'];
        $row['status_label'] = corebb_contact_mods_status_label((string)$row['status']);
    }
    unset($row);
    return $model + [
        'requests' => $rows, 'total' => (int)$total, 'first' => $offset + 1, 'last' => $offset + count($rows),
        'pagination' => corebb_pagination_model($pages > 1 ? $queue . '&page={page}' : '', $page, $pages, 'BoardRowBLink'),
    ];
}
