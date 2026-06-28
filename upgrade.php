<?php
require_once __DIR__ . '/lib/helpers/admin_log_helpers.php';
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  upgrade.php - Authenticated CoreBB upgrade recovery. |
 +-------------------------------------------------------+*/

define('IN_ADMIN', true);

require_once __DIR__ . '/lib/helpers/bootstrap.php';
require_once __DIR__ . '/lib/models/admin_user_tools_view_model.php';
require_once __DIR__ . '/lib/helpers/corebb_update_package_helpers.php';

function corebb_upgrade_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function corebb_upgrade_array_text($value): string
{
    if (!is_array($value) || !$value) {
        return 'None';
    }
    return implode(', ', array_map(static fn($item): string => (string)$item, $value));
}

function corebb_upgrade_deny(string $message, int $status = 403): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>CoreBB Upgrade Recovery</title></head><body>';
    echo '<h1>CoreBB Upgrade Recovery</h1>';
    echo '<p>' . corebb_upgrade_h($message) . '</p>';
    echo '</body></html>';
    exit;
}

function corebb_upgrade_log(array $viewer, string $action, string $type, string $description = ''): void
{
    {
        corebb_adminlog_viewer($viewer, $action, $type, $description);
    }
}

function corebb_upgrade_process_action(array $viewer, ?array &$packageSummary, ?array &$preview): array
{
    $messages = [];
    $errors = [];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return [$messages, $errors];
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate_preview') {
            $preview = corebb_update_build_preview($packageSummary);
            if (!$preview) {
                throw new RuntimeException('No validated update package is currently staged.');
            }
            $messages[] = 'Recovery preview generated for CoreBB ' . (string)($preview['package_version'] ?? 'unknown') . '.';
            corebb_upgrade_log($viewer, 'CoreBB recovery preview generated', 'update_preview_generated', 'Target version: ' . (string)($preview['package_version'] ?? ''));
        } elseif ($action === 'apply_package') {
            if ((string)($_POST['backup_confirmed'] ?? '') !== '1') {
                throw new RuntimeException('Confirm that you have backed up the CoreBB files and database before applying an upgrade.');
            }
            if (!$packageSummary) {
                throw new RuntimeException('No validated update package is currently staged.');
            }
            corebb_upgrade_log($viewer, 'CoreBB recovery update started', 'update_started', 'Target version: ' . (string)($packageSummary['version'] ?? ''));
            $result = corebb_update_apply_package($packageSummary);
            $packageSummary = null;
            $preview = null;
            $messages[] = 'CoreBB upgrade completed to version ' . (string)($result['version'] ?? 'unknown') . '. Copied '
                . (int)($result['copied_files'] ?? 0) . ' file(s), deleted '
                . (int)($result['deleted_files'] ?? 0) . ' obsolete file(s), applied '
                . (int)($result['migrations'] ?? 0) . ' migration(s), and cleared '
                . (int)($result['cache_removed'] ?? 0) . ' cache item(s).';
            corebb_upgrade_log($viewer, 'CoreBB recovery update completed', 'update_completed', 'Target version: ' . (string)($result['version'] ?? ''));
        } elseif ($action === 'clear_update_lock') {
            if ((string)($_POST['clear_lock_confirmed'] ?? '') !== '1') {
                throw new RuntimeException('Confirm that the failed upgrade has been reviewed before clearing the upgrade lock.');
            }
            $lock = corebb_update_lock_status();
            if (empty($lock['exists'])) {
                throw new RuntimeException('No upgrade lock exists.');
            }
            $disableMaintenance = (string)($_POST['disable_maintenance'] ?? '') === '1';
            corebb_update_clear_upgrade_lock($disableMaintenance);
            $messages[] = $disableMaintenance
                ? 'Upgrade lock cleared and maintenance mode disabled.'
                : 'Upgrade lock cleared.';
            corebb_upgrade_log(
                $viewer,
                'CoreBB recovery upgrade lock cleared',
                'update_lock_cleared',
                'Target version: ' . (string)($lock['target_version'] ?? '') . '; maintenance disabled: ' . ($disableMaintenance ? 'yes' : 'no')
            );
        } elseif ($action === 'disable_maintenance') {
            if ((string)($_POST['disable_maintenance_confirmed'] ?? '') !== '1') {
                throw new RuntimeException('Confirm that you want to disable maintenance mode.');
            }
            corebb_update_set_maintenance_mode(false);
            $messages[] = 'Maintenance mode disabled.';
            corebb_upgrade_log($viewer, 'CoreBB recovery maintenance mode disabled', 'update_maintenance_disabled');
        } else {
            throw new RuntimeException('Unknown upgrade recovery action.');
        }
    } catch (Throwable $e) {
        $errors[] = corebb_update_failure_recovery_message($e);
        if ($action === 'apply_package') {
            corebb_upgrade_log($viewer, 'CoreBB recovery update failed', corebb_update_failure_action_type($e), $e->getMessage());
        } elseif ($action !== '') {
            corebb_upgrade_log($viewer, 'CoreBB recovery action failed', 'update_recovery_failed', $e->getMessage());
        }
    }

    return [$messages, $errors];
}

if (!corebb_load_logged_in_user()) {
    header('Location: ' . corebb_public_join_base_path('/'));
    exit;
}

$viewer = is_array($GLOBALS['userlogindata_a'] ?? null) ? $GLOBALS['userlogindata_a'] : [];
if (!corebb_admin_can_access_admin($viewer) || (int)($viewer['accesslevel'] ?? 0) < 5 || !corebb_admin_can_access_tool($viewer, 'updates')) {
    corebb_upgrade_deny('Full administrator access is required to use upgrade recovery.');
}

$packageSummary = corebb_update_last_package_summary();
$preview = null;
$initialErrors = [];
if ($packageSummary) {
    try {
        $preview = corebb_update_build_preview($packageSummary);
    } catch (Throwable $e) {
        $initialErrors[] = 'The staged update package could not be previewed: ' . $e->getMessage();
    }
}
[$messages, $errors] = corebb_upgrade_process_action($viewer, $packageSummary, $preview);
$errors = array_merge($initialErrors, $errors);
$status = corebb_update_status();
$lock = corebb_update_lock_status();
$maintenanceOn = corebb_update_setting('maintenancemode', '0') === '1';
$csrfField = corebb_security_csrf_field();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>CoreBB Upgrade Recovery</title>
    <style>
        body { background: #1f2427; color: #dbeafe; font: 14px Verdana, Arial, sans-serif; margin: 0; padding: 20px; }
        a { color: #8ec5ff; }
        h1 { color: #9cc8ff; font-size: 24px; margin: 0 0 4px; }
        h2 { color: #ffffff; font-size: 16px; margin: 0 0 10px; }
        .meta, .help { color: #a9bed8; font-size: 12px; }
        .wrap { max-width: 1180px; margin: 0 auto; }
        .panel { border: 1px solid #343d45; background: #20262a; margin: 12px 0; padding: 12px; }
        .note { border-left: 4px solid #8ec5ff; background: #242b30; padding: 10px; margin: 10px 0; }
        .note.warning { border-color: #f4c542; color: #ffe082; }
        .note.danger { border-color: #ff7a66; color: #ffb0a4; }
        .message { color: #ffd966; font-weight: bold; margin: 8px 0; }
        .error { color: #ff9a8d; font-weight: bold; margin: 8px 0; }
        table { border-collapse: collapse; width: 100%; margin: 8px 0; }
        th, td { border-top: 1px solid #343d45; padding: 8px; text-align: left; vertical-align: top; }
        th { width: 240px; background: #2a3136; color: #ffffff; }
        button, .button { background: #25364a; border: 1px solid #38526d; color: #dbeafe; cursor: pointer; display: inline-block; font-weight: bold; padding: 6px 10px; text-decoration: none; }
        button.danger { background: #4a2d2b; border-color: #7a4039; }
        form { margin: 10px 0 0; }
        label { display: block; margin: 8px 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>CoreBB Upgrade Recovery</h1>
    <div class="meta">
        Signed in as <?php echo corebb_upgrade_h($viewer['username'] ?? 'Administrator'); ?>.
        <a href="/admin/?act=updates">Admin Updates</a>
        <a href="/admin/">Admin Home</a>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="message"><?php echo corebb_upgrade_h($message); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="error"><?php echo corebb_upgrade_h($error); ?></div>
    <?php endforeach; ?>

    <div class="panel">
        <h2>Status</h2>
        <table>
            <tbody>
                <tr><th>Installed Version</th><td><?php echo corebb_upgrade_h($status['installed_version'] ?? 'Unknown'); ?></td></tr>
                <tr><th>Schema Version</th><td><?php echo corebb_upgrade_h($status['schema_version'] ?? 'Unknown'); ?></td></tr>
                <tr><th>Latest Stable</th><td><?php echo corebb_upgrade_h(($status['latest_stable'] ?? '') !== '' ? $status['latest_stable'] : 'Unknown'); ?></td></tr>
                <tr><th>Maintenance Mode</th><td><?php echo $maintenanceOn ? 'Enabled' : 'Disabled'; ?></td></tr>
                <tr><th>Staged Package</th><td><?php echo $packageSummary ? corebb_upgrade_h($packageSummary['version'] ?? 'Unknown') : 'None'; ?></td></tr>
                <tr><th>Upgrade Lock</th><td><?php echo !empty($lock['exists']) ? 'Present' : 'None'; ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if (!empty($lock['exists'])): ?>
        <div class="panel">
            <h2>Upgrade Lock</h2>
            <div class="note <?php echo !empty($lock['stale']) ? 'danger' : 'warning'; ?>">
                <?php echo !empty($lock['stale']) ? 'A stale upgrade lock is present.' : 'An upgrade lock is present.'; ?>
                Review the server state before clearing it.
            </div>
            <table>
                <tbody>
                    <tr><th>Target Version</th><td><?php echo corebb_upgrade_h(($lock['target_version'] ?? '') !== '' ? $lock['target_version'] : 'Unknown'); ?></td></tr>
                    <tr><th>Started</th><td><?php echo corebb_upgrade_h($lock['created_label'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>Age</th><td><?php echo corebb_upgrade_h($lock['age_label'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>Lock Path</th><td><?php echo corebb_upgrade_h($lock['path'] ?? ''); ?></td></tr>
                </tbody>
            </table>
            <form method="post" action="/upgrade.php">
                <?php echo $csrfField; ?>
                <input type="hidden" name="action" value="clear_update_lock">
                <label><input type="checkbox" name="clear_lock_confirmed" value="1"> I have reviewed the failed upgrade and want to clear this lock.</label>
                <label><input type="checkbox" name="disable_maintenance" value="1" checked> Disable maintenance mode when clearing the lock.</label>
                <button class="danger" type="submit">Clear Upgrade Lock</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($maintenanceOn): ?>
        <div class="panel">
            <h2>Maintenance Mode</h2>
            <div class="note warning">Maintenance mode is enabled. Disable it only after you have confirmed the forum state is safe.</div>
            <form method="post" action="/upgrade.php">
                <?php echo $csrfField; ?>
                <input type="hidden" name="action" value="disable_maintenance">
                <label><input type="checkbox" name="disable_maintenance_confirmed" value="1"> I want to disable maintenance mode.</label>
                <button class="danger" type="submit">Disable Maintenance Mode</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Staged Package</h2>
        <?php if (!$packageSummary): ?>
            <div class="note">No validated update package is currently staged. Upload and validate a package from Admin Updates first.</div>
        <?php else: ?>
            <table>
                <tbody>
                    <tr><th>Package Version</th><td><?php echo corebb_upgrade_h($packageSummary['version'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>Installed Version</th><td><?php echo corebb_upgrade_h($packageSummary['installed_version'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>Release Type</th><td><?php echo corebb_upgrade_h($packageSummary['release_type'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>Schema Version</th><td><?php echo corebb_upgrade_h($packageSummary['schema_version'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>ZIP SHA-256</th><td><?php echo corebb_upgrade_h($packageSummary['zip_sha256'] ?? ''); ?></td></tr>
                    <tr><th>Replace Paths</th><td><?php echo corebb_upgrade_h(corebb_upgrade_array_text($packageSummary['replace'] ?? [])); ?></td></tr>
                    <tr><th>Obsolete Files</th><td><?php echo corebb_upgrade_h(corebb_upgrade_array_text($packageSummary['delete_files'] ?? [])); ?></td></tr>
                </tbody>
            </table>
            <form method="post" action="/upgrade.php">
                <?php echo $csrfField; ?>
                <input type="hidden" name="action" value="generate_preview">
                <button type="submit">Regenerate Preview</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($preview): ?>
        <div class="panel">
            <h2>Upgrade Preview</h2>
            <table>
                <tbody>
                    <tr><th>Package Version</th><td><?php echo corebb_upgrade_h($preview['package_version'] ?? 'Unknown'); ?></td></tr>
                    <tr><th>Will Replace</th><td><?php echo corebb_upgrade_h(corebb_upgrade_array_text($preview['replace_paths'] ?? [])); ?></td></tr>
                    <tr><th>Will Preserve</th><td><?php echo corebb_upgrade_h(corebb_upgrade_array_text($preview['preserve_paths'] ?? [])); ?></td></tr>
                    <tr><th>Will Delete</th><td><?php echo corebb_upgrade_h(corebb_upgrade_array_text($preview['delete_files'] ?? [])); ?></td></tr>
                    <tr><th>Pending Migrations</th><td><?php echo corebb_upgrade_h(corebb_upgrade_array_text($preview['migrations'] ?? [])); ?></td></tr>
                </tbody>
            </table>
            <h2>Preflight</h2>
            <table>
                <tbody>
                    <?php foreach (($preview['preflight'] ?? []) as $check): ?>
                        <tr>
                            <th><?php echo corebb_upgrade_h($check['label'] ?? 'Check'); ?></th>
                            <td><strong><?php echo !empty($check['ok']) ? 'OK' : 'Needs Attention'; ?></strong><br><span class="help"><?php echo corebb_upgrade_h($check['detail'] ?? ''); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="/upgrade.php">
                <?php echo $csrfField; ?>
                <input type="hidden" name="action" value="apply_package">
                <label><input type="checkbox" name="backup_confirmed" value="1"> I have backed up the CoreBB files and database.</label>
                <button class="danger" type="submit"<?php echo empty($preview['ok']) ? ' disabled' : ''; ?>>Apply Staged Upgrade</button>
                <div class="help">This uses only the already validated staged package. It does not accept uploads or filesystem paths.</div>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
