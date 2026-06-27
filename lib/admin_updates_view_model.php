<?php
require_once __DIR__ . '/admin_log_helpers.php';
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
require_once __DIR__ . '/corebb_update_package_helpers.php';
require_once __DIR__ . '/security.php';

/**
 * Usage: Build and process the admin Updates page model.
 * Referenced by: admin route act=updates.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query parameters.
 * @param array<string, mixed> $post Posted form data.
 * @param array<string, mixed> $files Uploaded files.
 * @return array<string, mixed>
 */
function corebb_admin_updates_model(array $viewer, array $request, array $post, array $files = []): array
{
    unset($request);
    $messages = [];
    $errors = [];
    $manifest = corebb_update_cached_manifest();
    $packageSummary = corebb_update_last_package_summary();
    $preview = null;

    if ((int)($viewer['accesslevel'] ?? 0) < 5) {
        $errors[] = 'Administrator access is required.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string)($post['action'] ?? '');
        if (!corebb_security_csrf_valid($post)) {
            $errors[] = 'Security token expired. Please reload the page and try again.';
        } elseif ($action === 'check_updates') {
            {
                corebb_adminlog_viewer($viewer, 'CoreBB update check started', 'update_check_started');
            }
            $result = corebb_update_fetch_manifest();
            if ($result['ok']) {
                $manifest = $result['manifest'];
                $messages[] = (string)$result['message'];
                {
                    corebb_adminlog_viewer($viewer, 'CoreBB update check completed', 'update_check_completed');
                }
            } else {
                $errors[] = (string)$result['message'];
                {
                    corebb_adminlog_viewer($viewer, 'CoreBB update check failed', 'update_check_failed', (string)$result['message']);
                }
            }
        } elseif ($action === 'clear_update_lock') {
            try {
                if ((string)($post['clear_lock_confirmed'] ?? '') !== '1') {
                    throw new RuntimeException('Confirm that the failed upgrade has been reviewed before clearing the upgrade lock.');
                }
                $lock = corebb_update_lock_status();
                if (empty($lock['exists'])) {
                    throw new RuntimeException('No upgrade lock exists.');
                }
                $disableMaintenance = (string)($post['disable_maintenance'] ?? '') === '1';
                corebb_update_clear_upgrade_lock($disableMaintenance);
                $messages[] = $disableMaintenance
                    ? 'Upgrade lock cleared and maintenance mode disabled.'
                    : 'Upgrade lock cleared.';
                {
                    corebb_adminlog_viewer(
                        $viewer,
                        'CoreBB upgrade lock cleared',
                        'update_lock_cleared',
                        'Target version: ' . (string)($lock['target_version'] ?? '') . '; maintenance disabled: ' . ($disableMaintenance ? 'yes' : 'no')
                    );
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                {
                    corebb_adminlog_viewer($viewer, 'CoreBB upgrade lock clear failed', 'update_lock_clear_failed', $e->getMessage());
                }
            }
        } elseif ($action === 'upload_package') {
            try {
                $packageSummary = corebb_update_handle_package_upload(is_array($files['update_package'] ?? null) ? $files['update_package'] : []);
                $messages[] = 'Update package validated: CoreBB ' . (string)($packageSummary['version'] ?? 'unknown') . '.';
                {
                    corebb_adminlog_viewer(
                        $viewer,
                        'CoreBB update package uploaded and validated',
                        'update_package_uploaded',
                        'Target version: ' . (string)($packageSummary['version'] ?? '')
                    );
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                {
                    corebb_adminlog_viewer($viewer, 'CoreBB update package validation failed', 'update_package_validation_failed', $e->getMessage());
                }
            }
        } elseif ($action === 'sideload_package') {
            try {
                $version = trim((string)($post['release_version'] ?? ''));
                $packageSummary = corebb_update_handle_official_package_download($version);
                $messages[] = 'Official package downloaded and validated: CoreBB ' . (string)($packageSummary['version'] ?? 'unknown') . '.';
                {
                    corebb_adminlog_viewer(
                        $viewer,
                        'CoreBB official update package downloaded and validated',
                        'update_package_sideloaded',
                        'Target version: ' . (string)($packageSummary['version'] ?? '')
                    );
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                {
                    corebb_adminlog_viewer($viewer, 'CoreBB official update package download failed', 'update_package_sideload_failed', $e->getMessage());
                }
            }
        } elseif ($action === 'preview_package') {
            try {
                $preview = corebb_update_build_preview($packageSummary);
                if (!$preview) {
                    throw new RuntimeException('Upload and validate an update package before generating a preview.');
                }
                $messages[] = 'Upgrade preview generated for CoreBB ' . (string)($preview['package_version'] ?? 'unknown') . '.';
                {
                    corebb_adminlog_viewer(
                        $viewer,
                        'CoreBB update preview generated',
                        'update_preview_generated',
                        'Target version: ' . (string)($preview['package_version'] ?? '')
                    );
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        } elseif ($action === 'apply_package') {
            try {
                if ((string)($post['backup_confirmed'] ?? '') !== '1') {
                    throw new RuntimeException('Confirm that you have backed up the CoreBB files and database before applying an upgrade.');
                }
                if (!$packageSummary) {
                    throw new RuntimeException('Upload and validate an update package before applying an upgrade.');
                }
                {
                    corebb_adminlog_viewer(
                        $viewer,
                        'CoreBB update started',
                        'update_started',
                        'Target version: ' . (string)($packageSummary['version'] ?? '')
                    );
                }
                $result = corebb_update_apply_package($packageSummary);
                $packageSummary = null;
                $preview = null;
                $messages[] = 'CoreBB upgrade completed to version ' . (string)($result['version'] ?? 'unknown') . '. Copied '
                    . (int)($result['copied_files'] ?? 0) . ' file(s), deleted '
                    . (int)($result['deleted_files'] ?? 0) . ' obsolete file(s), applied '
                    . (int)($result['migrations'] ?? 0) . ' migration(s), and cleared '
                    . (int)($result['cache_removed'] ?? 0) . ' cache item(s).';
                {
                    corebb_adminlog_viewer(
                        $viewer,
                        'CoreBB update completed',
                        'update_completed',
                        'Target version: ' . (string)($result['version'] ?? '')
                    );
                }
            } catch (Throwable $e) {
                $errors[] = corebb_update_failure_recovery_message($e);
                {
                    corebb_adminlog_viewer($viewer, 'CoreBB update failed', corebb_update_failure_action_type($e), $e->getMessage());
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
        'lock' => corebb_update_lock_status(),
        'package' => $packageSummary,
        'preview' => $preview,
        'zip_available' => class_exists('ZipArchive'),
        'official_download_available' => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
        'package_max_mb' => (int)(COREBB_UPDATE_PACKAGE_MAX_BYTES / 1048576),
    ];
}
