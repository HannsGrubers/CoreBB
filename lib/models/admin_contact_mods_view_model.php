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

/**
 * Usage: Add template-only labels and admin URLs to a Contact Mods request row.
 * Referenced by: corebb_admin_contact_mods_model().
 *
 * @param array<string, mixed> $row Raw contact_mod_requests row with requester/handler joins.
 * @return array<string, mixed> Row decorated for admin list/detail templates.
 */
function corebb_admin_contact_mods_enrich_row(array $row): array
{
    $row['_status_label'] = corebb_contact_mods_status_label((string)($row['status'] ?? 'new'));
    $row['_detail_url'] = '/admin/?act=contact_mods&request=' . (int)($row['id'] ?? 0);
    $row['_requester_url'] = '/admin/?act=user_pages&usr=' . rawurlencode((string)($row['requester_username'] ?? $row['userid'] ?? ''));
    $row['_ip_url'] = '/admin/?act=user_ip_check&ip=' . rawurlencode((string)($row['created_ip'] ?? ''));
    return $row;
}

/**
 * Usage: Build the Admin Contact Mods inbox/reply model.
 * Referenced by: admin.php Contact Mods action.
 *
 * Handles listing current or closed contact requests, showing one selected
 * request, sending staff replies as PMs, closing requests without reply, and
 * reopening closed items. The caller is expected to be the admin route after its
 * normal moderator/admin permission checks have already run.
 *
 * @param array<string, mixed> $viewer Current logged-in moderator/admin viewer row.
 * @param array<string, mixed> $get Query parameters from the admin route.
 * @param array<string, mixed> $post POST payload for reply/close/reopen actions.
 * @return array<string, mixed> Template model consumed by the admin contact-mods view.
 */
function corebb_admin_contact_mods_model(array $viewer, array $get, array $post): array
{
    corebb_contact_mods_ensure_schema();
    $messages = [];
    $errors = [];
    $viewerId = (int)($viewer['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($post['action'] ?? '');
        $requestId = (int)($post['request_id'] ?? 0);
        $request = corebb_contact_mods_fetch_request($requestId);
        if (!corebb_contact_mods_check_token($post)) {
            $errors[] = 'Invalid request token. Please try again.';
        } elseif ($viewerId <= 0) {
            $errors[] = 'Unknown staff user.';
        } elseif (!$request) {
            $errors[] = 'Unknown Contact Mods request ID.';
        } elseif ($action === 'send_reply') {
            if ((string)($request['status'] ?? 'new') !== 'new') {
                $errors[] = 'This request is already closed.';
            } else {
                $response = corebb_contact_mods_clean_text((string)($post['response'] ?? ''), 65535);
                $result = corebb_contact_mods_send_reply_pm($viewer, $request, $response);
                if (!empty($result['ok'])) {
                    corebb_contact_mods_close($requestId, $viewerId, 'responded', $response, (int)($result['pm_id'] ?? 0));
                    corebb_contact_mods_log($viewer, "Responded to Contact Mods request {$requestId}", 'contact_mods_responded');
                    $messages[] = (string)$result['message'];
                } else {
                    $errors[] = (string)$result['message'];
                }
            }
        } elseif ($action === 'close_request') {
            $note = corebb_contact_mods_clean_text((string)($post['handler_note'] ?? 'Closed without reply.'), 65535);
            corebb_contact_mods_close($requestId, $viewerId, 'ignored', $note !== '' ? $note : 'Closed without reply.', 0);
            corebb_contact_mods_log($viewer, "Closed Contact Mods request {$requestId}", 'contact_mods_closed');
            $messages[] = 'Contact Mods request closed.';
        } elseif ($action === 'reopen_request') {
            corebb_contact_mods_reopen($requestId);
            corebb_contact_mods_log($viewer, "Reopened Contact Mods request {$requestId}", 'contact_mods_reopened');
            $messages[] = 'Contact Mods request reopened.';
        }
    }

    $old = (string)($get['old'] ?? $get['view'] ?? '') === 'yes' || (string)($get['view'] ?? '') === 'old';
    $requestId = (int)($get['request'] ?? 0);
    $page = max(1, (int)($get['page'] ?? 1));
    $perPage = 25;

    $detail = $requestId > 0 ? corebb_contact_mods_fetch_request($requestId) : false;
    if ($detail) {
        $detail = corebb_admin_contact_mods_enrich_row($detail);
    }

    $where = $old ? "cmr.status <> 'new'" : "cmr.status = 'new'";
    $total = (int)db_value("SELECT COUNT(*) FROM contact_mod_requests cmr WHERE {$where}", [], 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = [];
    $sql = "SELECT cmr.id, cmr.userid, cmr.subject, cmr.message, cmr.status, cmr.created_at, cmr.created_ip, cmr.handled_by, cmr.handled_at, cmr.handler_note, cmr.response_pm_id,
                   requester.username AS requester_username, requester.accesslevel AS requester_accesslevel,
                   handler.username AS handler_username
              FROM contact_mod_requests cmr
              LEFT JOIN users requester ON requester.id = cmr.userid
              LEFT JOIN users handler ON handler.id = cmr.handled_by
             WHERE {$where}
             ORDER BY cmr.id DESC
             LIMIT {$offset}, {$perPage}";
    foreach (db_all($sql) as $row) {
        $rows[] = corebb_admin_contact_mods_enrich_row($row);
    }

    $base = '/admin/?act=contact_mods&' . ($old ? 'old=yes&' : '') . 'page={page}';

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'messages' => $messages,
        'errors' => $errors,
        'token' => corebb_contact_mods_token(),
        'old' => $old,
        'detail' => $detail ?: null,
        'requests' => $rows,
        'new_count' => corebb_contact_mods_new_count(true),
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'pagination' => corebb_pagination_model($totalPages > 1 ? $base : '', $page, $totalPages, 'BoardRowBLink'),
    ];
}
