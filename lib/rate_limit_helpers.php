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
 |  rate_limit_helpers.php  - Lightweight DB-backed      |
 |  rate limiting for login and user-generated writes.   |
 +-------------------------------------------------------+*/

if (!defined('COREBB_RATE_LIMIT_FAIL_OPEN')) {
    // If the table cannot be created because of host permissions, keep the board
    // usable instead of locking out legitimate users. The failure is logged.
    define('COREBB_RATE_LIMIT_FAIL_OPEN', true);
}

if (!defined('COREBB_RATE_LIMIT_TABLE')) {
    define('COREBB_RATE_LIMIT_TABLE', 'corebb_rate_limits');
}

/**
 * Usage: Validate and quote a database identifier used by rate-limit queries.
 * Referenced by: schema migration, cleanup, check, and reset helpers.
 *
 * @param string $identifier Raw table or column identifier.
 * @return string Backtick-quoted identifier.
 * @throws InvalidArgumentException When the identifier contains unsafe characters.
 */
function corebb_rate_limit_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Detect the legacy wb_rate_limits table before one-time row migration.
 * Referenced by: corebb_rate_limit_migrate_legacy_rows().
 *
 * @return bool True when the legacy table exists in the active database.
 */
function corebb_rate_limit_legacy_table_exists(): bool
{
    $db = function_exists('corebb_db_connection_name') ? corebb_db_connection_name() : (string)($GLOBALS['MySQL_Database'] ?? '');
    if ($db === '') {
        return false;
    }
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
        [$db, 'wb_rate_limits']
    );
}

/**
 * Usage: Copy old wb_rate_limits rows into the CoreBB table once per request.
 * Referenced by: corebb_rate_limit_ensure_schema().
 *
 * @return void
 */
function corebb_rate_limit_migrate_legacy_rows(): void
{
    static $migrated = false;
    if ($migrated || !corebb_rate_limit_legacy_table_exists()) {
        return;
    }
    $migrated = true;

    db_run(
        'INSERT IGNORE INTO ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' (id, action, actor_hash, window_started, hits, last_hit)
         SELECT id, action, actor_hash, window_started, hits, last_hit FROM wb_rate_limits'
    );
}

/**
 * Usage: Ensure the DB-backed rate-limit table exists before checks run.
 * Referenced by: check, reset, and wrapper helpers.
 *
 * @return bool True when the table exists or was created successfully.
 */
function corebb_rate_limit_ensure_schema(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = db_run("CREATE TABLE IF NOT EXISTS `corebb_rate_limits` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
        `actor_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
        `window_started` INT UNSIGNED NOT NULL DEFAULT 0,
        `hits` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_hit` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_corebb_rate_action_actor` (`action`, `actor_hash`),
        KEY `idx_corebb_rate_last_hit` (`last_hit`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!$ready) {
        error_log('CoreBB rate limit table unavailable: ' . db_error());
    } else {
        corebb_rate_limit_migrate_legacy_rows();
    }

    return $ready;
}

/**
 * Usage: Remove stale rate-limit rows on a throttled cadence.
 * Referenced by: corebb_rate_limit_check().
 *
 * @return void
 */
function corebb_rate_limit_cleanup(): void
{
    static $lastCleanup = 0;
    $now = time();
    if ($lastCleanup > 0 && ($now - $lastCleanup) < 300) {
        return;
    }
    $lastCleanup = $now;
    db_run('DELETE FROM ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' WHERE last_hit < ?', [$now - 172800]);
}

/**
 * Usage: Resolve the client IP used by IP-based rate-limit identities.
 * Referenced by: identity builders and login/registration/report limiters.
 *
 * @return string Client IP address, or "Unknown" when unavailable.
 */
function corebb_rate_limit_current_ip(): string
{
    if (function_exists('corebb_security_client_ip')) {
        return (string)corebb_security_client_ip();
    }
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 64) : 'Unknown';
}

/**
 * Usage: Hash a rate-limit identity before storing it in the database.
 * Referenced by: check and reset helpers.
 *
 * @param string $identity Raw rate-limit identity string.
 * @return string SHA-256 identity hash.
 */
function corebb_rate_limit_hash_identity(string $identity): string
{
    $identity = trim($identity);
    if ($identity === '') {
        $identity = 'unknown';
    }
    return hash('sha256', $identity);
}

/**
 * Usage: Build the standard user-or-IP identity for write limits.
 * Referenced by: PM, post, and report limiters.
 *
 * @param array<string, mixed> $user Current viewer row.
 * @return string Rate-limit identity string.
 */
function corebb_rate_limit_user_identity(array $user): string
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0) {
        return 'user:' . $userId;
    }
    return 'ip:' . corebb_rate_limit_current_ip();
}

/**
 * Usage: Check whether a user is exempt from normal write rate limits.
 * Referenced by: PM and post limit wrappers.
 *
 * @param array<string, mixed> $user Current viewer row.
 * @return bool True when the user is moderator level or higher.
 */
function corebb_rate_limit_is_moderator_or_higher(array $user): bool
{
    return (int)($user['accesslevel'] ?? 0) >= 3;
}

/**
 * Usage: Return a consistent rate-limit result when the backing table is unavailable.
 * Referenced by: corebb_rate_limit_check().
 *
 * @param string $action Normalized action key.
 * @return array<string, mixed> Allow/deny result according to fail-open policy.
 */
function corebb_rate_limit_allow_unavailable(string $action): array
{
    if (COREBB_RATE_LIMIT_FAIL_OPEN) {
        return [
            'allowed' => true,
            'unavailable' => true,
            'action' => $action,
            'retry_after' => 0,
            'remaining' => null,
        ];
    }

    return [
        'allowed' => false,
        'unavailable' => true,
        'action' => $action,
        'retry_after' => 60,
        'remaining' => 0,
    ];
}

/**
 * Usage: Apply one fixed-window rate-limit rule for an action and identity.
 * Referenced by: rule sets, API guardrails, and reset-aware wrappers.
 *
 * @param string $action Rate-limit action key.
 * @param string $identity Raw actor identity.
 * @param int $maxHits Allowed hits in the window.
 * @param int $windowSeconds Window length in seconds.
 * @return array<string, mixed> Rate-limit decision with remaining/retry metadata.
 */
function corebb_rate_limit_check(string $action, string $identity, int $maxHits, int $windowSeconds): array
{
    $action = preg_replace('/[^A-Za-z0-9_:\-]/', '_', $action) ?? 'unknown';
    $action = substr($action, 0, 64);
    $maxHits = max(1, $maxHits);
    $windowSeconds = max(1, $windowSeconds);
    $now = time();
    $actorHash = corebb_rate_limit_hash_identity($identity);

    if (!corebb_rate_limit_ensure_schema()) {
        return corebb_rate_limit_allow_unavailable($action);
    }

    corebb_rate_limit_cleanup();

    $row = db_one(
        'SELECT id, window_started, hits FROM ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' WHERE action = ? AND actor_hash = ? LIMIT 1',
        [$action, $actorHash]
    );
    if (!$row) {
        $insert = db_run(
            'INSERT INTO ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' (action, actor_hash, window_started, hits, last_hit) VALUES (?, ?, ?, 1, ?)',
            [$action, $actorHash, $now, $now]
        );
        if (!$insert) {
            error_log('CoreBB rate limit insert failed: ' . db_error());
            return corebb_rate_limit_allow_unavailable($action);
        }
        return [
            'allowed' => true,
            'action' => $action,
            'retry_after' => 0,
            'remaining' => max(0, $maxHits - 1),
            'limit' => $maxHits,
            'window' => $windowSeconds,
        ];
    }

    $windowStarted = (int)($row['window_started'] ?? 0);
    $hits = (int)($row['hits'] ?? 0);
    if ($windowStarted <= 0 || ($now - $windowStarted) >= $windowSeconds) {
        db_run(
            'UPDATE ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' SET window_started = ?, hits = 1, last_hit = ? WHERE id = ?',
            [$now, $now, (int)$row['id']]
        );
        return [
            'allowed' => true,
            'action' => $action,
            'retry_after' => 0,
            'remaining' => max(0, $maxHits - 1),
            'limit' => $maxHits,
            'window' => $windowSeconds,
        ];
    }

    $hits++;
    db_run(
        'UPDATE ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' SET hits = ?, last_hit = ? WHERE id = ?',
        [$hits, $now, (int)$row['id']]
    );

    $retryAfter = max(1, $windowSeconds - ($now - $windowStarted));
    return [
        'allowed' => $hits <= $maxHits,
        'action' => $action,
        'retry_after' => $hits <= $maxHits ? 0 : $retryAfter,
        'remaining' => max(0, $maxHits - $hits),
        'limit' => $maxHits,
        'window' => $windowSeconds,
    ];
}

/**
 * Usage: Apply a sequence of rate-limit rules and return the first denial.
 * Referenced by: login, registration, PM, post, report, and API limiters.
 *
 * @param array<int, array<string, mixed>> $rules Rate-limit rules with action, identity, max, and window keys.
 * @return array<string, mixed> Final allow result or first denied rule result.
 */
function corebb_rate_limit_check_rules(array $rules): array
{
    $allowed = [
        'allowed' => true,
        'retry_after' => 0,
        'remaining' => null,
    ];

    foreach ($rules as $rule) {
        $result = corebb_rate_limit_check(
            (string)($rule['action'] ?? 'unknown'),
            (string)($rule['identity'] ?? 'unknown'),
            (int)($rule['max'] ?? 1),
            (int)($rule['window'] ?? 60)
        );
        if (empty($result['allowed'])) {
            return $result;
        }
        $allowed = $result;
    }

    $allowed['allowed'] = true;
    return $allowed;
}

/**
 * Usage: Clear one rate-limit bucket after a successful action.
 * Referenced by: corebb_rate_limit_login_success().
 *
 * @param string $action Rate-limit action key.
 * @param string $identity Raw actor identity.
 * @return void
 */
function corebb_rate_limit_reset(string $action, string $identity): void
{
    $action = preg_replace('/[^A-Za-z0-9_:\-]/', '_', $action) ?? 'unknown';
    $action = substr($action, 0, 64);
    if (!corebb_rate_limit_ensure_schema()) {
        return;
    }
    db_run('DELETE FROM ' . corebb_rate_limit_identifier(COREBB_RATE_LIMIT_TABLE) . ' WHERE action = ? AND actor_hash = ?', [$action, corebb_rate_limit_hash_identity($identity)]);
}

/**
 * Usage: Convert a retry-after value into a short user-facing wait string.
 * Referenced by: corebb_rate_limit_message().
 *
 * @param int $seconds Seconds until retry.
 * @return string Human-readable wait duration.
 */
function corebb_rate_limit_format_wait(int $seconds): string
{
    $seconds = max(1, $seconds);
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    $hours = (int)ceil($minutes / 60);
    return $hours . ' hour' . ($hours === 1 ? '' : 's');
}

/**
 * Usage: Build the standard user-facing rate-limit error message.
 * Referenced by: login, API, PM, post, report, and Contact Mods flows.
 *
 * @param array<string, mixed> $result Rate-limit result from a check helper.
 * @param string $label Human-readable action label.
 * @return string Error message with retry timing.
 */
function corebb_rate_limit_message(array $result, string $label): string
{
    $wait = corebb_rate_limit_format_wait((int)($result['retry_after'] ?? 60));
    return 'Slow down. You have hit the temporary rate limit for ' . $label . '. Please try again in ' . $wait . '.';
}

/**
 * Usage: Provide default admin-configurable rate-limit settings.
 * Referenced by: admin settings helpers and setting lookup.
 *
 * @return array<string, string> Default setting values keyed by systemsettings name.
 */
function corebb_rate_limit_default_settings(): array
{
    return [
        'rate_limit_login_enabled' => '1',
        'rate_limit_login_ip_10m_max' => '8',
        'rate_limit_login_ip_10m_window' => '600',
        'rate_limit_login_ip_1h_max' => '25',
        'rate_limit_login_ip_1h_window' => '3600',
        'rate_limit_login_user_ip_15m_max' => '6',
        'rate_limit_login_user_ip_15m_window' => '900',
        'rate_limit_registration_enabled' => '1',
        'rate_limit_registration_ip_10m_max' => '2',
        'rate_limit_registration_ip_10m_window' => '600',
        'rate_limit_registration_ip_1d_max' => '5',
        'rate_limit_registration_ip_1d_window' => '86400',
        'rate_limit_pm_enabled' => '1',
        'rate_limit_pm_user_10m_max' => '10',
        'rate_limit_pm_user_10m_window' => '600',
        'rate_limit_pm_user_1h_max' => '40',
        'rate_limit_pm_user_1h_window' => '3600',
        'rate_limit_post_enabled' => '1',
        'rate_limit_post_user_20s_max' => '1',
        'rate_limit_post_user_20s_window' => '20',
        'rate_limit_post_user_10m_max' => '15',
        'rate_limit_post_user_10m_window' => '600',
        'rate_limit_post_user_1h_max' => '60',
        'rate_limit_post_user_1h_window' => '3600',
        'rate_limit_report_enabled' => '1',
        'rate_limit_report_user_10m_max' => '5',
        'rate_limit_report_user_10m_window' => '600',
        'rate_limit_report_user_1d_max' => '25',
        'rate_limit_report_user_1d_window' => '86400',
        'rate_limit_report_ip_1h_max' => '30',
        'rate_limit_report_ip_1h_window' => '3600',
    ];
}

/**
 * Usage: Load and clamp one numeric rate-limit setting from systemsettings.
 * Referenced by: enabled checks and action-specific rate limiters.
 *
 * @param string $name systemsettings.name value.
 * @param int $default Fallback numeric value.
 * @param int $min Minimum allowed value.
 * @param int $max Maximum allowed value.
 * @return int Clamped setting value.
 */
function corebb_rate_limit_setting(string $name, int $default, int $min = 1, int $max = 604800): int
{
    static $cache = [];
    $defaults = corebb_rate_limit_default_settings();
    $fallback = (string)($defaults[$name] ?? (string)$default);

    if (!array_key_exists($name, $cache)) {
        $value = $fallback;
        if (function_exists('db_value')) {
            $value = (string)db_value('SELECT setting FROM systemsettings WHERE name = ? ORDER BY id DESC LIMIT 1', [$name], $fallback);
        }
        $cache[$name] = $value;
    }

    $raw = trim((string)$cache[$name]);
    if (!preg_match('/^-?\d+$/', $raw)) {
        $raw = $fallback;
    }
    return max($min, min($max, (int)$raw));
}

/**
 * Usage: Load a boolean rate-limit setting.
 * Referenced by: action-specific rate limiter wrappers.
 *
 * @param string $name systemsettings.name value.
 * @param bool $default Fallback boolean value.
 * @return bool True when the setting is enabled.
 */
function corebb_rate_limit_setting_enabled(string $name, bool $default = true): bool
{
    return corebb_rate_limit_setting($name, $default ? 1 : 0, 0, 1) === 1;
}

/**
 * Usage: Return a consistent allow result when a limiter is administratively disabled.
 * Referenced by: action-specific rate limiter wrappers.
 *
 * @param string $action Action label for the disabled result.
 * @return array<string, mixed> Allowed result marked as disabled.
 */
function corebb_rate_limit_allowed_disabled(string $action): array
{
    return [
        'allowed' => true,
        'disabled' => true,
        'action' => $action,
        'retry_after' => 0,
        'remaining' => null,
    ];
}

/**
 * Usage: Apply login rate limits by IP and username/IP combination.
 * Referenced by: auth login flow and API auth.
 *
 * @param string $username Submitted username.
 * @return array<string, mixed> Rate-limit decision for the login attempt.
 */
function corebb_rate_limit_login_attempt(string $username): array
{
    if (!corebb_rate_limit_setting_enabled('rate_limit_login_enabled', true)) {
        return corebb_rate_limit_allowed_disabled('login');
    }

    $ip = corebb_rate_limit_current_ip();
    $name = strtolower(trim($username));
    if ($name === '') {
        $name = 'unknown';
    }

    return corebb_rate_limit_check_rules([
        [
            'action' => 'login_ip_10m',
            'identity' => 'ip:' . $ip,
            'max' => corebb_rate_limit_setting('rate_limit_login_ip_10m_max', 8, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_login_ip_10m_window', 600, 1, 604800),
        ],
        [
            'action' => 'login_ip_1h',
            'identity' => 'ip:' . $ip,
            'max' => corebb_rate_limit_setting('rate_limit_login_ip_1h_max', 25, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_login_ip_1h_window', 3600, 1, 604800),
        ],
        [
            'action' => 'login_user_ip_15m',
            'identity' => 'user:' . $name . '|ip:' . $ip,
            'max' => corebb_rate_limit_setting('rate_limit_login_user_ip_15m_max', 6, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_login_user_ip_15m_window', 900, 1, 604800),
        ],
    ]);
}

/**
 * Usage: Clear the username/IP login bucket after a successful login.
 * Referenced by: auth login flow and API auth.
 *
 * @param string $username Submitted username that just authenticated.
 * @return void
 */
function corebb_rate_limit_login_success(string $username): void
{
    $ip = corebb_rate_limit_current_ip();
    $name = strtolower(trim($username));
    if ($name === '') {
        return;
    }
    corebb_rate_limit_reset('login_user_ip_15m', 'user:' . $name . '|ip:' . $ip);
}

/**
 * Usage: Apply registration rate limits by IP.
 * Referenced by: api/v1/index.php registration endpoint.
 *
 * @return array<string, mixed> Rate-limit decision for a registration attempt.
 */
function corebb_rate_limit_registration_attempt(): array
{
    if (!corebb_rate_limit_setting_enabled('rate_limit_registration_enabled', true)) {
        return corebb_rate_limit_allowed_disabled('registration');
    }

    $identity = 'ip:' . corebb_rate_limit_current_ip();
    return corebb_rate_limit_check_rules([
        [
            'action' => 'registration_ip_10m',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_registration_ip_10m_max', 2, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_registration_ip_10m_window', 600, 1, 604800),
        ],
        [
            'action' => 'registration_ip_1d',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_registration_ip_1d_max', 5, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_registration_ip_1d_window', 86400, 1, 604800),
        ],
    ]);
}

/**
 * Usage: Apply private-message send rate limits for non-staff users.
 * Referenced by: PM send helpers.
 *
 * @param array<string, mixed> $user Sender user row.
 * @return array<string, mixed> Rate-limit decision for sending a PM.
 */
function corebb_rate_limit_pm_send(array $user): array
{
    if (!corebb_rate_limit_setting_enabled('rate_limit_pm_enabled', true)) {
        return corebb_rate_limit_allowed_disabled('pm');
    }
    if (corebb_rate_limit_is_moderator_or_higher($user)) {
        return ['allowed' => true, 'exempt' => true, 'retry_after' => 0];
    }
    $identity = corebb_rate_limit_user_identity($user);
    return corebb_rate_limit_check_rules([
        [
            'action' => 'pm_user_10m',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_pm_user_10m_max', 10, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_pm_user_10m_window', 600, 1, 604800),
        ],
        [
            'action' => 'pm_user_1h',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_pm_user_1h_max', 40, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_pm_user_1h_window', 3600, 1, 604800),
        ],
    ]);
}

/**
 * Usage: Apply post/write rate limits for non-staff users.
 * Referenced by: post workflow view model.
 *
 * @param array<string, mixed> $user Posting user row.
 * @return array<string, mixed> Rate-limit decision for post creation/editing.
 */
function corebb_rate_limit_post_write(array $user): array
{
    if (!corebb_rate_limit_setting_enabled('rate_limit_post_enabled', true)) {
        return corebb_rate_limit_allowed_disabled('post');
    }
    if (corebb_rate_limit_is_moderator_or_higher($user)) {
        return ['allowed' => true, 'exempt' => true, 'retry_after' => 0];
    }
    $identity = corebb_rate_limit_user_identity($user);
    return corebb_rate_limit_check_rules([
        [
            'action' => 'post_user_20s',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_post_user_20s_max', 1, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_post_user_20s_window', 20, 1, 604800),
        ],
        [
            'action' => 'post_user_10m',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_post_user_10m_max', 15, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_post_user_10m_window', 600, 1, 604800),
        ],
        [
            'action' => 'post_user_1h',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_post_user_1h_max', 60, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_post_user_1h_window', 3600, 1, 604800),
        ],
    ]);
}

/**
 * Usage: Apply report/contact rate limits by user and IP.
 * Referenced by: post reports, PM reports, admin report creation, and Contact Mods.
 *
 * @param array<string, mixed> $user Reporting user row.
 * @return array<string, mixed> Rate-limit decision for report submission.
 */
function corebb_rate_limit_report_submit(array $user): array
{
    if (!corebb_rate_limit_setting_enabled('rate_limit_report_enabled', true)) {
        return corebb_rate_limit_allowed_disabled('report');
    }
    $identity = corebb_rate_limit_user_identity($user);
    $ip = corebb_rate_limit_current_ip();
    return corebb_rate_limit_check_rules([
        [
            'action' => 'report_user_10m',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_report_user_10m_max', 5, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_report_user_10m_window', 600, 1, 604800),
        ],
        [
            'action' => 'report_user_1d',
            'identity' => $identity,
            'max' => corebb_rate_limit_setting('rate_limit_report_user_1d_max', 25, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_report_user_1d_window', 86400, 1, 604800),
        ],
        [
            'action' => 'report_ip_1h',
            'identity' => 'ip:' . $ip,
            'max' => corebb_rate_limit_setting('rate_limit_report_ip_1h_max', 30, 1, 100000),
            'window' => corebb_rate_limit_setting('rate_limit_report_ip_1h_window', 3600, 1, 604800),
        ],
    ]);
}

?>
