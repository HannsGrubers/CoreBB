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
 |  admin_maintenance_view_model.php  - Admin database   |
 |  tools.                                               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/db_backup_helpers.php';
require_once __DIR__ . '/../helpers/performance_helpers.php';

/**
 * Usage: Build and process the maintenance admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_maintenance_model(array $viewer, array $request, array $post, array $files = []): array
{
    $canDeploy = corebb_admin_can_access_tool($viewer, 'db_schema_deploy');
    if (($request['section'] ?? '') === 'schema') {
        require_once __DIR__ . '/admin_db_schema_deploy_view_model.php';
        $schema = corebb_admin_db_schema_deploy_model($viewer, $request, $post, $files);
        return ['section' => 'schema', 'can_deploy' => $canDeploy, 'schema' => $schema, 'result' => $schema['result']];
    }
    $section = ($request['section'] ?? '') === 'backups' ? 'backups' : 'maintenance';
    $result = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = $post['action'] ?? '';
        $section = $action === 'create_backup' ? 'backups' : 'maintenance';
        $details = [];
        $ok = false;
        switch (is_string($action) ? $action : '') {
            case 'rebuild_counts':
                @set_time_limit(0);
                $details = corebb_perf_rebuild_cached_counts($ok);
                $summary = $ok ? 'Counts rebuilt. The count cache is enabled.' : 'Count rebuild did not complete.';
                break;
            case 'prepare_search_indexes':
                @set_time_limit(0);
                $details = corebb_perf_prepare_search_indexes($ok);
                $summary = $ok ? 'Search indexes are ready.' : 'Search index preparation did not complete.';
                break;
            case 'create_backup':
                $backup = corebb_db_backup_run($viewer);
                $ok = $backup['ok'];
                $summary = $backup['message'];
                if ($ok) {
                    $details[] = sprintf('%s tables, %s views, %s rows exported.',
                        number_format($backup['tables']), number_format($backup['views']), number_format($backup['rows']));
                }
                break;
            default:
                $summary = 'Unknown database tool action.';
        }
        if (in_array($action, ['rebuild_counts', 'prepare_search_indexes', 'create_backup'], true)) {
            $auditDetail = $action === 'create_backup' && $ok ? $details[0] : $summary;
            corebb_adminlog_viewer($viewer, $summary, 'maintenance', $auditDetail);
        }
        $result = ['ok' => $ok, 'summary' => $summary, 'details' => $details];
    }

    $model = ['section' => $section, 'result' => $result, 'can_deploy' => $canDeploy];
    if ($section === 'backups') {
        $model['backup_directory'] = corebb_db_backup_directory();
        $model['backup_recent'] = corebb_db_backup_recent(5);
    } else {
        $model['cache_ready'] = corebb_perf_cache_ready();
        $model['cache_rebuilt_at'] = corebb_perf_get_setting('perf_cache_rebuilt_at');
        $model['search_ready'] = corebb_perf_search_fulltext_ready();
        $model['search_prepared_at'] = corebb_perf_get_setting('search_fulltext_prepared_at');
    }
    return $model;
}
