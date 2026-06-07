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
 |  user_appearance_view_model.php - Username appearance |
 |  models for User CP and admin user tools.             |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/vip_style_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';

/**
 * Usage: Return the shared appearance form token.
 * Referenced by: self-service and admin appearance forms.
 *
 * @return string Session-backed form token.
 */
function corebb_user_appearance_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['corebb_user_appearance_token'])) {
        $_SESSION['corebb_user_appearance_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['corebb_user_appearance_token'];
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
    $got = (string)($post['user_appearance_token'] ?? '');
    return $got !== '' && hash_equals(corebb_user_appearance_token(), $got);
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
 * Usage: Find an admin-selected target user by id or username.
 * Referenced by: corebb_admin_user_appearance_model().
 *
 * @param array<string, mixed> $request GET values.
 * @param array<string, mixed> $post POST values.
 * @return array<string, mixed>|null User row or null when no match exists.
 */
function corebb_user_appearance_find_target(array $request, array $post): ?array
{
    $identifier = trim((string)($post['user'] ?? $post['userid'] ?? $request['user'] ?? $request['userid'] ?? $request['username'] ?? ''));
    if ($identifier === '') {
        return null;
    }
    if (ctype_digit($identifier)) {
        return corebb_user_appearance_load_user((int)$identifier);
    }
    $row = db_one('SELECT * FROM users WHERE username = ? LIMIT 1', [$identifier]);
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
            'level_name' => function_exists('LoadUserLevel') ? LoadUserLevel((int)($user['accesslevel'] ?? 0)) : 'Level ' . (int)($user['accesslevel'] ?? 0),
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
    $model['canEditAppearance'] = $user && corebb_vip_style_user_can_self_manage($userId, $user);
    $model['appearance'] = $user
        ? corebb_user_appearance_form_model($user, '/user-cp/appearance/', 'self')
        : [];
    return $model;
}

/**
 * Usage: Check whether an admin may edit a target user's appearance.
 * Referenced by: corebb_admin_user_appearance_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed>|null $target Target user row.
 * @return string Empty when allowed, otherwise a user-facing error.
 */
function corebb_admin_user_appearance_target_error(array $viewer, ?array $target): string
{
    if (!$target) {
        return 'Unknown user.';
    }
    if (function_exists('corebb_admin_target_content_error')) {
        return corebb_admin_target_content_error($viewer, $target);
    }
    if ((int)($viewer['id'] ?? 0) > 0 && (int)($viewer['id'] ?? 0) === (int)($target['id'] ?? 0)) {
        return '';
    }
    $viewerLevel = (int)($viewer['accesslevel'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);
    return $targetLevel >= $viewerLevel ? 'You cannot edit a user with equal or higher rights.' : '';
}

/**
 * Usage: Save admin-edited appearance settings for a target user.
 * Referenced by: corebb_admin_user_appearance_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $target Target user row.
 * @param array<string, mixed> $post Submitted POST data.
 * @return array{ok: bool, message: string} Save result.
 */
function corebb_admin_user_appearance_save(array $viewer, array $target, array $post): array
{
    if (!corebb_user_appearance_token_ok($post)) {
        return ['ok' => false, 'message' => 'Your appearance form token expired. Reload the page and try again.'];
    }
    $error = corebb_admin_user_appearance_target_error($viewer, $target);
    if ($error !== '') {
        return ['ok' => false, 'message' => $error];
    }
    $result = corebb_vip_style_save_user((int)$target['id'], $post, false);
    if (!empty($result['ok']) && function_exists('addlogentry')) {
        addlogentry(
            (string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'),
            (int)($viewer['accesslevel'] ?? 0),
            'Changed username appearance for ' . (string)($target['username'] ?? ('user #' . (int)$target['id'])),
            'user_appearance'
        );
    }
    return $result;
}

/**
 * Usage: Build the admin user-appearance page model.
 * Referenced by: admin route act=user_appearance.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request GET values.
 * @param array<string, mixed> $post POST values.
 * @return array<string, mixed> Admin page model.
 */
function corebb_admin_user_appearance_model(array $viewer, array $request, array $post): array
{
    $model = corebb_admin_require_model_base($viewer, 'User Appearance', $request);
    $model['token'] = corebb_user_appearance_token();
    $model['mode'] = 'search';
    $model['search_value'] = trim((string)($request['user'] ?? $request['userid'] ?? $request['username'] ?? $post['user'] ?? ''));
    $model['user'] = null;
    $model['appearance'] = [];

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = (string)($post['action'] ?? '');
    $target = corebb_user_appearance_find_target($request, $post);

    if ($isPost && $action === 'save_appearance') {
        if (!$target) {
            $model['errors'][] = 'Unknown user.';
        } else {
            $result = corebb_admin_user_appearance_save($viewer, $target, $post);
            if (!empty($result['ok'])) {
                $model['messages'][] = (string)($result['message'] ?? 'Username appearance saved.');
                $target = corebb_user_appearance_load_user((int)$target['id']);
            } else {
                $model['errors'][] = (string)($result['message'] ?? 'Unable to save username appearance.');
            }
        }
    }

    if ($target) {
        $error = corebb_admin_user_appearance_target_error($viewer, $target);
        if ($error !== '') {
            $model['errors'][] = $error;
        } else {
            $model['mode'] = 'edit';
            $model['user'] = [
                'id' => (int)($target['id'] ?? 0),
                'username' => (string)($target['username'] ?? ''),
                'profile_url' => '/profile/' . (int)($target['id'] ?? 0) . '/',
            ];
            $model['appearance'] = corebb_user_appearance_form_model(
                $target,
                '/admin/?act=user_appearance',
                'admin'
            );
        }
    } elseif ($model['search_value'] !== '') {
        $model['errors'][] = 'No user with the requested name or ID exists.';
    }

    return $model;
}
