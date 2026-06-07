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
 |  admin_maintenance_view_model.php  - Admin database   |
 |  tools.                                               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/db_backup_helpers.php';
require_once __DIR__ . '/performance_helpers.php';

/**
 * Usage: Build and process the maintenance admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_maintenance_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $errors = [];
    $ranAction = '';
    $backupResult = null;

    $isPost = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST');
    $action = (string)($post['action'] ?? '');

    if ($isPost) {
        if ($action === 'rebuild_counts') {
            $ranAction = 'rebuild_counts';
            @set_time_limit(0);
            $messages = corebb_perf_rebuild_cached_counts();

            if (function_exists('addlogentry')) {
                addlogentry(
                    (string)($viewer['username'] ?? 'Unknown'),
                    (int)($viewer['accesslevel'] ?? 0),
                    'Rebuilt forum counts from Database Tools',
                    'maintenance',
                    'Rebuilt cached topic, reply, forum, and user post counts from visible posts.'
                );
            }
        } elseif ($action === 'prepare_search_indexes') {
            $ranAction = 'prepare_search_indexes';
            @set_time_limit(0);
            $messages = function_exists('corebb_perf_prepare_search_indexes')
                ? corebb_perf_prepare_search_indexes()
                : ['Search index helper is unavailable.'];

            if (function_exists('addlogentry')) {
                addlogentry(
                    (string)($viewer['username'] ?? 'Unknown'),
                    (int)($viewer['accesslevel'] ?? 0),
                    'Prepared search indexes from Database Tools',
                    'maintenance',
                    'Checked/created fulltext indexes for CoreBB search.'
                );
            }
        } elseif ($action === 'create_backup') {
            $ranAction = 'create_backup';
            $backupResult = corebb_db_backup_run($viewer);
            if (!empty($backupResult['ok'])) {
                $messages[] = (string)($backupResult['message'] ?? 'Database backup created.');

                if (function_exists('addlogentry')) {
                    addlogentry(
                        (string)($viewer['username'] ?? 'Unknown'),
                        (int)($viewer['accesslevel'] ?? 0),
                        'Created database backup from Database Tools',
                        'maintenance',
                        'Created backup file ' . (string)($backupResult['file'] ?? '') . ' containing ' . number_format((int)($backupResult['tables'] ?? 0)) . ' table(s).'
                    );
                }
            } else {
                $errors[] = (string)($backupResult['message'] ?? 'Database backup failed.');
            }
        } else {
            $errors[] = 'Unknown database tool action.';
        }
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'messages' => $messages,
        'errors' => $errors,
        'ran_action' => $ranAction,
        'cache_ready' => function_exists('corebb_perf_cache_ready') ? corebb_perf_cache_ready() : false,
        'cache_rebuilt_at' => function_exists('corebb_perf_get_setting') ? corebb_perf_get_setting('perf_cache_rebuilt_at', 'never') : 'unknown',
        'search_ready' => function_exists('corebb_perf_search_fulltext_ready') ? corebb_perf_search_fulltext_ready() : false,
        'search_prepared_at' => function_exists('corebb_perf_get_setting') ? corebb_perf_get_setting('search_fulltext_prepared_at', 'never') : 'unknown',
        'backup_directory' => corebb_db_backup_directory(),
        'backup_recent' => corebb_db_backup_recent(5),
        'backup_result' => $backupResult,
    ];
}
