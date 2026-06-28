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
 |  moderator_view_model.php  - View-model/controller    |
 |  helpers for controllers/moderation.php.              |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';

/**
 * Build a public topic URL for moderator redirects and confirmation screens.
 *
 * Usage: build rewritten topic URLs for moderator confirmations.
 * Referenced by: corebb_moderator_normalize_redirect() and moderation action
 * view models.
 *
 * @param int $topicId Topic id to link.
 * @param int $boardId Optional board id for route helpers that need it.
 * @param int $postId Optional post anchor id.
 * @return string Public topic URL or site root when the id is invalid.
 */
function corebb_moderator_thread_url(int $topicId, int $boardId = 0, int $postId = 0): string
{
    if ($topicId <= 0) {
        return '/';
    }

    return corebb_thread_url($topicId, $boardId, 1, '', $postId);
}

/**
 * Build a public board URL for moderator redirects.
 *
 * Usage: build rewritten board URLs for moderator confirmations.
 * Referenced by: corebb_moderator_normalize_redirect().
 *
 * @param int $boardId Board id to link.
 * @return string Public board URL or site root when the id is invalid.
 */
function corebb_moderator_board_url(int $boardId): string
{
    if ($boardId <= 0) {
        return '/';
    }

    return corebb_board_url($boardId, 1);
}

/**
 * Build a public profile URL for moderator redirects.
 *
 * Usage: return moderators to the affected user profile after account actions.
 * Referenced by: corebb_moderator_normalize_redirect() and ban confirmations.
 *
 * @param int $userId User id to link.
 * @return string Public profile URL or site root when the id is invalid.
 */
function corebb_moderator_profile_url(int $userId): string
{
    return $userId > 0 ? '/profile/' . $userId . '/' : '/';
}

/**
 * Convert internal redirect targets into public rewritten URLs.
 *
 * Usage: sanitize operation-result redirects before handing them to Twig.
 * Referenced by: corebb_moderator_message().
 *
 * @param string $url Internal route target or public redirect URL.
 * @return string Public URL suitable for the rendered continue link.
 */
function corebb_moderator_normalize_redirect(string $url): string
{
    $url = trim($url);
    if ($url === '' || $url === 'index.php') {
        return '/';
    }

    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?|mailto:|tel:)~i', $url)) {
        return $url;
    }

    return corebb_public_join_base_path($url);
}

/**
 * Read the moderator action request from GET/POST input.
 *
 * Usage: provide a small typed request array for the moderation controller.
 * Referenced by: controllers/moderation.php before calling corebb_moderator_build().
 *
 * @return array{act: string, post_id: int, topic_id: int, user_id: int, confirmed: bool} Normalized request data.
 */
function corebb_moderator_request(): array
{
    return [
        'act' => (string)($_POST['act'] ?? $_GET['act'] ?? ''),
        'post_id' => (int)($_POST['post'] ?? $_GET['post'] ?? $_POST['id'] ?? $_GET['id'] ?? 0),
        'topic_id' => (int)($_POST['topic'] ?? $_GET['topic'] ?? 0),
        'user_id' => (int)($_POST['user'] ?? $_GET['user'] ?? 0),
        'confirmed' => (string)($_POST['confirm'] ?? '') === '1',
    ];
}

/**
 * Build a simple moderator message view model.
 *
 * Usage: return success, failure, and not-found states to the moderator Twig
 * template with a normalized continue URL.
 * Referenced by: corebb_moderator_build().
 *
 * @param string $title Message title.
 * @param string $message Body text to display.
 * @param string $continueUrl Optional redirect/continue target.
 * @return array{type: string, title: string, message: string, continue_url: string} Message view model.
 */
function corebb_moderator_message(string $title, string $message, string $continueUrl = ''): array
{
    return [
        'type' => 'message',
        'title' => $title,
        'message' => $message,
        'continue_url' => corebb_moderator_normalize_redirect($continueUrl),
    ];
}

/**
 * Build the moderator confirmation or result view model for one action.
 *
 * Usage: central controller switch for the moderation controller; performs confirmation
 * lookups and dispatches confirmed actions to moderation_helpers.php.
 * Referenced by: controllers/moderation.php.
 *
 * @param array{act: string, post_id: int, topic_id: int, user_id: int, confirmed: bool} $request Normalized request data.
 * @return array<string, mixed> Twig view model for a confirmation screen, IP check, or message.
 */
function corebb_moderator_build(array $request): array
{
    $act = $request['act'];
    $postId = (int)$request['post_id'];
    $topicId = (int)$request['topic_id'];
    $userId = (int)$request['user_id'];
    $confirmed = (bool)$request['confirmed'];

    if ($act === 'remove_post') {
        $post = corebb_mod_get_post($postId);
        if (!$post) {
            return corebb_moderator_message('Remove Post', 'Unknown post ID.');
        }
        if (!corebb_secure_archive_user_can_write_board_id((int)($post['boardid'] ?? 0), corebb_mod_actor_level())) {
            return corebb_moderator_message('Remove Post', corebb_secure_archive_denied_message(), corebb_moderator_thread_url((int)($post['threadid'] ?? 0), (int)($post['boardid'] ?? 0)));
        }
        if ($confirmed) {
            $result = corebb_mod_remove_post($postId);
            return corebb_moderator_message('Remove Post', (string)$result['message'], (string)$result['redirect']);
        }
        return [
            'type' => 'confirm_remove_post',
            'title' => 'Confirm Remove Post',
            'post_id' => $postId,
            'topic_id' => (int)($post['threadid'] ?? 0),
            'board_id' => (int)($post['boardid'] ?? 0),
            'cancel_url' => corebb_moderator_thread_url((int)($post['threadid'] ?? 0), (int)($post['boardid'] ?? 0)),
            'post_title' => (string)($post['title'] ?? ''),
            'preview' => mb_substr((string)($post['body'] ?? ''), 0, 600),
        ];
    }

    if ($act === 'lock_topic') {
        if ($topicId <= 0 && $postId > 0) {
            $post = corebb_mod_get_post($postId);
            $topicId = (int)($post['threadid'] ?? 0);
        }
        $topic = corebb_mod_get_topic($topicId);
        if (!$topic) {
            return corebb_moderator_message('Lock Topic', 'Unknown topic ID.');
        }
        if (!corebb_secure_archive_user_can_write_board_id((int)($topic['boardid'] ?? 0), corebb_mod_actor_level())) {
            return corebb_moderator_message('Lock Topic', corebb_secure_archive_denied_message(), corebb_moderator_thread_url($topicId, (int)($topic['boardid'] ?? 0)));
        }
        $currentlyLocked = (int)($topic['locked'] ?? 0) === 1;
        $newState = $currentlyLocked ? 0 : 1;
        $verb = $currentlyLocked ? 'Unlock' : 'Lock';

        if ($confirmed) {
            $result = corebb_mod_set_topic_locked($topicId, $newState);
            return corebb_moderator_message($newState ? 'Topic Locked' : 'Topic Unlocked', (string)$result['message'], (string)$result['redirect']);
        }
        return [
            'type' => 'confirm_lock_topic',
            'title' => 'Confirm ' . $verb . ' Topic',
            'topic_id' => $topicId,
            'board_id' => (int)($topic['boardid'] ?? 0),
            'cancel_url' => corebb_moderator_thread_url($topicId, (int)($topic['boardid'] ?? 0)),
            'topic_title' => (string)($topic['title'] ?? ''),
            'verb' => $verb,
            'new_state' => $newState,
        ];
    }

    if ($act === 'ban_user') {
        if ($userId <= 0 && $postId > 0) {
            $post = corebb_mod_get_post($postId);
            $userId = (int)($post['posterid'] ?? 0);
        }
        $target = corebb_mod_get_user($userId);
        if (!$target) {
            return corebb_moderator_message('Ban User', 'Unknown user ID.');
        }
        if ($confirmed) {
            $result = corebb_mod_ban_user($userId);
            return corebb_moderator_message('Ban User', (string)$result['message'], (string)$result['redirect']);
        }
        return [
            'type' => 'confirm_ban_user',
            'title' => 'Confirm Ban User',
            'user_id' => $userId,
            'username' => (string)($target['username'] ?? $userId),
            'cancel_url' => corebb_moderator_profile_url($userId),
        ];
    }

    if ($act === 'ipc') {
        $post = corebb_mod_get_post($postId);
        if (!$post) {
            return corebb_moderator_message('IP Check', 'Unknown post ID.');
        }
        $target = corebb_mod_get_user((int)($post['posterid'] ?? 0));
        return [
            'type' => 'ip_check',
            'title' => 'IP Check',
            'post_id' => $postId,
            'topic_id' => (int)($post['threadid'] ?? 0),
            'board_id' => (int)($post['boardid'] ?? 0),
            'topic_url' => corebb_moderator_thread_url((int)($post['threadid'] ?? 0), (int)($post['boardid'] ?? 0), $postId),
            'username' => (string)($target['username'] ?? $post['posterid'] ?? 'Unknown'),
            'post_ip' => (string)($post['postip'] ?? ''),
            'last_ip' => (string)($target['lastip'] ?? ''),
        ];
    }

    if ($act === 'notes') {
        return corebb_moderator_message('Moderator Notes', 'Moderator notes are not implemented yet.');
    }

    return corebb_moderator_message('Moderation', 'Unknown moderation action.');
}
