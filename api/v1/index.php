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
 |  index.php  - CoreBB API v1 front controller.         |
 +-------------------------------------------------------+*/

require_once dirname(__DIR__, 2) . '/lib/api/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/api/auth.php';
require_once dirname(__DIR__, 2) . '/lib/api/serializers.php';
require_once dirname(__DIR__, 2) . '/lib/auth_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/index_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/board_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/thread_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/profile_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/post_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/pm_view_model.php';
require_once dirname(__DIR__, 2) . '/lib/moderation_helpers.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    corebb_api_ok(['version' => 'v1']);
}

$path = trim((string)($_GET['path'] ?? ''), '/');
if ($path === '') {
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? trim($requestPath, '/') : '';
    if ($requestPath === 'api/v1/index.php') {
        $requestPath = 'api/v1';
    }
    if (str_starts_with($requestPath, 'api/v1')) {
        $path = trim(substr($requestPath, strlen('api/v1')), '/');
    }
}

$segments = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn($part): bool => $part !== ''));
$resource = (string)($segments[0] ?? '');
$rateResource = $resource === '' ? 'health' : $resource;
corebb_api_apply_rate_limit($rateResource);

// Route API requests through the same CoreBB models used by the classic pages.
if ($resource === 'auth') {
    $action = (string)($segments[1] ?? '');
    if ($action === 'csrf') {
        if ($method !== 'GET') {
            corebb_api_error('method_not_allowed', 'CSRF token retrieval uses GET.', 405);
        }
        corebb_api_ok([
            'csrfToken' => corebb_security_csrf_token(),
            'header' => 'X-CoreBB-CSRF',
            'field' => 'corebb_csrf_token',
            'limits' => corebb_api_boundary(),
        ]);
    }

    if (!in_array($action, ['login', 'logout', 'register'], true)) {
        corebb_api_error('not_found', 'Unknown auth endpoint.', 404);
    }
    if ($method !== 'POST') {
        corebb_api_error('method_not_allowed', 'This auth endpoint requires POST.', 405);
    }

    $data = corebb_api_request_data();
    corebb_api_require_csrf($data);

    if ($action === 'register') {
        $rate = corebb_rate_limit_registration_attempt();
        corebb_api_rate_limit_headers($rate);
        if (empty($rate['allowed'])) {
            corebb_api_error('registration_rate_limited', corebb_rate_limit_message($rate, 'registration attempts'), 429, [
                'retryAfter' => (int)($rate['retry_after'] ?? 60),
            ]);
        }

        $model = corebb_registration_model([
            'username' => (string)($data['username'] ?? ''),
            'email' => (string)($data['email'] ?? ''),
            'pass1' => (string)($data['password'] ?? $data['pass1'] ?? ''),
            'pass2' => (string)($data['passwordConfirm'] ?? $data['password_confirm'] ?? $data['pass2'] ?? ''),
            'agree_tos' => !empty($data['agreeTos']) || !empty($data['agree_tos']) ? '1' : '',
            'confirm_age_13' => !empty($data['confirmAge13']) || !empty($data['confirm_age_13']) ? '1' : '',
            'website' => (string)($data['website'] ?? ''),
        ], 'POST');
        $payload = corebb_api_registration_result($model) + ['limits' => corebb_api_boundary()];
        if (empty($model['success'])) {
            corebb_api_error('registration_failed', 'Registration could not be completed.', 400, $payload);
        }
        corebb_api_ok($payload, 201);
    }

    if ($action === 'login') {
        corebb_api_ok(corebb_api_auth_login($data) + ['limits' => corebb_api_boundary()]);
    }
    corebb_api_ok(corebb_api_auth_logout() + ['limits' => corebb_api_boundary()]);
}

if ($resource === 'post' && $method === 'POST') {
    $action = (string)($segments[1] ?? '');
    if (!in_array($action, ['reply', 'new', 'edit'], true)) {
        corebb_api_error('method_not_allowed', 'Only reply, new topic, and edit posting are enabled for API writes.', 405);
    }

    $viewer = corebb_api_viewer();
    if (!$viewer) {
        corebb_api_error('login_required', 'You must be logged in to post.', 401);
    }

    $data = corebb_api_request_data();
    corebb_api_require_csrf($data);

    // Build the form payload expected by corebb_post_process() so validation,
    // permissions, SQL writes, notifications, and polls stay in one code path.
    if ($action === 'edit') {
        $postId = (int)($segments[2] ?? $data['postId'] ?? $data['post_id'] ?? $data['threadid'] ?? $data['id'] ?? 0);
        $postPayload = [
            'from_edit' => '1',
            'threadid' => (string)$postId,
            'message_subject' => (string)($data['message_subject'] ?? $data['subject'] ?? ''),
            'message_body' => (string)($data['message_body'] ?? $data['body'] ?? ''),
            'post_image_upload_already_inserted' => (string)($data['post_image_upload_already_inserted'] ?? '0'),
        ];

        if (!empty($data['mod']) || !empty($data['from_mod'])) {
            $postPayload['from_mod'] = '1';
            $postPayload['sticky_control'] = '1';
            if (!empty($data['sticky']) || !empty($data['issticky'])) {
                $postPayload['issticky'] = 'checkbox';
            }
        }

        $result = corebb_post_process($postPayload, $viewer);
        $payload = corebb_api_post_result($result) + ['limits' => corebb_api_boundary()];
        if ((string)($result['status'] ?? '') !== 'success') {
            corebb_api_error('post_edit_failed', (string)($result['message'] ?? 'Unable to edit post.'), 400, $payload);
        }
        corebb_api_ok($payload);
    }

    if ($action === 'new') {
        $boardId = (int)($segments[2] ?? $data['boardId'] ?? $data['board_id'] ?? $data['boardtopost'] ?? $data['id'] ?? 0);
        $postPayload = [
            'from_post' => '1',
            'posttype' => 'new',
            'threadid' => '',
            'posttoboard' => '',
            'boardtopost' => (string)$boardId,
            'myuserid' => (string)($viewer['id'] ?? 0),
            'mycurrentposts' => (string)($viewer['posts'] ?? 0),
            'message_subject' => (string)($data['message_subject'] ?? $data['subject'] ?? ''),
            'message_body' => (string)($data['message_body'] ?? $data['body'] ?? ''),
            'post_image_upload_already_inserted' => (string)($data['post_image_upload_already_inserted'] ?? '0'),
        ];
        if (!empty($data['sticky']) || !empty($data['issticky'])) {
            $postPayload['issticky'] = 'checkbox';
        }

        $result = corebb_post_process($postPayload, $viewer);

        $payload = corebb_api_post_result($result) + ['limits' => corebb_api_boundary()];
        if ((string)($result['status'] ?? '') !== 'success') {
            corebb_api_error('post_new_failed', (string)($result['message'] ?? 'Unable to post topic.'), 400, $payload);
        }
        corebb_api_ok($payload, 201);
    }

    $threadId = (int)($segments[2] ?? $data['threadId'] ?? $data['thread_id'] ?? $data['threadid'] ?? $data['id'] ?? 0);
    $boardId = (int)($data['boardId'] ?? $data['board_id'] ?? $data['posttoboard'] ?? $data['brd'] ?? 0);
    if ($boardId <= 0) {
        $topic = corebb_post_fetch_topic($threadId);
        $boardId = (int)($topic['boardid'] ?? 0);
    }

    $subject = (string)($data['message_subject'] ?? $data['subject'] ?? '');
    if (trim($subject) === '' && $threadId > 0 && $boardId > 0) {
        $preflight = corebb_post_reply_form_model($threadId, $boardId, $viewer);
        $subject = (string)($preflight['subject'] ?? '');
    }

    $result = corebb_post_process([
        'from_post' => '1',
        'posttype' => 'reply',
        'threadid' => (string)$threadId,
        'posttoboard' => (string)$boardId,
        'boardtopost' => '',
        'myuserid' => (string)($viewer['id'] ?? 0),
        'mycurrentposts' => (string)($viewer['posts'] ?? 0),
        'message_subject' => $subject,
        'message_body' => (string)($data['message_body'] ?? $data['body'] ?? ''),
        'post_image_upload_already_inserted' => (string)($data['post_image_upload_already_inserted'] ?? '0'),
    ], $viewer);

    $payload = corebb_api_post_result($result) + ['limits' => corebb_api_boundary()];
    if ((string)($result['status'] ?? '') !== 'success') {
        corebb_api_error('post_reply_failed', (string)($result['message'] ?? 'Unable to post reply.'), 400, $payload);
    }
    corebb_api_ok($payload, 201);
}

if ($resource === 'pm' && $method === 'POST') {
    $action = (string)($segments[1] ?? '');
    if ($action !== 'send' && $action !== 'messages') {
        corebb_api_error('method_not_allowed', 'Only private-message send and mark-read are enabled for API writes.', 405);
    }

    $viewer = corebb_api_viewer();
    if (!$viewer) {
        corebb_api_error('login_required', 'You must be logged in to use private messages.', 401);
    }

    $data = corebb_api_request_data();
    corebb_api_require_csrf($data);

    // Normal users cannot delete private messages in CoreBB.
    if ($action === 'messages') {
        $pmId = (int)($segments[2] ?? $data['id'] ?? $data['messageId'] ?? $data['message_id'] ?? 0);
        $messageAction = (string)($segments[3] ?? $data['action'] ?? '');
        if ($messageAction !== 'read') {
            corebb_api_error('method_not_allowed', 'Only private-message mark-read is enabled for message actions.', 405);
        }

        $result = corebb_pm_mark_read($viewer, $pmId);
        $payload = corebb_api_pm_action_result($result) + ['limits' => corebb_api_boundary()];
        if (empty($result['ok'])) {
            corebb_api_error('pm_mark_read_failed', (string)($result['message'] ?? 'Unable to mark private message read.'), 400, $payload);
        }
        corebb_api_ok($payload);
    }

    $to = $data['to'] ?? $data['recipients'] ?? $data['user_name'] ?? '';
    if (is_array($to)) {
        $to = implode(',', array_map(static fn($value): string => (string)$value, $to));
    }

    $result = corebb_pm_send_from_post($viewer, [
        'from_send' => '1',
        'user_name' => (string)$to,
        'message_subject' => (string)($data['message_subject'] ?? $data['subject'] ?? ''),
        'message_body' => (string)($data['message_body'] ?? $data['body'] ?? ''),
    ]);

    $payload = corebb_api_pm_send_result($result) + ['limits' => corebb_api_boundary()];
    if (empty($result['ok'])) {
        corebb_api_error('pm_send_failed', (string)($result['message'] ?? 'Unable to send private message.'), 400, $payload);
    }
    corebb_api_ok($payload, 201);
}

if ($resource === 'mod' && $method === 'POST') {
    $section = (string)($segments[1] ?? '');
    $targetId = (int)($segments[2] ?? 0);
    $action = (string)($segments[3] ?? '');
    if (
        ($section !== 'topics' || !in_array($action, ['lock', 'unlock'], true))
        && ($section !== 'posts' || !in_array($action, ['remove', 'restore'], true))
        && ($section !== 'users' || !in_array($action, ['ban', 'unban'], true))
    ) {
        corebb_api_error('method_not_allowed', 'Only topic lock/unlock, post remove/restore, and user ban/unban are enabled for API moderation.', 405);
    }

    $viewer = corebb_api_viewer();
    if (!$viewer) {
        corebb_api_error('login_required', 'You must be logged in to moderate topics.', 401);
    }
    if (!corebb_mod_can_moderate()) {
        corebb_api_error('moderator_required', 'Moderator access is required.', 403);
    }

    $data = corebb_api_request_data();
    corebb_api_require_csrf($data);

    // Keep mobile moderation on the helper layer used by the classic tools.
    if ($section === 'posts') {
        $result = $action === 'restore'
            ? corebb_mod_restore_post($targetId)
            : corebb_mod_remove_post($targetId, (string)($data['reason'] ?? ''));
        $payload = corebb_api_mod_result($result) + ['limits' => corebb_api_boundary()];
        if (empty($result['ok'])) {
            corebb_api_error('post_moderation_failed', (string)($result['message'] ?? 'Unable to moderate post.'), 400, $payload);
        }
        corebb_api_ok($payload);
    }

    if ($section === 'users') {
        $result = $action === 'unban'
            ? corebb_mod_unban_user($targetId, (string)($data['note'] ?? $data['reason'] ?? ''))
            : corebb_mod_ban_user($targetId, (string)($data['reason'] ?? ''));
        $payload = corebb_api_mod_result($result) + ['limits' => corebb_api_boundary()];
        if (empty($result['ok'])) {
            corebb_api_error('user_moderation_failed', (string)($result['message'] ?? 'Unable to moderate user.'), 400, $payload);
        }
        corebb_api_ok($payload);
    }

    $result = corebb_mod_set_topic_locked($targetId, $action === 'lock' ? 1 : 0);
    $payload = corebb_api_mod_result($result) + ['limits' => corebb_api_boundary()];
    if (empty($result['ok'])) {
        corebb_api_error('topic_lock_failed', (string)($result['message'] ?? 'Unable to update topic lock state.'), 400, $payload);
    }
    corebb_api_ok($payload);
}

if ($resource === 'polls' && $method === 'POST') {
    $topicId = (int)($segments[1] ?? 0);
    $action = (string)($segments[2] ?? '');
    if ($action !== 'vote') {
        corebb_api_error('method_not_allowed', 'Only poll voting is enabled for API poll writes.', 405);
    }

    $viewer = corebb_api_viewer();
    if (!$viewer) {
        corebb_api_error('login_required', 'You must be logged in to vote in polls.', 401);
    }
    if (corebb_api_viewer_is_banned()) {
        corebb_api_error('banned', 'This account is banned.', 403);
    }

    $data = corebb_api_request_data();
    corebb_api_require_csrf($data);
    $optionId = (int)($data['optionId'] ?? $data['option_id'] ?? $data['optionid'] ?? 0);
    $userId = (int)($viewer['id'] ?? 0);
    $accessLevel = (int)($viewer['accesslevel'] ?? 0);

    // A vote is a write, so board visibility and Secure Archive read-only
    // checks are enforced before the poll helper records the ballot.
    $boardId = function_exists('corebb_topic_board_id') ? (int)corebb_topic_board_id($topicId) : 0;
    if ($boardId <= 0 || !corebb_private_user_can_view_board_id($boardId, $userId, $accessLevel)) {
        corebb_api_error('poll_not_found', 'Poll not found.', 404);
    }
    if (!corebb_secure_archive_user_can_write_board_id($boardId, $accessLevel)) {
        corebb_api_error('poll_archive_read_only', corebb_secure_archive_denied_message(), 403);
    }

    $result = corebb_poll_cast_vote($topicId, $optionId, $userId);
    $payload = corebb_api_poll_vote_result($result, $topicId) + ['limits' => corebb_api_boundary()];
    if (empty($result['ok'])) {
        corebb_api_error('poll_vote_failed', (string)($result['message'] ?? 'Unable to record poll vote.'), 400, $payload);
    }
    corebb_api_ok($payload);
}

if ($method !== 'GET') {
    corebb_api_error('method_not_allowed', 'This API endpoint does not support that method.', 405);
}

// Page caps are applied before loading board/thread result sets.
if ($resource === '' || $resource === 'health') {
    corebb_api_ok([
        'name' => 'CoreBB API',
        'version' => 'v1',
        'status' => 'ok',
        'limits' => corebb_api_boundary(),
    ]);
}

if ($resource === 'me') {
    corebb_api_ok(corebb_api_viewer_payload() + ['limits' => corebb_api_boundary()]);
}

if (corebb_api_viewer_is_banned()) {
    corebb_api_error('banned', 'This account is banned.', 403);
}

if ($resource === 'index') {
    $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? max(1, (int)$_GET['category_id']) : null;
    $showEmptyArchiveBoards = (string)($_GET['show_empty'] ?? '') === '1';
    corebb_api_ok(corebb_api_index(corebb_fetch_index_model($categoryId, $showEmptyArchiveBoards)) + ['limits' => corebb_api_boundary()]);
}

if ($resource === 'boards') {
    $boardId = (int)($segments[1] ?? ($_GET['id'] ?? 0));
    $page = corebb_api_limited_page($_GET);
    $model = corebb_board_fetch_model($boardId, $page);
    if (!empty($model['missing'])) {
        corebb_api_error('board_not_found', (string)($model['message'] ?? 'Board not found.'), 404);
    }
    corebb_api_ok(corebb_api_board($model) + ['limits' => corebb_api_boundary()]);
}

if ($resource === 'threads') {
    $threadId = (int)($segments[1] ?? ($_GET['id'] ?? 0));
    $page = corebb_api_limited_page($_GET);
    $model = corebb_thread_fetch_model($threadId, $page);
    if (!empty($model['missing'])) {
        corebb_api_error('thread_not_found', (string)($model['message'] ?? 'Thread not found.'), 404);
    }
    corebb_api_ok(corebb_api_thread($model) + ['limits' => corebb_api_boundary()]);
}

if ($resource === 'profiles') {
    $userId = (int)($segments[1] ?? ($_GET['id'] ?? 0));
    $model = corebb_profile_model($userId);
    if (empty($model['found'])) {
        corebb_api_error('profile_not_found', (string)($model['message'] ?? 'Profile not found.'), 404);
    }
    corebb_api_ok(corebb_api_profile($model) + ['limits' => corebb_api_boundary()]);
}

if ($resource === 'pm') {
    $viewer = corebb_api_viewer();
    if (!$viewer) {
        corebb_api_error('login_required', 'You must be logged in to view private messages.', 401);
    }

    $action = (string)($segments[1] ?? 'folders');
    if ($action === 'folders') {
        corebb_api_ok(corebb_api_pm_folders($viewer) + ['limits' => corebb_api_boundary()]);
    }

    if (in_array($action, ['inbox', 'unread', 'read', 'sent'], true)) {
        $folder = match ($action) {
            'sent' => 'sent',
            'read' => 'read',
            default => 'unread',
        };
        corebb_api_ok(corebb_api_pm_folder(corebb_pm_folder_model($viewer, $folder)) + ['limits' => corebb_api_boundary()]);
    }

    if ($action === 'messages') {
        $pmId = (int)($segments[2] ?? ($_GET['id'] ?? 0));
        $method = strtolower(trim((string)($_GET['folder'] ?? $_GET['method'] ?? '')));
        if (!in_array($method, ['sent', 'read', 'unread'], true)) {
            $method = 'read';
        }
        $viewMethod = $method === 'unread' ? 'read' : $method;
        $model = corebb_pm_view_model($viewer, $pmId, $viewMethod);
        if (!empty($model['missing']) && $viewMethod !== 'sent') {
            $model = corebb_pm_view_model($viewer, $pmId, 'sent');
        }
        if (!empty($model['missing'])) {
            corebb_api_error('pm_not_found', (string)($model['message'] ?? 'Private message not found.'), 404);
        }
        corebb_api_ok(corebb_api_pm_message($model) + ['limits' => corebb_api_boundary()]);
    }

    corebb_api_error('not_found', 'Unknown private-message endpoint.', 404);
}

if ($resource === 'post') {
    $action = (string)($segments[1] ?? '');
    if (!in_array($action, ['reply', 'new', 'edit'], true)) {
        corebb_api_error('not_found', 'Unknown post preflight endpoint.', 404);
    }

    $viewer = corebb_api_viewer();
    if (!$viewer) {
        corebb_api_error('login_required', 'You must be logged in to load post forms.', 401);
    }

    if ($action === 'reply') {
        $threadId = (int)($segments[2] ?? ($_GET['thread_id'] ?? $_GET['id'] ?? 0));
        $boardId = (int)($_GET['board_id'] ?? $_GET['brd'] ?? 0);
        if ($boardId <= 0) {
            $topic = corebb_post_fetch_topic($threadId);
            $boardId = (int)($topic['boardid'] ?? 0);
        }
        $quotePostId = (int)($_GET['quote_id'] ?? $_GET['quote'] ?? 0);
        $model = corebb_post_reply_form_model($threadId, $boardId, $viewer, $quotePostId);
        if (!empty($model['error'])) {
            corebb_api_error('post_preflight_denied', (string)$model['error'], 403);
        }
        corebb_api_ok(corebb_api_post_preflight($model, $action) + ['limits' => corebb_api_boundary()]);
    }

    if ($action === 'new') {
        $boardId = (int)($segments[2] ?? ($_GET['board_id'] ?? $_GET['id'] ?? 0));
        $pollMode = (string)($_GET['poll'] ?? '') !== '';
        $model = corebb_post_new_form_model($boardId, $viewer, $pollMode);
        if (!empty($model['error'])) {
            corebb_api_error('post_preflight_denied', (string)$model['error'], 403);
        }
        corebb_api_ok(corebb_api_post_preflight($model, $action) + ['limits' => corebb_api_boundary()]);
    }

    $postId = (int)($segments[2] ?? ($_GET['post_id'] ?? $_GET['id'] ?? 0));
    $model = corebb_post_edit_form_model($postId, $viewer);
    if (!empty($model['error'])) {
        corebb_api_error('post_preflight_denied', (string)$model['error'], 403);
    }
    corebb_api_ok(corebb_api_post_preflight($model, $action) + ['limits' => corebb_api_boundary()]);
}

corebb_api_error('not_found', 'Unknown API endpoint.', 404, [
    'available' => [
        corebb_api_public_url('/api/v1/health'),
        corebb_api_public_url('/api/v1/auth/csrf'),
        corebb_api_public_url('/api/v1/auth/register'),
        corebb_api_public_url('/api/v1/auth/login'),
        corebb_api_public_url('/api/v1/auth/logout'),
        corebb_api_public_url('/api/v1/me'),
        corebb_api_public_url('/api/v1/index'),
        corebb_api_public_url('/api/v1/boards/{id}'),
        corebb_api_public_url('/api/v1/threads/{id}'),
        corebb_api_public_url('/api/v1/profiles/{id}'),
        corebb_api_public_url('/api/v1/pm/folders'),
        corebb_api_public_url('/api/v1/pm/inbox'),
        corebb_api_public_url('/api/v1/pm/read'),
        corebb_api_public_url('/api/v1/pm/sent'),
        corebb_api_public_url('/api/v1/pm/messages/{id}?folder={inbox|read|sent}'),
        corebb_api_public_method_url('POST', '/api/v1/pm/send'),
        corebb_api_public_method_url('POST', '/api/v1/pm/messages/{id}/read'),
        corebb_api_public_method_url('POST', '/api/v1/mod/topics/{id}/lock'),
        corebb_api_public_method_url('POST', '/api/v1/mod/topics/{id}/unlock'),
        corebb_api_public_method_url('POST', '/api/v1/mod/posts/{id}/remove'),
        corebb_api_public_method_url('POST', '/api/v1/mod/posts/{id}/restore'),
        corebb_api_public_method_url('POST', '/api/v1/mod/users/{id}/ban'),
        corebb_api_public_method_url('POST', '/api/v1/mod/users/{id}/unban'),
        corebb_api_public_method_url('POST', '/api/v1/polls/{topicId}/vote'),
        corebb_api_public_url('/api/v1/post/reply/{threadId}?board_id={boardId}&quote_id={postId}'),
        corebb_api_public_method_url('POST', '/api/v1/post/reply'),
        corebb_api_public_url('/api/v1/post/new/{boardId}'),
        corebb_api_public_method_url('POST', '/api/v1/post/new'),
        corebb_api_public_url('/api/v1/post/edit/{postId}'),
        corebb_api_public_method_url('POST', '/api/v1/post/edit'),
    ],
]);
