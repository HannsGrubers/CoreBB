<?php

require_once __DIR__ . '/corebb_update_package_helpers.php';

/** Only call with a signature-verified catalog. Unknown compatibility never implies a usable hop. */
function corebb_update_route_catalog(array $manifest, string $phpVersion = PHP_VERSION): array
{
    $legacy = json_decode((string)@file_get_contents(dirname(__DIR__, 2) . '/core/legacy-package-routes.json'), true) ?: [];
    $catalog = [];
    foreach ($manifest['releases'] ?? [] as $row) {
        if (!is_array($row) || !is_string($row['version'] ?? null)) { continue; }
        $version = $row['version'];
        try {
            corebb_update_validate_official_download_release($row, $version);
        } catch (RuntimeException $e) { continue; }
        $sha = strtolower($row['sha256']);
        $known = $legacy[$version] ?? [];
        if (!isset($row['from_versions']) && ($known['sha256'] ?? '') === $sha) {
            $row += $known;
        }
        $from = $row['from_versions'] ?? null;
        $php = $row['requires_php'] ?? null;
        if (!is_string($from) || !corebb_update_version_range_valid($from)
            || !is_string($php) || !corebb_update_version_range_matches($php, $phpVersion)) { continue; }
        $catalog[$version] = ['version'=>$version, 'from'=>$from, 'sha256'=>$sha, 'php'=>$php, 'type'=>(string)($row['type'] ?? '')];
    }
    uksort($catalog, static fn($a, $b) => version_compare($b, $a));
    return $catalog;
}

/** Breadth-first search over strictly newer, compatible releases. Each edge costs one upgrade. */
function corebb_update_route_steps(array $catalog, string $start, string $target): array
{
    if (!isset($catalog[$target]) || version_compare($start, $target, '>=')) { return []; }
    $queue = [$start];
    $parents = [$start => null];
    for ($i = 0; $i < count($queue); ++$i) {
        $from = $queue[$i];
        foreach ($catalog as $version => $row) {
            if (isset($parents[$version]) || version_compare($from, $version, '>=')
                || version_compare($version, $target, '>') || !corebb_update_version_range_matches($row['from'], $from)) { continue; }
            $parents[$version] = $from;
            if ($version === $target) {
                $steps = [];
                for ($at = $target; $at !== $start; $at = $parents[$at]) {
                    $steps[] = $catalog[$at];
                }
                return array_reverse($steps);
            }
            $queue[] = $version;
        }
    }
    return [];
}

/** Store just the endpoints and a digest. Full paths can exceed older settings column limits. */
function corebb_update_route_state(array $manifest, string $installed, string $requested, string $setting): array
{
    $raw = corebb_update_setting($setting, '');
    $saved = json_decode($raw, true);
    $start = $raw === '' ? $installed : (string)($saved['start'] ?? '');
    $target = $raw === '' ? ($requested ?: (string)($manifest['latest_stable'] ?? '')) : (string)($saved['target'] ?? '');
    $catalog = corebb_update_route_catalog($manifest);
    $steps = corebb_update_route_steps($catalog, $start, $target);
    $fingerprint = hash('sha256', json_encode([$start, $target, $steps], JSON_THROW_ON_ERROR));
    $positions = array_merge([$start], array_column($steps, 'version'));
    $position = array_search($installed, $positions, true);
    $problem = '';
    if ($raw !== '' && (($saved['fingerprint'] ?? '') !== $fingerprint || $position === false)) {
        $problem = 'The catalog, PHP compatibility, or installed version has changed. Clear the saved route and preview a new one.';
    } elseif ($target === $installed) {
        // A completed route stays visible until dismissed.
    } elseif (!$manifest) {
        $problem = 'Check for updates to load the signed release catalog.';
    } elseif ($target !== '' && version_compare($target, $installed, '<')) {
        $problem = 'This forum is already newer than the selected destination.';
    } elseif (!$steps) {
        $problem = 'No compatible forward route is listed for this destination on this PHP version.';
    }
    return [
        'catalog'=>$catalog, 'installed'=>$installed, 'start'=>$start, 'target'=>$target,
        'steps'=>$steps, 'active'=>$raw !== '', 'position'=>$position, 'fingerprint'=>$fingerprint,
        'next'=>$problem === '' && $position !== false ? ($steps[$position] ?? null) : null,
        'problem'=>$problem,
    ];
}

function corebb_update_route_run(array $viewer, array $state, array $post, string $setting): array
{
    if ((int)($viewer['accesslevel'] ?? 0) < 5 || !corebb_security_csrf_valid($post)) {
        throw new RuntimeException('Administrator access and a valid form token are required.');
    }
    $action = $post['action'] ?? '';
    if (($post['fingerprint'] ?? '') !== $state['fingerprint']) {
        throw new RuntimeException('The upgrade route changed. Review it again before continuing.');
    }
    if ($action === 'route_cancel') {
        if (!corebb_update_set_setting($setting, '')) { throw new RuntimeException('Could not clear the saved route.'); }
        return ['message'=>'Saved route cleared.'];
    }
    if (!$state['next'] || !in_array($action, ['route_start', 'route_next'], true)
        || ($post['expected_version'] ?? '') !== $state['installed'] || ($action === 'route_next') !== $state['active']) {
        throw new RuntimeException('This step is no longer current. Review the route before continuing.');
    }
    if (corebb_update_lock_status()['exists'] || corebb_update_setting('upgrade_maintenance_active', '0') === '1') {
        throw new RuntimeException('An upgrade is already running or needs recovery. Resolve it before continuing.');
    }
    if ($action === 'route_start') {
        if (($post['backup_confirmed'] ?? '') !== '1') { throw new RuntimeException('Confirm your file and database backup before starting.'); }
        $checkpoint = ['start'=>$state['start'], 'target'=>$state['target'], 'fingerprint'=>$state['fingerprint']];
        if (!corebb_update_set_setting($setting, json_encode($checkpoint, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Could not save the upgrade route.');
        }
    }

    $next = $state['next'];
    corebb_adminlog_viewer($viewer, 'Started upgrade route step to ' . $next['version'], 'update_started');
    $summary = corebb_update_handle_official_package_download($next['version']);
    if ($summary['zip_sha256'] !== $next['sha256'] || $summary['version'] !== $next['version']
        || $summary['installed_version'] !== $state['installed'] || $summary['from_versions'] !== $next['from']
        || $summary['requires_php'] !== $next['php']) {
        throw new RuntimeException('The validated package differs from the reviewed route. No files were applied.');
    }
    corebb_update_apply_package($summary); // Includes fresh preflight, lock, migrations and cleanup.
    if (corebb_update_installed_version() !== $next['version']) {
        throw new RuntimeException('The installed version could not be confirmed. Review recovery before continuing.');
    }
    corebb_adminlog_viewer($viewer, 'Completed upgrade route step to ' . $next['version'], 'update_completed');
    return ['message'=>'Installed CoreBB ' . $next['version'] . '.', 'installed'=>$next['version'], 'fingerprint'=>$state['fingerprint']];
}
