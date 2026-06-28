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
 |  post_view_model.php  - Post/reply/edit/blog          |
 |  controller helpers.                                  |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/rate_limit_helpers.php';
require_once __DIR__ . '/../helpers/performance_helpers.php';
require_once __DIR__ . '/../helpers/blog_helpers.php';
require_once __DIR__ . '/../helpers/poll_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';
require_once __DIR__ . '/../helpers/notification_helpers.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';
require_once __DIR__ . '/../helpers/post_image_upload_helpers.php';

/**
 * Usage: Thin wrapper around db_one() for post-flow reads.
 * Referenced by: local fetch helpers in this file.
 */
function corebb_post_fetch_one(string $sql, array $params = []): array|false
{
    return db_one($sql, $params);
}

/**
 * Usage: Trim post inputs to byte limits before writing old varchar/text columns.
 * Referenced by: subject/body preparation and draft cookie handling.
 */
function corebb_post_limit_text(string $value, int $maxBytes): string
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
 * Usage: Load a board row with the category fields needed for access checks.
 * Referenced by: posting, editing, and form-model builders.
 */
function corebb_post_fetch_board(int $boardId): array|false
{
    if ($boardId <= 0) {
        return false;
    }

    return corebb_post_fetch_one(
        'SELECT f.id, f.name, f.description, f.edittimer, f.postcount, f.topiccount, f.lastpstdate, f.lastpstdatets,
                f.private, f.categoryid, b.private AS category_private, b.name AS category_name
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE f.id = ?
          LIMIT 1',
        [$boardId]
    );
}

/**
 * Usage: Load one live topic for reply validation and form defaults.
 * Referenced by: reply processing and reply-form construction.
 */
function corebb_post_fetch_topic(int $topicId): array|false
{
    if ($topicId <= 0) {
        return false;
    }

    return corebb_post_fetch_one(
        'SELECT id, boardid, title, body, posterid, lastpost, time, now, sticky, postcount, replycount, locked FROM topics WHERE id = ? AND is_deleted = 0 LIMIT 1',
        [$topicId]
    );
}

/**
 * Usage: Load the first visible post for reply subjects and quote context.
 * Referenced by: corebb_post_reply_form_model().
 */
function corebb_post_fetch_first_post(int $topicId): array|false
{
    if ($topicId <= 0) {
        return false;
    }

    return corebb_post_fetch_one(
        'SELECT id, posterid, title, body, author, threadid, boardid, ptd, posttime, posttimeraw, postip FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1',
        [$topicId]
    );
}

/**
 * Usage: Resolve a user id to a username while preserving legacy author fallback text.
 * Referenced by: reply-form quote headings.
 */
function corebb_post_username(int $userId, string $fallback = 'Unknown'): string
{
    $fallback = trim($fallback);
    if ($userId <= 0) {
        return $fallback !== '' ? $fallback : 'Unknown';
    }

    $row = corebb_post_fetch_one('SELECT username FROM users WHERE id = ? LIMIT 1', [$userId]);
    $username = trim((string)($row['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }

    return $fallback !== '' ? $fallback : 'Unknown';
}

/**
 * Usage: Build a topic URL for result links and form return links.
 * Referenced by: success links and reply form models.
 */
function corebb_post_thread_url(int $topicId, int $boardId): string
{
    if ($topicId <= 0) {
        return '#';
    }

    return corebb_thread_url($topicId, $boardId, 1);
}

/**
 * Usage: Build a board URL for post result links.
 * Referenced by: post process result links.
 */
function corebb_post_board_url(int $boardId, string $boardName = ''): string
{
    if ($boardId <= 0) {
        return '#';
    }

    return corebb_board_url($boardId, 1, $boardName);
}

/**
 * Usage: Return a consistent result model for post_result.twig.
 * Referenced by: all post processing branches.
 */
function corebb_post_result_model(string $status, string $message, array $links = []): array
{
    return [
        'status' => $status,
        'message' => $message,
        'links' => $links,
    ];
}

/**
 * Usage: Convert privileged image tags to normal image tags for regular users.
 * Referenced by: corebb_post_normalize_admin_image_tags_for_user().
 */
function corebb_post_downgrade_admin_image_tags(string $body): string
{
    if ($body === '' || stripos($body, '[a_image') === false) {
        return $body;
    }
    return preg_replace('~\[a_image(\s*=)~i', '[image$1', $body) ?? $body;
}

/**
 * Usage: Enforce image-tag privileges before storing or quoting post bodies.
 * Referenced by: upload preparation and quote generation.
 */
function corebb_post_normalize_admin_image_tags_for_user(string $body, array $user): string
{
    if (corebb_post_image_can_upload($user)) {
        return $body;
    }
    return corebb_post_downgrade_admin_image_tags($body);
}

/**
 * Usage: Normalize submitted post fields and fold upload fallback markup into the body.
 * Referenced by: edit, reply, and new-topic processing.
 */
function corebb_post_prepare_subject_body_with_upload(array $post, array $user): array
{
    $subject = corebb_post_limit_text(trim((string)($post['message_subject'] ?? '')), 100);
    $body = corebb_post_limit_text(corebb_prepare_post_data((string)($post['message_body'] ?? '')), 65535);
    $body = corebb_post_normalize_admin_image_tags_for_user($body, $user);
    $uploadedPath = '';

    $uploadAlreadyInserted = !empty($post['post_image_upload_already_inserted']);
    if (!$uploadAlreadyInserted && corebb_post_image_upload_present()) {
        $upload = corebb_post_image_handle_upload($user);
        if (empty($upload['ok'])) {
            return ['ok' => false, 'message' => (string)($upload['message'] ?? 'Post image upload failed.')];
        }

        $uploadedPath = (string)($upload['public_path'] ?? '');
        $bbcode = trim((string)($upload['bbcode'] ?? ''));
        if ($bbcode !== '') {
            $separator = trim($body) !== '' ? "

" : '';
            $reserve = strlen($separator . $bbcode);
            if ($reserve < 65535 && strlen($body) + $reserve > 65535) {
                $body = rtrim(corebb_post_limit_text($body, 65535 - $reserve));
                $separator = $body !== '' ? "

" : '';
            }
            $body = $body . $separator . $bbcode;
        }
    }

    if ($subject === '' && $body === '') {
        $subject = '-';
        $body = '(no message)';
    }
    if ($subject === '') {
        $subject = '-';
    }
    if ($body === '') {
        $body = '(no message)';
    }

    return ['ok' => true, 'subject' => $subject, 'body' => $body, 'uploaded_path' => $uploadedPath];
}

/**
 * Usage: Delete an uploaded image if a later database transaction fails.
 * Referenced by: edit, reply, and new-topic write branches.
 */
function corebb_post_cleanup_uploaded_image(string $publicPath): void
{
    if ($publicPath !== '') {
        corebb_post_image_delete_public_path($publicPath);
    }
}

/**
 * Usage: Provide upload capability metadata for the post form template.
 * Referenced by: edit/new/reply/blog form-model builders.
 */
function corebb_post_image_form_model(array $user): array
{
    return [
        'canUploadImage' => corebb_post_image_can_upload($user),
        'postImageMaxBytes' => COREBB_POST_IMAGE_MAX_BYTES,
        'postImageMaxWidth' => COREBB_POST_IMAGE_MAX_RENDER_WIDTH,
        'postImageMaxHeight' => COREBB_POST_IMAGE_MAX_RENDER_HEIGHT,
        'postImageUploadEndpoint' => '/post/image-upload/',
    ];
}

/**
 * Usage: Return the date formats required by legacy post/topic/forum columns.
 * Referenced by: reply and new-topic processing.
 */
function corebb_post_now_values(): array
{
    return [
        'vn_date' => convert_to_timestamp_raw(time()),
        'unix' => time(),
        'short_date' => date('m/d/y'),
    ];
}

/**
 * Usage: Process the blog-entry branch of post composer submissions.
 * Referenced by: corebb_post_process().
 */
function corebb_post_process_blog(array $post, array $user): array
{
    corebb_blog_ensure_schema();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return corebb_post_result_model('error', 'You must be logged in to post a blog entry.');
    }
    if (corebb_blog_is_locked($userId)) {
        return corebb_post_result_model('error', 'Your blog is currently closed. Open it before posting a new blog entry.', [
            ['href' => '/blogs/', 'text' => 'Open your blog from My Controls'],
        ]);
    }

    $subject = corebb_post_limit_text((string)($post['message_subject'] ?? ''), 255);
    $body = corebb_post_limit_text((string)($post['message_body'] ?? ''), 65535);
    $entryId = corebb_blog_insert_entry($userId, $subject, $body);
    if (!$entryId) {
        return corebb_post_result_model('error', 'Error posting blog entry: ' . db_error());
    }

    return corebb_post_result_model('success', "Your blog entry, '" . ($subject !== '' ? $subject : 'Untitled Blog Entry') . "', has been successfully posted.", [
        ['href' => '/blogs/entry/' . (int)$entryId . '/', 'text' => 'View it here'],
    ]);
}

/**
 * Usage: Process an edit submission, including moderator edit/sticky behavior.
 * Referenced by: corebb_post_process().
 */
function corebb_post_process_edit(array $post, array $user): array
{
    $postId = (int)($post['threadid'] ?? 0);
    if ($postId <= 0) {
        return corebb_post_result_model('error', 'Unknown message.');
    }

    $existing = corebb_mod_get_post($postId);
    if (!$existing) {
        return corebb_post_result_model('error', 'Unknown message.');
    }

    $board = corebb_post_fetch_board((int)($existing['boardid'] ?? 0));
    if (!$board || !corebb_private_user_can_view_board_row($board, (int)($user['id'] ?? 0), (int)($user['accesslevel'] ?? 0))) {
        return corebb_post_result_model('error', 'Unknown message.');
    }
    if (!corebb_secure_archive_user_can_write_board_row($board, (int)($user['accesslevel'] ?? 0))) {
        return corebb_post_result_model('error', corebb_secure_archive_denied_message());
    }
    $isModeratorEdit = corebb_mod_can_moderate() && (((string)($post['from_mod'] ?? '') === '1') || ((int)($existing['posterid'] ?? 0) !== (int)($user['id'] ?? 0)));

    if ((int)($existing['posterid'] ?? 0) !== (int)($user['id'] ?? 0) && !$isModeratorEdit) {
        return corebb_post_result_model('error', 'Unknown message.');
    }

    if (!$isModeratorEdit) {
        $boardTimer = (int)($board['edittimer'] ?? 0);
        $postTimerRaw = (int)($existing['posttimeraw'] ?? 0);
        if ($postTimerRaw <= 0) {
            return corebb_post_result_model('error', 'Sorry, you may not edit this post.');
        }
        $minutesOld = (int)((time() - $postTimerRaw) / 60);
        if ($minutesOld >= $boardTimer) {
            return corebb_post_result_model('error', 'Sorry, you may not edit this post.');
        }
    }

    $stickyChanged = false;
    $stickyControl = $isModeratorEdit && (string)($post['sticky_control'] ?? '') === '1';
    $desiredSticky = 0;
    $currentSticky = 0;
    $topicId = (int)($existing['threadid'] ?? 0);
    if ($stickyControl) {
        $topic = corebb_mod_get_topic($topicId);
        if (!$topic) {
            return corebb_post_result_model('error', 'Unknown topic.');
        }
        $desiredSticky = isset($post['issticky']) ? 1 : 0;
        $currentSticky = (int)($topic['sticky'] ?? 0) === 1 ? 1 : 0;
        $stickyChanged = ($desiredSticky !== $currentSticky);
    }

    $prepared = corebb_post_prepare_subject_body_with_upload($post, $user);
    if (empty($prepared['ok'])) {
        return corebb_post_result_model('error', (string)($prepared['message'] ?? 'Post image upload failed.'));
    }
    $subject = (string)$prepared['subject'];
    $body = (string)$prepared['body'];
    $uploadedImagePath = (string)($prepared['uploaded_path'] ?? '');
    $now = convert_to_timestamp_raw(time());

    if ($stickyChanged) {
        if (!db_begin()) {
            corebb_post_cleanup_uploaded_image($uploadedImagePath);
            return corebb_post_result_model('error', 'Error starting edit transaction: ' . db_error());
        }
    }

    $ok = corebb_mod_update_post_with_edit_metadata(
        $postId,
        $subject,
        $body,
        (int)($user['id'] ?? 0),
        $now
    );

    if (!$ok) {
        if ($stickyChanged) {
            db_rollback();
        }
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error updating post: ' . db_error());
    }

    if ($stickyChanged) {
        $stickyResult = corebb_mod_set_topic_sticky($topicId, $desiredSticky);
        if (empty($stickyResult['ok'])) {
            db_rollback();
            corebb_post_cleanup_uploaded_image($uploadedImagePath);
            return corebb_post_result_model('error', (string)($stickyResult['message'] ?? 'Error updating sticky state.'));
        }
        if (!db_commit()) {
            db_rollback();
            corebb_post_cleanup_uploaded_image($uploadedImagePath);
            return corebb_post_result_model('error', 'Error finalizing edit: ' . db_error());
        }
    }

    if ($isModeratorEdit) {
        corebb_mod_log('Moderator edited post ' . $postId);
        corebb_notifications_notify_moderated_post(
            $existing,
            'edited',
            (int)($user['id'] ?? 0),
            (string)($user['username'] ?? $user['displayname'] ?? 'A moderator')
        );
    }

    $boardName = (string)($board['name'] ?? 'the board');
    $topicId = (int)($existing['threadid'] ?? 0);
    return corebb_post_result_model('success', "Your message, '" . $subject . "', has been successfully updated on the " . $boardName . ' message board.', [
        ['href' => corebb_post_board_url((int)($board['id'] ?? 0), $boardName), 'text' => $boardName],
        ['href' => corebb_post_thread_url($topicId, (int)($board['id'] ?? 0)), 'text' => 'View your updated message'],
    ]);
}

/**
 * Usage: Insert a reply, update counters/activity, and send notifications.
 * Referenced by: corebb_post_process() and API write adapters.
 */
function corebb_post_process_reply(array $post, array $user): array
{
    $topicId = (int)($post['threadid'] ?? 0);
    $boardId = (int)($post['posttoboard'] ?? 0);
    $userId = (int)($user['id'] ?? 0);
    if ($topicId <= 0 || $boardId <= 0 || $userId <= 0) {
        return corebb_post_result_model('error', 'Missing topic or board information.');
    }

    $topic = corebb_post_fetch_topic($topicId);
    if (!$topic) {
        return corebb_post_result_model('error', 'Unknown topic.');
    }

    $topicBoardId = (int)($topic['boardid'] ?? 0);
    if ($topicBoardId <= 0 || ($boardId > 0 && $boardId !== $topicBoardId)) {
        return corebb_post_result_model('error', 'Invalid topic or board information.');
    }
    $boardId = $topicBoardId;
    if (!corebb_private_user_can_view_board_id($boardId, $userId, (int)($user['accesslevel'] ?? 0))) {
        return corebb_post_result_model('error', 'Unknown topic.');
    }
    if (!corebb_secure_archive_user_can_write_board_id($boardId, (int)($user['accesslevel'] ?? 0))) {
        return corebb_post_result_model('error', corebb_secure_archive_denied_message());
    }

    if (((int)($topic['locked'] ?? 0) === 1) && !corebb_mod_can_moderate()) {
        return corebb_post_result_model('error', 'This topic is locked. You cannot reply to it.');
    }

    $prepared = corebb_post_prepare_subject_body_with_upload($post, $user);
    if (empty($prepared['ok'])) {
        return corebb_post_result_model('error', (string)($prepared['message'] ?? 'Post image upload failed.'));
    }
    $subject = (string)$prepared['subject'];
    $body = (string)$prepared['body'];
    $uploadedImagePath = (string)($prepared['uploaded_path'] ?? '');
    $now = corebb_post_now_values();
    $author = (string)($user['username'] ?? $user['displayname'] ?? '');
    $ip = corebb_mod_current_ip();

    if (!db_begin()) {
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error starting post transaction: ' . db_error());
    }

    $ok = db_run(
        'INSERT INTO posts (posterid, title, body, author, threadid, boardid, ptd, posttime, posttimeraw, postip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $subject, $body, $author, $topicId, $boardId, $now['short_date'], $now['vn_date'], $now['unix'], $ip]
    );
    if (!$ok) {
        db_rollback();
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error posting reply: ' . db_error());
    }
    $newPostId = db_insert_id();

    $updatesOk = db_run('UPDATE forums SET lastpstdate = ?, lastpstdatets = ? WHERE id = ?', [$now['vn_date'], $now['unix'], $boardId])
        && db_run('UPDATE users SET posts = COALESCE(posts, 0) + 1, lastpost = ?, lastpstdate = ? WHERE id = ?', [$now['unix'], $now['vn_date'], $userId])
        && db_run('UPDATE topics SET lastpost = ?, now = ? WHERE id = ?', [$now['vn_date'], $now['unix'], $topicId]);
    if ($updatesOk && corebb_perf_cache_ready()) {
        $updatesOk = db_run('UPDATE topics SET postcount = COALESCE(postcount, 0) + 1, replycount = COALESCE(replycount, 0) + 1 WHERE id = ?', [$topicId])
            && db_run('UPDATE forums SET postcount = COALESCE(postcount, 0) + 1 WHERE id = ?', [$boardId]);
    }
    if (!$updatesOk || !db_commit()) {
        db_rollback();
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error finalizing reply: ' . db_error());
    }

    $board = corebb_post_fetch_board($boardId) ?: [];
    $boardName = (string)($board['name'] ?? 'the board');

    $topicOwnerId = (int)($topic['posterid'] ?? 0);
    $topicTitle = trim((string)($topic['title'] ?? ''));
    if ($topicTitle === '') {
        $topicTitle = $subject;
    }
    if ($topicOwnerId > 0 && $topicOwnerId !== $userId) {
        $targetUrl = corebb_notifications_reply_target_url($topicId, $boardId, (int)$newPostId, $boardName);
        $bodyText = $author . ' replied to your topic "' . $topicTitle . '".';
        corebb_notifications_add($topicOwnerId, 'topic_reply', 'New reply to your topic', $bodyText, $targetUrl, $userId, 'topic', $topicId);
    }

    corebb_notifications_notify_post_mentions(
        $userId,
        $author,
        (int)($user['accesslevel'] ?? 0),
        $boardId,
        $topicId,
        (int)$newPostId,
        $topicTitle,
        $body,
        $boardName
    );

    $kind = 'message';
    return corebb_post_result_model('success', "Your " . $kind . ", '" . $subject . "', has been successfully posted to the " . $boardName . ' message board.', [
        ['href' => corebb_post_board_url($boardId, $boardName), 'text' => $boardName],
        ['href' => corebb_post_thread_url($topicId, $boardId), 'text' => 'View your newly posted message'],
    ]);
}

/**
 * Usage: Insert a new topic, first post, optional poll, counters, and mentions.
 * Referenced by: corebb_post_process() and API write adapters.
 */
function corebb_post_process_new_topic(array $post, array $user): array
{
    $boardId = (int)($post['boardtopost'] ?? 0);
    $userId = (int)($user['id'] ?? 0);
    if ($boardId <= 0 || $userId <= 0) {
        return corebb_post_result_model('error', 'Missing board information.');
    }

    $board = corebb_post_fetch_board($boardId);
    if (!$board || !corebb_private_user_can_view_board_row($board, (int)($user['id'] ?? 0), (int)($user['accesslevel'] ?? 0))) {
        return corebb_post_result_model('error', 'Unknown board.');
    }
    if (!corebb_secure_archive_user_can_write_board_row($board, (int)($user['accesslevel'] ?? 0))) {
        return corebb_post_result_model('error', corebb_secure_archive_denied_message());
    }

    $pollPayload = corebb_poll_payload_from_post($post);
    if (!empty($pollPayload['error'])) {
        return corebb_post_result_model('error', (string)$pollPayload['error']);
    }
    if (!empty($pollPayload['enabled'])) {
        corebb_poll_ensure_schema();
    }

    $prepared = corebb_post_prepare_subject_body_with_upload($post, $user);
    if (empty($prepared['ok'])) {
        return corebb_post_result_model('error', (string)($prepared['message'] ?? 'Post image upload failed.'));
    }
    $subject = (string)$prepared['subject'];
    $body = (string)$prepared['body'];
    $uploadedImagePath = (string)($prepared['uploaded_path'] ?? '');

    $now = corebb_post_now_values();
    $sticky = (isset($post['issticky']) && (int)($user['accesslevel'] ?? 0) >= 4) ? 1 : 0;
    $author = (string)($user['username'] ?? $user['displayname'] ?? '');
    $ip = corebb_mod_current_ip();

    if (!db_begin()) {
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error starting topic transaction: ' . db_error());
    }

    $ok = db_run(
        'INSERT INTO topics (boardid, title, body, posterid, lastpost, time, sticky) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$boardId, $subject, $body, $userId, $now['vn_date'], $now['unix'], $sticky]
    );
    if (!$ok) {
        db_rollback();
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error creating topic: ' . db_error());
    }
    $topicId = db_insert_id();

    $ok = db_run(
        'INSERT INTO posts (posterid, title, body, author, threadid, boardid, ptd, posttime, posttimeraw, postip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $subject, $body, $author, $topicId, $boardId, $now['short_date'], $now['vn_date'], $now['unix'], $ip]
    );
    if (!$ok) {
        db_rollback();
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Topic was created, but the first post failed: ' . db_error());
    }
    $firstPostId = db_insert_id();

    if (!empty($pollPayload['enabled'])) {
        $pollResult = corebb_poll_create_for_topic($topicId, $userId, (string)$pollPayload['question'], (array)$pollPayload['options']);
        if (empty($pollResult['ok'])) {
            db_rollback();
            corebb_post_cleanup_uploaded_image($uploadedImagePath);
            return corebb_post_result_model('error', (string)($pollResult['message'] ?? 'Error creating poll.'));
        }
    }

    $updatesOk = db_run('UPDATE forums SET lastpstdate = ?, lastpstdatets = ? WHERE id = ?', [$now['vn_date'], $now['unix'], $boardId])
        && db_run('UPDATE topics SET lastpost = ?, now = ?, postcount = 1, replycount = 0 WHERE id = ?', [$now['vn_date'], $now['unix'], $topicId])
        && db_run('UPDATE users SET posts = COALESCE(posts, 0) + 1, lastpost = ?, lastpstdate = ? WHERE id = ?', [$now['unix'], $now['vn_date'], $userId]);
    if ($updatesOk && corebb_perf_cache_ready()) {
        $updatesOk = db_run('UPDATE forums SET topiccount = COALESCE(topiccount, 0) + 1, postcount = COALESCE(postcount, 0) + 1 WHERE id = ?', [$boardId]);
    }
    if (!$updatesOk || !db_commit()) {
        db_rollback();
        corebb_post_cleanup_uploaded_image($uploadedImagePath);
        return corebb_post_result_model('error', 'Error finalizing topic: ' . db_error());
    }

    $board = corebb_post_fetch_board($boardId) ?: [];
    $boardName = (string)($board['name'] ?? 'the board');
    corebb_notifications_notify_post_mentions(
        $userId,
        $author,
        (int)($user['accesslevel'] ?? 0),
        $boardId,
        $topicId,
        (int)$firstPostId,
        $subject,
        $body,
        $boardName
    );
    return corebb_post_result_model('success', "Your message, '" . $subject . "', has been successfully posted to the " . $boardName . ' message board.', [
        ['href' => corebb_post_board_url($boardId, $boardName), 'text' => $boardName],
        ['href' => corebb_post_thread_url($topicId, $boardId), 'text' => 'View your newly posted message'],
    ]);
}

/**
 * Usage: Dispatch a submitted post form to edit, blog, reply, or new-topic handling.
 * Referenced by: controllers/post.php and API v1 post endpoints.
 */
function corebb_post_process(array $post, array $user): array
{
    if (($post['from_edit'] ?? '') !== '') {
        return corebb_post_process_edit($post, $user);
    }

    if (($post['from_post'] ?? '') !== '') {
        $rate = corebb_rate_limit_post_write($user);
        if (empty($rate['allowed'])) {
            return corebb_post_result_model('error', corebb_rate_limit_message($rate, 'posting'));
        }

        $type = (string)($post['posttype'] ?? '');
        if ($type === 'blog') {
            return corebb_post_process_blog($post, $user);
        }
        if ($type === 'reply') {
            return corebb_post_process_reply($post, $user);
        }
        if ($type === 'new') {
            return corebb_post_process_new_topic($post, $user);
        }
    }

    return corebb_post_result_model('error', 'Unknown post action.');
}

/**
 * Usage: Build the post edit form model after permission and timer checks.
 * Referenced by: corebb_post_form_model().
 */
function corebb_post_edit_form_model(int $postId, array $user): array
{
    $post = corebb_mod_get_post($postId);
    if (!$post) {
        return ['error' => 'Unknown message.'];
    }

    $isModeratorEdit = corebb_mod_can_moderate() && (((string)($_GET['mod'] ?? '') === '1') || ((int)($post['posterid'] ?? 0) !== (int)($user['id'] ?? 0)));
    if ((int)($post['posterid'] ?? 0) !== (int)($user['id'] ?? 0) && !$isModeratorEdit) {
        return ['error' => 'Unknown message.'];
    }

    $board = corebb_post_fetch_board((int)($post['boardid'] ?? 0)) ?: [];
    if (!$board || !corebb_private_user_can_view_board_row($board, (int)($user['id'] ?? 0), (int)($user['accesslevel'] ?? 0))) {
        return ['error' => 'Unknown message.'];
    }
    if (!corebb_secure_archive_user_can_write_board_row($board, (int)($user['accesslevel'] ?? 0))) {
        return ['error' => corebb_secure_archive_denied_message()];
    }
    $topic = corebb_mod_get_topic((int)($post['threadid'] ?? 0)) ?: [];
    $boardTimer = (int)($board['edittimer'] ?? 0);
    $timeMessage = 'Moderator Edit: no time limit applies.';

    if (!$isModeratorEdit) {
        $postTimerRaw = (int)($post['posttimeraw'] ?? 0);
        if ($postTimerRaw <= 0) {
            return ['error' => 'Sorry, you may not edit this post.'];
        }
        $minutesOld = (int)((time() - $postTimerRaw) / 60);
        if ($minutesOld >= $boardTimer) {
            return ['error' => 'Sorry, you may not edit this post.'];
        }
        $timeLeft = max(0, $boardTimer - $minutesOld);
        $timeMessage = "You have {$timeLeft} minutes left to edit your message. This board allows users {$boardTimer} minutes to edit a message.";
    }

    return [
        'mode' => 'edit',
        'subject' => (string)($post['title'] ?? ''),
        'body' => (string)($post['body'] ?? ''),
        'timeMessage' => $timeMessage,
        'isModeratorEdit' => $isModeratorEdit,
        'showSticky' => $isModeratorEdit && !empty($topic),
        'stickyChecked' => ((int)($topic['sticky'] ?? 0) === 1),
        'stickyControlLabel' => 'Moderator',
        'stickyText' => 'Sticky Topic:',
        'submitLabel' => 'Edit Topic',
        'hidden' => [
            'threadid' => (string)$postId,
            'from_edit' => '1',
            'from_mod' => $isModeratorEdit ? '1' : '',
            'sticky_control' => $isModeratorEdit && !empty($topic) ? '1' : '',
        ],
    ] + corebb_post_image_form_model($user);
}

/**
 * Usage: Build the "new blog entry" post form model.
 * Referenced by: corebb_post_form_model().
 */
function corebb_post_blog_form_model(array $user): array
{
    corebb_blog_ensure_schema();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return ['error' => 'You must be logged in to post a blog entry.'];
    }
    if (corebb_blog_is_locked($userId)) {
        return ['error' => 'Your blog is currently closed. Open it before posting a new blog entry.', 'errorLink' => ['href' => '/blogs/', 'text' => 'Open your blog from My Controls']];
    }

    return [
        'mode' => 'blog',
        'subject' => '',
        'body' => '',
        'submitLabel' => 'Post Blog Entry',
        'notes' => 'By pressing the POST button below, you are agreeing to the blog posting rules and site TOS.',
        'hidden' => [
            'posttype' => 'blog',
            'from_post' => '1',
        ],
    ];
}


/**
 * Usage: Generate safe BBCode quote text for a reply textarea.
 * Referenced by: corebb_post_reply_form_model().
 */
function corebb_post_make_quote_markup(int $topicId, int $quotePostId, array $quotingUser = []): string
{
    if ($topicId <= 0 || $quotePostId <= 0) {
        return '';
    }

    $quotePost = corebb_post_fetch_one(
        'SELECT p.id, p.posterid, p.title, p.body, p.author, p.threadid, p.boardid, u.username AS quote_username FROM posts p LEFT JOIN users u ON u.id = p.posterid WHERE p.id = ? AND p.threadid = ? AND p.is_deleted = 0 LIMIT 1',
        [$quotePostId, $topicId]
    );
    if (!$quotePost || !corebb_private_user_can_view_board_id((int)($quotePost['boardid'] ?? 0))) {
        return '';
    }

    $author = trim((string)($quotePost['quote_username'] ?? ''));
    if ($author === '') {
        $author = trim((string)($quotePost['author'] ?? ''));
    }
    if ($author === '') {
        $author = 'Unknown';
    }

    // Usernames should not contain brackets, but keep the generated BBCode tidy
    // even if imported legacy data has odd characters.
    $author = str_replace([']', '[', "
", "
"], '', $author);
    $body = trim((string)($quotePost['body'] ?? ''));
    $body = corebb_post_normalize_admin_image_tags_for_user($body, $quotingUser);
    if ($body === '') {
        return '';
    }

    return '[quote=' . $author . ']' . $body . "[/quote]

";
}

/**
 * Usage: Build the new-topic or new-poll form model.
 * Referenced by: corebb_post_form_model().
 */
function corebb_post_new_form_model(int $boardId, array $user, bool $pollMode = false): array
{
    if ($boardId <= 0) {
        return ['error' => 'Unknown board.'];
    }
    $board = corebb_post_fetch_board($boardId);
    if (!$board || !corebb_private_user_can_view_board_row($board, (int)($user['id'] ?? 0), (int)($user['accesslevel'] ?? 0))) {
        return ['error' => 'Unknown board.'];
    }
    if (!corebb_secure_archive_user_can_write_board_row($board, (int)($user['accesslevel'] ?? 0))) {
        return ['error' => corebb_secure_archive_denied_message()];
    }

    $cookieBody = '';
    if (isset($_COOKIE['MyCookie'])) {
        $cookieBody = corebb_post_limit_text((string)$_COOKIE['MyCookie'], 65535);
        setcookie('MyCookie', '', 1, '/');
    }

    return [
        'mode' => 'new',
        'board' => $board,
        'subject' => '',
        'body' => $cookieBody,
        'showSticky' => ((int)($user['accesslevel'] ?? 0) >= 4),
        'showPollControls' => $pollMode,
        'pollChecked' => $pollMode,
        'pollQuestion' => '',
        'pollOptions' => array_fill(0, 10, ''),
        'submitLabel' => $pollMode ? 'Post Poll' : 'Post New Topic',
        'hidden' => [
            'boardtopost' => (string)$boardId,
            'posttype' => 'new',
            'threadid' => '',
            'myuserid' => (string)($user['id'] ?? 0),
            'mycurrentposts' => (string)($user['posts'] ?? 0),
            'posttoboard' => '',
            'from_post' => '1',
        ],
    ] + corebb_post_image_form_model($user);
}

/**
 * Usage: Build the reply form model, including optional quote prefill.
 * Referenced by: corebb_post_form_model().
 */
function corebb_post_reply_form_model(int $topicId, int $boardId, array $user, int $quotePostId = 0): array
{
    if ($topicId <= 0 || $boardId <= 0) {
        return ['error' => 'Unknown topic.'];
    }
    if (corebb_mod_topic_is_locked($topicId) && !corebb_mod_can_moderate()) {
        return ['error' => 'This topic is locked. You cannot reply to it.'];
    }

    $topic = corebb_post_fetch_topic($topicId);
    $firstPost = corebb_post_fetch_first_post($topicId);
    if (!$topic && !$firstPost) {
        return ['error' => 'Unknown topic.'];
    }

    $topicBoardId = (int)($topic['boardid'] ?? $firstPost['boardid'] ?? 0);
    if ($topicBoardId <= 0 || ($boardId > 0 && $boardId !== $topicBoardId)) {
        return ['error' => 'Invalid topic or board information.'];
    }
    $boardId = $topicBoardId;
    if (!corebb_private_user_can_view_board_id($boardId, (int)($user['id'] ?? 0), (int)($user['accesslevel'] ?? 0))) {
        return ['error' => 'Unknown topic.'];
    }
    if (!corebb_secure_archive_user_can_write_board_id($boardId, (int)($user['accesslevel'] ?? 0))) {
        return ['error' => corebb_secure_archive_denied_message()];
    }

    $title = (string)($firstPost['title'] ?? $topic['title'] ?? 'Untitled Topic');
    $subject = (strtoupper(substr($title, 0, 3)) === 'RE:') ? $title : 'RE:' . $title;
    $posterId = (int)($firstPost['posterid'] ?? $topic['posterid'] ?? 0);
    $posterName = corebb_post_username($posterId, (string)($firstPost['author'] ?? 'Unknown'));

    $cookieBody = '';
    if (isset($_COOKIE['MyCookie'])) {
        $cookieBody = corebb_post_limit_text((string)$_COOKIE['MyCookie'], 65535);
        setcookie('MyCookie', '', 1, '/');
    }

    $prefillBody = $cookieBody;
    $quoteMarkup = corebb_post_make_quote_markup($topicId, $quotePostId, $user);
    if ($quoteMarkup !== '') {
        $prefillBody = $quoteMarkup . $cookieBody;
    }

    return [
        'mode' => 'reply',
        'topic' => $topic ?: [],
        'firstPost' => $firstPost ?: [],
        'replyTitle' => $title,
        'replyThreadUrl' => corebb_post_thread_url($topicId, $boardId),
        'replyPosterName' => $posterName,
        'posterId' => $posterId,
        'subject' => $subject,
        'body' => $prefillBody,
        'submitLabel' => 'Post Reply',
        'hidden' => [
            'boardtopost' => '',
            'posttype' => 'reply',
            'threadid' => (string)$topicId,
            'myuserid' => (string)($user['id'] ?? 0),
            'mycurrentposts' => (string)($user['posts'] ?? 0),
            'posttoboard' => (string)$boardId,
            'from_post' => '1',
        ],
    ] + corebb_post_image_form_model($user);
}

/**
 * Usage: Route GET parameters to the correct post form model.
 * Referenced by: controllers/post.php when rendering form pages.
 */
function corebb_post_form_model(array $get, array $user): array
{
    if (isset($get['edit']) && (string)$get['edit'] !== '') {
        return corebb_post_edit_form_model((int)$get['edit'], $user);
    }

    $act = (string)($get['act'] ?? '');
    if ($act === 'blog') {
        return corebb_post_blog_form_model($user);
    }
    if ($act === 'new') {
        return corebb_post_new_form_model((int)($get['boardid'] ?? 0), $user, (string)($get['poll'] ?? '') !== '');
    }
    if ($act === 'reply') {
        return corebb_post_reply_form_model((int)($get['id'] ?? 0), (int)($get['brd'] ?? 0), $user, (int)($get['quote'] ?? 0));
    }

    return ['error' => 'Unknown post action.'];
}
