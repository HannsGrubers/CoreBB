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
 |  serializers.php  - JSON API model serializers.       |
 +-------------------------------------------------------+*/

require_once dirname(__DIR__) . '/user_display_helpers.php';
require_once dirname(__DIR__) . '/content_format_helpers.php';

/**
 * Convert formatted content into compact plain text.
 *
 * Usage: provide text previews/descriptions in API responses alongside raw or
 * rendered HTML fields.
 * Referenced by: forum, profile, and private-message serializers.
 *
 * @param mixed $value Source content.
 * @return string Plain text with collapsed whitespace.
 */
function corebb_api_plain_text($value): string
{
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

/**
 * Resolve a plain username for API payloads.
 *
 * Usage: add simple author names while keeping styled username rendering out of
 * JSON responses.
 * Referenced by: topic serializers.
 *
 * @param int $userId User id to resolve.
 * @param string $fallback Fallback username.
 * @return string Plain username or fallback.
 */
function corebb_api_user_plain(int $userId, string $fallback = ''): string
{
    if ($userId > 0) {
        $user = corebb_user_name_model($userId, $fallback, false);
        $name = trim((string)($user['username'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return $fallback !== '' ? $fallback : ($userId > 0 ? ('User #' . $userId) : 'Unknown');
}

/**
 * Convert a path into a public API/web URL.
 *
 * Usage: keep API link generation aligned with CoreBB pretty URL rewriting.
 * Referenced by: all serializers that expose links.
 *
 * @param string $path Legacy script path, pretty path, or absolute URL.
 * @return string Public URL.
 */
function corebb_api_url(string $path): string
{
    return corebb_public_url($path);
}

/**
 * Serialize shared pagination metadata.
 *
 * Usage: normalize board/thread view-model pagination for API clients.
 * Referenced by: board and thread serializers.
 *
 * @param array<string, mixed> $model View model containing pagination fields.
 * @return array<string, int> Pagination payload.
 */
function corebb_api_pagination(array $model): array
{
    return [
        'currentPage' => (int)($model['currentPage'] ?? 1),
        'totalPages' => (int)($model['totalPages'] ?? 1),
        'perPage' => (int)($model['perPage'] ?? 0),
        'totalItems' => (int)($model['postCount'] ?? $model['topicCount'] ?? 0),
    ];
}

/**
 * Serialize one forum/board row.
 *
 * Usage: expose board listing data without leaking template-only HTML markers.
 * Referenced by: index serializer.
 *
 * @param array<string, mixed> $forum Forum row from the board index model.
 * @return array<string, mixed> Forum API payload.
 */
function corebb_api_forum(array $forum): array
{
    $forumId = (int)($forum['id'] ?? 0);
    return [
        'id' => $forumId,
        'categoryId' => (int)($forum['categoryid'] ?? 0),
        'name' => (string)($forum['name'] ?? ''),
        'description' => corebb_api_plain_text($forum['description'] ?? ''),
        'topicCount' => (int)($forum['_topic_count'] ?? 0),
        'postCount' => (int)($forum['_post_count'] ?? 0),
        'lastPost' => [
            'display' => (string)($forum['_lastpost_display'] ?? ''),
            'raw' => (string)($forum['lastpstdate'] ?? ''),
        ],
        'isSecureArchive' => !empty($forum['_secure_archive']),
        'hasNewPosts' => trim(corebb_api_plain_text($forum['_new_mark'] ?? '')) !== '',
        'links' => [
            'self' => corebb_api_url('api/v1/boards/' . $forumId),
            'web' => corebb_board_url($forumId, 1, (string)($forum['name'] ?? 'Board')),
        ],
    ];
}

/**
 * Serialize the forum index model.
 *
 * Usage: expose categories, favorites, and expanded/collapsed board state.
 * Referenced by: API v1 index endpoint.
 *
 * @param array<string, mixed> $model Index page view model.
 * @return array<string, mixed> Index API payload.
 */
function corebb_api_index(array $model): array
{
    $categories = [];
    foreach (($model['openCategories'] ?? []) as $entry) {
        $category = $entry['category'] ?? [];
        $categories[] = [
            'id' => (int)($category['id'] ?? 0),
            'name' => (string)($category['name'] ?? ''),
            'isOpen' => true,
            'forums' => array_map('corebb_api_forum', $entry['forums'] ?? []),
        ];
    }
    foreach (($model['collapsedCategories'] ?? []) as $category) {
        $categories[] = [
            'id' => (int)($category['id'] ?? 0),
            'name' => (string)($category['name'] ?? ''),
            'isOpen' => false,
            'forums' => [],
        ];
    }

    return [
        'message' => (string)($model['message'] ?? ''),
        'expandedCategoryId' => isset($model['expandedCategoryId']) ? (int)$model['expandedCategoryId'] : null,
        'showEmptyArchiveBoards' => !empty($model['showEmptyArchiveBoards']),
        'favorites' => array_map('corebb_api_forum', $model['favorites'] ?? []),
        'categories' => $categories,
    ];
}

/**
 * Serialize one board topic summary.
 *
 * Usage: expose board topic rows with author/last-post/link metadata.
 * Referenced by: board serializer.
 *
 * @param array<string, mixed> $topic Topic row from board view model.
 * @return array<string, mixed> Topic summary payload.
 */
function corebb_api_topic(array $topic): array
{
    $topicId = (int)($topic['id'] ?? 0);
    $boardId = (int)($topic['_board_id'] ?? $topic['boardid'] ?? 0);
    $posterId = (int)($topic['posterid'] ?? 0);
    $lastPosterId = (int)($topic['last_poster_id'] ?? 0);
    $lastPosterName = trim((string)($topic['last_poster_name'] ?? ''));
    return [
        'id' => $topicId,
        'boardId' => $boardId,
        'title' => (string)($topic['title'] ?? ''),
        'poster' => [
            'id' => $posterId,
            'username' => corebb_api_user_plain($posterId),
        ],
        'replyCount' => (int)($topic['_reply_count'] ?? 0),
        'isLocked' => !empty($topic['_locked']),
        'isSticky' => !empty($topic['_sticky']),
        'hasPoll' => !empty($topic['_has_poll']),
        'lastPost' => [
            'display' => (string)($topic['_lastpost_display'] ?? ''),
            'raw' => (string)($topic['lastpost'] ?? ''),
            'poster' => [
                'id' => $lastPosterId,
                'username' => corebb_api_user_plain($lastPosterId, $lastPosterName),
            ],
        ],
        'links' => [
            'self' => corebb_api_url('api/v1/threads/' . $topicId),
            'web' => (string)($topic['_thread_url'] ?? corebb_api_url('controllers/forum.php?action=thread&id=' . $topicId)),
            'lastPost' => (string)($topic['_last_post_url'] ?? ''),
        ],
    ];
}

/**
 * Serialize a board page model.
 *
 * Usage: expose board metadata, pagination, topic list, and posting permissions.
 * Referenced by: API v1 board endpoint.
 *
 * @param array<string, mixed> $model Board view model.
 * @return array<string, mixed> Board API payload.
 */
function corebb_api_board(array $model): array
{
    return [
        'id' => (int)($model['boardId'] ?? 0),
        'name' => (string)($model['boardName'] ?? ''),
        'description' => corebb_api_plain_text($model['boardDescription'] ?? ''),
        'archiveReadOnly' => !empty($model['archiveReadOnly']),
        'isFavorite' => !empty($model['isFavorite']),
        'pagination' => corebb_api_pagination($model),
        'topics' => array_map('corebb_api_topic', $model['topics'] ?? []),
        'permissions' => [
            'canPost' => loggedin() && empty($model['archiveReadOnly']),
            'canFavorite' => loggedin(),
        ],
    ];
}

/**
 * Serialize a thread poll model.
 *
 * Usage: expose poll choices, current vote state, and vote availability.
 * Referenced by: thread serializer.
 *
 * @param array<string, mixed> $pollModel Poll thread model.
 * @return array<string, mixed> Poll API payload.
 */
function corebb_api_poll(array $pollModel): array
{
    if (empty($pollModel['exists'])) {
        return ['exists' => false];
    }

    $options = [];
    foreach (($pollModel['options'] ?? []) as $option) {
        $options[] = [
            'id' => (int)($option['id'] ?? 0),
            'text' => (string)($option['option_text'] ?? ''),
            'position' => (int)($option['position'] ?? 0),
            'votes' => (int)($option['_votes'] ?? $option['votes'] ?? 0),
            'percent' => (float)($option['_percent'] ?? 0),
            'isUserVote' => !empty($option['_is_user_vote']),
        ];
    }

    return [
        'exists' => true,
        'id' => (int)($pollModel['pollId'] ?? 0),
        'topicId' => (int)($pollModel['topicId'] ?? 0),
        'question' => (string)($pollModel['question'] ?? ''),
        'isClosed' => !empty($pollModel['isClosed']),
        'archiveReadOnly' => !empty($pollModel['archiveReadOnly']),
        'daysLeft' => (int)($pollModel['daysLeft'] ?? 0),
        'totalVotes' => (int)($pollModel['totalVotes'] ?? 0),
        'hasVoted' => !empty($pollModel['hasVoted']),
        'votedOptionId' => (int)($pollModel['votedOptionId'] ?? 0),
        'canVote' => loggedin()
            && (int)($pollModel['userId'] ?? 0) > 0
            && empty($pollModel['archiveReadOnly'])
            && empty($pollModel['hasVoted'])
            && empty($pollModel['isClosed']),
        'options' => $options,
    ];
}

/**
 * Serialize a poll vote result.
 *
 * Usage: return a consistent payload after vote attempts.
 * Referenced by: API v1 poll vote endpoint.
 *
 * @param array<string, mixed> $result Vote operation result.
 * @param int $topicId Topic id containing the poll.
 * @return array<string, mixed> Poll vote result payload.
 */
function corebb_api_poll_vote_result(array $result, int $topicId): array
{
    return [
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => (string)($result['message'] ?? ''),
        'topicId' => $topicId,
        'links' => [
            'thread' => corebb_api_url('api/v1/threads/' . $topicId),
            'web' => corebb_api_url('controllers/forum.php?action=thread&id=' . $topicId . '&p=1#poll'),
        ],
    ];
}

/**
 * Serialize one thread post.
 *
 * Usage: expose raw BBCode, rendered HTML, author metadata, edit state, and
 * web links for thread clients.
 * Referenced by: thread serializer.
 *
 * @param array<string, mixed> $post Post row/model.
 * @return array<string, mixed> Post API payload.
 */
function corebb_api_post(array $post): array
{
    $postId = (int)($post['id'] ?? 0);
    $posterId = (int)($post['_poster_id'] ?? $post['posterid'] ?? 0);
    $body = (string)($post['body'] ?? '');
    return [
        'id' => $postId,
        'topicId' => (int)($post['threadid'] ?? 0),
        'boardId' => (int)($post['boardid'] ?? 0),
        'title' => (string)($post['title'] ?? ''),
        // Provide raw BBCode text for editors and rendered HTML for display.
        // Rendering still uses the same markup helper as the classic thread UI.
        'body' => [
            'raw' => $body,
            'html' => corebb_markup_post($body, (int)($post['_author_accesslevel'] ?? 0)),
        ],
        'author' => [
            'id' => $posterId,
            'username' => (string)($post['_author_username'] ?? corebb_api_user_plain($posterId)),
            'title' => (string)($post['_author_title'] ?? ''),
            'registered' => (string)($post['_author_regdate'] ?? ''),
            'postCount' => (string)($post['_author_posts'] ?? '0'),
        ],
        'postedAt' => [
            'display' => convert_to_vndate((string)($post['posttime'] ?? '')),
            'raw' => (string)($post['posttime'] ?? ''),
            'unix' => (int)($post['posttimeraw'] ?? 0),
        ],
        'edited' => [
            'wasEdited' => (int)($post['wasedited'] ?? 0) === 1,
            'count' => (int)($post['editcount'] ?? 0),
            'editedBy' => (int)($post['editedby'] ?? 0),
            'date' => (string)($post['editdate'] ?? ''),
        ],
        'permissions' => [
            'canEdit' => !empty($post['_can_user_edit']),
            'editMinutesLeft' => (int)($post['_edit_minutes_left'] ?? 0),
        ],
        'links' => [
            'web' => corebb_api_url('post-id/' . $postId . '/'),
        ],
    ];
}

/**
 * Serialize a thread page model.
 *
 * Usage: expose topic metadata, pagination, permissions, poll, and post list.
 * Referenced by: API v1 thread endpoint.
 *
 * @param array<string, mixed> $model Thread view model.
 * @return array<string, mixed> Thread API payload.
 */
function corebb_api_thread(array $model): array
{
    return [
        'id' => (int)($model['topicId'] ?? 0),
        'boardId' => (int)($model['boardId'] ?? 0),
        'boardName' => (string)($model['boardName'] ?? ''),
        'title' => (string)($model['topicTitle'] ?? ''),
        'isLocked' => !empty($model['locked']),
        'archiveReadOnly' => !empty($model['archiveReadOnly']),
        'pagination' => corebb_api_pagination($model),
        'permissions' => [
            'canReply' => !empty($model['canReply']),
            'canModerate' => !empty($model['canModerate']),
        ],
        'poll' => corebb_api_poll($model['poll'] ?? []),
        'posts' => array_map('corebb_api_post', $model['posts'] ?? []),
    ];
}

/**
 * Serialize a user profile model.
 *
 * Usage: expose profile fields, dates, bio in multiple formats, permissions,
 * and content links.
 * Referenced by: API v1 profile endpoint.
 *
 * @param array<string, mixed> $model Profile view model.
 * @return array<string, mixed> Profile API payload.
 */
function corebb_api_profile(array $model): array
{
    $user = $model['user'] ?? [];
    $fields = [];
    foreach (($model['fields'] ?? []) as $field) {
        $fields[] = [
            'label' => (string)($field['label'] ?? ''),
            'value' => (string)($field['value'] ?? ''),
            'format' => (string)($field['format'] ?? 'plain'),
        ];
    }
    $bio = (string)($model['bio'] ?? '');
    $profileTitle = (string)($model['profile_title'] ?? '');

    return [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($model['username_plain'] ?? $user['username'] ?? ''),
        'level' => (string)($model['level'] ?? ''),
        'postCount' => (string)($model['post_count'] ?? '0'),
        'isBanned' => (string)($user['status'] ?? '') === '2',
        'profileTitle' => $profileTitle !== '' ? $profileTitle : (string)($model['username_plain'] ?? $user['username'] ?? ''),
        'fields' => $fields,
        'dates' => [
            'registered' => (string)($model['reg_date'] ?? ''),
            'lastUpdated' => (string)($model['last_update'] ?? ''),
            'lastLogin' => (string)($model['last_login'] ?? ''),
            'lastPost' => (string)($model['last_post'] ?? ''),
        ],
        'bio' => [
            'raw' => $bio,
            'html' => corebb_formatted_content_html(corebb_profile_bio_model($bio)),
            'text' => corebb_api_plain_text($bio),
        ],
        'permissions' => [
            'canEditSelf' => !empty($model['can_edit_self']),
            'canViewContentLinks' => !empty($model['can_view_content_links']),
            'canModerate' => corebb_mod_can_moderate(),
        ],
        'links' => [
            'web' => corebb_api_url('profile/' . (int)($user['id'] ?? 0) . '/'),
            'topics' => (string)($model['all_topics_url'] ?? ''),
            'posts' => (string)($model['all_posts_url'] ?? ''),
        ],
    ];
}

/**
 * Serialize the post form preflight model.
 *
 * Usage: give API/mobile clients the hidden fields, CSRF info, controls, and
 * context required to submit a post through the shared post workflow.
 * Referenced by: API v1 post preflight endpoints.
 *
 * @param array<string, mixed> $model Post form view model.
 * @param string $action Post action such as new, reply, or edit.
 * @return array<string, mixed> Post preflight payload.
 */
function corebb_api_post_preflight(array $model, string $action): array
{
    $hidden = [];
    foreach (($model['hidden'] ?? []) as $name => $value) {
        if ($value !== '') {
            $hidden[(string)$name] = (string)$value;
        }
    }

    // Preflight payloads expose the hidden context needed for mobile forms
    // while keeping final submission validation inside corebb_post_process().
    $board = is_array($model['board'] ?? null) ? $model['board'] : [];
    $topic = is_array($model['topic'] ?? null) ? $model['topic'] : [];
    $firstPost = is_array($model['firstPost'] ?? null) ? $model['firstPost'] : [];
    $threadId = (int)($hidden['threadid'] ?? $topic['id'] ?? $firstPost['threadid'] ?? 0);
    $boardId = (int)($hidden['boardtopost'] ?? $hidden['posttoboard'] ?? $board['id'] ?? $topic['boardid'] ?? $firstPost['boardid'] ?? 0);

    $payload = [
        'action' => $action,
        'mode' => (string)($model['mode'] ?? $action),
        'canSubmit' => empty($model['error']),
        'error' => isset($model['error']) ? (string)$model['error'] : null,
        'subject' => (string)($model['subject'] ?? ''),
        'body' => (string)($model['body'] ?? ''),
        'submitLabel' => (string)($model['submitLabel'] ?? 'Post'),
        'notes' => (string)($model['notes'] ?? 'Please read the Terms Of Service before posting. By pressing the POST button below, you are agreeing to the TOS.'),
        'hidden' => $hidden,
        'csrf' => [
            'token' => corebb_security_csrf_token(),
            'header' => 'X-CoreBB-CSRF',
            'field' => 'corebb_csrf_token',
        ],
        'context' => [
            'boardId' => $boardId,
            'boardName' => (string)($board['name'] ?? ''),
            'threadId' => $threadId,
            'threadTitle' => (string)($model['replyTitle'] ?? $topic['title'] ?? $firstPost['title'] ?? ''),
            'posterId' => (int)($model['posterId'] ?? 0),
            'posterName' => (string)($model['replyPosterName'] ?? ''),
        ],
        'controls' => [
            'showSticky' => !empty($model['showSticky']),
            'stickyChecked' => !empty($model['stickyChecked']),
            'stickyControlLabel' => (string)($model['stickyControlLabel'] ?? ''),
            'stickyText' => (string)($model['stickyText'] ?? ''),
            'showPollControls' => !empty($model['showPollControls']),
            'pollChecked' => !empty($model['pollChecked']),
            'pollQuestion' => (string)($model['pollQuestion'] ?? ''),
            'pollOptions' => array_values($model['pollOptions'] ?? []),
            'canUploadImage' => !empty($model['canUploadImage']),
        ],
        'imageUpload' => [
            'enabled' => !empty($model['canUploadImage']),
            'endpoint' => (string)($model['postImageUploadEndpoint'] ?? '/post/image-upload/'),
            'maxBytes' => (int)($model['postImageMaxBytes'] ?? 0),
            'maxWidth' => (int)($model['postImageMaxWidth'] ?? 0),
            'maxHeight' => (int)($model['postImageMaxHeight'] ?? 0),
        ],
        'links' => [
            'submit' => corebb_api_url('post/submit/'),
        ],
    ];

    if ($action === 'edit') {
        $payload['edit'] = [
            'timeMessage' => (string)($model['timeMessage'] ?? ''),
            'isModeratorEdit' => !empty($model['isModeratorEdit']),
        ];
    }

    return $payload;
}

/**
 * Serialize a post workflow result.
 *
 * Usage: return shared post_process() messages and links to API clients.
 * Referenced by: API v1 post write endpoints.
 *
 * @param array<string, mixed> $model Post result model.
 * @return array<string, mixed> Post result payload.
 */
function corebb_api_post_result(array $model): array
{
    $links = [];
    foreach (($model['links'] ?? []) as $link) {
        $href = (string)($link['href'] ?? '');
        $links[] = [
            'text' => (string)($link['text'] ?? ''),
            'href' => $href,
            'web' => $href !== '' ? corebb_api_url($href) : '',
        ];
    }

    return [
        'status' => (string)($model['status'] ?? ''),
        'message' => (string)($model['message'] ?? ''),
        'links' => $links,
    ];
}

/**
 * Serialize private-message folder counts.
 *
 * Usage: normalize unread/read/sent counts for PM endpoints.
 * Referenced by: private-message serializers.
 *
 * @param array<string, mixed> $counts Raw PM counts.
 * @return array{unread: int, read: int, sent: int} Count payload.
 */
function corebb_api_pm_counts(array $counts): array
{
    return [
        'unread' => (int)($counts['unread'] ?? 0),
        'read' => (int)($counts['read'] ?? 0),
        'sent' => (int)($counts['sent'] ?? 0),
    ];
}

/**
 * Serialize the private-message folder list.
 *
 * Usage: expose PM folders and counts for the current viewer.
 * Referenced by: API v1 PM folder endpoint.
 *
 * @param array<string, mixed> $viewer Current user row.
 * @return array<string, mixed> PM folder list payload.
 */
function corebb_api_pm_folders(array $viewer): array
{
    $counts = corebb_pm_counts((int)($viewer['id'] ?? 0));
    return [
        'counts' => corebb_api_pm_counts($counts),
        'folders' => [
            [
                'key' => 'inbox',
                'title' => 'Inbox',
                'count' => (int)($counts['unread'] ?? 0),
                'links' => ['self' => corebb_api_url('api/v1/pm/inbox')],
            ],
            [
                'key' => 'read',
                'title' => 'Read Items',
                'count' => (int)($counts['read'] ?? 0),
                'links' => ['self' => corebb_api_url('api/v1/pm/read')],
            ],
            [
                'key' => 'sent',
                'title' => 'Sent Items',
                'count' => (int)($counts['sent'] ?? 0),
                'links' => ['self' => corebb_api_url('api/v1/pm/sent')],
            ],
        ],
    ];
}

/**
 * Serialize one private-message summary row.
 *
 * Usage: expose folder list items with other-user and link metadata.
 * Referenced by: private-message folder serializer.
 *
 * @param array<string, mixed> $message PM summary row.
 * @return array<string, mixed> PM summary payload.
 */
function corebb_api_pm_summary(array $message): array
{
    $id = (int)($message['id'] ?? 0);
    $method = (string)($message['method'] ?? 'read');
    return [
        'id' => $id,
        'title' => (string)($message['title'] ?? ''),
        'method' => $method,
        'direction' => (string)($message['direction'] ?? ''),
        'otherUser' => [
            'id' => (int)($message['other_user_id'] ?? 0),
            'username' => (string)($message['other_user_name'] ?? ''),
        ],
        'sentAt' => [
            'display' => (string)($message['date_sent_display'] ?? ''),
            'raw' => (string)($message['date_sent'] ?? ''),
        ],
        'links' => [
            'self' => corebb_api_url('api/v1/pm/messages/' . $id . '?folder=' . rawurlencode($method)),
            'web' => corebb_api_url('private-messages/message/' . $id . '/' . rawurlencode($method) . '/'),
        ],
    ];
}

/**
 * Serialize a private-message folder model.
 *
 * Usage: expose a PM folder title, counts, and message summaries.
 * Referenced by: API v1 PM folder endpoint.
 *
 * @param array<string, mixed> $model PM folder view model.
 * @return array<string, mixed> PM folder payload.
 */
function corebb_api_pm_folder(array $model): array
{
    return [
        'folder' => (string)($model['folder'] ?? 'unread'),
        'title' => (string)($model['title'] ?? 'Private Messages'),
        'counts' => corebb_api_pm_counts($model['counts'] ?? []),
        'messages' => array_map('corebb_api_pm_summary', $model['messages'] ?? []),
    ];
}

/**
 * Serialize one private-message detail model.
 *
 * Usage: expose PM raw/rendered/plain body, counts, other-user data, and
 * reply/report links.
 * Referenced by: API v1 PM message endpoint.
 *
 * @param array<string, mixed> $model PM detail view model.
 * @return array<string, mixed> PM message payload.
 */
function corebb_api_pm_message(array $model): array
{
    $id = (int)($model['id'] ?? 0);
    $method = (string)($model['method'] ?? 'read');
    return [
        'id' => $id,
        'method' => $method,
        'title' => (string)($model['title'] ?? ''),
        'body' => [
            'raw' => (string)($model['body'] ?? ''),
            'html' => corebb_formatted_content_html(corebb_pm_body_model((string)($model['body'] ?? ''))),
            'text' => corebb_api_plain_text($model['body'] ?? ''),
        ],
        'counts' => corebb_api_pm_counts($model['counts'] ?? []),
        'otherUser' => [
            'id' => (int)($model['other_user_id'] ?? 0),
            'label' => (string)($model['name_label'] ?? ''),
            'username' => (string)($model['other_user_name'] ?? ''),
        ],
        'sentAt' => [
            'display' => (string)($model['date_sent_display'] ?? ''),
            'raw' => (string)($model['date_sent'] ?? ''),
        ],
        'permissions' => [
            'canReply' => !empty($model['can_reply']),
            'canReport' => $method !== 'sent',
        ],
        'links' => [
            'web' => corebb_api_url('private-messages/message/' . $id . '/' . rawurlencode($method) . '/'),
            'reply' => (string)($model['reply_url'] ?? ''),
            'report' => (string)($model['report_url'] ?? ''),
        ],
    ];
}

/**
 * Serialize a private-message send result.
 *
 * Usage: return PM send status, sent/missing recipient lists, and updated
 * counts.
 * Referenced by: API v1 PM send endpoint.
 *
 * @param array<string, mixed> $result Send result.
 * @return array<string, mixed> PM send result payload.
 */
function corebb_api_pm_send_result(array $result): array
{
    return [
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => (string)($result['message'] ?? ''),
        'sentTo' => array_values(array_map('strval', $result['sent_to'] ?? [])),
        'missing' => array_values(array_map('strval', $result['missing'] ?? [])),
        'counts' => corebb_api_pm_counts(corebb_pm_counts((int)(corebb_api_viewer()['id'] ?? 0))),
    ];
}

/**
 * Serialize a private-message action result.
 *
 * Usage: return read/delete/etc. PM action status plus refreshed counts.
 * Referenced by: API v1 PM action endpoints.
 *
 * @param array<string, mixed> $result Action result.
 * @return array<string, mixed> PM action result payload.
 */
function corebb_api_pm_action_result(array $result): array
{
    return [
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => (string)($result['message'] ?? ''),
        'counts' => corebb_api_pm_counts(corebb_pm_counts((int)(corebb_api_viewer()['id'] ?? 0))),
    ];
}

/**
 * Serialize a moderation operation result.
 *
 * Usage: map shared moderation helper results into API status/message/link data.
 * Referenced by: API v1 moderation endpoints.
 *
 * @param array<string, mixed> $result Moderation result.
 * @return array<string, mixed> Moderation result payload.
 */
function corebb_api_mod_result(array $result): array
{
    $redirect = (string)($result['redirect'] ?? '');
    return [
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => (string)($result['message'] ?? ''),
        'links' => [
            'web' => $redirect !== '' ? corebb_api_url($redirect) : '',
        ],
    ];
}

/**
 * Serialize registration results.
 *
 * Usage: expose registration success/error state and mail verification warning.
 * Referenced by: API v1 auth/register endpoint.
 *
 * @param array<string, mixed> $model Registration view model.
 * @return array<string, mixed> Registration result payload.
 */
function corebb_api_registration_result(array $model): array
{
    return [
        'status' => !empty($model['success']) ? 'success' : 'error',
        'success' => !empty($model['success']),
        'requiresEmailVerification' => !empty($model['success']),
        'message' => !empty($model['success'])
            ? 'Account created. Please check your email and verify the account before logging in.'
            : 'Registration could not be completed.',
        'errors' => array_values(array_map('strval', $model['errors'] ?? [])),
        'mailWarning' => (string)($model['mailWarning'] ?? ''),
    ];
}

/**
 * Serialize the current API viewer.
 *
 * Usage: return authenticated user metadata for login/csrf/session clients.
 * Referenced by: API auth helpers and front controller.
 *
 * @return array<string, mixed> Viewer payload.
 */
function corebb_api_viewer_payload(): array
{
    $viewer = corebb_api_viewer();
    if (!$viewer) {
        return ['authenticated' => false, 'user' => null];
    }

    $userId = (int)($viewer['id'] ?? 0);
    return [
        'authenticated' => true,
        'user' => [
            'id' => $userId,
            'username' => (string)($viewer['username'] ?? ''),
            'accessLevel' => (int)($viewer['accesslevel'] ?? 0),
            'level' => LoadUserLevel((int)($viewer['accesslevel'] ?? 0)),
            'isBanned' => (string)($viewer['status'] ?? '') === '2',
            'canModerate' => corebb_mod_can_moderate(),
            'links' => [
                'profile' => corebb_api_url('profile/' . $userId . '/'),
            ],
        ],
    ];
}
