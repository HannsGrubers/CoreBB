<?php
/*-------------------------------------------------------
 | admin_log_helpers.php - Admin action log schema and
 | write helpers.
 +-------------------------------------------------------*/

/**
 * Usage: Run a scalar COUNT-style query for admin-log schema checks.
 * Referenced by: adminlogs table, column, key, and index detection helpers.
 *
 * @param string $sql SQL returning a scalar numeric value.
 * @param array<int, mixed> $params Bound query parameters.
 * @return int Scalar result cast to an integer.
 */
function corebb_adminlogs_scalar_count(string $sql, array $params = []): int
{
    return (int)db_value($sql, $params, 0);
}

/**
 * Usage: Check whether the adminlogs table exists.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @return bool True when adminlogs is available in the current database.
 */
function corebb_adminlogs_table_exists(): bool
{
    $count = corebb_adminlogs_scalar_count(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['adminlogs']
    );
    if ($count > 0) {
        return true;
    }

    // Fallback for hosts with restricted INFORMATION_SCHEMA access.
    return db_exists('SHOW TABLES LIKE ?', ['adminlogs']);
}

/**
 * Usage: Check whether an adminlogs column exists before selecting or writing it.
 * Referenced by: schema migration, insert filtering, and select expression helpers.
 *
 * @param string $column Adminlogs column name.
 * @return bool True when the column exists.
 */
function corebb_adminlogs_column_exists(string $column): bool
{
    $column = trim($column);
    if ($column === '') {
        return false;
    }

    $count = corebb_adminlogs_scalar_count(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['adminlogs', $column]
    );
    if ($count > 0) {
        return true;
    }

    // Do not use result-row counting for SHOW COLUMNS here; PDO rowCount can be
    // unreliable for metadata SELECT-like statements.
    return db_exists('SHOW COLUMNS FROM `adminlogs` LIKE ?', [$column]);
}

/**
 * Usage: Detect whether adminlogs already has a primary key.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @return bool True when a primary key is present.
 */
function corebb_adminlogs_primary_key_exists(): bool
{
    return corebb_adminlogs_scalar_count(
        "SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'adminlogs' AND INDEX_NAME = 'PRIMARY'"
    ) > 0;
}

/**
 * Usage: Detect whether a named adminlogs index exists.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @param string $index Index name to check.
 * @return bool True when the index exists.
 */
function corebb_adminlogs_index_exists(string $index): bool
{
    $index = trim($index);
    if ($index === '') {
        return false;
    }
    return corebb_adminlogs_scalar_count(
        'SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        ['adminlogs', $index]
    ) > 0;
}

/**
 * Usage: Create or upgrade adminlogs so current admin pages can read/write it.
 * Referenced by: corebb_adminlogs_insert() and admin log viewers.
 *
 * @return void
 */
function corebb_adminlogs_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!corebb_adminlogs_table_exists()) {
        db_run("CREATE TABLE IF NOT EXISTS `adminlogs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `userid` INT NOT NULL DEFAULT 0,
            `userlevel` VARCHAR(255) NOT NULL DEFAULT '',
            `action` TEXT NULL,
            `admin_username` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
            `action_type` VARCHAR(80) NOT NULL DEFAULT '',
            `description` TEXT NULL,
            `date_performed` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return;
    }

    /*
     * Old CoreBB/VN imports may already have adminlogs, but without the id or
     * metadata columns that the rebuilt dashboard/action-log pages now read.
     * A previous version used result-row counting on SHOW COLUMNS/TABLES, which can
     * report 0 under PDO even when the row exists. Use INFORMATION_SCHEMA above
     * and make the migration idempotent here. Lipstick on a pig!
     */
    if (!corebb_adminlogs_column_exists('id')) {
        if (corebb_adminlogs_primary_key_exists()) {
            db_run("ALTER TABLE `adminlogs` ADD `id` INT NOT NULL AUTO_INCREMENT FIRST, ADD KEY `idx_adminlogs_id` (`id`)");
        } else {
            $addedPrimaryId = db_run("ALTER TABLE `adminlogs` ADD `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
            if (!$addedPrimaryId && !corebb_adminlogs_column_exists('id')) {
                db_run("ALTER TABLE `adminlogs` ADD `id` INT NOT NULL AUTO_INCREMENT FIRST, ADD KEY `idx_adminlogs_id` (`id`)");
            }
        }
    } elseif (!corebb_adminlogs_index_exists('idx_adminlogs_id') && !corebb_adminlogs_primary_key_exists()) {
        db_run("ALTER TABLE `adminlogs` ADD KEY `idx_adminlogs_id` (`id`)");
    }

    $columns = [
        'userid' => "ALTER TABLE `adminlogs` ADD `userid` INT NOT NULL DEFAULT 0",
        'userlevel' => "ALTER TABLE `adminlogs` ADD `userlevel` VARCHAR(255) NOT NULL DEFAULT ''",
        'action' => "ALTER TABLE `adminlogs` ADD `action` TEXT NULL",
        'admin_username' => "ALTER TABLE `adminlogs` ADD `admin_username` VARCHAR(255) NOT NULL DEFAULT ''",
        'ip_address' => "ALTER TABLE `adminlogs` ADD `ip_address` VARCHAR(64) NOT NULL DEFAULT ''",
        'action_type' => "ALTER TABLE `adminlogs` ADD `action_type` VARCHAR(80) NOT NULL DEFAULT ''",
        'description' => "ALTER TABLE `adminlogs` ADD `description` TEXT NULL",
        'date_performed' => "ALTER TABLE `adminlogs` ADD `date_performed` DATETIME NULL",
    ];

    foreach ($columns as $column => $sql) {
        if (!corebb_adminlogs_column_exists($column)) {
            db_run($sql);
        }
    }

    if (corebb_adminlogs_column_exists('description') && corebb_adminlogs_column_exists('action')) {
        db_run("UPDATE `adminlogs` SET `description` = `action` WHERE (`description` IS NULL OR `description` = '') AND `action` IS NOT NULL");
    }
    if (corebb_adminlogs_column_exists('admin_username') && corebb_adminlogs_column_exists('userid')) {
        db_run("UPDATE `adminlogs` al JOIN `users` u ON u.id = al.userid SET al.admin_username = u.username WHERE al.admin_username = '' AND al.userid > 0");
    }
}

/**
 * Usage: Insert an admin log row while tolerating older partial schemas.
 * Referenced by: corebb_adminlog_entry().
 *
 * @param array<string, mixed> $values Column/value pairs for the new row.
 * @return bool True when an insert was attempted and succeeded.
 */
function corebb_adminlogs_insert(array $values): bool
{
    corebb_adminlogs_ensure_schema();

    $columns = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (corebb_adminlogs_column_exists((string)$column)) {
            $columns[] = '`' . str_replace('`', '``', (string)$column) . '`';
            $params[] = $value;
        }
    }

    if (!$columns) {
        return false;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO `adminlogs` (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    return db_run($sql, $params);
}

/**
 * Usage: Choose a safe SELECT expression for optional adminlogs columns.
 * Referenced by: admin log list queries.
 *
 * @param string $column Preferred adminlogs column name.
 * @param string $fallbackSql SQL expression to use when the column is absent.
 * @return string SQL expression suitable for a SELECT list.
 */
function corebb_adminlogs_select_expr(string $column, string $fallbackSql): string
{
    return corebb_adminlogs_column_exists($column)
        ? 'al.`' . str_replace('`', '``', $column) . '`'
        : $fallbackSql;
}

/**
 * Usage: Pick a stable ORDER BY clause for whatever adminlogs schema exists.
 * Referenced by: admin log listing queries.
 *
 * @return string SQL ORDER BY expression, without the ORDER BY keyword.
 */
function corebb_adminlogs_order_sql(): string
{
    if (corebb_adminlogs_column_exists('id')) {
        return 'al.`id` DESC';
    }
    if (corebb_adminlogs_column_exists('date_performed')) {
        return 'al.`date_performed` DESC';
    }
    if (corebb_adminlogs_column_exists('userid')) {
        return 'al.`userid` DESC';
    }
    return '1 DESC';
}

/**
 * Usage: Convert a human admin action label into a compact action type key.
 * Referenced by: corebb_adminlog_entry() when callers do not provide an explicit type.
 *
 * @param string $action Human-readable admin action.
 * @return string Stable action type key up to 80 characters.
 */
function corebb_adminlog_action_type(string $action): string
{
    $text = strtolower(trim($action));
    if ($text === '') {
        return 'admin_action';
    }

    $map = [
        'unban request' => 'unban_request_response',
        'unbanned user' => 'unban_user',
        'banned user' => 'ban_user',
        'ban user' => 'ban_user',
        'added admin note' => 'admin_note',
        'admin note' => 'admin_note',
        'avatar' => 'manage_user_icons',
        'icon' => 'manage_user_icons',
        'global message' => 'global_message',
        'modified system settings' => 'edit_system_settings',
        'modified the system tos' => 'edit_system_tos',
        'modified the system style' => 'edit_system_style',
        'destroyed admin session' => 'admin_session',
        'viewed admin message' => 'view_message',
        'viewed post message' => 'view_message',
        'viewed user portal' => 'user_pages',
        'user ip check' => 'user_ip_check',
        'host address lookup' => 'host_lookup',
        'assigned title' => 'assign_title',
        'cleared title' => 'assign_title',
        'user title cleared' => 'user_pages',
        'user signature cleared' => 'user_pages',
        'user icon removed' => 'user_pages',
        'removed post' => 'moderate_content',
        'removed topic' => 'moderate_content',
        'moved post' => 'moderate_content',
        'moved topic' => 'moderate_content',
        'restored deleted post' => 'moderate_content',
        'permanently purged deleted post' => 'moderate_content',
        'locked topic' => 'moderate_content',
        'unlocked topic' => 'moderate_content',
        'closed mod request' => 'mod_request_closed',
        'reopened mod request' => 'mod_request_reopened',
        'changed vip username colors' => 'change_user_style',
        'private message report' => 'pm_moderation',
        'deleted private message' => 'pm_delete',
        'restored private message' => 'pm_restore',
        'cancelled private message report' => 'pm_report_cancelled',
        'changed user' => 'edit_user',
        'added new user' => 'add_user',
        'added board' => 'add_board',
        'modified board' => 'modify_board',
        'deleted board' => 'delete_board',
        'moved board' => 'move_board',
        'moved contents' => 'move_board',
        'added category' => 'add_category',
        'modified category' => 'modify_category',
        'deleted category' => 'delete_category',
    ];

    foreach ($map as $needle => $type) {
        if (strpos($text, $needle) !== false) {
            return $type;
        }
    }

    $slug = preg_replace('/[^a-z0-9]+/', '_', $text);
    $slug = trim((string)$slug, '_');
    if ($slug === '') {
        return 'admin_action';
    }
    $parts = array_slice(explode('_', $slug), 0, 5);
    return substr(implode('_', $parts), 0, 80);
}

/**
 * Usage: Read the current request IP for admin log metadata.
 * Referenced by: corebb_adminlog_entry().
 *
 * @return string Remote IP capped for the adminlogs.ip_address column.
 */
function corebb_adminlog_remote_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 64) : 'Unknown';
}

/**
 * Usage: Resolve a log actor id/name from either a user id or username.
 * Referenced by: corebb_adminlog_entry().
 *
 * @param mixed $userid User id, username, or caller-provided actor value.
 * @return array{0: int, 1: string} Actor id and display username.
 */
function corebb_adminlog_resolve_actor($userid): array
{
    $actorId = 0;
    $actorName = trim((string)$userid);

    if ($actorName !== '' && ctype_digit($actorName)) {
        $actorId = (int)$actorName;
        $row = db_one('SELECT username FROM users WHERE id = ? LIMIT 1', [$actorId]);
        if ($row && !empty($row['username'])) {
            $actorName = (string)$row['username'];
        }
    } elseif ($actorName !== '') {
        $row = db_one('SELECT id, username FROM users WHERE username = ? LIMIT 1', [$actorName]);
        if ($row) {
            $actorId = (int)($row['id'] ?? 0);
            $actorName = (string)($row['username'] ?? $actorName);
        }
    }

    if ($actorName === '') {
        $actorName = 'Unknown';
    }

    return [$actorId, $actorName];
}

/**
 * Usage: Write an admin log entry for an already-loaded viewer row.
 * Referenced by: admin controllers that keep the viewer record in scope.
 *
 * @param array<string, mixed> $viewer Current admin user/session row.
 * @param string $action Human-readable action label.
 * @param string $actionType Optional stable action type key.
 * @param string $description Optional detailed description.
 * @return void
 */
function corebb_adminlog_viewer(array $viewer, string $action, string $actionType = '', string $description = ''): void
{
    $level = (int)($viewer['accesslevel'] ?? 0);
    $actor = $viewer['id'] ?? $viewer['userid'] ?? $viewer['username'] ?? 'Unknown';
    corebb_adminlog_entry((string)$actor, $level, $action, $actionType, $description);
}

/**
 * Usage: Record an admin action in the normalized adminlogs table.
 * Referenced by: admin pages and controllers.
 *
 * @param mixed $userid User id or username performing the action.
 * @param mixed $userlevel Actor access level at the time of the action.
 * @param mixed $action Human-readable action label.
 * @param string $actionType Optional stable action type key.
 * @param string $description Optional detailed description.
 * @return void
 */
function corebb_adminlog_entry($userid, $userlevel, $action, $actionType = '', $description = ''){
    [$actorId, $actorName] = corebb_adminlog_resolve_actor($userid);
    $level = (string)$userlevel;
    $action = (string)$action;
    $description = trim((string)$description) !== '' ? (string)$description : $action;
    $actionType = trim((string)$actionType) !== '' ? trim((string)$actionType) : corebb_adminlog_action_type($action);
    $ip = corebb_adminlog_remote_ip();
    $date = date('Y-m-d H:i:s');

    corebb_adminlogs_insert([
        'userid' => $actorId,
        'userlevel' => $level,
        'action' => $action,
        'admin_username' => $actorName,
        'ip_address' => $ip,
        'action_type' => $actionType,
        'description' => $description,
        'date_performed' => $date,
    ]);
}
