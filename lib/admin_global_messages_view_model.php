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
 |  admin_global_messages_view_model.php  - Admin        |
 |  global-message models.                               |
 +-------------------------------------------------------+*/

if (!defined('COREBB_ADMIN_GLOBAL_MESSAGES_LOADED')) {
    define('COREBB_ADMIN_GLOBAL_MESSAGES_LOADED', true);
}

require_once __DIR__ . '/admin_helpers.php';

/**
 * Usage: Return all rows needed by this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_global_messages_all(): array
{
    $rows = [];
    foreach (db_all('SELECT id, message, poster FROM globalmessages ORDER BY id ASC') as $row) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'message' => (string)($row['message'] ?? ''),
            'poster' => (string)($row['poster'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * Usage: Build the shared page model for global message tools.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param string $mode Admin page mode.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_global_messages_base(array $viewer, string $mode): array
{
    return [
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'viewer' => $viewer,
        'mode' => $mode,
        'messages' => [],
        'global_messages' => corebb_admin_global_messages_all(),
    ];
}

/**
 * Usage: Build and process the global message add admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_global_message_add_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_global_messages_base($viewer, 'add');
    $method = (string)($get['method'] ?? '');
    $model['message_value'] = '';

    if ($method === 'post') {
        $message = trim((string)($post['message'] ?? ''));
        $model['message_value'] = $message;
        if ($message === '') {
            $model['messages'][] = 'Global message cannot be empty.';
        } elseif (strlen($message) > 255) {
            $model['messages'][] = 'Global message must be 255 characters or less.';
        } else {
            $poster = (string)($viewer['username'] ?? 'System');
            if (db_run('INSERT INTO globalmessages (message, poster) VALUES (?, ?)', [$message, $poster])) {
                {
                    corebb_adminlog_entry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), 'Added global message');
                }
                $model['messages'][] = 'Successfully created global message.';
                $model['message_value'] = '';
                $model['global_messages'] = corebb_admin_global_messages_all();
            } else {
                $model['messages'][] = 'Error creating global message: ' . db_error();
            }
        }
    }

    return $model;
}

/**
 * Usage: Build and process the global message edit admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_global_message_edit_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_global_messages_base($viewer, 'edit');
    $method = (string)($get['method'] ?? '');

    if ($method === 'post') {
        $id = (int)($post['id'] ?? 0);
        $message = trim((string)($post['message'] ?? ''));
        if ($id <= 0) {
            $model['messages'][] = 'Choose a message to edit.';
        } elseif ($message === '') {
            $model['messages'][] = 'Message text cannot be empty.';
        } elseif (strlen($message) > 255) {
            $model['messages'][] = 'Global message must be 255 characters or less.';
        } else {
            if (db_run('UPDATE globalmessages SET message = ? WHERE id = ?', [$message, $id])) {
                {
                    corebb_adminlog_entry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), 'Edited global message: ' . $id);
                }
                $model['messages'][] = 'Global message updated.';
            } else {
                $model['messages'][] = 'Error updating global message: ' . db_error();
            }
        }
        $model['global_messages'] = corebb_admin_global_messages_all();
    }

    return $model;
}

/**
 * Usage: Build and process the global message remove admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_global_message_remove_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_global_messages_base($viewer, 'remove');
    $method = (string)($get['method'] ?? '');

    if ($method === 'delete') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $model['messages'][] = 'Use the remove button to delete a global message.';
            return $model;
        }

        $id = (int)($post['id'] ?? 0);
        if ($id <= 0) {
            $model['messages'][] = 'Choose a message to remove.';
        } else {
            if (db_run('DELETE FROM globalmessages WHERE id = ?', [$id])) {
                {
                    corebb_adminlog_entry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), 'Removed global message: ' . $id);
                }
                $model['messages'][] = 'Global message removed.';
            } else {
                $model['messages'][] = 'Error removing global message: ' . db_error();
            }
        }
        $model['global_messages'] = corebb_admin_global_messages_all();
    }

    return $model;
}
