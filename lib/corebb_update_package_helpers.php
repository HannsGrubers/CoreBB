<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  corebb_update_package_helpers.php - Package checks.  |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_update_helpers.php';

const COREBB_UPDATE_PACKAGE_MAX_BYTES = 104857600;
const COREBB_UPDATE_DOWNLOAD_TIMEOUT_SECONDS = 45;

/**
 * @return array<int, string>
 */
function corebb_update_preserve_paths(): array
{
    return [
        'corebb_private/',
        'uploads/',
        'avatars/',
        'attachments/',
        'themes/custom/',
        'plugins/',
        'cache/',
        'tmp/',
        'temp/',
        'sessions/',
        'logs/',
        'images/user_avatars/',
        'images/post_uploads/',
        'config.php',
        'config.local.php',
        'corebb_private_config.php',
        '.env',
        '.htaccess',
    ];
}

function corebb_update_app_root(): string
{
    if (defined('COREBB_APP_ROOT')) {
        return (string)COREBB_APP_ROOT;
    }
    return dirname(__DIR__);
}

function corebb_update_private_base_dir(): string
{
    if (function_exists('corebb_config_private_base_dir')) {
        return corebb_config_private_base_dir(corebb_update_app_root());
    }
    return dirname(corebb_update_app_root()) . DIRECTORY_SEPARATOR . 'corebb_private';
}

function corebb_update_private_dir(string $child): string
{
    $base = rtrim(corebb_update_private_base_dir(), "\\/");
    $dir = $base . DIRECTORY_SEPARATOR . 'updates' . DIRECTORY_SEPARATOR . $child;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Update private directory could not be created: ' . $dir);
    }
    return $dir;
}

function corebb_update_normalize_package_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('~/+~', '/', $path) ?? $path;
    while (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }
    return trim($path, '/');
}

function corebb_update_package_path_is_safe(string $path): bool
{
    if ($path === '' || strpos($path, "\0") !== false) {
        return false;
    }
    $raw = str_replace('\\', '/', $path);
    if ($raw[0] === '/' || preg_match('/^[A-Za-z]:\//', $raw) === 1) {
        return false;
    }
    $parts = explode('/', $raw);
    foreach ($parts as $part) {
        if ($part === '..') {
            return false;
        }
    }
    return true;
}

function corebb_update_path_is_preserved(string $path): bool
{
    $path = corebb_update_normalize_package_path($path);
    foreach (corebb_update_preserve_paths() as $preserve) {
        $preserve = corebb_update_normalize_package_path($preserve);
        if (str_ends_with($preserve, '/')) {
            if (str_starts_with($path . '/', $preserve)) {
                return true;
            }
        } elseif (strcasecmp($path, $preserve) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $file
 */
function corebb_update_store_uploaded_package(array $file): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a CoreBB update ZIP to upload.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Package upload failed with error ' . $error . '.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Uploaded package could not be read.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > COREBB_UPDATE_PACKAGE_MAX_BYTES) {
        throw new RuntimeException('Uploaded package size is invalid or too large.');
    }

    $name = strtolower((string)($file['name'] ?? ''));
    if (!str_ends_with($name, '.zip')) {
        throw new RuntimeException('Uploaded package must be a ZIP file.');
    }

    $dest = corebb_update_private_dir('uploads') . DIRECTORY_SEPARATOR
        . 'corebb-update-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    if (!move_uploaded_file($tmpName, $dest)) {
        throw new RuntimeException('Uploaded package could not be moved to private storage.');
    }
    @chmod($dest, 0600);
    return $dest;
}

/**
 * @return array{root: string, files: array<int, string>}
 */
function corebb_update_extract_package(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZIP extension is not available.');
    }
    if (!is_file($zipPath)) {
        throw new RuntimeException('Update package file does not exist.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Update package could not be opened as a ZIP file.');
    }

    $root = corebb_update_private_dir('extracted') . DIRECTORY_SEPARATOR
        . 'package-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        $zip->close();
        throw new RuntimeException('Package extraction directory could not be created.');
    }

    $files = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
            if (!corebb_update_package_path_is_safe($name)) {
                throw new RuntimeException('Unsafe ZIP path rejected: ' . $name);
            }
            $isDirEntry = str_ends_with(str_replace('\\', '/', $name), '/');
            $rel = corebb_update_normalize_package_path($name);
            if ($rel === '') {
                continue;
            }

            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $targetDir = $isDirEntry ? $target : dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Could not create package extraction directory.');
            }
            if ($isDirEntry) {
                continue;
            }

            $source = $zip->getStream($name);
            if (!is_resource($source)) {
                throw new RuntimeException('Could not read ZIP entry: ' . $rel);
            }
            $dest = fopen($target, 'wb');
            if (!is_resource($dest)) {
                fclose($source);
                throw new RuntimeException('Could not write extracted package file: ' . $rel);
            }
            stream_copy_to_stream($source, $dest);
            fclose($source);
            fclose($dest);
            @chmod($target, 0600);
            $files[] = $rel;
        }
    } finally {
        $zip->close();
    }

    return ['root' => $root, 'files' => $files];
}

/**
 * @return array<string, mixed>
 */
function corebb_update_read_package_json(string $root): array
{
    $path = $root . DIRECTORY_SEPARATOR . 'package.json';
    if (!is_file($path)) {
        throw new RuntimeException('Update package is missing package.json.');
    }
    $data = json_decode(corebb_update_json_body((string)file_get_contents($path)), true);
    if (!is_array($data)) {
        throw new RuntimeException('Update package package.json is not valid JSON.');
    }
    return $data;
}

function corebb_update_validate_version_range(string $range, string $version): bool
{
    $range = trim($range);
    if ($range === '') {
        return true;
    }
    if (preg_match('/^(>=|>|<=|<|=)?\s*(\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9.]+)?)$/', $range, $m) !== 1) {
        return false;
    }
    $op = $m[1] !== '' ? $m[1] : '=';
    return version_compare($version, $m[2], $op);
}

/**
 * @param array<int, string> $paths
 * @return array<int, string>
 */
function corebb_update_clean_package_path_list(array $paths, string $label): array
{
    $clean = [];
    foreach ($paths as $path) {
        $raw = (string)$path;
        if (!corebb_update_package_path_is_safe($raw)) {
            throw new RuntimeException('Unsafe ' . $label . ' path rejected: ' . (string)$path);
        }
        $rel = corebb_update_normalize_package_path($raw);
        if (corebb_update_path_is_preserved($rel)) {
            throw new RuntimeException('Package attempts to modify preserved path: ' . $rel);
        }
        $clean[] = $rel;
    }
    return $clean;
}

/**
 * @return array{count: int, paths: array<string, bool>}
 */
function corebb_update_validate_hash_manifest(string $root): array
{
    $manifest = $root . DIRECTORY_SEPARATOR . 'manifest.sha256';
    if (!is_file($manifest)) {
        throw new RuntimeException('Update package is missing manifest.sha256.');
    }

    $checked = 0;
    $paths = [];
    foreach (preg_split('/\R/', (string)file_get_contents($manifest)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([a-fA-F0-9]{64})\s+\*?(.+)$/', $line, $m) !== 1) {
            throw new RuntimeException('Package manifest.sha256 contains an invalid line.');
        }
        $rel = corebb_update_normalize_package_path($m[2]);
        if (!corebb_update_package_path_is_safe($rel) || $rel === 'manifest.sha256') {
            throw new RuntimeException('Package manifest.sha256 references an unsafe path.');
        }
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            throw new RuntimeException('Package manifest.sha256 references a missing file: ' . $rel);
        }
        if (!hash_equals(strtolower($m[1]), hash_file('sha256', $path))) {
            throw new RuntimeException('Package hash mismatch: ' . $rel);
        }
        $paths[$rel] = true;
        $checked++;
    }
    return ['count' => $checked, 'paths' => $paths];
}

/**
 * @return array<string, mixed>|null
 */
function corebb_update_manifest_release_for_version(string $version): ?array
{
    $manifest = corebb_update_cached_manifest();
    foreach (($manifest['releases'] ?? []) as $release) {
        if (is_array($release) && (string)($release['version'] ?? '') === $version) {
            return $release;
        }
    }
    return null;
}

function corebb_update_validate_official_zip_hash(string $zipPath, string $version): array
{
    $release = corebb_update_manifest_release_for_version($version);
    if (!$release) {
        throw new RuntimeException('Official update manifest does not list package version ' . $version . '. Check for updates before uploading the package.');
    }

    $expected = strtolower(trim((string)($release['sha256'] ?? '')));
    if ($expected === '' || $expected === 'placeholder' || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
        throw new RuntimeException('Official update manifest does not provide a valid ZIP SHA-256 for package version ' . $version . '.');
    }

    $actual = strtolower((string)hash_file('sha256', $zipPath));
    if (!hash_equals($expected, $actual)) {
        throw new RuntimeException('Uploaded package ZIP SHA-256 does not match the official update manifest for version ' . $version . '.');
    }

    return ['expected' => $expected, 'actual' => $actual];
}

function corebb_update_official_manifest_host(): string
{
    $host = strtolower((string)(parse_url(COREBB_UPDATE_MANIFEST_URL, PHP_URL_HOST) ?: ''));
    return $host !== '' ? $host : 'corebb.net';
}

function corebb_update_validate_official_download_release(array $release, string $version): string
{
    if (!corebb_update_version_valid($version)) {
        throw new RuntimeException('Choose a valid CoreBB release version to sideload.');
    }
    if ((string)($release['version'] ?? '') !== $version) {
        throw new RuntimeException('Official update manifest release version mismatch.');
    }

    $sha = strtolower(trim((string)($release['sha256'] ?? '')));
    if ($sha === '' || $sha === 'placeholder' || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        throw new RuntimeException('Official update manifest does not provide a valid ZIP SHA-256 for version ' . $version . '.');
    }

    $url = trim((string)($release['download_url'] ?? ''));
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new RuntimeException('Official update manifest release URL is invalid.');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    $port = (int)($parts['port'] ?? 443);
    $manifestHost = corebb_update_official_manifest_host();

    if ($scheme !== 'https' || $host !== $manifestHost || !empty($parts['user']) || !empty($parts['pass'])) {
        throw new RuntimeException('Official package sideload is limited to HTTPS downloads from ' . $manifestHost . '.');
    }
    if (!in_array($port, [0, 443], true)) {
        throw new RuntimeException('Official package sideload requires the default HTTPS port.');
    }
    if (str_contains($path, '..') || str_contains($path, '\\')) {
        throw new RuntimeException('Official package sideload release URL path is not allowed.');
    }
    if (!str_starts_with($path, '/releases/') || basename($path) !== 'corebb-' . $version . '.zip' || !empty($parts['query']) || !empty($parts['fragment'])) {
        throw new RuntimeException('Official package sideload release URL path is not allowed.');
    }

    return $url;
}

function corebb_update_store_official_package_download(string $version): string
{
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('Official package sideload requires allow_url_fopen or another outbound HTTPS download path.');
    }

    $release = corebb_update_manifest_release_for_version($version);
    if (!$release) {
        throw new RuntimeException('Official update manifest does not list package version ' . $version . '. Check for updates before sideloading.');
    }
    $url = corebb_update_validate_official_download_release($release, $version);

    $destDir = corebb_update_private_dir('downloads');
    $dest = $destDir . DIRECTORY_SEPARATOR . 'corebb-official-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $version)
        . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    $part = $dest . '.part';

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => COREBB_UPDATE_DOWNLOAD_TIMEOUT_SECONDS,
            'ignore_errors' => false,
            'header' => "Accept: application/zip, application/octet-stream\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $source = @fopen($url, 'rb', false, $context);
    if (!is_resource($source)) {
        throw new RuntimeException('Official package could not be downloaded from corebb.net.');
    }
    $target = @fopen($part, 'wb');
    if (!is_resource($target)) {
        fclose($source);
        throw new RuntimeException('Official package could not be written to private storage.');
    }

    $bytes = 0;
    try {
        while (!feof($source)) {
            $chunk = fread($source, 8192);
            if ($chunk === false) {
                throw new RuntimeException('Official package download failed while reading.');
            }
            if ($chunk === '') {
                continue;
            }
            $bytes += strlen($chunk);
            if ($bytes > COREBB_UPDATE_PACKAGE_MAX_BYTES) {
                throw new RuntimeException('Official package download exceeded the maximum package size.');
            }
            if (fwrite($target, $chunk) === false) {
                throw new RuntimeException('Official package download failed while writing.');
            }
        }
    } finally {
        fclose($source);
        fclose($target);
    }

    if ($bytes <= 0) {
        @unlink($part);
        throw new RuntimeException('Official package download was empty.');
    }
    if (!rename($part, $dest)) {
        @unlink($part);
        throw new RuntimeException('Official package could not be finalized in private storage.');
    }
    @chmod($dest, 0600);
    corebb_update_validate_official_zip_hash($dest, $version);
    return $dest;
}

/**
 * @return array<string, mixed>
 */
function corebb_update_validate_extracted_package(string $root, array $files): array
{
    foreach ($files as $file) {
        if (is_link($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))) {
            throw new RuntimeException('Package symlink rejected: ' . $file);
        }
    }

    $package = corebb_update_read_package_json($root);
    if ((string)($package['project'] ?? '') !== 'corebb') {
        throw new RuntimeException('Update package project is not corebb.');
    }
    if ((int)($package['schema'] ?? 0) !== 1) {
        throw new RuntimeException('Update package schema is missing or unsupported.');
    }

    $version = trim((string)($package['version'] ?? ''));
    if (!corebb_update_version_valid($version)) {
        throw new RuntimeException('Update package version is invalid.');
    }
    $installed = corebb_update_installed_version();
    if (version_compare($version, $installed, '<')) {
        throw new RuntimeException('Update package version is older than the installed version.');
    }

    $requiresPhp = trim((string)($package['requires_php'] ?? ''));
    if ($requiresPhp !== '' && !corebb_update_validate_version_range($requiresPhp, PHP_VERSION)) {
        throw new RuntimeException('This package requires PHP ' . $requiresPhp . '.');
    }

    $fromVersions = trim((string)($package['from_versions'] ?? ''));
    if ($fromVersions !== '' && !corebb_update_validate_version_range($fromVersions, $installed)) {
        throw new RuntimeException('This package does not support updating from CoreBB ' . $installed . '.');
    }

    $copyRoot = corebb_update_normalize_package_path((string)($package['copy_root'] ?? ''));
    if (!corebb_update_package_path_is_safe($copyRoot) || $copyRoot === '') {
        throw new RuntimeException('Update package copy_root is invalid.');
    }
    $copyRootDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $copyRoot);
    if (!is_dir($copyRootDir)) {
        throw new RuntimeException('Update package copy_root directory is missing.');
    }

    $replace = corebb_update_clean_package_path_list(is_array($package['replace'] ?? null) ? $package['replace'] : [], 'replace');
    if (!$replace) {
        throw new RuntimeException('Update package has no replace paths.');
    }
    $deleteFiles = corebb_update_clean_package_path_list(is_array($package['delete_files'] ?? null) ? $package['delete_files'] : [], 'delete');
    $hashManifest = corebb_update_validate_hash_manifest($root);
    $hashedPaths = $hashManifest['paths'];
    $hashCount = $hashManifest['count'];

    foreach ($files as $file) {
        if ($file !== 'manifest.sha256' && empty($hashedPaths[$file])) {
            throw new RuntimeException('Package contains an unmanifested file: ' . $file);
        }
        $allowedRootFile = in_array($file, ['package.json', 'package.sig', 'removed-files.json', 'manifest.sha256'], true);
        $allowedSidecar = str_starts_with($file, 'migrations/') || str_starts_with($file, 'docs/');
        if (!str_starts_with($file, $copyRoot . '/') && !$allowedRootFile && !$allowedSidecar) {
            throw new RuntimeException('Unexpected package file outside copy_root: ' . $file);
        }
        if (str_starts_with($file, $copyRoot . '/')) {
            $targetRel = substr($file, strlen($copyRoot) + 1);
            if (corebb_update_path_is_preserved($targetRel)) {
                throw new RuntimeException('Package attempts to overwrite preserved path: ' . $targetRel);
            }
        }
    }

    return [
        'ok' => true,
        'version' => $version,
        'installed_version' => $installed,
        'release_type' => (string)($package['release_type'] ?? ''),
        'requires_php' => $requiresPhp,
        'from_versions' => $fromVersions,
        'schema_version' => (int)($package['schema_version'] ?? 0),
        'copy_root' => $copyRoot,
        'replace' => $replace,
        'delete_files' => $deleteFiles,
        'file_count' => count($files),
        'hash_count' => $hashCount,
        'extracted_root' => $root,
    ];
}

/**
 * @return array<string, mixed>
 */
function corebb_update_stage_zip_package(string $zipPath, string $source, string $expectedVersion = ''): array
{
    $extracted = corebb_update_extract_package($zipPath);
    $summary = corebb_update_validate_extracted_package($extracted['root'], $extracted['files']);
    $version = (string)($summary['version'] ?? '');
    if ($expectedVersion !== '' && $version !== $expectedVersion) {
        throw new RuntimeException('Downloaded package version ' . $version . ' does not match requested version ' . $expectedVersion . '.');
    }
    $zipHash = corebb_update_validate_official_zip_hash($zipPath, $version);
    $summary['zip_path'] = $zipPath;
    $summary['zip_sha256'] = $zipHash['actual'];
    $summary['zip_source'] = $source;
    corebb_update_set_setting('last_update_package_path', $zipPath);
    corebb_update_set_setting('last_update_package_summary', json_encode($summary, JSON_UNESCAPED_SLASHES));
    return $summary;
}

/**
 * @param array<string, mixed> $file
 * @return array<string, mixed>
 */
function corebb_update_handle_package_upload(array $file): array
{
    $zipPath = corebb_update_store_uploaded_package($file);
    return corebb_update_stage_zip_package($zipPath, 'upload');
}

/**
 * @return array<string, mixed>
 */
function corebb_update_handle_official_package_download(string $version): array
{
    $version = trim($version);
    $zipPath = corebb_update_store_official_package_download($version);
    return corebb_update_stage_zip_package($zipPath, 'official', $version);
}

/**
 * @return array<string, mixed>|null
 */
function corebb_update_last_package_summary(): ?array
{
    $raw = trim(corebb_update_setting('last_update_package_summary', ''));
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function corebb_update_preflight_row(string $label, bool $ok, string $detail): array
{
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

/**
 * @param array<string, mixed> $summary
 * @return array<int, string>
 */
function corebb_update_package_migration_rows(array $summary): array
{
    $root = (string)($summary['extracted_root'] ?? '');
    $dir = $root !== '' ? $root . DIRECTORY_SEPARATOR . 'migrations' : '';
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }

    $rows = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
        $rows[] = basename($path, '.php');
    }
    sort($rows, SORT_NATURAL);
    return $rows;
}

/**
 * @param array<string, mixed> $summary
 * @return array<int, string>
 */
function corebb_update_expanded_replace_paths(array $summary): array
{
    $root = (string)($summary['extracted_root'] ?? '');
    $copyRoot = corebb_update_normalize_package_path((string)($summary['copy_root'] ?? ''));
    $base = $root !== '' && $copyRoot !== '' ? $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $copyRoot) : '';
    $replace = is_array($summary['replace'] ?? null) ? $summary['replace'] : [];
    $rows = [];

    foreach ($replace as $rel) {
        $rel = corebb_update_normalize_package_path((string)$rel);
        $source = $base !== '' ? $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel) : '';
        if ($source !== '' && is_dir($source)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $fileRel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                $rows[] = $fileRel;
            }
        } else {
            $rows[] = $rel;
        }
    }

    sort($rows, SORT_NATURAL);
    return array_values(array_unique($rows));
}

/**
 * @param array<string, mixed> $summary
 * @return array<int, array{label: string, ok: bool, detail: string}>
 */
function corebb_update_preflight_rows(array $summary): array
{
    $rows = [];
    $rows[] = corebb_update_preflight_row('PHP version', true, PHP_VERSION);
    $rows[] = corebb_update_preflight_row('ZIP support', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'Available' : 'PHP ZIP extension is missing.');

    $dbOk = false;
    if (function_exists('corebb_db_connection')) {
        $dbOk = corebb_db_connection() instanceof PDO;
    } elseif (function_exists('db_value')) {
        $dbOk = db_value('SELECT 1', [], 0) == 1;
    }
    $rows[] = corebb_update_preflight_row('Database connection', $dbOk, $dbOk ? 'Available' : 'Database connection could not be verified.');

    $tempDir = corebb_update_private_dir('extracted');
    $rows[] = corebb_update_preflight_row('Writable temp directory', is_writable($tempDir), $tempDir);

    $appRoot = corebb_update_app_root();
    $replacePaths = corebb_update_expanded_replace_paths($summary);
    $checked = 0;
    $blocked = [];
    foreach ($replacePaths as $rel) {
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $checkPath = is_file($target) ? $target : dirname($target);
        if (!is_dir($checkPath) && !is_file($checkPath)) {
            $parent = dirname($target);
            while ($parent !== '' && $parent !== dirname($parent) && !is_dir($parent)) {
                $parent = dirname($parent);
            }
            $checkPath = $parent;
        }
        $checked++;
        if ($checkPath === '' || !is_writable($checkPath)) {
            $blocked[] = $rel;
            if (count($blocked) >= 5) {
                break;
            }
        }
    }
    $rows[] = corebb_update_preflight_row(
        'Writable target paths',
        !$blocked,
        !$blocked ? 'Checked ' . $checked . ' replace target(s).' : 'Not writable: ' . implode(', ', $blocked)
    );

    $obsolete = is_array($summary['delete_files'] ?? null) ? $summary['delete_files'] : [];
    $deleteBlocked = [];
    foreach ($obsolete as $rel) {
        $rel = corebb_update_normalize_package_path((string)$rel);
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($target) && !is_writable($target)) {
            $deleteBlocked[] = $rel;
        }
    }
    $rows[] = corebb_update_preflight_row(
        'Obsolete file permissions',
        !$deleteBlocked,
        !$deleteBlocked ? 'No blocking obsolete-file permissions found.' : 'Not writable: ' . implode(', ', array_slice($deleteBlocked, 0, 5))
    );

    return $rows;
}

/**
 * @param array<string, mixed>|null $summary
 * @return array<string, mixed>|null
 */
function corebb_update_build_preview(?array $summary = null): ?array
{
    $summary = $summary ?? corebb_update_last_package_summary();
    if (!$summary) {
        return null;
    }

    $replacePaths = is_array($summary['replace'] ?? null) ? array_values($summary['replace']) : [];
    $deleteFiles = is_array($summary['delete_files'] ?? null) ? array_values($summary['delete_files']) : [];
    $migrations = corebb_update_package_migration_rows($summary);
    $preflight = corebb_update_preflight_rows($summary);
    $ok = true;
    foreach ($preflight as $row) {
        if (empty($row['ok'])) {
            $ok = false;
            break;
        }
    }

    return [
        'installed_version' => (string)($summary['installed_version'] ?? corebb_update_installed_version()),
        'package_version' => (string)($summary['version'] ?? ''),
        'release_type' => (string)($summary['release_type'] ?? ''),
        'schema_version' => (string)($summary['schema_version'] ?? ''),
        'replace_paths' => $replacePaths,
        'delete_files' => $deleteFiles,
        'preserve_paths' => corebb_update_preserve_paths(),
        'migrations' => $migrations,
        'preflight' => $preflight,
        'ok' => $ok,
    ];
}

function corebb_update_lock_path(): string
{
    return rtrim(corebb_update_private_base_dir(), "\\/") . DIRECTORY_SEPARATOR . 'upgrade.lock';
}

function corebb_update_acquire_lock(array $summary): string
{
    $lockPath = corebb_update_lock_path();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir) && !mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
        throw new RuntimeException('Upgrade lock directory could not be created.');
    }
    if (is_file($lockPath)) {
        throw new RuntimeException('An upgrade lock already exists. Clear or resolve the existing upgrade before applying another package.');
    }
    $payload = [
        'created_at' => date('Y-m-d H:i:s'),
        'installed_version' => (string)($summary['installed_version'] ?? ''),
        'target_version' => (string)($summary['version'] ?? ''),
        'pid' => getmypid(),
    ];
    if (file_put_contents($lockPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Upgrade lock could not be written.');
    }
    @chmod($lockPath, 0600);
    return $lockPath;
}

function corebb_update_clear_lock(): void
{
    $lockPath = corebb_update_lock_path();
    if (is_file($lockPath) && !unlink($lockPath)) {
        throw new RuntimeException('Upgrade lock could not be cleared: ' . $lockPath);
    }
}

function corebb_update_duration_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    $hours = intdiv($minutes, 60);
    if ($hours < 48) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    $days = intdiv($hours, 24);
    return $days . ' day' . ($days === 1 ? '' : 's');
}

/**
 * @return array<string, mixed>
 */
function corebb_update_lock_status(): array
{
    $lockPath = corebb_update_lock_path();
    if (!is_file($lockPath)) {
        return [
            'exists' => false,
            'path' => $lockPath,
        ];
    }

    $raw = (string)@file_get_contents($lockPath);
    $payload = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $createdAt = trim((string)($payload['created_at'] ?? ''));
    $createdTs = corebb_update_timestamp($createdAt);
    if ($createdTs <= 0) {
        $mtime = @filemtime($lockPath);
        $createdTs = is_int($mtime) ? $mtime : time();
        $createdAt = date('Y-m-d H:i:s', $createdTs);
    }
    $ageSeconds = max(0, time() - $createdTs);

    return [
        'exists' => true,
        'path' => $lockPath,
        'created_at' => $createdAt,
        'created_label' => corebb_update_format_date($createdAt),
        'age_seconds' => $ageSeconds,
        'age_label' => corebb_update_duration_label($ageSeconds),
        'stale' => $ageSeconds >= 3600,
        'installed_version' => (string)($payload['installed_version'] ?? ''),
        'target_version' => (string)($payload['target_version'] ?? ''),
        'pid' => (string)($payload['pid'] ?? ''),
        'readable' => $raw !== '',
    ];
}

function corebb_update_clear_upgrade_lock(bool $disableMaintenance = true): void
{
    corebb_update_clear_lock();
    if ($disableMaintenance) {
        corebb_update_set_maintenance_mode(false);
    }
}

function corebb_update_set_maintenance_mode(bool $enabled): void
{
    corebb_update_set_setting('maintenancemode', $enabled ? '1' : '0');
    if ($enabled) {
        corebb_update_set_setting('maintenancesubject', 'CoreBB Upgrade in Progress');
        corebb_update_set_setting('maintenancemessage', 'The forum is temporarily unavailable while a software upgrade is being completed.');
    }
}

function corebb_update_copy_file(string $source, string $target): void
{
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create target directory: ' . $dir);
    }
    if (!copy($source, $target)) {
        throw new RuntimeException('Could not copy update file: ' . $target);
    }
    @chmod($target, 0644);
}

/**
 * @param array<string, mixed> $summary
 * @return int Number of copied files.
 */
function corebb_update_copy_replace_paths(array $summary): int
{
    $root = (string)($summary['extracted_root'] ?? '');
    $copyRoot = corebb_update_normalize_package_path((string)($summary['copy_root'] ?? ''));
    $base = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $copyRoot);
    $appRoot = corebb_update_app_root();
    $replace = is_array($summary['replace'] ?? null) ? $summary['replace'] : [];
    $copied = 0;

    foreach ($replace as $rel) {
        $rel = corebb_update_normalize_package_path((string)$rel);
        if (!corebb_update_package_path_is_safe($rel) || corebb_update_path_is_preserved($rel)) {
            throw new RuntimeException('Unsafe replace path during apply: ' . $rel);
        }
        $source = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!file_exists($source)) {
            throw new RuntimeException('Package replace source is missing: ' . $rel);
        }

        if (is_dir($source)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if ($file->isLink()) {
                    throw new RuntimeException('Package symlink rejected during apply: ' . $rel);
                }
                $fileRel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                if (corebb_update_path_is_preserved($fileRel)) {
                    throw new RuntimeException('Package attempted to overwrite preserved path during apply: ' . $fileRel);
                }
                $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileRel);
                corebb_update_copy_file($file->getPathname(), $target);
                $copied++;
            }
        } else {
            $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            corebb_update_copy_file($source, $target);
            $copied++;
        }
    }

    return $copied;
}

/**
 * @param array<string, mixed> $summary
 * @return int Number of deleted files.
 */
function corebb_update_delete_obsolete_files(array $summary): int
{
    $appRoot = corebb_update_app_root();
    $deleteFiles = is_array($summary['delete_files'] ?? null) ? $summary['delete_files'] : [];
    $deleted = 0;

    foreach ($deleteFiles as $rel) {
        $rel = corebb_update_normalize_package_path((string)$rel);
        if (!corebb_update_package_path_is_safe($rel) || corebb_update_path_is_preserved($rel)) {
            throw new RuntimeException('Unsafe obsolete file path during apply: ' . $rel);
        }
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!file_exists($target)) {
            continue;
        }
        if (is_dir($target)) {
            throw new RuntimeException('Obsolete path is a directory, not a file: ' . $rel);
        }
        if (!unlink($target)) {
            throw new RuntimeException('Could not delete obsolete file: ' . $rel);
        }
        $deleted++;
    }

    return $deleted;
}

function corebb_update_migrations_ensure_schema(): void
{
    if (!db_run("CREATE TABLE IF NOT EXISTS `corebb_migrations` (
        `id` VARCHAR(191) NOT NULL,
        `applied_at` DATETIME NOT NULL,
        `corebb_version` VARCHAR(32) DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Migration tracker table could not be created.');
    }
}

function corebb_update_run_migration_file(string $path, array $summary): void
{
    $result = include $path;
    if (is_callable($result)) {
        $ok = $result($summary);
        if ($ok === false) {
            throw new RuntimeException('Migration returned failure: ' . basename($path));
        }
    }
}

/**
 * @param array<string, mixed> $summary
 * @return int Number of migrations applied.
 */
function corebb_update_run_pending_migrations(array $summary): int
{
    $root = (string)($summary['extracted_root'] ?? '');
    $dir = $root !== '' ? $root . DIRECTORY_SEPARATOR . 'migrations' : '';
    if ($dir === '' || !is_dir($dir)) {
        return 0;
    }

    corebb_update_migrations_ensure_schema();
    $applied = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
        $id = basename($path, '.php');
        if (db_exists('SELECT id FROM corebb_migrations WHERE id = ? LIMIT 1', [$id])) {
            continue;
        }
        corebb_update_run_migration_file($path, $summary);
        if (!db_run('INSERT INTO corebb_migrations (id, applied_at, corebb_version) VALUES (?, NOW(), ?)', [$id, (string)($summary['version'] ?? '')])) {
            throw new RuntimeException('Migration could not be recorded: ' . $id);
        }
        $applied++;
    }
    return $applied;
}

function corebb_update_remove_tree_contents(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $removed = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        if ($item->isDir() && !$item->isLink()) {
            if (@rmdir($item->getPathname())) {
                $removed++;
            }
        } elseif (@unlink($item->getPathname())) {
            $removed++;
        }
    }
    return $removed;
}

function corebb_update_clear_cache_files(): int
{
    $removed = 0;
    foreach ([
        corebb_update_app_root() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'twig',
        corebb_update_app_root() . DIRECTORY_SEPARATOR . 'tmp',
        corebb_update_app_root() . DIRECTORY_SEPARATOR . 'temp',
    ] as $dir) {
        $removed += corebb_update_remove_tree_contents($dir);
    }
    return $removed;
}

function corebb_update_failure_action_type(Throwable $e): string
{
    $message = strtolower($e->getMessage());
    if (str_contains($message, 'migration')) {
        return 'update_migration_failed';
    }
    if (str_contains($message, 'obsolete') || str_contains($message, 'delete')) {
        return 'update_obsolete_delete_failed';
    }
    if (str_contains($message, 'cache')) {
        return 'update_cache_clear_failed';
    }
    if (str_contains($message, 'copy') || str_contains($message, 'replace') || str_contains($message, 'source')) {
        return 'update_file_copy_failed';
    }
    return 'update_failed';
}

function corebb_update_failure_recovery_message(Throwable $e): string
{
    $message = $e->getMessage();
    $lock = corebb_update_lock_status();
    if (!empty($lock['exists'])) {
        $message .= ' The upgrade lock remains at ' . (string)$lock['path']
            . '; resolve the underlying issue, then clear the lock from this page. Maintenance mode may still be enabled.';
    }
    return $message;
}

/**
 * @param array<string, mixed>|null $summary
 * @return array<string, mixed>
 */
function corebb_update_apply_package(?array $summary = null): array
{
    $summary = $summary ?? corebb_update_last_package_summary();
    if (!$summary) {
        throw new RuntimeException('Upload and validate an update package before applying an upgrade.');
    }
    $preview = corebb_update_build_preview($summary);
    if (!$preview || empty($preview['ok'])) {
        throw new RuntimeException('Preflight checks must pass before applying an upgrade.');
    }

    $lockPath = corebb_update_acquire_lock($summary);
    corebb_update_set_maintenance_mode(true);

    try {
        $copied = corebb_update_copy_replace_paths($summary);
    } catch (Throwable $e) {
        throw new RuntimeException('File copy failed: ' . $e->getMessage(), 0, $e);
    }

    try {
        $deleted = corebb_update_delete_obsolete_files($summary);
    } catch (Throwable $e) {
        throw new RuntimeException('Obsolete file deletion failed: ' . $e->getMessage(), 0, $e);
    }

    try {
        $migrations = corebb_update_run_pending_migrations($summary);
    } catch (Throwable $e) {
        throw new RuntimeException('Migration failed: ' . $e->getMessage(), 0, $e);
    }

    try {
        $cacheRemoved = corebb_update_clear_cache_files();
    } catch (Throwable $e) {
        throw new RuntimeException('Cache clear failed: ' . $e->getMessage(), 0, $e);
    }

    corebb_update_set_setting('installed_version', (string)($summary['version'] ?? ''));
    corebb_update_set_setting('schema_version', (string)($summary['schema_version'] ?? COREBB_SCHEMA_VERSION));
    corebb_update_set_setting('last_update_package_summary', '');
    corebb_update_set_setting('last_update_package_path', '');
    corebb_update_set_maintenance_mode(false);
    corebb_update_clear_lock();

    return [
        'ok' => true,
        'version' => (string)($summary['version'] ?? ''),
        'copied_files' => $copied,
        'deleted_files' => $deleted,
        'migrations' => $migrations,
        'cache_removed' => $cacheRemoved,
        'lock_path' => $lockPath,
    ];
}
