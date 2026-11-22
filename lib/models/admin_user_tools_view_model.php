<?php
require_once __DIR__ . '/../helpers/corebb_date_helpers.php';
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
require_once __DIR__ . '/../helpers/auth_password_helpers.php';
/**
 * Admin user/message tools.
 *
 * Migrates these old admin.php actions into view-model backed pages while
 * keeping CoreBB' original look and permission model.
 */
require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';
require_once __DIR__ . '/../helpers/notification_helpers.php';
require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/../helpers/user_display_helpers.php';

/**
 * Usage: Cache the available columns for a table before building adaptive SELECTs.
 * Referenced by: admin user/message tools that support older CoreBB schemas.
 *
 * @param string $table Database table name.
 * @return array<string, bool> Existing column names keyed for quick lookup.
 */
function corebb_admin_table_columns(string $table): array
{
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    foreach (db_all('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '' && preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            $columns[$field] = true;
        }
    }
    $cache[$table] = $columns;
    return $columns;
}

/**
 * Usage: Build a SELECT list from columns that actually exist in the current schema.
 * Referenced by: corebb_admin_user_select_list() and admin user lookups.
 *
 * @param string $table Database table name.
 * @param array<int, string> $wanted Candidate columns to select.
 * @param string $alias SQL table alias.
 * @return string SQL SELECT fragment.
 */
function corebb_admin_select_columns(string $table, array $wanted, string $alias = ''): string
{
    $existing = corebb_admin_table_columns($table);
    $parts = [];
    foreach ($wanted as $column) {
        $column = (string)$column;
        if (!isset($existing[$column]) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            continue;
        }
        $prefix = $alias !== '' ? ('`' . str_replace('`', '``', $alias) . '`.') : '';
        $parts[] = $prefix . '`' . str_replace('`', '``', $column) . '` AS `' . str_replace('`', '``', $column) . '`';
    }
    return $parts ? implode(', ', $parts) : '1 AS `_empty`';
}

/**
 * Usage: Return the user columns needed by admin people tools.
 * Referenced by: corebb_admin_find_user() and related target summaries.
 *
 * @param string $alias SQL table alias.
 * @return string SQL SELECT fragment for users.
 */
function corebb_admin_user_select_list(string $alias = ''): string
{
    return corebb_admin_select_columns('users', [
        'id', 'username', 'displayname', 'password', 'accesslevel', 'posts',
        'regdate', 'lastip', 'lastlogindate', 'lastpstdate', 'lastpost',
        'status', 'approved', 'iconid', 'profpic', 'title', 'profiletitle',
        'signature', 'sig1', 'sig2', 'sig3', 'sig4', 'sig5',
        'style', 'appearance_preset_id', 'vip_bg_color', 'vip_text_color', 'vip_strike', 'vip_bold',
        'vip_italic', 'vip_border', 'vip_border_color',
        'legacy_remote_user_id', 'legacy_user_id', 'email', 'publicemail',
        'privateemail', 'useragent', 'ban_reason', 'banned_at', 'banned_by',
        'unbanned_at', 'unbanned_by', 'disabledreason'
    ], $alias);
}

/**
 * Usage: List user access levels shown in admin rights forms.
 * Referenced by: rights, add-user, and user summary models.
 *
 * @return array<int, string> Access level labels keyed by level.
 */
function corebb_admin_level_options(): array
{
    return [
        5 => corebb_user_level_label(5),
        4 => corebb_user_level_label(4),
        3 => corebb_user_level_label(3),
        2 => corebb_user_level_label(2),
        1 => corebb_user_level_label(1),
    ];
}

/**
 * Usage: Explain what each access level means on rights-management forms.
 * Referenced by: corebb_admin_level_details().
 *
 * @param int $level Access level value.
 * @return string Human-readable access description.
 */
function corebb_admin_level_description(int $level): string
{
    switch ($level) {
        case 5:
            return 'Full system administration, including settings, authentication, user rights, logs, and every lower-level tool.';
        case 4:
            return 'Board and user management access, including profile administration, titles, and all moderation tools.';
        case 3:
            return 'Moderation access for bans, reports, user lookups, post requests, deleted posts, and action logs.';
        case 2:
            return 'Limited control-panel access for VIP self-service tools such as appearance settings.';
        case 1:
            return 'Standard forum account. Admin tools are unavailable unless specific extra grants are assigned.';
        default:
            return 'Custom access level. Review inherited and extra tool access before saving.';
    }
}

/**
 * Usage: Attach descriptions and inherited-tool counts to access levels.
 * Referenced by: edit-rights and add-user style admin forms.
 *
 * @param array<int, string> $levels Access level labels keyed by level.
 * @return array<int, array{value: int, label: string, description: string, inherited_tool_count: int}>
 */
function corebb_admin_level_details(array $levels): array
{
    $catalog = corebb_admin_tool_catalog();
    $details = [];
    foreach ($levels as $level => $label) {
        $level = (int)$level;
        $inheritedToolCount = 0;
        foreach ($catalog as $items) {
            foreach ($items as $item) {
                if ($level >= (int)($item['min_level'] ?? 0)) {
                    $inheritedToolCount++;
                }
            }
        }
        $details[$level] = [
            'value' => $level,
            'label' => (string)$label,
            'description' => corebb_admin_level_description($level),
            'inherited_tool_count' => $inheritedToolCount,
        ];
    }
    return $details;
}

/**
 * Usage: Define every admin/sidebar tool and the minimum level that receives it.
 * Referenced by: route permission checks, sidebar rendering, and grant forms.
 *
 * @return array<string, array<string, array{label: string, min_level: int}>> Tool catalog grouped by section.
 */
function corebb_admin_tool_catalog(): array
{
    return [
        'Controls' => [
            'admin_home' => ['label' => 'Home', 'min_level' => 2],
            'version_history' => ['label' => 'Version History', 'min_level' => 2],
        ],
        'Administrator' => [
            'edit_settings' => ['label' => 'Edit System Settings', 'min_level' => 5],
            'auth_settings' => ['label' => 'Authentication Settings', 'min_level' => 5],
            'mail_services' => ['label' => 'Mail Services', 'min_level' => 5],
            'database_tools' => ['label' => 'Database Tools', 'min_level' => 5],
            'updates' => ['label' => 'Updates', 'min_level' => 5],
            'api_explorer' => ['label' => 'API Explorer', 'min_level' => 5],
            'forum_sim' => ['label' => 'Forum Sim-Test', 'min_level' => 5],
            'edit_tos' => ['label' => 'System Settings · Terms of Service only', 'min_level' => 5],
            'edit_rights' => ['label' => 'Edit User Rights', 'min_level' => 5],
            'change_user_password' => ['label' => "Change User's Password", 'min_level' => 5],
            'add_user' => ['label' => 'Add User', 'min_level' => 5],
            'global_message' => ['label' => 'Add Global Message', 'min_level' => 5],
            'edit_global_message' => ['label' => 'Edit Global Message', 'min_level' => 5],
            'remove_global_message' => ['label' => 'Remove Global Message', 'min_level' => 5],
            'pm_history' => ['label' => 'Private Message History', 'min_level' => 5],
        ],
        'Manager' => [
            'manage_boards' => ['label' => 'Manage Boards', 'min_level' => 4],
            'forum_statistics' => ['label' => 'Forum Statistics', 'min_level' => 4],
            'manage_icons' => ['label' => 'Manage Icons', 'min_level' => 3],
            'assign_title' => ['label' => 'Assign Title', 'min_level' => 4],
            'edit_profile' => ['label' => "Edit User's Profile", 'min_level' => 4],
            'user_appearance_admin' => ['label' => 'Edit User Appearance', 'min_level' => 4],
        ],
        'Moderator' => [
            'moderation_ban' => ['label' => 'Ban User', 'min_level' => 3],
            'moderation_unban' => ['label' => 'Unban User', 'min_level' => 3],
            'mod_requests' => ['label' => 'Mod Post Requests', 'min_level' => 3],
            'contact_mods' => ['label' => 'Contact Mods Inbox', 'min_level' => 3],
            'pm_reports' => ['label' => 'Private Message Reports', 'min_level' => 3],
            'latest_users' => ['label' => 'Latest Users', 'min_level' => 3],
            'view_message' => ['label' => 'View a Message', 'min_level' => 3],
            'deleted_posts' => ['label' => 'Deleted Posts', 'min_level' => 3],
            'spam_ratings' => ['label' => 'Spam Ratings', 'min_level' => 3],
            'moderation_requests' => ['label' => 'Unban Requests', 'min_level' => 3],
            'user_pages' => ['label' => 'User Pages', 'min_level' => 3],
            'admin_notes' => ['label' => "User's Admin Notes", 'min_level' => 3],
            'user_ip_check' => ['label' => 'User IP Check', 'min_level' => 3],
            'host_lookup' => ['label' => 'Host Address Lookup', 'min_level' => 3],
            'action_log' => ['label' => 'Admin Action Log', 'min_level' => 3],
        ],
        'VIP' => [
            'vip_appearance_self' => ['label' => 'Edit Own Appearance', 'min_level' => 2],
        ],
    ];
}

/**
 * Usage: Flatten the grouped tool catalog into a lookup by tool key.
 * Referenced by: permission checks and navigation builders.
 *
 * @return array<string, array<string, mixed>> Tool metadata keyed by tool key.
 */
function corebb_admin_tool_map(): array
{
    $map = [];
    foreach (corebb_admin_tool_catalog() as $group => $items) {
        foreach ($items as $key => $item) {
            $item['key'] = $key;
            $item['group'] = $group;
            $map[$key] = $item;
        }
    }
    return $map;
}

/** The unified page still uses the three existing, independently assignable grants. */
function corebb_admin_global_message_tools(): array
{
    return ['add' => 'global_message', 'edit' => 'edit_global_message', 'remove' => 'remove_global_message'];
}

/**
 * Usage: Convert an admin act/mode pair into its permission-tool key.
 * Referenced by: admin.php before dispatching a route model.
 *
 * @param string $act Requested admin action.
 * @param array<string, mixed> $request Query parameters for actions with modes.
 * @return string Permission-tool key.
 */
function corebb_admin_tool_key_for_request(string $act, array $request = [], array $viewer = []): string
{
    if ($act === 'global_messages') {
        foreach (corebb_admin_global_message_tools() as $tool) {
            if (corebb_admin_can_access_tool($viewer, $tool)) { return $tool; }
        }
        return 'global_message';
    }
    if ($act === 'edit_settings' && ($request['section'] ?? '') === 'terms'
        && !corebb_admin_can_access_tool($viewer, 'edit_settings')) {
        return 'edit_tos';
    }
    if ($act === '') {
        return 'admin_home';
    }
    if ($act === 'administrator_tools' || $act === 'database_tools') {
        return ($request['section'] ?? '') === 'schema' ? 'db_schema_deploy' : 'database_tools';
    }
    if ($act === 'moderate_message') {
        return 'mod_requests';
    }
    if ($act === 'spam_ratings') {
        return 'spam_ratings';
    }
    if ($act === 'user_appearance') {
        return 'user_appearance_admin';
    }
    if (in_array($act, ['manageboards', 'movebrd', 'movecat', 'add_category', 'delete_category', 'manageboards_cat', 'addboard', 'modifyboard'], true)) {
        return 'manage_boards';
    }
    if ($act === 'moderation') {
        $mode = (string)($request['mode'] ?? 'ban');
        if ($mode === 'unban') {
            return 'moderation_unban';
        }
        if ($mode === 'requests') {
            return 'moderation_requests';
        }
        return 'moderation_ban';
    }
    return $act;
}

/**
 * Usage: Ensure per-user admin tool grants can be stored.
 * Referenced by: grant lookup and save helpers.
 *
 * @return void
 */
function corebb_admin_tool_permissions_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db_run("CREATE TABLE IF NOT EXISTS `admin_tool_permissions` (
        `userid` INT NOT NULL DEFAULT 0,
        `tool_key` VARCHAR(64) NOT NULL DEFAULT '',
        `granted_by` INT NOT NULL DEFAULT 0,
        `granted_at` VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`userid`, `tool_key`),
        KEY `idx_admin_tool_permissions_tool` (`tool_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Usage: Normalize posted tool keys to known catalog entries.
 * Referenced by: edit-rights model and grant persistence.
 *
 * @param mixed $keys Raw key list.
 * @return array<int, string> Unique known tool keys.
 */
function corebb_admin_normalize_tool_keys($keys): array
{
    $known = corebb_admin_tool_map();
    $keys = is_array($keys) ? $keys : [];
    $clean = [];
    foreach ($keys as $key) {
        $key = (string)$key;
        if (isset($known[$key])) {
            $clean[$key] = true;
        }
    }
    return array_keys($clean);
}

/**
 * Usage: Fetch explicit tool grants assigned to a user.
 * Referenced by: access checks, sidebar rendering, and edit-rights forms.
 *
 * @param int $userId User id.
 * @return array<int, string> Explicitly granted tool keys.
 */
function corebb_admin_user_granted_tool_keys(int $userId, bool $refresh = false): array
{
    static $cache = [];
    if ($userId <= 0) {
        return [];
    }
    if (!$refresh && isset($cache[$userId])) {
        return $cache[$userId];
    }

    corebb_admin_tool_permissions_ensure_schema();
    $known = corebb_admin_tool_map();
    $keys = [];
    foreach (db_all('SELECT tool_key FROM admin_tool_permissions WHERE userid = ? ORDER BY tool_key ASC', [$userId]) as $row) {
        $key = (string)($row['tool_key'] ?? '');
        if (isset($known[$key])) {
            $keys[$key] = true;
        }
    }
    $cache[$userId] = array_keys($keys);
    return $cache[$userId];
}

/**
 * Usage: Decide whether the current viewer may open a specific admin tool.
 * Referenced by: admin.php route gate and admin sidebar rendering.
 *
 * @param array<string, mixed> $viewer Current admin/special-access user row.
 * @param string $toolKey Permission-tool key.
 * @return bool True when level or explicit grant allows access.
 */
function corebb_admin_can_access_tool(array $viewer, string $toolKey): bool
{
    if ($toolKey === 'db_schema_deploy') {
        return corebb_admin_viewer_level($viewer) >= 5;
    }
    $toolMap = corebb_admin_tool_map();
    if (!isset($toolMap[$toolKey])) {
        return false;
    }

    $viewerLevel = corebb_admin_viewer_level($viewer);
    if ($viewerLevel >= (int)$toolMap[$toolKey]['min_level']) {
        return true;
    }

    $viewerId = corebb_admin_viewer_id($viewer);
    $grants = corebb_admin_user_granted_tool_keys($viewerId);
    if ($toolKey === 'admin_home') {
        return !empty($grants);
    }
    return in_array($toolKey, $grants, true);
}

/**
 * Usage: Detect whether access came from an explicit grant instead of level inheritance.
 * Referenced by: admin layout notices and sidebar presentation.
 *
 * @param array<string, mixed> $viewer Current admin/special-access user row.
 * @param string $toolKey Permission-tool key.
 * @return bool True when the viewer is using special access for this tool.
 */
function corebb_admin_tool_is_special_access(array $viewer, string $toolKey): bool
{
    $toolMap = corebb_admin_tool_map();
    if (!isset($toolMap[$toolKey])) {
        return false;
    }
    if (corebb_admin_viewer_level($viewer) >= (int)$toolMap[$toolKey]['min_level']) {
        return false;
    }
    return in_array($toolKey, corebb_admin_user_granted_tool_keys(corebb_admin_viewer_id($viewer)), true);
}

/**
 * Usage: Decide whether a viewer may enter the admin shell at all.
 * Referenced by: admin.php before route permission checks.
 *
 * @param array<string, mixed> $viewer Current user row.
 * @return bool True when the user has admin level or at least one tool grant.
 */
function corebb_admin_can_access_admin(array $viewer): bool
{
    return corebb_admin_viewer_level($viewer) >= 2 || !empty(corebb_admin_user_granted_tool_keys(corebb_admin_viewer_id($viewer)));
}

/**
 * Usage: Decide whether the viewer may grant special admin tool access to users.
 * Referenced by: edit-rights model and template.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @return bool True for full administrators.
 */
function corebb_admin_can_manage_tool_grants(array $viewer): bool
{
    return corebb_admin_viewer_level($viewer) >= 5;
}

/**
 * Usage: Build grant checkbox groups for one target user.
 * Referenced by: edit-rights view and confirmation model.
 *
 * @param array<string, mixed> $user Target user row.
 * @param array<int, string>|null $selectedKeys Optional posted grant keys to preview.
 * @return array<string, array<int, array<string, mixed>>> Tool groups for Twig.
 */
function corebb_admin_tool_groups_for_user(array $user, ?array $selectedKeys = null): array
{
    $level = (int)($user['accesslevel'] ?? 0);
    $explicit = $selectedKeys ?? corebb_admin_user_granted_tool_keys((int)($user['id'] ?? 0));
    $explicit = array_fill_keys(corebb_admin_normalize_tool_keys($explicit), true);
    $groups = [];
    foreach (corebb_admin_tool_catalog() as $group => $items) {
        foreach ($items as $key => $item) {
            $minLevel = (int)$item['min_level'];
            $groups[$group][] = [
                'key' => $key,
                'label' => (string)$item['label'],
                'min_level' => $minLevel,
                'min_level_label' => corebb_user_level_label($minLevel),
                'selected' => isset($explicit[$key]),
                'inherited' => $level >= $minLevel,
            ];
        }
    }
    return $groups;
}

/**
 * Usage: Summarize tool access groups for the edit-rights page.
 * Referenced by: corebb_admin_edit_rights_model().
 *
 * @param array<string, array<int, array<string, mixed>>> $groups Tool groups from corebb_admin_tool_groups_for_user().
 * @return array{total: int, inherited: int, explicit: int, groups: array<string, array{total: int, inherited: int, explicit: int}>}
 */
function corebb_admin_tool_group_summary(array $groups): array
{
    $summary = [
        'total' => 0,
        'inherited' => 0,
        'explicit' => 0,
        'groups' => [],
    ];

    foreach ($groups as $group => $tools) {
        $groupSummary = ['total' => 0, 'inherited' => 0, 'explicit' => 0];
        foreach ($tools as $tool) {
            $groupSummary['total']++;
            $summary['total']++;
            if (!empty($tool['inherited'])) {
                $groupSummary['inherited']++;
                $summary['inherited']++;
            }
            if (!empty($tool['selected']) && empty($tool['inherited'])) {
                $groupSummary['explicit']++;
                $summary['explicit']++;
            }
        }
        $summary['groups'][(string)$group] = $groupSummary;
    }

    return $summary;
}

/**
 * Usage: Replace all explicit admin tool grants for a user.
 * Referenced by: corebb_admin_edit_rights_model().
 *
 * @param int $userId User id.
 * @param array<int, string> $toolKeys New grant keys.
 * @param array<string, mixed> $viewer Admin performing the change.
 * @return bool True when all grant writes succeed.
 */
function corebb_admin_replace_tool_grants(int $userId, array $toolKeys, array $viewer): bool
{
    if ($userId <= 0) {
        return false;
    }
    corebb_admin_tool_permissions_ensure_schema();
    $toolKeys = corebb_admin_normalize_tool_keys($toolKeys);
    if (!db_run('DELETE FROM admin_tool_permissions WHERE userid = ?', [$userId])) {
        return false;
    }
    $viewerId = corebb_admin_viewer_id($viewer);
    $now = date('Y-m-d H:i:s');
    foreach ($toolKeys as $toolKey) {
        if (!db_run('INSERT INTO admin_tool_permissions (userid, tool_key, granted_by, granted_at) VALUES (?, ?, ?, ?)', [$userId, $toolKey, $viewerId, $now])) {
            return false;
        }
    }
    return true;
}


/**
 * Usage: Read the normalized id from a viewer row.
 * Referenced by: admin permission and mutation helpers.
 *
 * @param array<string, mixed> $viewer Current viewer row.
 * @return int Viewer user id.
 */
function corebb_admin_viewer_id(array $viewer): int
{
    return (int)($viewer['id'] ?? $viewer['userid'] ?? $viewer['user_id'] ?? 0);
}

/**
 * Usage: Read the normalized access level from a viewer row.
 * Referenced by: admin permission and mutation helpers.
 *
 * @param array<string, mixed> $viewer Current viewer row.
 * @return int Viewer access level.
 */
function corebb_admin_viewer_level(array $viewer): int
{
    return (int)($viewer['accesslevel'] ?? 0);
}

/**
 * Usage: Return only the access levels the current viewer may assign.
 * Referenced by: edit-rights and add-user forms.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @return array<int, string> Assignable levels keyed by level.
 */
function corebb_admin_assignable_level_options(array $viewer): array
{
    $viewerLevel = corebb_admin_viewer_level($viewer);
    $levels = corebb_admin_level_options();
    return array_filter(
        $levels,
        static function ($label, int $level) use ($viewerLevel): bool {
            return $level > 0 && $level < $viewerLevel;
        },
        ARRAY_FILTER_USE_BOTH
    );
}

/**
 * Usage: Check whether account-rights changes are allowed for a target user.
 * Referenced by: edit-rights and password/admin user tools.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed>|null $target Target user row.
 * @return string Empty when allowed, otherwise a user-facing error.
 */
function corebb_admin_target_rights_error(array $viewer, ?array $target): string
{
    if (!$target) {
        return 'Unknown user.';
    }

    $viewerId = corebb_admin_viewer_id($viewer);
    $viewerLevel = corebb_admin_viewer_level($viewer);
    $targetId = (int)($target['id'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);

    if ($viewerId > 0 && $targetId === $viewerId) {
        return 'You cannot modify your own account rights.';
    }
    if ($targetLevel >= $viewerLevel) {
        return 'You cannot modify a user with equal or higher rights.';
    }
    return '';
}

/**
 * Usage: Check whether an admin may edit non-rights user-owned content.
 * Referenced by: title, profile cleanup, icon cleanup, and appearance tools.
 *
 * Unlike account-rights changes, content edits may target the viewer's own
 * account. Other users with equal or higher rights remain protected.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed>|null $target Target user row.
 * @return string Empty when allowed, otherwise a user-facing error.
 */
function corebb_admin_target_content_error(array $viewer, ?array $target): string
{
    if (!$target) {
        return 'Unknown user.';
    }

    $viewerId = corebb_admin_viewer_id($viewer);
    $viewerLevel = corebb_admin_viewer_level($viewer);
    $targetId = (int)($target['id'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);

    if ($viewerId > 0 && $targetId === $viewerId) {
        return '';
    }
    if ($targetLevel >= $viewerLevel) {
        return 'You cannot modify a user with equal or higher rights.';
    }
    return '';
}

/**
 * Usage: Validate a requested new access level against the current viewer.
 * Referenced by: edit-rights and add-user models.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param int $newLevel Requested access level.
 * @return string Empty when allowed, otherwise a user-facing error.
 */
function corebb_admin_new_level_error(array $viewer, int $newLevel): string
{
    $viewerLevel = corebb_admin_viewer_level($viewer);
    if ($newLevel <= 0) {
        return 'Invalid user level.';
    }
    if ($newLevel >= $viewerLevel) {
        return 'You cannot assign equal or higher rights than your own.';
    }
    return '';
}

/**
 * Usage: Locate a user by numeric id or exact username.
 * Referenced by: admin people tools, message tools, and moderation helpers.
 *
 * @param string $identifier User, table, or column identifier.
 * @return array<string, mixed>|null User row when found.
 */
function corebb_admin_find_user(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    if (ctype_digit($identifier)) {
        $row = db_one('SELECT ' . corebb_admin_user_select_list() . ' FROM users WHERE id = ? LIMIT 1', [(int)$identifier]);
    } else {
        $row = db_one('SELECT ' . corebb_admin_user_select_list() . ' FROM users WHERE username = ? LIMIT 1', [$identifier]);
    }
    return $row ?: null;
}

/**
 * Usage: Create a compact display model for a user found by admin tools.
 * Referenced by: rights, password, add-user, and message models.
 *
 * @param array<string, mixed> $user User row.
 * @return array<string, mixed> Twig-ready user summary.
 */
function corebb_admin_user_summary(array $user): array
{
    $level = (int)($user['accesslevel'] ?? 0);
    return [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'profile_url' => '/profile/' . (int)($user['id'] ?? 0) . '/',
        'accesslevel' => $level,
        'level_name' => corebb_user_level_label($level),
        'posts' => (int)($user['posts'] ?? 0),
        'registered' => (string)($user['regdate'] ?? ($user['date_account_added'] ?? '')),
    ];
}

/**
 * Usage: Start a standard admin page model with viewer, title, and message fields.
 * Referenced by: most admin view-model builders.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param string $title Title text.
 * @param array<string, mixed> $request Query parameters.
 * @return array<string, mixed> Base page model.
 */
function corebb_admin_require_model_base(array $viewer, string $title, array $request = []): array
{
    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'title' => $title,
        'message' => (string)($request['msg'] ?? ''),
        'messages' => [],
        'errors' => [],
    ];
}

/**
 * Usage: Build and process the Edit User Rights admin page.
 * Referenced by: admin route act=edit_rights.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query parameters.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed> Page model.
 */
function corebb_admin_edit_rights_model(array $viewer, array $request, array $post): array
{
    $levels = corebb_admin_assignable_level_options($viewer);
    $canGrant = corebb_admin_can_manage_tool_grants($viewer);
    $model = [
        'mode'=>'search', 'messages'=>[], 'errors'=>[], 'user'=>null, 'levels'=>$levels,
        'role_details'=>corebb_admin_level_details($levels), 'can_manage_tool_grants'=>$canGrant,
        'can_view_user_pages'=>corebb_admin_can_access_tool($viewer,'user_pages'), 'search_value'=>'',
    ];
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $method = $isPost ? ($request['method'] ?? 'view') : 'view';
    $identifier = $isPost ? ($post['userid'] ?? $post['username'] ?? '') : ($request['user'] ?? '');
    $identifier = is_scalar($identifier) ? trim((string)$identifier) : '';
    $model['search_value'] = $identifier;
    if (!$levels || !corebb_admin_can_access_tool($viewer, 'edit_rights')) {
        $model['errors'][] = 'You cannot assign user rights.';
        return $model;
    }
    if ($identifier === '' && !$isPost) { return $model; }
    $target = corebb_admin_find_user($identifier);
    $error = corebb_admin_target_rights_error($viewer, $target);
    if ($error !== '') {
        $model['errors'][] = $error;
        return $model;
    }
    $id = (int)$target['id'];
    $key = 'corebb_rights_review:' . corebb_admin_viewer_id($viewer) . ':' . $id;
    $stored = corebb_admin_user_granted_tool_keys($id, true);
    if (db_error() !== '') {
        $model['errors'][] = 'Could not load the current tool grants.';
        return $model;
    }
    $level = (int)$target['accesslevel'];
    $tools = $stored;
    $model['revision'] = corebb_admin_rights_revision($target, $stored);
    if ($isPost && !corebb_security_csrf_valid($post)) {
        $model['errors'][] = 'Your form expired. Review the account again.';
    } elseif ($method === 'confirm') {
        $proposed = filter_var($post['userlevel'] ?? null, FILTER_VALIDATE_INT);
        $submitted = $post['admin_tools'] ?? [];
        $known = corebb_admin_tool_map();
        if (isset($post['revision']) && $post['revision'] !== $model['revision']) {
            $model['errors'][] = 'The rights on this account changed. Review the current settings again.';
        } elseif (!isset($levels[$proposed])) {
            $model['errors'][] = 'Choose a role below your own.';
        } elseif (!is_array($submitted) || array_filter($submitted, static fn($tool) => !is_string($tool) || !isset($known[$tool]))) {
            $model['errors'][] = 'The form contains an unknown tool. Review the grants again.';
        } elseif (!$canGrant && $submitted) {
            $model['errors'][] = 'Only full administrators can change direct tool grants.';
        } else {
            $level = $proposed;
            if ($canGrant) {
                $tools = array_values(array_filter(array_unique($submitted), static fn($tool) => $known[$tool]['min_level'] > $level));
            }
            $model['added'] = array_values(array_map(static fn($k) => $known[$k]['label'], array_diff($tools, $stored)));
            $model['removed'] = array_values(array_map(static fn($k) => $known[$k]['label'], array_diff($stored, $tools)));
            if ($level === (int)$target['accesslevel'] && !$model['added'] && !$model['removed']) {
                $model['messages'][] = 'No changes to save.';
            } else {
                $model['mode'] = 'confirm';
                $model['review_token'] = bin2hex(random_bytes(24));
                $_SESSION[$key] = ['token'=>$model['review_token'], 'revision'=>$model['revision'],
                    'level'=>$level, 'tools'=>$tools, 'change_grants'=>(bool)($model['added'] || $model['removed'])];
            }
        }
    } elseif ($method === 'save' || $method === 'revise') {
        $review = $_SESSION[$key] ?? null;
        if (!$review || !is_string($post['review_token'] ?? null) || !hash_equals($review['token'], $post['review_token'])) {
            $model['errors'][] = 'Review the changes again before saving.';
        } else {
            unset($_SESSION[$key]);
            if ($review['revision'] !== $model['revision'] || !isset($levels[$review['level']]) || ($review['change_grants'] && !$canGrant)) {
                $model['errors'][] = 'The rights on this account or your permissions changed. Review the current settings again.';
            } elseif ($method === 'revise') {
                $level = $review['level'];
                $tools = $review['tools'];
            } else {
                $error = corebb_admin_save_reviewed_rights($viewer, $id, $review);
                if ($error === '') {
                    $model['saved'] = true;
                    $model['user'] = corebb_admin_user_summary($target);
                    $model['messages'][] = 'Rights saved for ' . $target['username'] . '.';
                    return $model;
                }
                $model['errors'][] = $error;
            }
        }
    }
    if ($model['mode'] !== 'confirm') { $model['mode'] = 'view'; }
    $model['user'] = corebb_admin_user_summary($target);
    $model['search_value'] = $model['user']['username'];
    $model['selected_level'] = $level;
    $target['accesslevel'] = $level;
    $model['tool_groups'] = corebb_admin_tool_groups_for_user($target, $tools);
    $model['tool_summary'] = corebb_admin_tool_group_summary($model['tool_groups']);
    return $model;
}

function corebb_admin_rights_revision(array $target, array $tools): string
{
    sort($tools, SORT_STRING);
    return hash('sha256', json_encode([(int)$target['id'], (int)$target['accesslevel'], $tools], JSON_THROW_ON_ERROR));
}

function corebb_admin_save_reviewed_rights(array $viewer, int $userId, array $review): string
{
    $failure = 'Could not save the rights change. No changes were committed.';
    // The grants table is provisioned outside the transaction (DDL may commit implicitly).
    corebb_admin_tool_permissions_ensure_schema();
    if (!db_begin()) { return $failure; }
    try {
        $viewerId = corebb_admin_viewer_id($viewer);
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = db_all('SELECT id, username, accesslevel FROM users WHERE id IN (?, ?) ORDER BY id' . $lock, [$viewerId,$userId]);
        $users = array_column($rows, null, 'id');
        $actor = $users[$viewerId] ?? [];
        $target = $users[$userId] ?? null;
        corebb_admin_user_granted_tool_keys($viewerId, true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor, 'edit_rights')
            || corebb_admin_target_rights_error($actor,$target) !== '' || corebb_admin_new_level_error($actor,$review['level']) !== ''
            || ($review['change_grants'] && !corebb_admin_can_manage_tool_grants($actor))) {
            return 'You can no longer apply this rights change.';
        }
        $current = corebb_admin_user_granted_tool_keys($userId, true);
        if (db_error() !== '') { return $failure; }
        if (corebb_admin_rights_revision($target,$current) !== $review['revision']) {
            return 'This account changed after review. Reopen it before saving.';
        }
        if (!db_run('UPDATE users SET accesslevel = ? WHERE id = ?', [$review['level'],$userId])
            || ($review['change_grants'] && !corebb_admin_replace_tool_grants($userId,$review['tools'],$actor))
            || !db_commit()) {
            return $failure;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    corebb_admin_user_granted_tool_keys($userId, true);
    $detail = 'Role ' . $target['accesslevel'] . ' → ' . $review['level'];
    if ($review['change_grants']) {
        $detail .= '. Added: ' . (implode(', ', array_diff($review['tools'],$current)) ?: 'none')
            . '. Removed: ' . (implode(', ', array_diff($current,$review['tools'])) ?: 'none');
    }
    corebb_adminlog_viewer($actor, 'Updated rights for ' . $target['username'] . ' (#' . $userId . ')', 'edit_rights', $detail);
    return '';
}

/**
 * Usage: Build and process the Change User Password admin page.
 * Referenced by: admin route act=change_user_password.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query parameters.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed> Page model.
 */
function corebb_admin_change_password_model(array $viewer, array $request, array $post): array
{
    $model = ['errors' => [], 'user' => null, 'search_value' => '', 'saved' => false,
        'can_view_member' => corebb_admin_can_access_tool($viewer, 'user_pages')];
    if (!corebb_admin_can_access_tool($viewer, 'change_user_password')) {
        $model['errors'][] = 'You do not have access to this tool.';
        return $model;
    }

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $method = $request['method'] ?? 'view';
    $saving = $isPost && $method === 'save';
    if ($isPost && !corebb_security_csrf_valid($post)) {
        $model['errors'][] = 'Your form expired. Find the member again.';
        return $model;
    }
    if ($saving) {
        $id = filter_var($post['userid'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id || (isset($request['user']) && $request['user'] !== (string)$id)) {
            $model['errors'][] = 'Find the member again before changing their password.';
            return $model;
        }
        $identifier = (string)$id;
    } else {
        $input = $isPost ? ($post['user'] ?? '') : ($request['user'] ?? '');
        $identifier = is_string($input) ? trim($input) : '';
        if ($identifier === '') { return $model; }
    }

    $model['search_value'] = $identifier;
    $target = corebb_admin_find_user($identifier);
    $error = corebb_admin_target_rights_error($viewer, $target);
    if ($error !== '') {
        $model['errors'][] = !$target ? 'No member found for that username or ID.'
            : 'Choose another member with a lower role to change their password.';
        return $model;
    }
    $model['user'] = corebb_admin_user_summary($target);
    $model['search_value'] = $target['username'];
    if (!$saving) { return $model; }

    $password = $post['password1'] ?? '';
    if (!is_string($password) || $password === '' || $password !== ($post['password2'] ?? null)) {
        $model['errors'][] = 'Enter the same new password in both fields.';
        return $model;
    }
    $error = corebb_admin_save_member_password($viewer, (int)$target['id'], $password);
    if ($error !== '') {
        $model['errors'][] = $error;
    } else {
        $model['saved'] = true;
    }
    return $model;
}

function corebb_admin_save_member_password(array $viewer, int $userId, string $password): string
{
    try {
        $hash = corebb_auth_password_hash($password);
    } catch (ValueError | RuntimeException $error) {
        return 'Could not set this password. Enter a different password.';
    }
    // Both schema helpers can issue DDL. Finish setup before opening the transaction.
    corebb_auth_ensure_schema();
    corebb_admin_tool_permissions_ensure_schema();
    $failure = 'Could not save the password change. Please try again.';
    if (!db_begin()) { return $failure; }
    try {
        $actorId = corebb_admin_viewer_id($viewer);
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = db_all('SELECT id, username, accesslevel FROM users WHERE id IN (?, ?) ORDER BY id' . $lock, [$actorId, $userId]);
        $users = array_column($rows, null, 'id');
        $actor = $users[$actorId] ?? [];
        $target = $users[$userId] ?? null;
        corebb_admin_user_granted_tool_keys($actorId, true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor, 'change_user_password')
            || corebb_admin_target_rights_error($actor, $target) !== '') {
            return 'You can no longer change the password for this member.';
        }
        if (!db_run('UPDATE users SET password = ? WHERE id = ?', [$hash, $userId])
            || !corebb_auth_revoke_user_login_tokens($userId) || !db_commit()) {
            return $failure;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    corebb_adminlog_viewer($actor, 'Changed password for ' . $target['username'] . ' (#' . $userId . ')', 'change_user_password');
    return '';
}

/**
 * Usage: Build and process the Add User admin page.
 * Referenced by: admin route act=add_user.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query parameters.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed> Page model.
 */
function corebb_admin_add_user_model(array $viewer, array $request, array $post): array
{
    $levels = corebb_admin_assignable_level_options($viewer);
    $model = [
        'levels' => $levels,
        'role_details' => corebb_admin_level_details($levels),
        'form' => ['username' => '', 'userlevel' => 1],
        'errors' => [],
        'created_user' => null,
        'can_view_member' => corebb_admin_can_access_tool($viewer, 'user_pages'),
        'can_edit_rights' => corebb_admin_can_access_tool($viewer, 'edit_rights'),
    ];
    if (!corebb_admin_can_access_tool($viewer, 'add_user') || !$levels) {
        $model['levels'] = [];
        $model['errors'][] = 'You do not have an assignable role for a new account.';
        return $model;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return $model; }
    if (!corebb_security_csrf_valid($post)) {
        $model['errors'][] = 'Your form expired. Enter the account details again.';
        return $model;
    }

    $username = is_string($post['username'] ?? null) ? trim($post['username']) : '';
    $password = $post['password1'] ?? '';
    $confirmation = $post['password2'] ?? '';
    $level = filter_var($post['userlevel'] ?? 1, FILTER_VALIDATE_INT);
    $model['form'] = ['username' => $username, 'userlevel' => $level];
    if ($username === '' || !preg_match('/\A.{1,255}\z/us', $username)) {
        $model['errors'][] = 'Enter a username of up to 255 characters.';
    }
    if (!is_string($password) || $password === '' || $password !== $confirmation) {
        $model['errors'][] = 'Enter the same password in both password fields.';
    }
    if (!isset($levels[$level])) {
        $model['errors'][] = 'Choose a role below your own.';
    }
    if ($model['errors']) { return $model; }

    corebb_auth_ensure_schema();
    try {
        $hash = corebb_auth_password_hash($password);
    } catch (ValueError | RuntimeException $e) {
        $model['errors'][] = 'Could not set this password. Enter a different password.';
        return $model;
    }
    $newUser = ['username' => $username, 'password' => $hash, 'accesslevel' => $level];
    $columns = corebb_admin_table_columns('users');
    foreach (['regdate', 'profadded'] as $dateColumn) {
        if (isset($columns[$dateColumn])) { $newUser[$dateColumn] = date('M y'); }
    }
    $failure = 'Could not create the account. Please try again.';
    if (!db_begin()) {
        $model['errors'][] = $failure;
        return $model;
    }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $existing = db_one('SELECT id FROM users WHERE username = ? LIMIT 1' . $lock, [$username]);
        if ($existing) {
            $model['errors'][] = 'That username already exists.';
            return $model;
        }
        $names = '`' . implode('`, `', array_keys($newUser)) . '`';
        $placeholders = implode(', ', array_fill(0, count($newUser), '?'));
        if (db_error() !== '' || !db_run('INSERT INTO users (' . $names . ') VALUES (' . $placeholders . ')', array_values($newUser))) {
            $model['errors'][] = $failure;
            return $model;
        }
        $id = db_insert_id();
        if (!$id || !db_commit()) {
            $model['errors'][] = $failure;
            return $model;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    corebb_adminlog_viewer($viewer, 'Added new user: ' . $username . ' - Userlevel: ' . $level, 'add_user');
    $model['created_user'] = ['id' => $id, 'username' => $username, 'role' => $levels[$level], 'accesslevel' => $level];
    return $model;
}

/**
 * Usage: Trim message text to the byte limit used by the posts table.
 * Referenced by: corebb_admin_view_message_normalize().
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Trimmed text.
 */
function corebb_admin_view_message_limit_text(string $value, int $maxBytes): string
{
    if ($maxBytes <= 0) {
        return '';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
    return substr($value, 0, $maxBytes);
}

/**
 * Usage: Normalize admin-edited message subject/body before saving a post.
 * Referenced by: corebb_admin_view_message_model().
 *
 * @param string $subject Message subject.
 * @param string $body Message body.
 * @return array{0: string, 1: string} Clean subject and body.
 */
function corebb_admin_view_message_normalize(string $subject, string $body): array
{
    $subject = corebb_admin_view_message_limit_text(trim($subject), 100);
    $body = corebb_admin_view_message_limit_text(corebb_prepare_post_data($body), 65535);
    if ($subject === '' && trim($body) === '') {
        return ['-', '(no message)'];
    }
    if ($subject === '') {
        $subject = '-';
    }
    if (trim($body) === '') {
        $body = '(no message)';
    }
    return [$subject, $body];
}

/**
 * Usage: Check whether the viewer may edit/delete a message by its author level.
 * Referenced by: corebb_admin_view_message_model() and apply helper.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed>|null $author Message author row.
 * @return string Empty when allowed, otherwise a user-facing error.
 */
function corebb_admin_view_message_author_error(array $viewer, ?array $author): string
{
    if (!$author) {
        return '';
    }
    $viewerId = (int)($viewer['id'] ?? 0);
    $viewerLevel = (int)($viewer['accesslevel'] ?? 0);
    $authorId = (int)($author['id'] ?? 0);
    $authorLevel = (int)($author['accesslevel'] ?? 0);

    if ($viewerId > 0 && $authorId > 0 && $viewerId === $authorId) {
        return '';
    }
    if ($authorLevel >= $viewerLevel) {
        return 'You cannot edit or delete a message posted by a user with equal or higher rights.';
    }
    return '';
}

/**
 * Usage: Load one post and attach the state needed by the View a Message page.
 * Referenced by: corebb_admin_view_message_model().
 *
 * @param array<string, mixed> $model Existing page model.
 * @param array<string, mixed> $viewer Current admin user row.
 * @param int $messageId Post/message id.
 * @return array<string, mixed> Updated page model.
 */
function corebb_admin_view_message_apply_post(array $model, array $viewer, int $messageId): array
{
    $row = corebb_mod_get_post($messageId, true);
    $board = $row ? corebb_private_board_row((int)$row['boardid']) : null;
    if (!$board || !corebb_private_user_can_view_board_row($board, (int)$viewer['id'], (int)$viewer['accesslevel'])) {
        $model['errors'][] = 'No visible message found for that ID.';
        return $model;
    }
    $author = $row['posterid'] ? corebb_admin_find_user((string)$row['posterid']) : null;
    $topic = db_one('SELECT id,title,is_deleted FROM topics WHERE id=? AND boardid=?', [$row['threadid'],$row['boardid']]);
    $model['post'] = array_intersect_key($row, array_flip(['id','posterid','author','boardid','threadid','title','body']));
    $model['poster'] = $author ? corebb_admin_user_summary($author) : null;
    $model['board_name'] = $board['name'];
    $model['board_url'] = corebb_board_url((int)$board['id'], 1, $board['name']);
    $model['topic_title'] = $topic['title'] ?? '';
    $model['topic_url'] = $topic && !$topic['is_deleted'] ? corebb_thread_url((int)$topic['id'], (int)$board['id'], 1, $board['name']) : '';
    $model['is_deleted'] = (bool)$row['is_deleted'];
    $model['post_date'] = convert_to_vndate((string)$row['posttime']);
    $model['post_edit'] = [
        'was_edited'=>(bool)$row['wasedited'], 'date'=>convert_to_vndate((string)$row['editdate']),
        'count'=>max(1,(int)$row['editcount']), 'edited_by_id'=>(int)$row['editedby'],
    ];
    $model['revision'] = corebb_admin_message_revision($row);
    $model['modify_block_reason'] = corebb_admin_view_message_author_error($viewer,$author);
    if (!corebb_secure_archive_user_can_write_board_row($board, (int)$viewer['accesslevel'])) {
        $model['modify_block_reason'] = corebb_secure_archive_denied_message();
    }
    return $model;
}

function corebb_admin_message_revision(array $row): string
{
    return hash('sha256', serialize(array_map(static fn($key) => (string)($row[$key] ?? ''),
        ['id','boardid','threadid','posterid','title','body','editcount','is_deleted'])));
}

function corebb_admin_view_message_model(array $viewer, array $request, array $post): array
{
    $model = ['messages'=>[], 'errors'=>[], 'messageid'=>'', 'post'=>null];
    if (corebb_admin_viewer_id($viewer) <= 0 || !corebb_admin_can_access_tool($viewer, 'view_message')) {
        $model['errors'][] = 'You do not have access to View a Message.';
        return $model;
    }
    $model['can_view_bin'] = corebb_admin_can_access_tool($viewer, 'deleted_posts');
    $method = $request['method'] ?? '';
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $input = $isPost ? ($post['messageid'] ?? '') : ($request['messageid'] ?? '');
    $model['messageid'] = is_scalar($input) ? trim((string)$input) : '';
    if ($method === '' && $model['messageid'] === '') { return $model; }
    $id = filter_var($model['messageid'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) { $model['errors'][] = 'Enter a positive message ID.'; return $model; }
    $write = $isPost && in_array($method, ['edit','delete'], true);
    if ($write && (!corebb_security_csrf_valid($post) || (isset($request['messageid']) && (string)$id !== (string)$request['messageid']))) {
        $model['errors'][] = 'Reopen the message before making changes.';
        return $model;
    }
    $model = corebb_admin_view_message_apply_post($model, $viewer, $id);
    $row = $model['post'];
    if (!$row) { return $model; }
    if (!$write) {
        corebb_adminlog_viewer($viewer, 'Viewed admin message #' . $id . ' by user #' . (int)$row['posterid'], 'view_message');
        return $model;
    }
    if ($model['is_deleted'] || $model['modify_block_reason'] !== '') {
        $model['errors'][] = $model['modify_block_reason'] ?: 'This message is already in Deleted Posts.';
        return $model;
    }
    if (isset($post['revision']) && $post['revision'] !== $model['revision']) {
        $model['errors'][] = 'This message changed. Review the latest version before continuing.';
        return $model;
    }
    if ($method === 'delete') {
        $result = corebb_mod_remove_post($id);
        if (empty($result['ok'])) { $model['errors'][] = 'Could not move this message to Deleted Posts.'; return $model; }
        $model['saved'] = true;
        $model['messages'][] = $result['message'];
        $model['continue_url'] = $result['redirect'] ?? '';
        return $model;
    }
    if (!is_string($post['message_subject'] ?? null) || !is_string($post['message_body'] ?? null)) {
        $model['errors'][] = 'Submit both the subject and the message.';
        return $model;
    }
    [$subject,$body] = corebb_admin_view_message_normalize($post['message_subject'],$post['message_body']);
    if (!db_begin()) { $model['errors'][] = 'Could not start the edit.'; return $model; }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $latest = db_one('SELECT id,boardid,threadid,posterid,title,body,editcount,is_deleted FROM posts WHERE id=?' . $lock, [$id]);
        if (!$latest || corebb_admin_message_revision($latest) !== $model['revision']) {
            $model['errors'][] = 'This message changed during the edit. Reopen it before continuing.';
            return $model;
        }
        $ok = corebb_mod_update_post_with_edit_metadata($id,$subject,$body,(int)$viewer['id'],convert_to_timestamp_raw(time()));
        if (!$ok || !db_commit()) {
            $model['errors'][] = 'Could not save this edit. Please try again.';
            $model['draft'] = ['title'=>$post['message_subject'], 'body'=>$post['message_body']];
            return $model;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    if ($row['threadid'] > 0) { corebb_mod_refresh_topic_from_posts((int)$row['threadid']); }
    corebb_mod_log('Moderator edited post ' . $id . ' from View a Message');
    corebb_notifications_notify_moderated_post($row, 'edited', (int)$viewer['id'], (string)$viewer['username']);
    $model['saved'] = true;
    $model['messages'][] = 'Message #' . $id . ' was updated.';
    return $model;
}
