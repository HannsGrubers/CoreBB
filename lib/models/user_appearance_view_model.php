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
 |  user_appearance_view_model.php - Username appearance |
 |  models for User CP and admin user tools.             |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/vip_style_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/admin_appearance_presets.php';

/**
 * Usage: Return the shared appearance form token.
 * Referenced by: self-service and admin appearance forms.
 *
 * @return string Session-backed form token.
 */
function corebb_user_appearance_token(): string
{
    return corebb_security_named_token('corebb_user_appearance_token');
}

/**
 * Usage: Validate the shared appearance form token.
 * Referenced by: save handlers before writing style values.
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @return bool True when the token matches the session value.
 */
function corebb_user_appearance_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('corebb_user_appearance_token', $post, 'user_appearance_token');
}

/**
 * Usage: Load one user row for appearance editing.
 * Referenced by: self-service and admin appearance models.
 *
 * @param int $userId User id to load.
 * @return array<string, mixed>|null User row or null when not found.
 */
function corebb_user_appearance_load_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    corebb_vip_style_ensure_schema();
    $row = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
    return is_array($row) ? $row : null;
}

/**
 * Usage: Build a structured preview for Twig without pre-rendered HTML.
 * Referenced by: corebb_user_appearance_form_model().
 *
 * @param array<string, mixed> $user User row being previewed.
 * @param array<string, mixed> $values Normalized appearance values.
 * @return array<string, mixed> Preview label and CSS state.
 */
function corebb_user_appearance_preview_model(array $user, array $values): array
{
    return [
        'username' => (string)($user['username'] ?? 'Preview'),
        'style_css' => corebb_vip_style_css_from_values($values),
    ];
}

/**
 * Usage: Build the shared appearance editor form model.
 * Referenced by: User CP and admin appearance pages.
 *
 * @param array<string, mixed> $user User row being edited.
 * @param string $formAction Form action URL.
 * @param string $mode Either "self" or "admin".
 * @return array<string, mixed> Twig-ready appearance editor state.
 */
function corebb_user_appearance_form_model(array $user, string $formAction, string $mode): array
{
    $values = corebb_vip_style_values_from_user($user);
    return [
        'mode' => $mode,
        'form_action' => $formAction,
        'token' => corebb_user_appearance_token(),
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'accesslevel' => (int)($user['accesslevel'] ?? 0),
            'level_name' => corebb_user_level_label((int)($user['accesslevel'] ?? 0)),
            'profile_url' => '/profile/' . (int)($user['id'] ?? 0) . '/',
        ],
        'values' => $values,
        'preview' => corebb_user_appearance_preview_model($user, $values),
        'picker_defaults' => [
            'vip_text_color' => $values['vip_text_color'] !== '' ? $values['vip_text_color'] : '#ffffff',
            'vip_bg_color' => $values['vip_bg_color'] !== '' ? $values['vip_bg_color'] : '#333333',
            'vip_border_color' => $values['vip_border_color'] !== '' ? $values['vip_border_color'] : '#ffffff',
        ],
    ];
}

/**
 * Usage: Save self-service appearance settings for the logged-in user.
 * Referenced by: controllers/usercp.php action=appearance before redirecting back to User CP.
 *
 * @param int $userId Current logged-in user id.
 * @param array<string, mixed> $post Submitted POST data.
 * @return array{ok: bool, message: string} Save result.
 */
function corebb_user_appearance_save_self(int $userId, array $post): array
{
    if (!corebb_user_appearance_token_ok($post)) {
        return ['ok' => false, 'message' => 'Your appearance form token expired. Reload the page and try again.'];
    }
    $user = corebb_user_appearance_load_user($userId);
    if (!$user || !corebb_vip_style_user_can_self_manage($userId, $user)) {
        return ['ok' => false, 'message' => 'You do not have access to username appearance settings.'];
    }
    return corebb_vip_style_save_user($userId, $post, true);
}

/**
 * Usage: Build the User CP appearance page model.
 * Referenced by: controllers/usercp.php action=appearance.
 *
 * @param int $userId Current logged-in user id.
 * @param array<string, mixed> $request GET values.
 * @return array<string, mixed> User CP appearance page model.
 */
function corebb_user_appearance_self_model(int $userId, array $request): array
{
    require_once __DIR__ . '/usercp_settings_view_model.php';

    $model = corebb_usercp_base_model($userId);
    $model['error'] = (string)($request['err'] ?? '0') === '1';
    $user = corebb_user_appearance_load_user($userId) ?: [];
    $model['canEditAppearance'] = $user && (!empty($user['appearance_preset_id']) || corebb_vip_style_user_can_self_manage($userId, $user));
    $model['appearance'] = $user
        ? corebb_user_appearance_form_model($user, '/user-cp/appearance/', 'self')
        : [];
    if (!empty($user['appearance_preset_id'])) {
        $model['managed_preset'] = db_value('SELECT name FROM appearance_presets WHERE id=?', [$user['appearance_preset_id']], 'Staff preset');
    }
    return $model;
}

function corebb_appearance_revision(array $user): string
{
    $state = [corebb_vip_style_values_from_user($user), (string)($user['style'] ?? '')];
    if (!empty($user['appearance_preset_id'])) { $state[] = (int)$user['appearance_preset_id']; }
    return hash('sha256', serialize($state));
}

function corebb_admin_user_appearance_model(array $viewer, array $request, array $post): array
{
    $model = ['search_value'=>'', 'selected_user'=>null, 'saved'=>false, 'messages'=>[], 'errors'=>[]];
    $actorId = corebb_admin_viewer_id($viewer);
    if (!$actorId || !corebb_admin_can_access_tool($viewer, 'user_appearance_admin')) {
        $model['errors'][] = 'You do not have access to Edit User Appearance.';
        return $model;
    }
    $identifier = $request['user'] ?? $request['userid'] ?? $request['username'] ?? $post['user'] ?? $post['userid'] ?? '';
    if (!corebb_appearance_presets_prepare_schema()) {
        $model['errors'][] = 'The appearance preset storage could not be prepared.';
        return $model;
    }
    $model['presets'] = corebb_appearance_preset_list();
    if (($request['section'] ?? '') === 'presets') {
        return corebb_admin_preset_library($model, $viewer, $request, $post);
    }
    $model['search_value'] = is_scalar($identifier) ? trim((string)$identifier) : '';
    $action = $post['action'] ?? '';
    $saving = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($action, ['save_appearance','assign_preset','unlink_preset'],true);
    if ($saving && (!corebb_security_csrf_valid($post) || !corebb_user_appearance_token_ok($post))) {
        $model['errors'][] = 'Your form expired. Find the member again.';
        return $model;
    }
    if ($model['search_value'] === '') { return $model; }
    $user = corebb_admin_find_user($model['search_value']);
    if (!$user) {
        $model['errors'][] = 'No member found for that username or ID.';
        return $model;
    }
    $id = (int)$user['id'];
    if ($saving && (!is_scalar($post['userid'] ?? null) || (string)$post['userid'] !== (string)$id)) {
        $model['errors'][] = 'Select the member again before changing their appearance.';
        return $model;
    }
    $error = corebb_admin_target_content_error($viewer, $user);
    if ($error !== '') {
        $model['errors'][] = $error;
        return $model;
    }
    $model['search_value'] = $user['username'];
    $model['selected_user'] = corebb_admin_user_summary($user);
    $model['can_view_member'] = corebb_admin_can_access_tool($viewer, 'user_pages');
    $model['appearance'] = corebb_user_appearance_form_model($user, '/admin/?act=user_appearance&userid=' . $id, 'admin');
    $model['revision'] = corebb_appearance_revision($user);
    $model['assigned'] = $model['presets'][(int)($user['appearance_preset_id'] ?? 0)] ?? null;
    $values = $model['appearance']['values'];
    $model['has_custom'] = (bool)array_filter($values) || !empty($user['style']);
    $model['legacy_only'] = !array_filter($values) && !empty($user['style']);
    if (!$saving) { return $model; }

    $clear = !empty($post['clear_vip_style']);
    if (isset($post['revision']) && $post['revision'] !== $model['revision']) {
        $model['errors'][] = 'The appearance changed since you opened this page. Review the current settings.';
        return $model;
    }
    $membershipChange = $action === 'assign_preset' || $action === 'unlink_preset';
    if ($membershipChange && !isset($post['revision'])) {
        $model['errors'][] = 'Reopen the member before changing the preset.';
        return $model;
    }
    if ($action === 'save_appearance' && !empty($user['appearance_preset_id'])) {
        $model['errors'][] = 'Unlink the preset before changing individual settings.';
        return $model;
    }
    if (!$clear && !$membershipChange) {
        foreach ($values as $key => $value) {
            if (is_int($value)) {
                $model['appearance']['values'][$key] = empty($post[$key]) ? 0 : 1;
                continue;
            }
            $input = $post[$key] ?? null;
            $model['appearance']['values'][$key] = is_string($input) ? $input : '';
            if (!is_string($input) || (trim($input) !== '' && corebb_vip_style_clean_hex($input) === '')) {
                $model['errors'][] = 'Enter a three- or six-digit hex color, or leave the color blank.';
            }
        }
        if ($model['errors']) {
            $model['errors'] = array_unique($model['errors']);
            return $model;
        }
        if (isset($post['revision']) && array_filter($values) && !array_filter(corebb_vip_style_values_from_post($post))) {
            $model['errors'][] = 'Use Clear custom appearance to confirm removal of all styling.';
            return $model;
        }
    }

    // The schema helpers may issue DDL on older installations. Finish it before locking the accounts.
    corebb_vip_style_ensure_schema();
    corebb_admin_tool_permissions_ensure_schema();
    if (!db_begin()) {
        $model['errors'][] = 'Could not start the appearance change.';
        return $model;
    }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($action === 'assign_preset') {
            $choice = $post['preset_choice'] ?? '';
            if (!is_string($choice) || !preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/', $choice,$parts)
                || !($preset = db_one('SELECT * FROM appearance_presets WHERE id=?' . $lock, [$parts[1]]))
                || (string)$preset['revision'] !== $parts[2]) {
                $model['errors'][] = 'Select a current preset and try again.';
                return $model;
            }
        }
        $rows = db_all('SELECT id, username, accesslevel, appearance_preset_id, style, vip_text_color, vip_bg_color, vip_border_color,
            vip_bold, vip_italic, vip_strike, vip_border FROM users WHERE id IN (?, ?) ORDER BY id' . $lock, [$actorId,$id]);
        $accounts = array_column($rows, null, 'id');
        $actor = $accounts[$actorId] ?? [];
        $target = $accounts[$id] ?? null;
        corebb_admin_user_granted_tool_keys($actorId, true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor, 'user_appearance_admin')
            || corebb_admin_target_content_error($actor, $target) !== '') {
            $model['selected_user'] = null;
            $model['errors'][] = 'You can no longer change this appearance.';
            return $model;
        }
        if (corebb_appearance_revision($target) !== $model['revision']) {
            $model['errors'][] = 'The appearance changed during this save. Reopen the member before continuing.';
            return $model;
        }
        if (!$membershipChange && !$clear && corebb_vip_style_values_from_post($post) === $values) {
            $model['messages'][] = 'No appearance changes to save.';
            return $model;
        }
        if ($action === 'assign_preset') {
            $presetValues = corebb_vip_style_values_from_user(json_decode($preset['settings'], true, 512, JSON_THROW_ON_ERROR));
            $result = ['ok'=>corebb_appearance_write_values($presetValues,$id,false,(int)$preset['id']), 'message'=>'Preset assigned.'];
        } elseif ($action === 'unlink_preset') {
            $result = ['ok'=>db_run('UPDATE users SET appearance_preset_id=NULL WHERE id=?', [$id]), 'message'=>'Preset unlinked. Current colors and formatting were kept.'];
        } else {
            $result = corebb_vip_style_save_user($id, $clear ? ['clear_vip_style'=>1] : $post, false);
        }
        if (!$result['ok'] || !db_commit()) {
            $model['errors'][] = 'Could not save the appearance. Please try again.';
            return $model;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    $operation = match ($action) {
        'assign_preset' => 'Assigned appearance preset #' . $preset['id'] . ' to',
        'unlink_preset' => 'Unlinked appearance preset from',
        default => ($clear ? 'Cleared' : 'Changed') . ' username appearance for',
    };
    corebb_adminlog_viewer($actor, $operation . ' ' . $target['username'] . ' (#' . $id . ')', 'user_appearance');
    $model['messages'][] = $result['message'];
    $model['saved'] = true;
    return $model;
}
