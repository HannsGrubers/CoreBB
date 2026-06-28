<?php
require_once __DIR__ . '/../helpers/corebb_date_helpers.php';
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
 |  pm_view_model.php  - View-model layer for            |
 |  private-message folders and message viewing.         |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/pm_helpers.php';

/**
 * Usage: Render private-message body text through the standard forum markup pipe.
 * Referenced by: private-message Twig templates.
 *
 * @param string $text Raw stored PM body.
 * @return string Display HTML with line breaks preserved.
 */
function corebb_pm_markup(string $text): string
{
    return nl2br(corebb_render_markup($text, 'B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB-MD-IMG'));
}

/**
 * Usage: Resolve a folder key into its public PM route.
 * Referenced by: PM folder/view templates.
 *
 * @param string $folder Folder key from model state.
 * @return string Public folder URL.
 */
function corebb_pm_folder_url(string $folder): string
{
    return match ($folder) {
        'read' => '/private-messages/read/',
        'sent' => '/private-messages/sent/',
        default => '/private-messages/',
    };
}

/**
 * Usage: Build all PM folder counts for the sidebar/header model.
 * Referenced by: PM folder, view, and send models.
 *
 * @param int $userId Current user id.
 * @return array{unread: int, read: int, sent: int} Folder counts.
 */
function corebb_pm_counts(int $userId): array
{
    return [
        'unread' => corebb_pm_count($userId, 'unread'),
        'read' => corebb_pm_count($userId, 'read'),
        'sent' => corebb_pm_count($userId, 'sent'),
    ];
}

/**
 * Usage: Resolve a user id to a display username for PM rows.
 * Referenced by: PM folder and message-view models.
 *
 * @param int $userId User id from sender/recipient columns.
 * @return string Username or Unknown.
 */
function corebb_pm_username(int $userId): string
{
    if ($userId <= 0) {
        return 'Unknown';
    }
    return (string)db_value('SELECT username FROM users WHERE id = ? LIMIT 1', [$userId], 'Unknown');
}

/**
 * Usage: Build the template model for a PM folder listing.
 * Referenced by: controllers/messages.php folder routes.
 *
 * @param array<string, mixed> $user Current logged-in user row.
 * @param string $folder Requested folder key.
 * @return array<string, mixed> Template model consumed by views/pages/pm_folder.twig.
 */
function corebb_pm_folder_model(array $user, string $folder): array
{
    if (!in_array($folder, ['unread', 'read', 'sent'], true)) {
        $folder = 'unread';
    }

    $userId = corebb_pm_user_id($user);
    $result = corebb_pm_folder_result($userId, $folder);
    $messages = [];

    if ($result) {
        foreach ($result as $pm) {
            $pmId = (int)($pm['id'] ?? 0);
            if ($folder === 'sent') {
                $otherUserId = (int)($pm['recieveid'] ?? 0);
                $method = 'sent';
                $direction = 'to';
            } elseif ($folder === 'read') {
                $otherUserId = (int)($pm['senderid'] ?? 0);
                $method = 'read';
                $direction = 'from';
            } else {
                $otherUserId = (int)($pm['senderid'] ?? 0);
                $method = 'unread';
                $direction = 'from';
            }

            $sendDate = (string)($pm['datesent'] ?? '');
            $messages[] = [
                'id' => $pmId,
                'title' => (string)($pm['title'] ?? ''),
                'method' => $method,
                'direction' => $direction,
                'other_user_id' => $otherUserId,
                'other_user_name' => corebb_pm_username($otherUserId),
                'date_sent' => $sendDate,
                'date_sent_display' => $sendDate !== '' ? convert_to_vndate($sendDate) : $sendDate,
            ];
        }
    }

    return [
        'folder' => $folder,
        'title' => corebb_pm_folder_title($folder),
        'counts' => corebb_pm_counts($userId),
        'messages' => $messages,
    ];
}

/**
 * Usage: Build the template model for reading one private message.
 * Referenced by: controllers/messages.php message routes.
 *
 * @param array<string, mixed> $user Current logged-in user row.
 * @param int $pmId Private message id from the route.
 * @param string $method Folder/view method: unread, read, or sent.
 * @return array<string, mixed> Template model consumed by views/pages/pm_view.twig.
 */
function corebb_pm_view_model(array $user, int $pmId, string $method): array
{
    $userId = corebb_pm_user_id($user);
    $method = strtolower(trim($method));
    if ($pmId <= 0 || !in_array($method, ['unread', 'read', 'sent'], true)) {
        return ['missing' => true, 'message' => 'Sorry, unknown private message ID.'];
    }

    $pm = corebb_pm_get_for_view($userId, (int)$pmId, $method);
    if (!$pm) {
        return ['missing' => true, 'message' => 'Sorry, unknown private message ID.'];
    }

    $messages = [];
    $errors = [];
    $showReportForm = isset($_GET['report']) && (string)($_GET['report'] ?? '') !== '' && (string)($_GET['report'] ?? '') !== '0';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'report_pm') {
        $result = corebb_pm_report_private_message(
            $user,
            (int)$pmId,
            (string)($_POST['reason_type'] ?? 'Other'),
            (int)($_POST['severity'] ?? 1),
            (string)($_POST['comments'] ?? '')
        );
        if (!empty($result['ok'])) {
            $messages[] = (string)($result['message'] ?? 'Private-message report submitted.');
            $showReportForm = false;
        } else {
            $errors[] = (string)($result['message'] ?? 'Unable to submit private-message report.');
            $showReportForm = true;
        }
    }

    $isSent = $method === 'sent';
    $otherUserId = $isSent ? (int)($pm['recieveid'] ?? 0) : (int)($pm['senderid'] ?? 0);
    $dateSent = (string)($pm['datesent'] ?? '');

    return [
        'missing' => false,
        'method' => $method,
        'counts' => corebb_pm_counts($userId),
        'id' => (int)($pm['id'] ?? $pmId),
        'title' => (string)($pm['title'] ?? ''),
        'body' => (string)($pm['message'] ?? ''),
        'messages' => $messages,
        'errors' => $errors,
        'date_sent' => $dateSent,
        'date_sent_display' => $dateSent !== '' ? convert_to_vndate($dateSent) : $dateSent,
        'name_label' => $isSent ? 'To:' : 'From:',
        'other_user_id' => $otherUserId,
        'other_user_name' => corebb_pm_username($otherUserId),
        'can_reply' => !$isSent,
        'reply_url' => '/private-messages/send/' . (int)$otherUserId . '/?title=' . rawurlencode(str_replace(' ', '+', corebb_pm_clean_subject((string)($pm['title'] ?? '')))),
        'show_report_form' => $showReportForm,
        'report_url' => '/private-messages/message/' . (int)($pm['id'] ?? $pmId) . '/' . rawurlencode($method) . '/?report=1',
        'report_form_url' => '/private-messages/message/' . (int)($pm['id'] ?? $pmId) . '/' . rawurlencode($method) . '/?report=1',
    ];
}
?>
