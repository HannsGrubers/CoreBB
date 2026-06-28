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
 * Usage: Find the target user for the profile editor.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_admin_edit_profile_find_user(array $request, array $post): ?array
{
    $identifier = trim((string)(
        $post['user']
        ?? $post['username']
        ?? $post['userid']
        ?? $request['user']
        ?? $request['username']
        ?? $request['userid']
        ?? $request['uid']
        ?? ''
    ));

    if ($identifier === '') {
        return null;
    }

    $identifier = corebb_admin_edit_profile_limit_text($identifier, 255);
    return corebb_admin_find_user($identifier);
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

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_edit_profile_user_summary(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $level = (int)($user['accesslevel'] ?? 0);

    return [
        'id' => $userId,
        'username' => (string)($user['username'] ?? ''),
        'profile_url' => '/profile/' . $userId . '/',
        'accesslevel' => $level,
        'level_name' => corebb_user_level_label($level),
        'posts' => number_format((int)($user['posts'] ?? 0)),
        'registered' => (string)($user['regdate'] ?? ''),
    ];
}

/**
 * Usage: Build and process the edit profile admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_edit_profile_model(array $viewer, array $request, array $post): array
{
    $model = corebb_admin_require_model_base($viewer, "Edit User's Profile", $request);
    $model['mode'] = 'search';
    $model['token'] = corebb_admin_edit_profile_token();
    $model['search_value'] = trim((string)($post['user'] ?? $post['username'] ?? $post['userid'] ?? $request['user'] ?? $request['username'] ?? $request['userid'] ?? ''));
    $model['fields'] = corebb_profile_fields();
    $model['user'] = null;
    $model['profile'] = [];

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $method = (string)($request['method'] ?? ($post['method'] ?? ''));

    if ($isPost && $method === 'save') {
        if (!corebb_admin_edit_profile_token_ok($post)) {
            $model['errors'][] = 'Security token expired. Please reload the page and try again.';
            return $model;
        }

        $targetId = (int)($post['userid'] ?? 0);
        $target = $targetId > 0 ? corebb_admin_find_user((string)$targetId) : null;
        $targetError = corebb_admin_edit_profile_target_error($viewer, $target);

        if ($targetError !== '') {
            $model['errors'][] = $targetError;
            return $model;
        }

        $ok = corebb_usercp_save_profile_from_array($targetId, $post);
        if ($ok) {
            {
                corebb_adminlog_entry(
                    (string)($viewer['username'] ?? 'Unknown'),
                    (int)($viewer['accesslevel'] ?? 0),
                    'Edited user profile: ' . $targetId,
                    'edit_profile',
                    'Edited user profile: ' . $targetId
                );
            }
            $model['messages'][] = 'Profile updated for ' . (string)($target['username'] ?? ('User #' . $targetId)) . '.';
        } else {
            $model['errors'][] = 'Error updating profile: ' . db_error();
        }

        $target = corebb_admin_find_user((string)$targetId);
        if ($target) {
            $model['mode'] = 'edit';
            $model['user'] = corebb_admin_edit_profile_user_summary($target);
            $model['profile'] = corebb_usercp_load_profile($targetId);
            $model['search_value'] = (string)($target['username'] ?? $targetId);
        }

        return $model;
    }

    if (($isPost && $method === 'view') || (!$isPost && ($model['search_value'] !== ''))) {
        $target = corebb_admin_edit_profile_find_user($request, $post);
        $targetError = corebb_admin_edit_profile_target_error($viewer, $target);
        if ($targetError !== '') {
            $model['errors'][] = $targetError;
            return $model;
        }

        $targetId = (int)($target['id'] ?? 0);
        $model['mode'] = 'edit';
        $model['user'] = corebb_admin_edit_profile_user_summary($target);
        $model['profile'] = corebb_usercp_load_profile($targetId);
        $model['search_value'] = (string)($target['username'] ?? $targetId);
    }

    return $model;
}
