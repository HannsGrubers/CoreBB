<?php
require_once __DIR__ . '/corebb_date_helpers.php';
require_once __DIR__ . '/admin_log_helpers.php';
require_once __DIR__ . '/auth_password_helpers.php';
/**
 * Admin user/message tools.
 *
 * Migrates these old admin.php actions into view-model backed pages while
 * keeping CoreBB' original look and permission model.
 */
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/user_display_helpers.php';

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
        'style', 'vip_bg_color', 'vip_text_color', 'vip_strike', 'vip_bold',
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
            'db_schema_deploy' => ['label' => 'DB Schema Deploy', 'min_level' => 5],
            'updates' => ['label' => 'Updates', 'min_level' => 5],
            'api_explorer' => ['label' => 'API Explorer', 'min_level' => 5],
            'forum_sim' => ['label' => 'Forum Sim-Test', 'min_level' => 5],
            'edit_tos' => ['label' => 'Edit System TOS', 'min_level' => 5],
            'edit_style' => ['label' => 'Edit System Style', 'min_level' => 5],
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

/**
 * Usage: Convert an admin act/mode pair into its permission-tool key.
 * Referenced by: admin.php before dispatching a route model.
 *
 * @param string $act Requested admin action.
 * @param array<string, mixed> $request Query parameters for actions with modes.
 * @return string Permission-tool key.
 */
function corebb_admin_tool_key_for_request(string $act, array $request = []): string
{
    if ($act === '') {
        return 'admin_home';
    }
    if ($act === 'administrator_tools' || $act === 'database_tools') {
        return 'database_tools';
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
function corebb_admin_user_granted_tool_keys(int $userId): array
{
    static $cache = [];
    if ($userId <= 0) {
        return [];
    }
    if (isset($cache[$userId])) {
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
    $model = corebb_admin_require_model_base($viewer, 'Edit User Rights', $request);
    $model['levels'] = corebb_admin_assignable_level_options($viewer);
    $model['role_details'] = corebb_admin_level_details($model['levels']);
    $model['can_manage_tool_grants'] = corebb_admin_can_manage_tool_grants($viewer);
    $model['tool_groups'] = corebb_admin_tool_catalog();
    $model['tool_summary'] = corebb_admin_tool_group_summary([]);
    $model['search_value'] = trim((string)($request['user'] ?? ''));
    $model['current_level'] = null;
    $model['target_level'] = null;
    $model['mode'] = 'search';

    $method = (string)($request['method'] ?? '');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($isPost && $method === 'save') {
        $userId = (int)($post['userid'] ?? 0);
        $newLevel = (int)($post['userslevel'] ?? 0);
        $target = $userId > 0 ? corebb_admin_find_user((string)$userId) : null;
        $targetError = corebb_admin_target_rights_error($viewer, $target);
        $levelError = corebb_admin_new_level_error($viewer, $newLevel);
        $selectedTools = corebb_admin_normalize_tool_keys($post['admin_tools'] ?? []);

        if ($targetError !== '') {
            $model['errors'][] = $targetError;
        } elseif ($levelError !== '') {
            $model['errors'][] = $levelError;
        } elseif (!isset($model['levels'][$newLevel])) {
            $model['errors'][] = 'Invalid user level.';
        } else {
            $ok = db_run('UPDATE users SET accesslevel = ? WHERE id = ?', [$newLevel, $userId]);
            if ($ok) {
                $newLevelText = $model['levels'][$newLevel] ?? (string)$newLevel;
                {
                    corebb_adminlog_entry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), 'Changed user: ' . $userId . ' to level: ' . $newLevel . ' (' . $newLevelText . ')');
                }
                $model['messages'][] = 'User ' . ($target['username'] ?? $userId) . ' was updated to level ' . $newLevel . ' (' . $newLevelText . ').';
                if ($model['can_manage_tool_grants']) {
                    if (corebb_admin_replace_tool_grants($userId, $selectedTools, $viewer)) {
                        $model['messages'][] = 'Additional admin tool access updated.';
                        {
                            corebb_adminlog_entry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), 'Updated admin tool access for user: ' . $userId);
                        }
                    } else {
                        $model['errors'][] = 'Error updating admin tool access: ' . db_error();
                    }
                }
                $updatedTarget = corebb_admin_find_user((string)$userId);
                if ($updatedTarget) {
                    $model['mode'] = 'view';
                    $model['user'] = corebb_admin_user_summary($updatedTarget);
                    $model['tool_groups'] = corebb_admin_tool_groups_for_user($updatedTarget);
                    $model['tool_summary'] = corebb_admin_tool_group_summary($model['tool_groups']);
                    $model['current_level'] = [
                        'value' => (int)($updatedTarget['accesslevel'] ?? 0),
                        'label' => corebb_user_level_label((int)($updatedTarget['accesslevel'] ?? 0)),
                    ];
                }
            } else {
                $model['errors'][] = 'Error updating user level: ' . db_error();
            }
        }
    } elseif ($isPost && $method === 'confirm') {
        $userId = (int)($post['userid'] ?? 0);
        $newLevel = (int)($post['userlevel'] ?? 0);
        $target = $userId > 0 ? corebb_admin_find_user((string)$userId) : null;
        $targetError = corebb_admin_target_rights_error($viewer, $target);
        $levelError = corebb_admin_new_level_error($viewer, $newLevel);

        if ($targetError !== '') {
            $model['errors'][] = $targetError;
        } elseif ($levelError !== '') {
            $model['errors'][] = $levelError;
        } elseif (!isset($model['levels'][$newLevel])) {
            $model['errors'][] = 'Invalid user level.';
        } else {
            $model['mode'] = 'confirm';
            $model['user'] = corebb_admin_user_summary($target);
            $model['new_level'] = $newLevel;
            $model['new_level_name'] = $model['levels'][$newLevel];
            $model['current_level'] = [
                'value' => (int)($target['accesslevel'] ?? 0),
                'label' => corebb_user_level_label((int)($target['accesslevel'] ?? 0)),
            ];
            $model['target_level'] = [
                'value' => $newLevel,
                'label' => $model['levels'][$newLevel],
            ];
            $selectedTools = $model['can_manage_tool_grants'] ? corebb_admin_normalize_tool_keys($post['admin_tools'] ?? []) : corebb_admin_user_granted_tool_keys($userId);
            $confirmTarget = $target;
            $confirmTarget['accesslevel'] = $newLevel;
            $model['tool_groups'] = corebb_admin_tool_groups_for_user($confirmTarget, $selectedTools);
            $model['tool_summary'] = corebb_admin_tool_group_summary($model['tool_groups']);
        }
    } elseif ($isPost && $method === 'view') {
        $identifier = (string)($post['username'] ?? '');
        $model['search_value'] = trim($identifier);
        $target = corebb_admin_find_user($identifier);
        $targetError = corebb_admin_target_rights_error($viewer, $target);
        if ($targetError !== '') {
            $model['errors'][] = $targetError;
        } else {
            $model['mode'] = 'view';
            $model['user'] = corebb_admin_user_summary($target);
            $model['tool_groups'] = corebb_admin_tool_groups_for_user($target);
            $model['tool_summary'] = corebb_admin_tool_group_summary($model['tool_groups']);
            $model['current_level'] = [
                'value' => (int)($target['accesslevel'] ?? 0),
                'label' => corebb_user_level_label((int)($target['accesslevel'] ?? 0)),
            ];
        }
    } elseif (!$isPost && isset($request['user'])) {
        $identifier = (string)($request['user'] ?? '');
        $model['search_value'] = trim($identifier);
        $target = corebb_admin_find_user($identifier);
        $targetError = corebb_admin_target_rights_error($viewer, $target);
        if ($targetError !== '') {
            $model['errors'][] = $targetError;
        } else {
            $model['mode'] = 'view';
            $model['user'] = corebb_admin_user_summary($target);
            $model['tool_groups'] = corebb_admin_tool_groups_for_user($target);
            $model['tool_summary'] = corebb_admin_tool_group_summary($model['tool_groups']);
            $model['current_level'] = [
                'value' => (int)($target['accesslevel'] ?? 0),
                'label' => corebb_user_level_label((int)($target['accesslevel'] ?? 0)),
            ];
        }
    }

    if (empty($model['levels'])) {
        $model['errors'][] = 'No assignable user levels are available for your account.';
    }

    return $model;
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
    $model = corebb_admin_require_model_base($viewer, "Change User's Password", $request);
    $model['mode'] = 'search';

    $method = (string)($request['method'] ?? '');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($isPost && $method === 'save') {
        $userId = (int)($post['userid'] ?? 0);
        $password1 = (string)($post['password1'] ?? '');
        $password2 = (string)($post['password2'] ?? '');
        $target = $userId > 0 ? corebb_admin_find_user((string)$userId) : null;
        $targetError = corebb_admin_target_rights_error($viewer, $target);
        if ($targetError !== '') {
            $model['errors'][] = $targetError;
        } elseif ($password1 === '' || $password2 === '' || $password1 !== $password2) {
            $model['mode'] = 'edit';
            $model['user'] = $target ? corebb_admin_user_summary($target) : null;
            $model['errors'][] = 'Password mismatch or no password entered.';
        } else {
            corebb_auth_ensure_schema();
            $hash = corebb_auth_password_hash($password1);
            $ok = db_run('UPDATE users SET password = ? WHERE id = ?', [$hash, $userId]);
            if ($ok) {
                corebb_auth_revoke_user_login_tokens($userId);
            }
            if ($ok) {
                {
                    corebb_adminlog_entry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), "Changed user: {$userId}'s password");
                }
                $model['messages'][] = 'Password updated for ' . ($target['username'] ?? $userId) . '.';
            } else {
                $model['errors'][] = 'Error updating password: ' . db_error();
            }
        }
    } elseif ($isPost && $method === 'view') {
        $identifier = (string)($post['user'] ?? '');
        $target = corebb_admin_find_user($identifier);
        $targetError = corebb_admin_target_rights_error($viewer, $target);
        if ($targetError !== '') {
            $model['errors'][] = $targetError;
        } else {
            $model['mode'] = 'edit';
            $model['user'] = corebb_admin_user_summary($target);
        }
    }

    return $model;
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
    $model = corebb_admin_require_model_base($viewer, 'Add User', $request);
    $model['levels'] = corebb_admin_assignable_level_options($viewer);
    $model['mode'] = 'form';
    $model['form'] = [
        'username' => '',
        'userlevel' => 1,
    ];

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost) {
        $username = trim((string)($post['username'] ?? ''));
        $password1 = (string)($post['password1'] ?? '');
        $password2 = (string)($post['password2'] ?? '');
        $level = (int)($post['userlevel'] ?? 1);
        $model['form'] = ['username' => $username, 'userlevel' => $level];

        if ($username === '') {
            $model['errors'][] = 'Username is required.';
        }
        if ($password1 === '' || $password2 === '' || $password1 !== $password2) {
            $model['errors'][] = 'Password mismatch or no password entered.';
        }
        if (!isset($model['levels'][$level])) {
            $levelError = corebb_admin_new_level_error($viewer, $level);
            $model['errors'][] = $levelError !== '' ? $levelError : 'Invalid user level.';
        }
        if ($username !== '' && corebb_admin_find_user($username)) {
            $model['errors'][] = 'That username already exists.';
        }

        if (!$model['errors']) {
            corebb_auth_ensure_schema();
            $hash = corebb_auth_password_hash($password1);
            $userColumns = corebb_admin_table_columns('users');
            $newUser = [
                'username' => $username,
                'password' => $hash,
                'accesslevel' => $level,
            ];
            $profileDate = date('M y');
            if (isset($userColumns['regdate'])) {
                $newUser['regdate'] = $profileDate;
            }
            if (isset($userColumns['profadded'])) {
                $newUser['profadded'] = $profileDate;
            }
            $insertColumns = array_keys($newUser);
            $insertSql = implode(', ', array_map(static function (string $column): string {
                return '`' . str_replace('`', '``', $column) . '`';
            }, $insertColumns));
            $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
            $ok = db_run('INSERT INTO users (' . $insertSql . ') VALUES (' . $placeholders . ')', array_values($newUser));
            if ($ok) {
                {
                    corebb_adminlog_entry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), 'Added new user: ' . $username . ' - Userlevel: ' . $level);
                }
                $model['messages'][] = 'User successfully added.';
                $model['form'] = ['username' => '', 'userlevel' => 1];
            } else {
                $model['errors'][] = 'Error adding user: ' . db_error();
            }
        }
    }

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
    corebb_mod_ensure_schema();
    $postRow = db_one('SELECT * FROM posts WHERE id = ? LIMIT 1', [$messageId]);
    if ($postRow && !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
        $postRow = false;
    }
    if (!$postRow) {
        $model['errors'][] = 'Unknown message ID.';
        $model['mode'] = 'form';
        return $model;
    }

    $user = null;
    if (!empty($postRow['posterid'])) {
        $user = corebb_admin_find_user((string)$postRow['posterid']);
    }

    $model['mode'] = 'view';
    $model['post'] = $postRow;
    $model['poster'] = $user ? corebb_admin_user_summary($user) : null;
    $rightsError = corebb_admin_view_message_author_error($viewer, $user);
    $archiveError = corebb_secure_archive_user_can_write_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['accesslevel'] ?? 0)) ? '' : corebb_secure_archive_denied_message();
    $isDeleted = (int)($postRow['is_deleted'] ?? 0) === 1;
    $model['is_deleted'] = $isDeleted;
    $model['can_modify_message'] = (!$isDeleted && $rightsError === '' && $archiveError === '');
    $model['modify_block_reason'] = $isDeleted ? 'This message is currently in the Deleted Posts bin. Restore or purge it from that tool.' : ($archiveError !== '' ? $archiveError : $rightsError);
    $model['edit_subject'] = (string)($postRow['title'] ?? '');
    $model['edit_body'] = (string)($postRow['body'] ?? '');
    $model['post_date'] = convert_to_vndate((string)($postRow['posttime'] ?? ''));
    $editCount = max(1, (int)($postRow['editcount'] ?? 0));
    $model['post_edit'] = [
        'was_edited' => (int)($postRow['wasedited'] ?? 0) === 1,
        'date' => convert_to_vndate((string)($postRow['editdate'] ?? '')),
        'count_text' => $editCount === 1 ? '1 edit total' : $editCount . ' edits total',
        'edited_by_id' => (int)($postRow['editedby'] ?? 0),
    ];

    return $model;
}

/**
 * Usage: Build and process the View a Message admin page.
 * Referenced by: admin route act=view_message.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query parameters.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed> Page model.
 */
function corebb_admin_view_message_model(array $viewer, array $request, array $post): array
{
    corebb_mod_ensure_schema();
    $model = corebb_admin_require_model_base($viewer, 'View a Message', $request);
    $model['mode'] = 'form';
    $model['messageid'] = 0;
    $model['edit_subject'] = '';
    $model['edit_body'] = '';
    $model['can_modify_message'] = false;
    $model['modify_block_reason'] = '';
    $model['delete_redirect'] = '';

    $method = (string)($request['method'] ?? '');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $messageId = (int)($isPost ? ($post['messageid'] ?? 0) : ($request['messageid'] ?? 0));
    $model['messageid'] = $messageId > 0 ? $messageId : 0;

    if ($isPost && $method === 'edit') {
        if ($messageId <= 0) {
            $model['errors'][] = 'Unknown message ID.';
            return $model;
        }
        $postRow = corebb_mod_get_post($messageId);
        if ($postRow && !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
            $postRow = false;
        }
        if (!$postRow) {
            $model['errors'][] = 'Unknown visible message ID. Deleted-bin messages must be restored before editing.';
            return $model;
        }
        $author = !empty($postRow['posterid']) ? corebb_admin_find_user((string)$postRow['posterid']) : null;
        $rightsError = corebb_admin_view_message_author_error($viewer, $author ?: null);
        if (!corebb_secure_archive_user_can_write_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
            $model['errors'][] = corebb_secure_archive_denied_message();
            return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
        }
        if ($rightsError !== '') {
            $model['errors'][] = $rightsError;
            return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
        }

        [$subject, $body] = corebb_admin_view_message_normalize(
            (string)($post['message_subject'] ?? ''),
            (string)($post['message_body'] ?? '')
        );
        $now = convert_to_timestamp_raw(time());
        $ok = corebb_mod_update_post_with_edit_metadata(
            $messageId,
            $subject,
            $body,
            (int)($viewer['id'] ?? 0),
            $now
        );
        if (!$ok) {
            $model['errors'][] = 'Error updating message: ' . db_error();
            return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
        }

        $topicId = (int)($postRow['threadid'] ?? 0);
        if ($topicId > 0) {
            corebb_mod_refresh_topic_from_posts($topicId);
        }
        corebb_mod_log('Moderator edited post ' . $messageId . ' from View a Message');
        corebb_notifications_notify_moderated_post(
            $postRow,
            'edited',
            (int)($viewer['id'] ?? 0),
            (string)($viewer['username'] ?? 'A moderator')
        );
        $model['messages'][] = 'Message #' . $messageId . ' was updated.';
        return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
    }

    if ($isPost && $method === 'delete') {
        if ($messageId <= 0) {
            $model['errors'][] = 'Unknown message ID.';
            return $model;
        }
        $postRow = corebb_mod_get_post($messageId, true);
        if ($postRow && !corebb_private_user_can_view_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
            $postRow = false;
        }
        if (!$postRow) {
            $model['errors'][] = 'Unknown message ID.';
            return $model;
        }
        $author = !empty($postRow['posterid']) ? corebb_admin_find_user((string)$postRow['posterid']) : null;
        $rightsError = corebb_admin_view_message_author_error($viewer, $author ?: null);
        if (!corebb_secure_archive_user_can_write_board_id((int)($postRow['boardid'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
            $model['errors'][] = corebb_secure_archive_denied_message();
            return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
        }
        if ($rightsError !== '') {
            $model['errors'][] = $rightsError;
            return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
        }

        $result = corebb_mod_remove_post($messageId);
        if (!empty($result['ok'])) {
            $model['messages'][] = (string)($result['message'] ?? 'Message moved to deleted-posts bin.');
            $model['delete_redirect'] = (string)($result['redirect'] ?? '');
            $model['messageid'] = 0;
            return $model;
        }
        $model['errors'][] = (string)($result['message'] ?? 'Error deleting message.');
        return corebb_admin_view_message_apply_post($model, $viewer, $messageId);
    }

    if ($method === 'view' || $messageId > 0) {
        if ($messageId <= 0) {
            $model['errors'][] = 'Unknown message ID.';
            return $model;
        }

        $model = corebb_admin_view_message_apply_post($model, $viewer, $messageId);
        if (($model['mode'] ?? '') === 'view' && (int)($viewer['accesslevel'] ?? 0) >= 3 ) {
            $posterId = (int)($model['post']['posterid'] ?? 0);
            corebb_adminlog_viewer(
                $viewer,
                'Viewed admin message #' . $messageId . ($posterId > 0 ? ' by user #' . $posterId : ''),
                'view_message'
            );
        }
    }

    return $model;
}
