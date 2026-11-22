<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
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
 |  admin_edit_profile_view_model.php  - Manager Edit    |
 |  User Profile tool.                                   |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/usercp_settings_view_model.php';

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_edit_profile_token(): string
{
    return corebb_security_named_token('admin_edit_profile_token');
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_edit_profile_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('admin_edit_profile_token', $post, 'admin_edit_profile_token');
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_edit_profile_limit_text(string $value, int $maxBytes): string
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
 * Usage: Return the reason a profile target cannot be edited.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param ?array $target Target user row.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_edit_profile_target_error(array $viewer, ?array $target): string
{
    if (!$target) {
        return 'Unknown user.';
    }

    $viewerId = corebb_admin_viewer_id($viewer);
    $viewerLevel = corebb_admin_viewer_level($viewer);
    $targetId = (int)($target['id'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);

    if ($viewerId > 0 && $targetId === $viewerId) {
        return 'Use your User CP to edit your own profile.';
    }
    if ($targetLevel >= $viewerLevel) {
        return 'You cannot edit the profile of a user with equal or higher rights.';
    }

    return '';
}


/** Group only editable public fields that exist on this installation. */
function corebb_admin_edit_profile_sections(): array
{
    $definitions = array_column(corebb_profile_fields(true), null, 'key');
    $definitions['gender']['maxlength'] = 64;
    $definitions['birthday']['maxlength'] = 64;
    $definitions['profpic'] = ['key'=>'profpic', 'label'=>'Picture URL', 'maxlength'=>255];
    $definitions['bio'] = ['key'=>'bio', 'label'=>'Bio', 'maxlength'=>65535];
    $columns = corebb_admin_table_columns('users');
    $sections = [];
    foreach ([
        'personal' => ['Personal details', ['profiletitle', 'country', 'gender', 'birthday']],
        'contact' => ['Work & contact', ['company', 'jobtitle', 'websiteurl', 'pubemail']],
        'bio' => ['Picture & bio', ['profpic', 'bio']],
    ] as $key => [$label, $names]) {
        $fields = [];
        foreach ($names as $name) {
            if (isset($columns[$name], $definitions[$name])) {
                $fields[$name] = $definitions[$name];
            }
        }
        if ($fields) { $sections[$key] = ['label'=>$label, 'fields'=>$fields]; }
    }
    return $sections;
}

function corebb_admin_edit_profile_revision(int $userId, array $values): string
{
    return hash('sha256', serialize([$userId, $values]));
}

function corebb_admin_edit_profile_model(array $viewer, array $request, array $post): array
{
    $model = ['user'=>null, 'errors'=>[], 'messages'=>[], 'saved'=>false, 'search_value'=>'',
        'can_view_member'=>corebb_admin_can_access_tool($viewer, 'user_pages')];
    if (!corebb_admin_can_access_tool($viewer, 'edit_profile')) {
        $model['errors'][] = 'You do not have access to this tool.';
        return $model;
    }
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $saving = $isPost && ($request['method'] ?? $post['method'] ?? '') === 'save';
    if ($saving && (!corebb_security_csrf_valid($post) || !corebb_admin_edit_profile_token_ok($post))) {
        $model['errors'][] = 'Your form expired. Find the member again.';
        return $model;
    }
    $input = $saving ? ($post['userid'] ?? '') : ($post['user'] ?? $post['username'] ?? $post['userid'] ?? $request['user'] ?? $request['username'] ?? $request['userid'] ?? $request['uid'] ?? '');
    $identifier = is_scalar($input) ? trim((string)$input) : '';
    if ($saving && (!ctype_digit($identifier) || (isset($request['userid']) && (string)$request['userid'] !== $identifier))) {
        $model['errors'][] = 'Find the member again before saving this profile.';
        return $model;
    }
    if ($identifier === '') { return $model; }
    $model['search_value'] = $identifier;
    $target = corebb_admin_find_user(corebb_admin_edit_profile_limit_text($identifier, 255));
    $error = corebb_admin_edit_profile_target_error($viewer, $target);
    if ($error !== '') {
        $model['errors'][] = $error;
        return $model;
    }
    $id = (int)$target['id'];
    $model['user'] = corebb_admin_user_summary($target);
    $model['search_value'] = $target['username'];
    $sections = corebb_admin_edit_profile_sections();
    if (!$sections) {
        $model['errors'][] = 'No editable profile fields are available on this installation.';
        return $model;
    }
    $section = $request['section'] ?? array_key_first($sections);
    if (!is_string($section) || !isset($sections[$section])) {
        $model['errors'][] = 'Unknown profile section.';
        return $model;
    }
    $model['sections'] = array_map(static fn($group) => $group['label'], $sections);
    $model['section'] = $section;
    $model['fields'] = $sections[$section]['fields'];
    // A previously opened, single-page editor posts all fields without a section.
    $allFields = array_merge(...array_values(array_column($sections, 'fields')));
    $fields = $saving && !isset($request['section']) ? $allFields : $model['fields'];
    $select = '`' . implode('`, `', array_keys($fields)) . '`';
    $values = db_one('SELECT ' . $select . ' FROM users WHERE id = ?', [$id]);
    if (!$values) {
        $model['errors'][] = 'Could not load the profile fields.';
        return $model;
    }
    $model['profile'] = array_intersect_key($values, $model['fields']);
    $model['revision'] = corebb_admin_edit_profile_revision($id, $model['profile']);
    $model['token'] = corebb_admin_edit_profile_token();
    if (!$saving) { return $model; }

    $input = array_intersect_key($post, $allFields);
    if (!$input || array_diff_key($input, $fields) || array_filter($input, static fn($value) => !is_string($value))) {
        $model['errors'][] = 'The submitted fields do not match this profile form.';
        return $model;
    }
    if (isset($request['section']) && ($post['revision'] ?? null) !== $model['revision']) {
        $model['errors'][] = 'This profile changed after you opened it. Review the current values before saving.';
        return $model;
    }
    $changes = [];
    foreach ($input as $key => $value) {
        if (str_replace("\r\n", "\n", $value) !== str_replace("\r\n", "\n", (string)$values[$key])) {
            $changes[$key] = $value;
        }
    }
    if (!$changes) {
        $model['messages'][] = 'No profile changes to save.';
        return $model;
    }
    $model['profile'] = array_replace($model['profile'], array_intersect_key($input, $model['fields']));
    foreach ($changes as $key => $value) {
        $clean = trim(strip_tags($value));
        $max = $fields[$key]['maxlength'];
        if ($key === 'bio' ? strlen($clean) > $max : !preg_match('/\A.{0,' . $max . '}\z/us', $clean)) {
            $label = rtrim($fields[$key]['label'], ':');
            $model['errors'][] = $label . ' exceeds the field limit of ' . $max . ($key === 'bio' ? ' bytes.' : ' characters.');
        }
    }
    if ($model['errors']) { return $model; }
    corebb_admin_tool_permissions_ensure_schema();
    if (!db_begin()) {
        $model['errors'][] = 'Could not start the profile save.';
        return $model;
    }
    try {
        $actorId = corebb_admin_viewer_id($viewer);
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = db_all('SELECT id, username, accesslevel FROM users WHERE id IN (?, ?) ORDER BY id' . $lock, [$actorId,$id]);
        $users = array_column($rows, null, 'id');
        $actor = $users[$actorId] ?? [];
        corebb_admin_user_granted_tool_keys($actorId, true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor, 'edit_profile')
            || corebb_admin_edit_profile_target_error($actor, $users[$id] ?? null) !== '') {
            return ['user'=>null,'errors'=>['You can no longer edit this profile.'],'saved'=>false];
        }
        $current = db_one('SELECT ' . $select . ' FROM users WHERE id = ?', [$id]);
        if ($current !== $values) {
            // A new GET will pick up both a fresh snapshot and matching revision.
            $model['errors'][] = 'The profile changed while saving. Reopen this section before trying again.';
            return $model;
        }
        if (!corebb_usercp_save_profile_from_array($id, $changes, true) || !db_commit()) {
            $model['errors'][] = 'Could not save the profile changes. Please try again.';
            return $model;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    corebb_adminlog_viewer($actor, 'Edited profile for ' . $target['username'] . ' (#' . $id . ')', 'edit_profile', implode(', ', array_keys($changes)));
    $model['saved'] = true;
    $model['messages'][] = 'Profile updated for ' . $target['username'] . '.';
    return $model;
}
