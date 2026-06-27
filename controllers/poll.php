<?php
/*-------------------------------------------------------
 | poll.php - Poll action controller.
 |
 | Handles poll vote submissions behind the rewritten
 | /poll/vote route.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

require_once $root . '/lib/bootstrap.php';
require_once $root . '/lib/poll_helpers.php';
require_once $root . '/lib/private_board_helpers.php';

/**
 * Usage: Redirect back to the topic poll with a compact poll status code.
 * Referenced by: archive-denied and vote-result branches in this controller.
 *
 * @param string $redirect Topic URL, optionally with a #poll anchor.
 * @param string $code Poll message code for the topic page.
 * @return never
 */
function corebb_poll_vote_redirect(string $redirect, string $code): void
{
    $separator = str_contains($redirect, '?') ? '&' : '?';
    $hash = '';
    $hashPos = strpos($redirect, '#');
    if ($hashPos !== false) {
        $hash = substr($redirect, $hashPos);
        $redirect = substr($redirect, 0, $hashPos);
    }
    header('Location: ' . $redirect . $separator . 'pollmsg=' . rawurlencode($code) . $hash);
    exit;
}

$topicId = (int)($_POST['topicid'] ?? 0);
$optionId = (int)($_POST['optionid'] ?? 0);
$userId = (int)($userlogindata_a['id'] ?? 0);

if (!corebb_load_logged_in_user() || $userId <= 0) {
    header('Location: ' . corebb_public_join_base_path('/login/'));
    exit;
}

$voteBoardId = (int)corebb_topic_board_id($topicId);
if ($voteBoardId <= 0 || !corebb_private_user_can_view_board_id($voteBoardId, $userId, (int)($userlogindata_a['accesslevel'] ?? 0))) {
    header('Location: ' . corebb_public_join_base_path('/'));
    exit;
}

$boardName = (string)db_value('SELECT name FROM forums WHERE id = ? LIMIT 1', [$voteBoardId], '');
$redirect = corebb_thread_url($topicId, $voteBoardId, 1, $boardName) . '#poll';

if (!corebb_secure_archive_user_can_write_board_id($voteBoardId, (int)($userlogindata_a['accesslevel'] ?? 0))) {
    corebb_poll_vote_redirect($redirect, 'archive');
}

$result = corebb_poll_cast_vote($topicId, $optionId, $userId);
$code = 'invalid';
$message = strtolower((string)($result['message'] ?? ''));
if (!empty($result['ok'])) {
    $code = 'voted';
} elseif (str_contains($message, 'already voted')) {
    $code = 'already';
} elseif (str_contains($message, 'closed')) {
    $code = 'closed';
}

corebb_poll_vote_redirect($redirect, $code);
