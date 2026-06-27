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
 |  admin_helpers.php  - Small helpers for the PHP 8     |
 |  admin modernization pass.                            |
 +-------------------------------------------------------+*/

if (!defined('COREBB_ADMIN_HELPERS_LOADED')) {
    define('COREBB_ADMIN_HELPERS_LOADED', true);
}

include_once __DIR__ . '/rate_limit_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/public_style_helpers.php';
require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/view.php';

/**
 * Usage: Read one system setting with a caller-supplied fallback.
 * Referenced by: admin settings, style, and content tools.
 *
 * @param string $name Setting or display name.
 * @param string $default Fallback value.
 * @return string Stored setting value or fallback.
 */
function corebb_admin_setting_get(string $name, string $default = ''): string
{
    return (string)db_value('SELECT setting FROM systemsettings WHERE name = ? LIMIT 1', [$name], $default);
}

/**
 * Usage: Insert or update a system setting, preserving id=1 for the default style.
 * Referenced by: corebb_admin_save_system_settings(), TOS saves, and style saves.
 *
 * @param string $name Setting or display name.
 * @param string $setting Setting value.
 * @param int|null $id Optional database id.
 * @return bool True when the write succeeds.
 */
function corebb_admin_setting_upsert(string $name, string $setting, ?int $id = null): bool
{
    if ($id !== null) {
        if (db_exists('SELECT id FROM systemsettings WHERE id = ? LIMIT 1', [$id])) {
            return db_run('UPDATE systemsettings SET name = ?, setting = ? WHERE id = ?', [$name, $setting, $id]);
        }
        return db_run('INSERT INTO systemsettings (id, name, setting) VALUES (?, ?, ?)', [$id, $name, $setting]);
    }

    $row = db_one('SELECT id FROM systemsettings WHERE name = ? LIMIT 1', [$name]);
    if ($row) {
        return db_run('UPDATE systemsettings SET setting = ? WHERE id = ?', [$setting, (int)$row['id']]);
    }
    return db_run('INSERT INTO systemsettings (name, setting) VALUES (?, ?)', [$name, $setting]);
}

/**
 * Usage: Describe editable rate-limit settings and their default values.
 * Referenced by: corebb_admin_system_settings() and normalization helpers.
 *
 * @return array<string, array{0: string, 1: string}> Setting labels and defaults keyed by setting name.
 */
function corebb_admin_rate_limit_setting_labels(): array
{
    return [
        'rate_limit_login_enabled' => ['Rate limiting: login enabled', '1'],
        'rate_limit_login_ip_10m_max' => ['Login: max attempts per IP, short window', '8'],
        'rate_limit_login_ip_10m_window' => ['Login: short IP window in seconds', '600'],
        'rate_limit_login_ip_1h_max' => ['Login: max attempts per IP, long window', '25'],
        'rate_limit_login_ip_1h_window' => ['Login: long IP window in seconds', '3600'],
        'rate_limit_login_user_ip_15m_max' => ['Login: max attempts per username/IP', '6'],
        'rate_limit_login_user_ip_15m_window' => ['Login: username/IP window in seconds', '900'],
        'rate_limit_pm_enabled' => ['Rate limiting: private messages enabled', '1'],
        'rate_limit_pm_user_10m_max' => ['PMs: max sends per user, short window', '10'],
        'rate_limit_pm_user_10m_window' => ['PMs: short user window in seconds', '600'],
        'rate_limit_pm_user_1h_max' => ['PMs: max sends per user, long window', '40'],
        'rate_limit_pm_user_1h_window' => ['PMs: long user window in seconds', '3600'],
        'rate_limit_post_enabled' => ['Rate limiting: posting enabled', '1'],
        'rate_limit_post_user_20s_max' => ['Posting: max posts per user, burst window', '1'],
        'rate_limit_post_user_20s_window' => ['Posting: burst user window in seconds', '20'],
        'rate_limit_post_user_10m_max' => ['Posting: max posts per user, short window', '15'],
        'rate_limit_post_user_10m_window' => ['Posting: short user window in seconds', '600'],
        'rate_limit_post_user_1h_max' => ['Posting: max posts per user, long window', '60'],
        'rate_limit_post_user_1h_window' => ['Posting: long user window in seconds', '3600'],
        'rate_limit_report_enabled' => ['Rate limiting: post reports enabled', '1'],
        'rate_limit_report_user_10m_max' => ['Reports: max reports per user, short window', '5'],
        'rate_limit_report_user_10m_window' => ['Reports: short user window in seconds', '600'],
        'rate_limit_report_user_1d_max' => ['Reports: max reports per user, daily window', '25'],
        'rate_limit_report_user_1d_window' => ['Reports: daily user window in seconds', '86400'],
        'rate_limit_report_ip_1h_max' => ['Reports: max reports per IP, hourly window', '30'],
        'rate_limit_report_ip_1h_window' => ['Reports: hourly IP window in seconds', '3600'],
    ];
}

/**
 * Usage: List rate-limit settings that should be normalized as on/off flags.
 * Referenced by: corebb_admin_normalize_system_setting().
 *
 * @return array<int, string> Boolean system setting names.
 */
function corebb_admin_rate_limit_boolean_settings(): array
{
    return ['rate_limit_login_enabled', 'rate_limit_pm_enabled', 'rate_limit_post_enabled', 'rate_limit_report_enabled'];
}

/**
 * Usage: List rate-limit settings that should be normalized as positive integers.
 * Referenced by: corebb_admin_normalize_system_setting().
 *
 * @return array<int, string> Numeric system setting names.
 */
function corebb_admin_rate_limit_numeric_settings(): array
{
    return array_values(array_diff(array_keys(corebb_admin_rate_limit_setting_labels()), corebb_admin_rate_limit_boolean_settings()));
}

/**
 * Usage: Coerce a posted system setting into the range/type CoreBB expects.
 * Referenced by: corebb_admin_save_system_settings().
 *
 * @param string $name Setting or display name.
 * @param string $value Raw value to normalize.
 * @return string Normalized value safe to persist.
 */
function corebb_admin_normalize_system_setting(string $name, string $value): string
{
    if ($name === 'defaultstyle') {
        $value = corebb_public_style_normalize_file(trim(str_replace('\\', '/', $value)));
        if (isset(corebb_public_style_builtin_options()[$value])) {
            return $value;
        }
        return 'style_vn_eol.css';
    }

    if (in_array($name, corebb_admin_rate_limit_boolean_settings(), true)) {
        return $value === '1' ? '1' : '0';
    }

    if (in_array($name, corebb_admin_rate_limit_numeric_settings(), true)) {
        $value = trim($value);
        if (!preg_match('/^-?\d+$/', $value)) {
            $defaults = corebb_rate_limit_default_settings();
            return (string)($defaults[$name] ?? '1');
        }
        $int = max(1, min(604800, (int)$value));
        if (str_ends_with($name, '_max')) {
            $int = max(1, min(100000, (int)$value));
        }
        return (string)$int;
    }

    return $value;
}

/**
 * Usage: Build the editable settings list shown in the admin settings page.
 * Referenced by: lib/admin_settings_view_model.php.
 *
 * @return array<string, array{id: int, label: string, value: string}> Settings keyed by name.
 */
function corebb_admin_system_settings(): array
{
    $known = [
        'defaultstyle' => ['Default stylesheet', 'style_vn_eol.css'],
        'theme_vn_eol' => ['Use VNBoards end-of-life public theme', '1'],
        'encaseboards' => ['Use wrapped board chrome', '1'],
        'showbasicstats' => ['Show basic board/message stats', '1'],
        'allowguests' => ['Allow guests to browse forums', '1'],
        'customtitles' => ['Enable custom titles', '1'],
        'quickreply' => ['Enable quick reply', '1'],
        'markupcode' => ['Markup/code allowed in posts', '1'],
        'maintenancemode' => ['Maintenance mode', '0'],
        'maintenancesubject' => ['Maintenance message subject', 'Boards Offline'],
        'maintenancemessage' => ['Maintenance message body', 'The boards are temporarily unavailable.'],
        'terms_of_service' => ['Terms of Service body', ''],
        'installed_version' => ['Installed CoreBB version', defined('COREBB_VERSION') ? COREBB_VERSION : '1.0.0'],
        'schema_version' => ['Installed CoreBB schema version', defined('COREBB_SCHEMA_VERSION') ? (string)COREBB_SCHEMA_VERSION : '1'],
        'last_update_check_at' => ['Last update check time', ''],
        'last_update_check_status' => ['Last update check status', 'never'],
        'last_update_manifest' => ['Cached update manifest', ''],
        'last_successful_update_check_at' => ['Last successful update check time', ''],
        'last_update_check_error' => ['Last update check error', ''],
        'update_manifest_signature_status' => ['Update manifest signature status', 'unsigned'],
    ];
    $known += corebb_admin_rate_limit_setting_labels();

    $settings = [];
    foreach (db_all('SELECT id, name, setting FROM systemsettings ORDER BY id ASC, name ASC') as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name === '' && (int)($row['id'] ?? 0) === 1) {
            $name = 'defaultstyle';
        }
        if ($name === '') {
            continue;
        }
        $settings[$name] = [
            'id' => (int)($row['id'] ?? 0),
            'label' => $known[$name][0] ?? $name,
            'value' => (string)($row['setting'] ?? ''),
        ];
    }

    foreach ($known as $name => [$label, $default]) {
        if (!isset($settings[$name])) {
            $settings[$name] = ['id' => 0, 'label' => $label, 'value' => $default];
        }
    }

    return $settings;
}

/**
 * Usage: Persist all settings submitted from the admin settings form.
 * Referenced by: corebb_admin_settings_model().
 *
 * @param array<string, mixed> $posted Raw POST payload.
 * @return array<int, string> User-facing result messages.
 */
function corebb_admin_save_system_settings(array $posted): array
{
    $messages = [];
    foreach (($posted['settings'] ?? []) as $name => $value) {
        $name = trim((string)$name);
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            $messages[] = "Skipped invalid setting name: {$name}";
            continue;
        }
        $value = corebb_admin_normalize_system_setting($name, (string)$value);
        $id = null;
        if ($name === 'defaultstyle') {
            $id = 1;
        }
        if (!corebb_admin_setting_upsert($name, $value, $id)) {
            $messages[] = "Failed to save {$name}: " . db_error();
        }
    }

    $newName = trim((string)($posted['new_setting_name'] ?? ''));
    if ($newName !== '') {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $newName)) {
            $messages[] = "Skipped invalid new setting name: {$newName}";
        } else {
            $newValue = corebb_admin_normalize_system_setting($newName, (string)($posted['new_setting_value'] ?? ''));
            if (!corebb_admin_setting_upsert($newName, $newValue)) {
                $messages[] = "Failed to add {$newName}: " . db_error();
            }
        }
    }

    if (!$messages) {
        $messages[] = 'System settings saved.';
    }
    return $messages;
}

/**
 * Usage: Choose the next display position for a new category.
 * Referenced by: corebb_admin_add_category().
 *
 * @return int Next category sort position.
 */
function corebb_admin_next_category_position(): int
{
    return (int)db_value('SELECT COALESCE(MAX(position), 0) FROM boards', [], 0) + 1;
}

/**
 * Usage: Renumber category positions after moves or deletes.
 * Referenced by: board-management helpers and models.
 *
 * @return void
 */
function corebb_admin_reindex_category_positions(): void
{
    $pos = 1;
    foreach (db_all('SELECT id FROM boards ORDER BY position ASC, id ASC') as $row) {
        db_run('UPDATE boards SET position = ? WHERE id = ?', [$pos++, (int)$row['id']]);
    }
}

/**
 * Usage: Choose the next display position for a new board in a category.
 * Referenced by: corebb_admin_add_board_model().
 *
 * @param int $categoryId Category id.
 * @return int Next board sort position.
 */
function corebb_admin_next_forum_position(int $categoryId): int
{
    return (int)db_value('SELECT COALESCE(MAX(position), 0) FROM forums WHERE categoryid = ?', [$categoryId], 0) + 1;
}

/**
 * Usage: Renumber board positions within one category after moves or deletes.
 * Referenced by: board-management helpers and models.
 *
 * @param int $categoryId Category id.
 * @return void
 */
function corebb_admin_reindex_forum_positions(int $categoryId): void
{
    if ($categoryId <= 0) { return; }
    $pos = 1;
    foreach (db_all('SELECT id FROM forums WHERE categoryid = ? ORDER BY position ASC, id ASC', [$categoryId]) as $row) {
        db_run('UPDATE forums SET position = ? WHERE id = ?', [$pos++, (int)$row['id']]);
    }
}

/**
 * Usage: Create a category from the admin board manager.
 * Referenced by: corebb_admin_add_category_model().
 *
 * @param string $name Setting or display name.
 * @param int $private Whether the category is private.
 * @param int $secureArchive Whether the category is secure archive/read-only.
 * @param int $defaultOpen Whether the category starts expanded.
 * @return bool True when the insert succeeds.
 */
function corebb_admin_add_category(string $name, int $private = 0, int $secureArchive = 0, int $defaultOpen = 0): bool
{
    corebb_perf_add_column_if_missing('boards', 'default_open', 'TINYINT(1) NOT NULL DEFAULT 0');

    return db_run(
        'INSERT INTO boards (name, position, private, secure_archive, default_open) VALUES (?, ?, ?, ?, ?)',
        [$name, corebb_admin_next_category_position(), $private ? 1 : 0, $secureArchive ? 1 : 0, $defaultOpen ? 1 : 0]
    );
}

/**
 * Usage: Delete a board and all rows that directly belong to it.
 * Referenced by: corebb_admin_modify_board_model().
 *
 * @param int $forumId Forum/board id.
 * @return array{0: bool, 1: string} Success flag and user-facing message.
 */
function corebb_admin_delete_board_full(int $forumId): array
{
    $forum = db_one('SELECT * FROM forums WHERE id = ? LIMIT 1', [$forumId]);
    if (!$forum) {
        return [false, 'Unknown board.'];
    }
    if (!corebb_secure_archive_user_can_write_board_id($forumId)) {
        return [false, corebb_secure_archive_denied_message()];
    }
    $categoryId = (int)($forum['categoryid'] ?? 0);
    db_run('DELETE FROM posts WHERE boardid = ?', [$forumId]);
    db_run('DELETE FROM topics WHERE boardid = ?', [$forumId]);
    db_run('DELETE FROM favoriteboards WHERE boardid = ?', [$forumId]);
    if (corebb_perf_table_exists('private_board_access')) {
        db_run('DELETE FROM private_board_access WHERE boardid = ?', [$forumId]);
    }
    if (!db_run('DELETE FROM forums WHERE id = ?', [$forumId])) {
        return [false, 'Error deleting board: ' . db_error()];
    }
    corebb_admin_reindex_forum_positions($categoryId);
    return [true, 'Successfully deleted the board and all topics/posts within it.'];
}

/**
 * Usage: Move all content to another board, then delete the now-empty source board.
 * Referenced by: corebb_admin_modify_board_model().
 *
 * @param int $fromForumId Source board id.
 * @param int $toForumId Destination board id.
 * @return array{0: bool, 1: string} Success flag and user-facing message.
 */
function corebb_admin_move_board_contents_and_delete(int $fromForumId, int $toForumId): array
{
    if ($fromForumId <= 0 || $toForumId <= 0 || $fromForumId === $toForumId) {
        return [false, 'Invalid source or destination board.'];
    }
    $from = db_one('SELECT * FROM forums WHERE id = ? LIMIT 1', [$fromForumId]);
    $to = db_one('SELECT * FROM forums WHERE id = ? LIMIT 1', [$toForumId]);
    if (!$from || !$to) {
        return [false, 'Unknown source or destination board.'];
    }
    if (!corebb_secure_archive_user_can_write_board_id($fromForumId) || !corebb_secure_archive_user_can_write_board_id($toForumId)) {
        return [false, corebb_secure_archive_denied_message()];
    }
    $categoryId = (int)($from['categoryid'] ?? 0);
    db_run('UPDATE topics SET boardid = ? WHERE boardid = ?', [$toForumId, $fromForumId]);
    db_run('UPDATE posts SET boardid = ? WHERE boardid = ?', [$toForumId, $fromForumId]);
    db_run('UPDATE favoriteboards SET boardid = ? WHERE boardid = ?', [$toForumId, $fromForumId]);
    if (corebb_perf_table_exists('private_board_access')) {
        db_run('DELETE FROM private_board_access WHERE boardid = ?', [$fromForumId]);
    }
    if (!db_run('DELETE FROM forums WHERE id = ?', [$fromForumId])) {
        return [false, 'Error deleting source board after move: ' . db_error()];
    }
    corebb_admin_reindex_forum_positions($categoryId);
    return [true, 'Successfully moved topics/posts to the destination board and deleted the old board.'];
}
