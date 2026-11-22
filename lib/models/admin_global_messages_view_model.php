<?php

require_once __DIR__ . '/admin_user_tools_view_model.php';

function corebb_admin_global_messages_model(array $viewer, array $request, array $post): array
{
    $tools = corebb_admin_global_message_tools();
    $allowed = [];
    foreach ($tools as $action => $key) {
        $allowed[$action] = corebb_admin_can_access_tool($viewer, $key);
    }
    $model = [
        'allowed' => $allowed, 'global_messages' => [], 'messages' => [], 'errors' => [],
        'editing' => filter_var($request['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0,
        'adding' => ($request['act'] ?? '') === 'global_message', 'draft' => null,
    ];
    if (!in_array(true, $allowed, true)) {
        $model['errors'][] = 'You do not have access to global messages.';
        return $model;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $legacyAction = array_search($request['act'] ?? '', $tools, true);
        if ($legacyAction === false) {
            $action = $post['action'] ?? '';
        } else {
            $method = $legacyAction === 'remove' ? 'delete' : 'post';
            $action = ($request['method'] ?? '') === $method ? $legacyAction : '';
        }

        if (!is_string($action) || empty($allowed[$action])) {
            $model['errors'][] = 'You do not have permission to perform that action.';
        } else {
            $id = filter_var($post['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $text = is_string($post['message'] ?? null) ? trim($post['message']) : '';
            $model['editing'] = $action === 'edit' ? $id : 0;
            $model['adding'] = $action === 'add';
            $model['draft'] = $text;
            [$saved, $feedback] = corebb_admin_global_message_write($viewer, $action, $id, $text, $post['revision'] ?? '', $legacyAction !== false);
            $model[$saved ? 'messages' : 'errors'][] = $feedback;
        }
    }

    $rows = db_all('SELECT id, message, poster FROM globalmessages ORDER BY id ASC');
    if (db_error() !== '') {
        $model['errors'][] = 'Could not load the published messages.';
    }
    foreach ($rows as $row) {
        $row['revision'] = hash('sha256', (string)$row['message']);
        $model['global_messages'][] = $row;
    }
    return $model;
}

/** Called after the operation-specific grant and request method have been checked. */
function corebb_admin_global_message_write(array $viewer, string $action, int $id, string $text, $revision, bool $legacy): array
{
    if ($action !== 'remove' && ($text === '' || !preg_match('/\A.{1,255}\z/us', $text))) {
        return [false, 'Enter a message between 1 and 255 characters.'];
    }
    if ($action === 'add') {
        $stmt = db_prepare_statement('INSERT INTO globalmessages (message, poster) VALUES (?, ?)', [$text, $viewer['username']]);
        $audit = 'Added global message';
        $notice = 'Global message added.';
    } else {
        $row = $id ? db_one('SELECT message FROM globalmessages WHERE id = ?', [$id]) : false;
        if (!$row) {
            return [false, 'That global message could not be found.'];
        }
        $stored = (string)$row['message'];
        if (!$legacy && $revision !== hash('sha256', $stored)) {
            return [false, 'This message has changed. Review the current text before trying again.'];
        }
        if ($action === 'edit' && $text === $stored) {
            return [true, 'No changes to save.'];
        }
        // Compare the original bytes as well as the ID, even on case-insensitive databases.
        $where = " WHERE id = ? AND HEX(COALESCE(message, '')) = ?";
        $match = [$id, strtoupper(bin2hex($stored))];
        if ($action === 'edit') {
            $stmt = db_prepare_statement('UPDATE globalmessages SET message = ?' . $where, array_merge([$text], $match));
            $audit = 'Edited global message: ' . $id;
            $notice = 'Global message updated.';
        } elseif ($action === 'remove') {
            $stmt = db_prepare_statement('DELETE FROM globalmessages' . $where, $match);
            $audit = 'Removed global message: ' . $id;
            $notice = 'Global message removed.';
        } else {
            return [false, 'Unknown global message action.'];
        }
    }
    if (!$stmt) {
        return [false, 'Could not save the change. Please try again.'];
    }
    if ($stmt->rowCount() !== 1) {
        return [false, 'This message has changed. Review the list before trying again.'];
    }
    corebb_adminlog_viewer($viewer, $audit, 'global_message');
    return [true, $notice];
}
