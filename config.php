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
 |  config.php  - Environment-aware private              |
 |  configuration loader.                                |
 +-------------------------------------------------------+*/

// Security: this file is not a public endpoint.
if (!defined('IN_BOARDS')) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $currentfile = basename((string)($_SERVER['PHP_SELF'] ?? 'config.php'));
    $now = date('l dS \of F Y h:i:s A');
    @file_put_contents(__DIR__ . '/invalidaccess.txt', "$now - Hacking attempt from: $ip - File Attempted Access: $currentfile\n", FILE_APPEND | LOCK_EX);
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!defined('COREBB_CONFIG_LOADED')) {
    define('COREBB_CONFIG_LOADED', true);

    function corebb_config_normalize_path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        return rtrim($path, '/');
    }

    function corebb_config_public_document_root(string $appRoot): string
    {
        $docRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? getenv('DOCUMENT_ROOT') ?: ''));
        if ($docRoot !== '') {
            $resolved = realpath($docRoot);
            $docRoot = corebb_config_normalize_path($resolved !== false ? $resolved : $docRoot);
            if ($docRoot !== '' && (corebb_config_normalize_path($appRoot) === $docRoot || str_starts_with(corebb_config_normalize_path($appRoot) . '/', $docRoot . '/'))) {
                return $docRoot;
            }
        }

        $root = corebb_config_normalize_path($appRoot);
        $publicHtmlPos = stripos($root, '/public_html');
        if ($publicHtmlPos !== false) {
            return substr($root, 0, $publicHtmlPos + strlen('/public_html'));
        }

        return '';
    }

    function corebb_config_instance_key(string $appRoot): string
    {
        $forced = strtolower(trim((string)(getenv('COREBB_INSTANCE') ?: getenv('WB_INSTANCE') ?: '')));
        if ($forced !== '' && preg_match('/^[a-z0-9_-]{1,80}$/', $forced)) {
            return $forced;
        }

        $root = corebb_config_normalize_path($appRoot);
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? getenv('HTTP_HOST') ?: '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $docRoot = corebb_config_public_document_root($root);
        $relativePath = '';
        if ($docRoot !== '' && str_starts_with($root . '/', $docRoot . '/')) {
            $relativePath = trim(substr($root, strlen($docRoot)), '/');
        }

        $seed = trim($host . ($relativePath !== '' ? '_' . str_replace('/', '_', $relativePath) : ''), '_');
        if ($seed === '') {
            $seed = basename($root);
        }

        $key = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $seed), '_'));
        return $key !== '' ? substr($key, 0, 80) : 'default';
    }

    function corebb_config_should_use_legacy_private_path(string $appRoot): bool
    {
        $forced = strtolower(trim((string)(getenv('COREBB_ALLOW_LEGACY_PRIVATE_CONFIG') ?: '')));
        if (in_array($forced, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $root = corebb_config_normalize_path($appRoot);
        $docRoot = corebb_config_public_document_root($root);
        return $docRoot === '' || $docRoot === $root;
    }

    function corebb_config_detect_environment(string $appRoot): string
    {
        $forced = strtolower(trim((string)(getenv('COREBB_ENV') ?: getenv('WB_ENV') ?: '')));
        if (in_array($forced, ['live', 'production', 'prod'], true)) {
            return 'live';
        }
        if (in_array($forced, ['staging', 'stage', 'test'], true)) {
            return 'staging';
        }
        if (in_array($forced, ['local', 'dev', 'development'], true)) {
            return 'local';
        }

        $root = corebb_config_normalize_path($appRoot);

        // Common staging directory names. Explicit COREBB_ENV still wins for
        // hosts with a different deployment layout.
        if (preg_match('#/(corebb-staging|staging-corebb|staging_corebb|staging|stage|test)(/|$)#i', $root)) {
            return 'staging';
        }

        // Common shared-host public document root layout.
        if (preg_match('#/public_html(/|$)#i', $root)) {
            return 'live';
        }

        return 'unknown';
    }

    function corebb_config_private_base_dir(string $appRoot): string
    {
        $forcedBase = trim((string)(getenv('COREBB_PRIVATE_BASE_DIR') ?: getenv('WB_PRIVATE_BASE_DIR') ?: ''));
        if ($forcedBase !== '') {
            return corebb_config_normalize_path($forcedBase);
        }

        $root = corebb_config_normalize_path($appRoot);
        $publicHtmlPos = stripos($root, '/public_html');
        if ($publicHtmlPos !== false) {
            return substr($root, 0, $publicHtmlPos) . '/corebb_private';
        }

        $docRoot = corebb_config_public_document_root($root);
        if ($docRoot !== '') {
            $docPublicHtmlPos = stripos($docRoot, '/public_html');
            if ($docPublicHtmlPos !== false) {
                return substr($docRoot, 0, $docPublicHtmlPos) . '/corebb_private';
            }

            return dirname($docRoot) . '/corebb_private';
        }

        // Preferred shared-host layout:
        //   /home/account/public_html/forum -> /home/account/corebb_private/
        $parent = dirname($root);
        if ($parent !== '' && $parent !== '.' && $parent !== '/') {
            return $parent . '/corebb_private';
        }

        return $root . '/corebb_private';
    }

    function corebb_config_candidate_paths(string $appRoot, string $environment): array
    {
        $paths = [];

            // Explicit override for unusual/local setups. Keep this first so a
            // developer can test locally without changing tracked project files.
            foreach (['COREBB_PRIVATE_CONFIG', 'COREBB_PRIVATE_CONFIG_FILE', 'WB_PRIVATE_CONFIG_FILE'] as $envKey) {
                $envPath = trim((string)(getenv($envKey) ?: ''));
                if ($envPath !== '') {
                    $paths[] = $envPath;
                }
            }

            $privateBase = corebb_config_private_base_dir($appRoot);
            $instanceBase = $privateBase . '/' . corebb_config_instance_key($appRoot);
            $useLegacyPrivatePath = corebb_config_should_use_legacy_private_path($appRoot);

            if ($environment === 'staging') {
                // Staging must not silently fall back to the live config. If this
                // file is missing, fail closed instead of touching production data.
                $paths[] = $instanceBase . '/config.staging.php';
                if ($useLegacyPrivatePath) {
                    $paths[] = $privateBase . '/config.staging.php';
                }
                // Last-resort installer fallback. .htaccess blocks direct browser
                // access to config.local.php, but a private path is still preferred.
                $paths[] = corebb_config_normalize_path($appRoot) . '/config.local.php';
            } elseif ($environment === 'live') {
                $paths[] = $instanceBase . '/config.live.php';
                $paths[] = $instanceBase . '/config.local.php';
                if ($useLegacyPrivatePath) {
                    $paths[] = $privateBase . '/config.live.php';
                    // Backward-compatible fallback for the current live install.
                    $paths[] = $privateBase . '/config.local.php';
                }
                // Last-resort installer fallback. This is used only when PHP could
                // not create/write the preferred private config directory.
                $paths[] = corebb_config_normalize_path($appRoot) . '/config.local.php';
            } elseif ($environment === 'local') {
                $paths[] = $instanceBase . '/config.local.php';
                if ($useLegacyPrivatePath) {
                    $paths[] = $privateBase . '/config.local.php';
                }
                $paths[] = corebb_config_normalize_path($appRoot) . '/config.local.php';
            }

            $clean = [];
            foreach ($paths as $path) {
                $path = corebb_config_normalize_path((string)$path);
                if ($path !== '' && !in_array($path, $clean, true)) {
                    $clean[] = $path;
                }
            }
        return $clean;
    }

    $corebbConfigAppRoot = realpath(__DIR__);
    if ($corebbConfigAppRoot === false) {
        error_log('CoreBB config error: unable to resolve application root.');
        http_response_code(500);
        echo 'Forum configuration is unavailable.';
        exit;
    }
    $corebbConfigAppRoot = corebb_config_normalize_path($corebbConfigAppRoot);
    $corebbConfigEnvironment = corebb_config_detect_environment($corebbConfigAppRoot);

    if ($corebbConfigEnvironment === 'unknown') {
        foreach (corebb_config_candidate_paths($corebbConfigAppRoot, 'local') as $localConfigCandidate) {
            if (is_file($localConfigCandidate)) {
                $corebbConfigEnvironment = 'local';
                break;
            }
        }
    }

    if ($corebbConfigEnvironment === 'unknown') {
        error_log('CoreBB config error: unknown environment for root ' . $corebbConfigAppRoot . '. Set COREBB_ENV and COREBB_PRIVATE_CONFIG for this install.');
        http_response_code(500);
        echo 'Forum configuration environment is unknown.';
        exit;
    }

    if (!defined('COREBB_ENV')) {
        define('COREBB_ENV', $corebbConfigEnvironment);
    }
    if (!defined('COREBB_CONFIG_ENVIRONMENT')) {
        define('COREBB_CONFIG_ENVIRONMENT', $corebbConfigEnvironment);
    }
    if (!defined('WB_CONFIG_ENVIRONMENT')) {
        define('WB_CONFIG_ENVIRONMENT', COREBB_CONFIG_ENVIRONMENT);
    }
    if (!defined('COREBB_APP_ROOT')) {
        define('COREBB_APP_ROOT', $corebbConfigAppRoot);
    }
    if (!defined('WB_APP_ROOT')) {
        define('WB_APP_ROOT', COREBB_APP_ROOT);
    }

    $privateConfigLoaded = false;
    $privateConfigTried = [];
    foreach (corebb_config_candidate_paths($corebbConfigAppRoot, $corebbConfigEnvironment) as $privateConfigPath) {
        $privateConfigTried[] = $privateConfigPath;
        if (is_file($privateConfigPath)) {
            require_once $privateConfigPath;
            if (!defined('COREBB_PRIVATE_CONFIG_FILE')) {
                define('COREBB_PRIVATE_CONFIG_FILE', $privateConfigPath);
            }
            if (!defined('WB_PRIVATE_CONFIG_FILE')) {
                define('WB_PRIVATE_CONFIG_FILE', COREBB_PRIVATE_CONFIG_FILE);
            }
            $privateConfigLoaded = true;
            break;
        }
    }

    if (!$privateConfigLoaded) {
        error_log('CoreBB private configuration file is missing for environment ' . $corebbConfigEnvironment . '. Tried: ' . implode(', ', $privateConfigTried));
        http_response_code(500);
        echo 'Forum configuration is missing.';
        exit;
    }

    // Required database settings. These remain as legacy globals so the older
    // forum code can keep working while the values live outside the web root.
    foreach (['MySQL_Host', 'MySQL_User', 'MySQL_Pass', 'MySQL_Database'] as $requiredConfigKey) {
        if (!isset($GLOBALS[$requiredConfigKey]) || (string)$GLOBALS[$requiredConfigKey] === '') {
            error_log('CoreBB private configuration is missing required value: ' . $requiredConfigKey);
            http_response_code(500);
            echo 'Forum configuration is incomplete.';
            exit;
        }
    }

    // Staging guardrail: a staging install should not accidentally use the live
    // production database. Override only if you intentionally named the staging
    // DB without stage/staging/test in its name.
    if ((string)COREBB_ENV === 'staging') {
        $stagingDbName = (string)$GLOBALS['MySQL_Database'];
        $allowNonStagingDb = defined('COREBB_ALLOW_NON_STAGING_DB') && (bool)COREBB_ALLOW_NON_STAGING_DB;
        if (!$allowNonStagingDb && !preg_match('/(staging|stage|test)/i', $stagingDbName)) {
            error_log('CoreBB staging config refused non-staging-looking database name: ' . $stagingDbName);
            http_response_code(500);
            echo 'Staging configuration refused a non-staging database name.';
            exit;
        }
    }

    // Safe public/site defaults. Private config may override any of these.
    $SQLPrefix = $SQLPrefix ?? '';
    $CookieDomain = $CookieDomain ?? '';

    $corebbFallbackScheme = strtolower((string)($_SERVER['HTTPS'] ?? '')) !== 'off' && (string)($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http';
    $corebbFallbackHost = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $corebbFallbackHost = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $corebbFallbackHost) ?? '';
    $corebbFallbackPath = '';
    $corebbFallbackDocRoot = corebb_config_public_document_root($corebbConfigAppRoot);
    if ($corebbFallbackDocRoot !== '' && str_starts_with($corebbConfigAppRoot . '/', $corebbFallbackDocRoot . '/')) {
        $corebbRelativeRoot = trim(substr($corebbConfigAppRoot, strlen($corebbFallbackDocRoot)), '/');
        $corebbFallbackPath = $corebbRelativeRoot !== '' ? '/' . $corebbRelativeRoot : '';
    }
    $corebbFallbackUrl = $corebbFallbackHost !== '' ? $corebbFallbackScheme . '://' . $corebbFallbackHost . $corebbFallbackPath : 'http://localhost';

    $SiteName = $SiteName ?? 'CoreBB';
    $SiteURL = $SiteURL ?? $corebbFallbackUrl;

    $BoardName = $BoardName ?? 'CoreBB Forum';
    $BoardURL = $BoardURL ?? $corebbFallbackUrl;

    $BoardLockdown = $BoardLockdown ?? '0';
    $ShortPHP = $ShortPHP ?? '.php';
    $GoogleClientID = trim((string)($GoogleClientID ?? '')) !== ''
        ? trim((string)$GoogleClientID)
        : trim((string)(getenv('COREBB_GOOGLE_CLIENT_ID') ?: ''));

    // Convenience globals used by newer helper code.
    $GLOBALS['COREBB_DB_HOST'] = $GLOBALS['MySQL_Host'];
    $GLOBALS['COREBB_DB_USER'] = $GLOBALS['MySQL_User'];
    $GLOBALS['COREBB_DB_NAME'] = $GLOBALS['MySQL_Database'];
    $GLOBALS['COREBB_ENV'] = (string)COREBB_ENV;
    $GLOBALS['WB_DB_HOST'] = $GLOBALS['COREBB_DB_HOST'];
    $GLOBALS['WB_DB_USER'] = $GLOBALS['COREBB_DB_USER'];
    $GLOBALS['WB_DB_NAME'] = $GLOBALS['COREBB_DB_NAME'];
    $GLOBALS['WB_ENV'] = $GLOBALS['COREBB_ENV'];

    if (!defined('COREBB_PUBLIC_BASE_URL')) {
        $corebbPublicBaseUrl = defined('WB_PUBLIC_BASE_URL') ? (string)WB_PUBLIC_BASE_URL : (string)$BoardURL;
        define('COREBB_PUBLIC_BASE_URL', rtrim($corebbPublicBaseUrl, '/'));
    }
    if (!defined('WB_PUBLIC_BASE_URL')) {
        define('WB_PUBLIC_BASE_URL', COREBB_PUBLIC_BASE_URL);
    }
}
?>
