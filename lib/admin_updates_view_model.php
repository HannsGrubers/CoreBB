<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  admin_updates_view_model.php  - CoreBB Updates page. |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_update_helpers.php';

/**
 * Usage: Build and process the admin Updates page model.
 * Referenced by: admin route act=updates.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query parameters.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed>
 */
function corebb_admin_updates_model(array $viewer, array $request, array $post): array
{
    unset($request);
    $messages = [];
    $errors = [];
    $manifest = corebb_update_cached_manifest();

    if ((int)($viewer['accesslevel'] ?? 0) < 5) {
        $errors[] = 'Administrator access is required.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string)($post['action'] ?? '');
        if (function_exists('corebb_security_csrf_valid') && !corebb_security_csrf_valid($post)) {
            $errors[] = 'Security token expired. Please reload the page and try again.';
        } elseif ($action === 'check_updates') {
            if (function_exists('corebb_adminlog_viewer')) {
                corebb_adminlog_viewer($viewer, 'CoreBB update check started', 'update_check_started');
            }
            $result = corebb_update_fetch_manifest();
            if ($result['ok']) {
                $manifest = $result['manifest'];
                $messages[] = (string)$result['message'];
                if (function_exists('corebb_adminlog_viewer')) {
                    corebb_adminlog_viewer($viewer, 'CoreBB update check completed', 'update_check_completed');
                }
            } else {
                $errors[] = (string)$result['message'];
                if (function_exists('corebb_adminlog_viewer')) {
                    corebb_adminlog_viewer($viewer, 'CoreBB update check failed', 'update_check_failed', (string)$result['message']);
                }
            }
        }
    }

    $status = corebb_update_status($manifest);

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'messages' => $messages,
        'errors' => $errors,
        'manifest_url' => COREBB_UPDATE_MANIFEST_URL,
        'status' => $status,
        'releases' => corebb_update_release_rows($manifest),
    ];
}
