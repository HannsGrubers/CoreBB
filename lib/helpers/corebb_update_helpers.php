<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  corebb_update_helpers.php  - Admin update status.    |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../../core/version.php';
require_once __DIR__ . '/admin_helpers.php';

const COREBB_UPDATE_MANIFEST_URL = 'https://corebb.net/security/updates.json';

class CoreBB_UpdateSignatureVerifier
{
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAjvN9ZtNSbaeGfGNDDgff
zZpB4SSmIhInts5u3kfELdivqTr3chp2Ttl75BnvQmu3MV9yppDa5587NY9Rj1op
h9+RKjFSQikAlf7jj5hUWLGcBwHTvsoj6gGMtuqsHBCXkPCQ+v2IMJSCxJWfHgoL
8rO+Q8zGhpcGhrHVHm8NoupVFuVU6M6Y1L9MdbIU8j2mVdo3fuYitcsDvCXENOcs
pKBJJWI0zZvL/TqPEQE8NRHjPiXHc3J5BfOpzUmiz4OxQUc5bECVuuq6XgTkF3gz
Ov1dtAfHVM7gseFPsgrSJ70f4bfZ7aG1TAbIDY5IPjTTphGN54KzArCnOIG7RxqY
y7PYq/Nge+eb88FmLecmFoOiLj0wJ9LgNKow7KFEjbo88re9ofbRAcBSYFjZyCpW
MBMQaHsKHDWHexaQNO82EAQSJD/HQ2jePg1C13D64sVrVXtWdQ9bJucwf0h3U5XK
nU3hWSzWStCciU0Vyo2Y3n2cuABQTW+d9014FLaip/bzAgMBAAE=
-----END PUBLIC KEY-----
PEM;

    public function isConfigured(): bool
    {
        return extension_loaded('openssl') && trim(self::PUBLIC_KEY) !== '';
    }

    /**
     * @return array{ok: bool, status: string, message: string}
     */
    public function verify(string $manifestBody, string $signatureBody = ''): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'status' => 'signature_unconfigured',
                'message' => 'Manifest signature verification is not configured.',
            ];
        }

        $signatureBody = preg_replace('/\s+/', '', $signatureBody) ?? '';
        if ($signatureBody === '') {
            return [
                'ok' => false,
                'status' => 'signature_missing',
                'message' => 'Update manifest signature is missing.',
            ];
        }

        $signature = base64_decode($signatureBody, true);
        if (!is_string($signature)) {
            return [
                'ok' => false,
                'status' => 'signature_invalid',
                'message' => 'Update manifest signature is not valid base64.',
            ];
        }

        $publicKey = openssl_pkey_get_public(self::PUBLIC_KEY);
        if ($publicKey === false) {
            return [
                'ok' => false,
                'status' => 'signature_key_invalid',
                'message' => 'Update manifest public key is invalid.',
            ];
        }

        $result = openssl_verify($manifestBody, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result === 1) {
            return [
                'ok' => true,
                'status' => 'verified',
                'message' => 'Update manifest signature verified.',
            ];
        }

        return [
            'ok' => false,
            'status' => 'signature_invalid',
            'message' => 'Update manifest signature verification failed.',
        ];
    }
}

/**
 * @return array<string, string>
 */
function corebb_update_default_settings(): array
{
    return [
        'installed_version' => COREBB_VERSION,
        'schema_version' => (string)COREBB_SCHEMA_VERSION,
        'last_update_check_at' => '',
        'last_update_check_status' => 'never',
        'last_update_manifest' => '',
        'last_successful_update_check_at' => '',
        'last_update_check_error' => '',
        'update_manifest_signature_status' => 'unsigned',
        'last_update_package_path' => '',
        'last_update_package_summary' => '',
    ];
}

function corebb_update_setting(string $name, string $default = ''): string
{
    return corebb_admin_setting_get($name, $default);
}

function corebb_update_set_setting(string $name, string $value): bool
{
    return corebb_admin_setting_upsert($name, $value);
}

/**
 * Usage: Seed update-related settings without requiring a migration yet.
 * Referenced by: update status/admin page builders.
 */
function corebb_update_ensure_settings(): void
{
    foreach (corebb_update_default_settings() as $name => $default) {
        if (corebb_update_setting($name, "\0") === "\0") {
            corebb_update_set_setting($name, $default);
        }
    }
}

function corebb_update_installed_version(): string
{
    corebb_update_ensure_settings();
    $version = trim(corebb_update_setting('installed_version', COREBB_VERSION));
    return $version !== '' ? $version : COREBB_VERSION;
}

function corebb_update_schema_version(): string
{
    corebb_update_ensure_settings();
    $version = trim(corebb_update_setting('schema_version', (string)COREBB_SCHEMA_VERSION));
    return $version !== '' ? $version : (string)COREBB_SCHEMA_VERSION;
}

/**
 * @return array<string, mixed>|null
 */
function corebb_update_cached_manifest(): ?array
{
    corebb_update_ensure_settings();
    $raw = trim(corebb_update_setting('last_update_manifest', ''));
    if ($raw === '') {
        return null;
    }
    $raw = corebb_update_json_body($raw);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Usage: Normalize manifest bytes before JSON decoding.
 * Referenced by: cached and freshly fetched manifest reads.
 */
function corebb_update_json_body(string $body): string
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    return ltrim($body, "\x00..\x1F");
}

function corebb_update_cache_bust_url(string $url): string
{
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'corebb_cache_bust=' . rawurlencode((string)time());
    }
    return $url;
}

function corebb_update_signature_url(string $manifestUrl): string
{
    $parts = explode('?', $manifestUrl, 2);
    return $parts[0] . '.sig' . (isset($parts[1]) ? '?' . $parts[1] : '');
}

function corebb_update_fetch_url(string $url, string $accept): string|false
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "Accept: $accept\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    return @file_get_contents(corebb_update_cache_bust_url($url), false, $context);
}

function corebb_update_version_valid(string $version): bool
{
    return preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9.]+)?$/', $version) === 1;
}

function corebb_update_version_range_matches(string $range, string $version): bool
{
    $range = trim($range);
    if ($range === '') {
        return false;
    }
    foreach (preg_split('/\s+/', $range) ?: [] as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^(>=|>|<=|<|=)?(\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9.]+)?)$/', $part, $m) !== 1) {
            return false;
        }
        $op = $m[1] !== '' ? $m[1] : '=';
        if (!version_compare($version, $m[2], $op)) {
            return false;
        }
    }
    return true;
}

function corebb_update_version_range_valid(string $range): bool
{
    $range = trim($range);
    if ($range === '') {
        return false;
    }
    foreach (preg_split('/\s+/', $range) ?: [] as $part) {
        if ($part !== '' && preg_match('/^(>=|>|<=|<|=)?\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9.]+)?$/', $part) !== 1) {
            return false;
        }
    }
    return true;
}

/**
 * @return array{ok: bool, manifest: array<string, mixed>|null, error: string}
 */
function corebb_update_validate_manifest(string $body): array
{
    $body = corebb_update_json_body($body);
    $manifest = json_decode($body, true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest is not valid JSON.'];
    }
    if ((string)($manifest['project'] ?? '') !== 'corebb') {
        return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest project is not corebb.'];
    }
    if ((int)($manifest['schema'] ?? 0) < 1) {
        return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest schema is missing or unsupported.'];
    }

    foreach (['latest_stable', 'latest_security', 'minimum_safe_version'] as $field) {
        $value = trim((string)($manifest[$field] ?? ''));
        if ($value !== '' && !corebb_update_version_valid($value)) {
            return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest has an invalid ' . $field . ' value.'];
        }
    }

    if (isset($manifest['releases']) && !is_array($manifest['releases'])) {
        return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest releases must be an array.'];
    }
    if (isset($manifest['advisories']) && !is_array($manifest['advisories'])) {
        return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest advisories must be an array.'];
    }
    if (isset($manifest['upgrade_path'])) {
        if (!is_array($manifest['upgrade_path'])) {
            return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest upgrade_path must be an array.'];
        }
        foreach ($manifest['upgrade_path'] as $step) {
            if (!is_array($step)) {
                return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest upgrade_path entries must be objects.'];
            }
            $from = trim((string)($step['from'] ?? ''));
            $next = trim((string)($step['next'] ?? ''));
            if (!corebb_update_version_range_valid($from)) {
                return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest upgrade_path has an invalid from range.'];
            }
            if (!corebb_update_version_valid($next)) {
                return ['ok' => false, 'manifest' => null, 'error' => 'Update manifest upgrade_path has an invalid next version.'];
            }
        }
    }

    return ['ok' => true, 'manifest' => $manifest, 'error' => ''];
}

/**
 * @return array{ok: bool, message: string, manifest: array<string, mixed>|null}
 */
function corebb_update_fetch_manifest(string $url = COREBB_UPDATE_MANIFEST_URL): array
{
    $body = corebb_update_fetch_url($url, 'application/json');
    $now = date('Y-m-d H:i:s');
    corebb_update_set_setting('last_update_check_at', $now);

    if (!is_string($body) || trim($body) === '') {
        $message = 'Unable to fetch update manifest.';
        corebb_update_set_setting('last_update_check_status', 'failed');
        corebb_update_set_setting('last_update_check_error', $message);
        return ['ok' => false, 'message' => $message, 'manifest' => null];
    }
    $body = corebb_update_json_body($body);

    $validation = corebb_update_validate_manifest($body);
    if (!$validation['ok'] || !is_array($validation['manifest'])) {
        $message = (string)$validation['error'];
        corebb_update_set_setting('last_update_check_status', 'failed');
        corebb_update_set_setting('last_update_check_error', $message);
        return ['ok' => false, 'message' => $message, 'manifest' => null];
    }

    $verifier = new CoreBB_UpdateSignatureVerifier();
    $signatureBody = corebb_update_fetch_url(corebb_update_signature_url($url), 'text/plain');
    $signature = $verifier->verify($body, is_string($signatureBody) ? $signatureBody : '');
    if (!$signature['ok']) {
        corebb_update_set_setting('last_update_check_status', $signature['status']);
        corebb_update_set_setting('last_update_check_error', $signature['message']);
        corebb_update_set_setting('update_manifest_signature_status', $signature['status']);
        return ['ok' => false, 'message' => $signature['message'], 'manifest' => null];
    }

    corebb_update_set_setting('last_update_manifest', $body);
    corebb_update_set_setting('last_successful_update_check_at', $now);
    corebb_update_set_setting('last_update_check_status', $signature['status']);
    corebb_update_set_setting('last_update_check_error', '');
    corebb_update_set_setting('update_manifest_signature_status', $signature['status']);

    return [
        'ok' => true,
        'message' => 'Update manifest fetched and verified.',
        'manifest' => $validation['manifest'],
    ];
}

function corebb_update_timestamp(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return 0;
    }
    $time = strtotime($value);
    return $time === false ? 0 : $time;
}

function corebb_update_format_date(string $value): string
{
    $time = corebb_update_timestamp($value);
    return $time > 0 ? date('M j, Y g:i A', $time) : 'Never';
}

/**
 * @param array<string, mixed>|null $manifest
 * @return array<string, string>
 */
function corebb_update_advisory_summary(?array $manifest, string $installedVersion): array
{
    $summary = ['severity' => '', 'title' => '', 'url' => ''];
    $weights = ['critical' => 5, 'high' => 4, 'medium' => 3, 'moderate' => 3, 'low' => 2, 'info' => 1];
    $bestWeight = 0;

    foreach (($manifest['advisories'] ?? []) as $advisory) {
        if (!is_array($advisory)) {
            continue;
        }
        $fixedIn = trim((string)($advisory['fixed_in'] ?? ''));
        if ($fixedIn !== '' && corebb_update_version_valid($fixedIn) && version_compare($installedVersion, $fixedIn, '>=')) {
            continue;
        }
        $severity = strtolower(trim((string)($advisory['severity'] ?? '')));
        $weight = $weights[$severity] ?? 0;
        if ($weight > $bestWeight) {
            $bestWeight = $weight;
            $summary = [
                'severity' => ucfirst($severity),
                'title' => (string)($advisory['title'] ?? ''),
                'url' => (string)($advisory['url'] ?? ''),
            ];
        }
    }

    return $summary;
}

/**
 * @param array<string, mixed>|null $manifest
 * @return array{version: string, from: string, found: bool}
 */
function corebb_update_recommended_step(?array $manifest, string $installedVersion): array
{
    $empty = ['version' => '', 'from' => '', 'found' => false];
    if (!$manifest) {
        return $empty;
    }

    foreach (($manifest['upgrade_path'] ?? []) as $step) {
        if (!is_array($step)) {
            continue;
        }
        $from = trim((string)($step['from'] ?? ''));
        $next = trim((string)($step['next'] ?? ''));
        if ($next === '' || !corebb_update_version_valid($next) || version_compare($installedVersion, $next, '>=')) {
            continue;
        }
        if ($from !== '' && corebb_update_version_range_matches($from, $installedVersion)) {
            $found = false;
            foreach (($manifest['releases'] ?? []) as $release) {
                if (is_array($release) && (string)($release['version'] ?? '') === $next) {
                    $found = true;
                    break;
                }
            }
            return ['version' => $next, 'from' => $from, 'found' => $found];
        }
    }

    return $empty;
}

/**
 * @param array<string, mixed>|null $manifest
 * @return array<string, mixed>
 */
function corebb_update_status(?array $manifest = null): array
{
    corebb_update_ensure_settings();
    $manifest = $manifest ?? corebb_update_cached_manifest();
    $installed = corebb_update_installed_version();
    $schemaVersion = corebb_update_schema_version();
    $lastCheck = corebb_update_setting('last_update_check_at', '');
    $lastSuccess = corebb_update_setting('last_successful_update_check_at', '');
    $checkStatus = corebb_update_setting('last_update_check_status', 'never');
    $signatureStatus = corebb_update_setting('update_manifest_signature_status', 'unsigned');
    $error = corebb_update_setting('last_update_check_error', '');
    $stale = corebb_update_timestamp($lastSuccess) > 0 && corebb_update_timestamp($lastSuccess) < (time() - 7 * 86400);

    $latestStable = is_array($manifest) ? trim((string)($manifest['latest_stable'] ?? '')) : '';
    $latestSecurity = is_array($manifest) ? trim((string)($manifest['latest_security'] ?? '')) : '';
    $minimumSafe = is_array($manifest) ? trim((string)($manifest['minimum_safe_version'] ?? '')) : '';
    $manifestGenerated = is_array($manifest) ? trim((string)($manifest['generated_at'] ?? '')) : '';
    $latest = $latestSecurity !== '' ? $latestSecurity : $latestStable;
    $recommended = corebb_update_recommended_step($manifest, $installed);

    $state = 'unknown';
    $headline = 'Update status has not been checked.';
    $detail = 'Use the Updates page to check the official CoreBB manifest.';
    $severity = 'info';

    if ($checkStatus === 'failed' && !$manifest) {
        $state = 'check_failed';
        $headline = 'Security update status could not be checked.';
        $detail = $error !== '' ? $error : 'The update manifest could not be loaded.';
        $severity = 'warning';
    } elseif ($stale) {
        $state = 'stale';
        $headline = 'Update check is stale.';
        $detail = 'Last successful check: ' . corebb_update_format_date($lastSuccess) . '.';
        $severity = 'warning';
    } elseif ($minimumSafe !== '' && version_compare($installed, $minimumSafe, '<')) {
        $state = 'below_minimum';
        $headline = 'Warning: this CoreBB version is below the minimum safe version.';
        $detail = 'Installed version: ' . $installed . '. Minimum safe version: ' . $minimumSafe . '.';
        $severity = 'danger';
    } elseif ($latestSecurity !== '' && version_compare($installed, $latestSecurity, '<')) {
        $state = 'security_available';
        $headline = 'Security update available: CoreBB ' . $latestSecurity;
        $detail = 'Installed version: ' . $installed . '. Action recommended: update as soon as possible.';
        $severity = 'danger';
    } elseif ($latestStable !== '' && version_compare($installed, $latestStable, '<')) {
        $state = 'maintenance_available';
        $headline = 'CoreBB ' . $latestStable . ' is available.';
        $detail = 'Installed version: ' . $installed . '. This is a maintenance update.';
        $severity = 'warning';
    } elseif ($manifest) {
        $state = 'current';
        $headline = 'CoreBB is up to date.';
        $detail = 'Installed version: ' . $installed . '.';
        $severity = 'success';
    }

    if ($recommended['version'] !== '') {
        $detail .= ' Recommended next package: CoreBB ' . $recommended['version'] . '.';
        if (!$recommended['found']) {
            $detail .= ' That bridge package is not listed in the current manifest.';
        }
    }

    return [
        'state' => $state,
        'severity' => $severity,
        'headline' => $headline,
        'detail' => $detail,
        'installed_version' => $installed,
        'schema_version' => $schemaVersion,
        'latest_version' => $latest !== '' ? $latest : 'Unknown',
        'latest_stable' => $latestStable,
        'latest_security' => $latestSecurity,
        'minimum_safe_version' => $minimumSafe,
        'recommended_next_version' => $recommended['version'],
        'recommended_next_found' => $recommended['found'],
        'manifest_generated_at' => $manifestGenerated,
        'last_checked' => corebb_update_format_date($lastCheck),
        'last_successful_check' => corebb_update_format_date($lastSuccess),
        'check_status' => $checkStatus,
        'signature_status' => $signatureStatus,
        'error' => $error,
        'advisory' => corebb_update_advisory_summary($manifest, $installed),
    ];
}

/**
 * @return array<int, array<string, string>>
 */
function corebb_update_release_rows(?array $manifest = null): array
{
    $manifest = $manifest ?? corebb_update_cached_manifest();
    $recommended = corebb_update_recommended_step($manifest, corebb_update_installed_version());
    $recommendedVersion = $recommended['version'];
    $rows = [];
    foreach (($manifest['releases'] ?? []) as $release) {
        if (!is_array($release)) {
            continue;
        }
        $rows[] = [
            'version' => (string)($release['version'] ?? ''),
            'type' => (string)($release['type'] ?? ''),
            'date' => (string)($release['date'] ?? ''),
            'download_url' => (string)($release['download_url'] ?? ''),
            'changelog_url' => (string)($release['changelog_url'] ?? ''),
            'sha256' => (string)($release['sha256'] ?? ''),
        ];
        if (count($rows) >= 5) {
            break;
        }
    }
    if ($recommendedVersion !== '') {
        $hasRecommended = false;
        foreach ($rows as $row) {
            if (($row['version'] ?? '') === $recommendedVersion) {
                $hasRecommended = true;
                break;
            }
        }
        if (!$hasRecommended) {
            foreach (($manifest['releases'] ?? []) as $release) {
                if (is_array($release) && (string)($release['version'] ?? '') === $recommendedVersion) {
                    $rows[] = [
                        'version' => (string)($release['version'] ?? ''),
                        'type' => (string)($release['type'] ?? ''),
                        'date' => (string)($release['date'] ?? ''),
                        'download_url' => (string)($release['download_url'] ?? ''),
                        'changelog_url' => (string)($release['changelog_url'] ?? ''),
                        'sha256' => (string)($release['sha256'] ?? ''),
                        'recommended' => '1',
                    ];
                    break;
                }
            }
        }
    }
    return $rows;
}
