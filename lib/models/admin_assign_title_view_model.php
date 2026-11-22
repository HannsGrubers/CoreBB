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
 |  admin_assign_title_view_model.php  - Admin Assign    |
 |  Title tool.                                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';

require_once __DIR__ . '/../helpers/forum_access.php';

function corebb_assign_title_token(): string
{
    return corebb_security_named_token('admin_assign_title_token');
}

/** Store the same normalized board markup used for preview; the title column is VARCHAR(255). */
function corebb_assign_title_prepare_title(string $title): string
{
    $title = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", $title)));
    return corebb_prepare_post_data($title);
}

function corebb_admin_assign_title_model(array $viewer, array $request, array $post): array
{
    $model = ['messages'=>[], 'errors'=>[], 'selected_user'=>null, 'search_value'=>'', 'saved'=>false,
        'previewing'=>false, 'can_view_member'=>corebb_admin_can_access_tool($viewer, 'user_pages')];
    if (corebb_admin_viewer_id($viewer) <= 0 || !corebb_admin_can_access_tool($viewer, 'assign_title')) {
        $model['errors'][] = 'You do not have access to this tool.';
        return $model;
    }
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = $isPost ? ($post['action'] ?? $request['action'] ?? '') : '';
    if ($action !== '') {
        if (!in_array($action, ['preview_title','save_title','clear_title'], true)) {
            $model['errors'][] = 'Unknown title action.';
            return $model;
        }
        if (!corebb_security_csrf_valid($post) || !corebb_security_named_token_valid('admin_assign_title_token', $post, 'admin_assign_title_token')) {
            $model['errors'][] = 'Your form expired. Find the member again.';
            return $model;
        }
        $id = filter_var($post['userid'] ?? $post['uid'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $requestedId = $request['userid'] ?? $request['uid'] ?? (string)$id;
        if (!$id || !is_scalar($requestedId) || (string)$requestedId !== (string)$id) {
            $model['errors'][] = 'Find the member again before editing their title.';
            return $model;
        }
        $target = corebb_admin_find_user((string)$id);
        $model['search_value'] = (string)$id;
    } else {
        $name = $request['user'] ?? $request['username'] ?? $request['usr'] ?? $post['username'] ?? $post['usr'] ?? '';
        $id = $request['userid'] ?? $request['uid'] ?? $post['userid'] ?? $post['uid'] ?? '';
        $name = is_string($name) ? trim($name) : '';
        $id = is_scalar($id) ? trim((string)$id) : '';
        $model['search_value'] = $name ?: $id;
        if ($name === '' && $id === '') { return $model; }
        $target = ctype_digit($id) ? corebb_admin_find_user($id) : null;
        if (!$target && $name !== '') { $target = corebb_admin_find_user($name); }
    }
    if (!$target) {
        $model['errors'][] = 'No member found for that username or ID.';
        return $model;
    }
    $id = (int)$target['id'];
    $model['selected_user'] = corebb_admin_user_summary($target);
    $model['search_value'] = $target['username'];
    $model['current_title'] = (string)($target['title'] ?? '');
    $model['form_title'] = $model['current_title'];
    $model['member_title'] = corebb_forum_access_settings()['member_titles'] ? (string)($target['profiletitle'] ?? '') : '';
    $error = corebb_admin_target_content_error($viewer, $target);
    $model['can_edit'] = $error === '';
    if ($error !== '') {
        $model['errors'][] = $error;
        return $model;
    }
    $model['token'] = corebb_assign_title_token();
    $model['revision'] = hash('sha256', $model['current_title']);
    if ($action === '') { return $model; }
    if (isset($post['title_revision']) && $post['title_revision'] !== $model['revision']) {
        $model['errors'][] = 'The assigned title changed. Review the current title before continuing.';
        return $model;
    }
    $draft = $action === 'clear_title' ? '' : ($post['title'] ?? null);
    if (!is_string($draft)) {
        $model['errors'][] = 'Enter a title before continuing.';
        return $model;
    }
    $model['form_title'] = $draft;
    $title = corebb_assign_title_prepare_title($draft);
    if (!preg_match('/\A.{0,255}\z/us', $title)) {
        $model['errors'][] = 'Use up to 255 characters, including board codes.';
        return $model;
    }
    $model['form_title'] = $title;
    if ($action === 'preview_title') {
        $model['previewing'] = true;
        return $model;
    }
    if ($action === 'save_title' && $title === '' && $model['current_title'] !== '' && isset($post['title_revision'])) {
        $model['errors'][] = 'Use Clear assigned title to confirm removal.';
        return $model;
    }
    if ($title === $model['current_title']) {
        $model['messages'][] = 'No title changes to save.';
        return $model;
    }

    // Older installations may lack this column. Only provision it on an authorized save.
    if (!isset(corebb_admin_table_columns('users')['title'])
        && !db_run("ALTER TABLE users ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''")
        && !corebb_user_column_exists('title')) {
        $model['errors'][] = 'Could not prepare the assigned-title field.';
        return $model;
    }
    corebb_admin_tool_permissions_ensure_schema();
    if (!db_begin()) {
        $model['errors'][] = 'Could not start the title change.';
        return $model;
    }
    try {
        $actorId = corebb_admin_viewer_id($viewer);
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = db_all('SELECT id, username, accesslevel, title FROM users WHERE id IN (?, ?) ORDER BY id' . $lock, [$actorId, $id]);
        $accounts = array_column($rows, null, 'id');
        $actor = $accounts[$actorId] ?? [];
        $member = $accounts[$id] ?? null;
        corebb_admin_user_granted_tool_keys($actorId, true);
        if (!$actor || db_error() !== '' || !corebb_admin_can_access_tool($actor, 'assign_title')
            || corebb_admin_target_content_error($actor, $member) !== '') {
            $model['can_edit'] = false;
            $model['errors'][] = 'You can no longer change this title.';
            return $model;
        }
        if ((string)$member['title'] !== $model['current_title']) {
            $model['errors'][] = 'The assigned title changed during this save. Reopen the member before continuing.';
            return $model;
        }
        if (!db_run('UPDATE users SET title = ? WHERE id = ?', [$title,$id]) || !db_commit()) {
            $model['errors'][] = 'Could not save the title change. Please try again.';
            return $model;
        }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    $verb = $title === '' ? 'Cleared' : 'Assigned';
    corebb_adminlog_viewer($actor, $verb . ' title for ' . $member['username'] . ' (#' . $id . ')', 'assign_title');
    $model['messages'][] = 'Title ' . ($title === '' ? 'cleared' : 'saved') . ' for ' . $member['username'] . '.';
    $model['saved'] = true;
    return $model;
}
