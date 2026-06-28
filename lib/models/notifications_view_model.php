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
 |  notifications_view_model.php  - View-model for the   |
 |  user's notification center.                          |
 +-------------------------------------------------------+*/

if (!defined('IN_BOARDS')) {
    die('Access denied.');
}
require_once __DIR__ . '/../helpers/notification_helpers.php';

/**
 * Usage: Build the notification-center model and apply notification actions.
 * Referenced by: controllers/usercp.php action=notifications.
 *
 * POST actions can clear notifications, silence one notification stream,
 * unsilence a stream, or toggle notification creation for the viewer. The
 * returned array contains messages/errors plus the current notifications,
 * mutes, settings, and counts for the template.
 *
 * @param array<string, mixed> $user Current logged-in user row.
 * @param array<string, mixed> $post Optional POST payload from the notifications route.
 * @return array<string, mixed> Template model consumed by views/pages/notifications.twig.
 */
function corebb_notifications_model(array $user, array $post = []): array
{
    $userId = (int)($user['id'] ?? 0);
    $messages = [];
    $errors = [];

    corebb_notifications_ensure_schema();

    if ($userId <= 0) {
        return [
            'messages' => [],
            'errors' => ['You must be logged in to view notifications.'],
            'items' => [],
            'mutes' => [],
            'settings' => ['notifications_enabled' => 0],
            'count' => 0,
            'muteCount' => 0,
        ];
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $action = strtolower(trim((string)($post['action'] ?? '')));
        if ($action === 'clear_one') {
            $notificationId = (int)($post['notification_id'] ?? 0);
            if ($notificationId > 0 && corebb_notifications_clear_one($userId, $notificationId)) {
                $messages[] = 'Notification cleared.';
            } else {
                $errors[] = 'Unable to clear that notification.';
            }
        } elseif ($action === 'clear_all') {
            if (corebb_notifications_clear_all($userId)) {
                $messages[] = 'All notifications cleared.';
            } else {
                $errors[] = 'Unable to clear notifications.';
            }
        } elseif ($action === 'silence_one') {
            $notificationId = (int)($post['notification_id'] ?? 0);
            $result = corebb_notifications_silence_notification($userId, $notificationId);
            if (!empty($result['ok'])) {
                $messages[] = (string)($result['message'] ?? 'Notification silenced.');
            } else {
                $errors[] = (string)($result['message'] ?? 'Unable to silence that notification.');
            }
        } elseif ($action === 'unsilence') {
            $muteId = (int)($post['mute_id'] ?? 0);
            if ($muteId > 0 && corebb_notifications_unsilence($userId, $muteId)) {
                $messages[] = 'Notifications unsilenced.';
            } else {
                $errors[] = 'Unable to unsilence that notification stream.';
            }
        } elseif ($action === 'notifications_off') {
            if (corebb_notifications_set_enabled($userId, false)) {
                $messages[] = 'New notifications are now turned off.';
            } else {
                $errors[] = 'Unable to turn off notifications.';
            }
        } elseif ($action === 'notifications_on') {
            if (corebb_notifications_set_enabled($userId, true)) {
                $messages[] = 'New notifications are now turned on.';
            } else {
                $errors[] = 'Unable to turn on notifications.';
            }
        }
    }

    $items = corebb_notifications_fetch($userId, 100);
    $mutes = corebb_notifications_fetch_mutes($userId);
    $settings = corebb_notifications_settings($userId);

    return [
        'messages' => $messages,
        'errors' => $errors,
        'items' => $items,
        'mutes' => $mutes,
        'settings' => $settings,
        'count' => count($items),
        'muteCount' => count($mutes),
    ];
}
?>
