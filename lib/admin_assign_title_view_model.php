<?php
require_once __DIR__ . '/admin_log_helpers.php';
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
 |  admin_assign_title_view_model.php  - Admin Assign    |
 |  Title tool.                                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';

/**
 * Usage: Quote and validate an identifier used by the title-assignment tool.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $identifier User, table, or column identifier.
 * @return string Normalized or display-ready string.
 */
function corebb_assign_title_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Normalized or display-ready string.
 */
function corebb_assign_title_limit_text(string $value, int $maxBytes): string
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
 * Usage: Check whether a title-assignment column exists before using it.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $column Database column name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_assign_title_column_exists(string $table, string $column): bool
{
    $tableSafe = corebb_assign_title_identifier($table);
    return db_exists("SHOW COLUMNS FROM {$tableSafe} LIKE ?", [$column]);
}

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_assign_title_ensure_schema(): void
{
    if (!corebb_assign_title_column_exists('users', 'title')) {
        db_run("ALTER TABLE `users` ADD COLUMN `title` varchar(255) NOT NULL DEFAULT ''");
    }
}

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_assign_title_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['admin_assign_title_token'])) {
        $_SESSION['admin_assign_title_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['admin_assign_title_token'];
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_assign_title_token_ok(array $post): bool
{
    $expected = corebb_assign_title_token();
    $got = (string)($post['admin_assign_title_token'] ?? '');
    return $got !== '' && hash_equals($expected, $got);
}

/**
 * Usage: Find the target user for the title-assignment form.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_assign_title_find_user(array $request, array $post): array
{
    $username = corebb_assign_title_limit_text(trim((string)($request['username'] ?? $request['usr'] ?? $post['username'] ?? $post['usr'] ?? '')), 255);
    $userId = corebb_assign_title_limit_text(trim((string)($request['userid'] ?? $request['uid'] ?? $post['userid'] ?? $post['uid'] ?? '')), 32);

    $selected = null;
    if ($userId !== '' && ctype_digit($userId)) {
        $selected = corebb_admin_find_user($userId);
    }
    if (!$selected && $username !== '') {
        $selected = corebb_admin_find_user($username);
    }

    return [$username, $userId, $selected];
}

/**
 * Usage: Clean an assigned title before previewing or saving it.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $title Title text.
 * @return string Normalized or display-ready string.
 */
function corebb_assign_title_prepare_title(string $title): string
{
    $title = str_replace(["\r\n", "\r"], "\n", $title);
    $title = trim(strip_tags($title));
    $title = corebb_prepare_post_data($title);
    return corebb_assign_title_limit_text($title, 255);
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_assign_title_user_summary(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    return [
        'id' => $userId,
        'username' => (string)($user['username'] ?? ''),
        'current_title' => (string)($user['title'] ?? ''),
        'posts' => number_format((int)($user['posts'] ?? 0)),
        'registered' => (string)($user['regdate'] ?? ''),
        'profile_url' => '/admin/?act=user_pages&userid=' . $userId,
    ];
}

/**
 * Usage: Build and process the assign title admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_assign_title_model(array $viewer, array $request, array $post): array
{
    corebb_assign_title_ensure_schema();

    $model = corebb_admin_require_model_base($viewer, 'Assign Title', $request);
    $model['token'] = corebb_assign_title_token();
    $model['search'] = ['username' => '', 'userid' => ''];
    $model['selected_user'] = null;
    $model['form_title'] = '';

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = (string)($post['action'] ?? $request['action'] ?? '');

    [$username, $userId, $selectedUser] = corebb_assign_title_find_user($request, $post);

    if ($isPost && in_array($action, ['save_title', 'clear_title'], true)) {
        if (!corebb_assign_title_token_ok($post)) {
            $model['errors'][] = 'Security token expired. Please reload the page and try again.';
        } elseif (!$selectedUser) {
            $model['errors'][] = 'No user selected.';
        } elseif ((int)($viewer['id'] ?? 0) <= 0) {
            $model['errors'][] = 'Unknown admin user.';
        } elseif (($targetError = corebb_admin_target_content_error($viewer, $selectedUser)) !== '') {
            $model['errors'][] = $targetError;
        } else {
            $targetId = (int)$selectedUser['id'];
            $newTitle = $action === 'clear_title' ? '' : corebb_assign_title_prepare_title((string)($post['title'] ?? ''));
            $ok = db_run('UPDATE users SET title = ? WHERE id = ?', [$newTitle, $targetId]);
            if ($ok) {
                $plainName = (string)($selectedUser['username'] ?? ('User #' . $targetId));
                $model['messages'][] = $action === 'clear_title'
                    ? 'Title cleared for ' . $plainName . '.'
                    : 'Title updated for ' . $plainName . '.';
                {
                    $description = $action === 'clear_title'
                        ? 'Cleared title for user: ' . $targetId
                        : 'Assigned title for user: ' . $targetId;
                    corebb_adminlog_entry(
                        (string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'),
                        (int)($viewer['accesslevel'] ?? 0),
                        $description,
                        'assign_title',
                        $description
                    );
                }
                $selectedUser = corebb_admin_find_user((string)$targetId);
            } else {
                $model['errors'][] = 'Error updating title: ' . db_error();
            }
        }
    }

    if ($selectedUser) {
        // Title writes allow self-edits while still protecting peers and seniors.
        $targetError = corebb_admin_target_content_error($viewer, $selectedUser);
        $model['selected_user'] = corebb_assign_title_user_summary($selectedUser);
        $model['selected_user']['can_edit_title'] = $targetError === '';
        if ($targetError !== '' && !in_array($targetError, $model['errors'], true)) {
            $model['errors'][] = $targetError;
        }
        $model['search'] = [
            'username' => (string)($selectedUser['username'] ?? $username),
            'userid' => (string)($selectedUser['id'] ?? $userId),
        ];
        $model['form_title'] = (string)($selectedUser['title'] ?? '');
    } else {
        $model['search'] = ['username' => $username, 'userid' => $userId];
        if ($username !== '' || $userId !== '') {
            $model['errors'][] = 'No user with the requested name or ID exists.';
        }
    }

    return $model;
}
