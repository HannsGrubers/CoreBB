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

/** Display the legacy four-part IPv4 format used in imported member records. */
function corebb_admin_display_ip(string $ip): string
{
    $ip = trim($ip);
    return preg_match('/^\d{1,3}(?:-\d{1,3}){3}$/D', $ip) ? str_replace('-', '.', $ip) : $ip;
}

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
    $labels = [];
    $defaults = corebb_rate_limit_default_settings();
    foreach (corebb_admin_rate_limit_groups() as $action => $group) {
        $prefix = 'rate_limit_' . $action . '_';
        $labels[$prefix . 'enabled'] = [$group['title'] . ' rate limiting', $defaults[$prefix . 'enabled']];
        foreach ($group['rules'] as $rule => $label) {
            foreach (['max' => $group['unit'], 'window' => 'Window in seconds'] as $suffix => $unit) {
                $name = $prefix . $rule . '_' . $suffix;
                $labels[$name] = [$group['title'] . ' · ' . $label . ' · ' . $unit, $defaults[$name]];
            }
        }
    }
    return $labels;
}

function corebb_admin_rate_limit_groups(): array
{
    return [
        'login' => ['title' => 'Sign-in attempts', 'unit' => 'Attempts', 'note' => 'Applies to all accounts.',
            'rules' => ['ip_10m' => 'Per IP · short window', 'ip_1h' => 'Per IP · long window', 'user_ip_15m' => 'Per username and IP']],
        'pm' => ['title' => 'Private messages', 'unit' => 'Sends', 'note' => 'Moderators and higher are exempt.',
            'rules' => ['user_10m' => 'Per member · short window', 'user_1h' => 'Per member · long window']],
        'post' => ['title' => 'Posting', 'unit' => 'Posts', 'note' => 'Moderators and higher are exempt.',
            'rules' => ['user_20s' => 'Per member · burst', 'user_10m' => 'Per member · short window', 'user_1h' => 'Per member · long window']],
        'report' => ['title' => 'Reports and Contact Mods', 'unit' => 'Submissions', 'note' => 'Applies to all accounts.',
            'rules' => ['user_10m' => 'Per member · short window', 'user_1d' => 'Per member · long window', 'ip_1h' => 'Per IP']],
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

/** Settings offered by this tool. The section definitions also bound each section's POST. */
function corebb_admin_settings_sections(): array
{
    return [
        'appearance' => ['title' => 'Appearance', 'fields' => [
            'defaultstyle' => ['Default theme', 'style_vn_eol.css', 'style'],
            'showbasicstats' => ['Show basic forum statistics', '1', 'boolean'],
        ]],
        'forum' => ['title' => 'Forum options', 'fields' => [
            'allowguests' => ['Guest browsing', '1', 'boolean'],
            'customtitles' => ['Custom member titles', '1', 'boolean'],
        ]],
        'limits' => ['title' => 'Rate limits', 'fields' => corebb_admin_rate_limit_setting_labels()],
        'maintenance' => ['title' => 'Maintenance', 'fields' => [
            'maintenancemode' => ['Maintenance mode', '0', 'boolean'],
            'maintenancesubject' => ['Notice subject', 'Boards Offline', 'text'],
            'maintenancemessage' => ['Notice message', 'The boards are temporarily unavailable.', 'textarea'],
        ]],
        'terms' => ['title' => 'Terms of service', 'fields' => [
            'terms_of_service' => ['Forum terms of service', '', 'textarea'],
        ]],
    ];
}

function corebb_admin_editable_settings(): array
{
    return array_merge(...array_column(corebb_admin_settings_sections(), 'fields'));
}

function corebb_admin_system_settings(): array
{
    $known = corebb_admin_editable_settings();

    $settings = [];
    $placeholders = implode(',', array_fill(0, count($known), '?'));
    $rows = db_all("SELECT id, name, setting FROM systemsettings WHERE name IN ($placeholders)
        OR (id = 1 AND (name = '' OR name IS NULL)) ORDER BY id", array_keys($known));
    foreach ($rows as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name === '' && (int)($row['id'] ?? 0) === 1) {
            $name = 'defaultstyle';
        }
        if (!isset($known[$name])) {
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

/** Validate the entire submission before writing. An empty error list means success. */
function corebb_admin_save_system_settings(array $posted, array $sectionFields, int &$changeCount): array
{
    $changeCount = 0;
    $input = $posted['settings'] ?? null;
    if (!is_array($input) || !$input) {
        return ['No settings were submitted.'];
    }

    $known = corebb_admin_editable_settings();
    $current = corebb_admin_system_settings();
    $changes = [];
    $errors = [];
    foreach ($input as $name => $value) {
        if (!isset($known[$name], $sectionFields[$name]) || !is_string($value)) {
            return ['The submitted fields do not match this settings form. Reload the page and try again.'];
        }
        if ($value === $current[$name]['value']) {
            continue;
        }
        $type = $known[$name][2] ?? '';
        $label = $known[$name][0];
        if ($type === 'boolean' || in_array($name, corebb_admin_rate_limit_boolean_settings(), true)) {
            if (!in_array($value, ['0', '1'], true)) {
                $errors[] = $label . ': choose Enabled or Disabled.';
                continue;
            }
        } elseif (in_array($name, corebb_admin_rate_limit_numeric_settings(), true)) {
            $max = str_ends_with($name, '_max') ? 100000 : 604800;
            if (!ctype_digit(trim($value)) || (int)$value < 1 || (int)$value > $max) {
                $errors[] = $label . ': enter a whole number from 1 to ' . $max . '.';
                continue;
            }
        } elseif ($type === 'style' && !isset(corebb_public_style_builtin_options()[corebb_public_style_canonical_file($value)])) {
            $errors[] = 'Choose an available default theme.';
            continue;
        }
        $changes[$name] = corebb_admin_normalize_system_setting($name, $value);
    }
    if ($errors || !$changes) {
        return $errors;
    }
    if (!db_begin()) {
        return ['Could not start the settings save. Please try again.'];
    }
    if (isset($changes['defaultstyle']) && db_exists("SELECT id FROM systemsettings WHERE id = 1 AND name NOT IN ('', 'defaultstyle')")) {
        db_rollback();
        return ['Could not save the default theme because its reserved settings row is in use.'];
    }
    foreach ($changes as $name => $value) {
        if (!corebb_admin_setting_upsert($name, $value, $name === 'defaultstyle' ? 1 : null)) {
            db_rollback();
            return ['Could not save the settings. Please reload and try again.'];
        }
    }
    if (!db_commit()) {
        db_rollback();
        return ['Could not finish saving settings. Please reload and try again.'];
    }
    $changeCount = count($changes);
    return [];
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
