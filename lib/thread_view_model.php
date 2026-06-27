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
 |  thread_view_model.php  - Data loader for the thread  |
 |  template.                                            |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/poll_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';

/**
 * Usage: Build the thread page model, including posts, poll state, and pagination.
 * Referenced by: controllers/forum.php?action=thread and api/v1/index.php.
 *
 * @param int $topicId Topic id from the route.
 * @param int $page Requested page number.
 * @return array<string, mixed> Thread display state for Twig/API consumers.
 */
function corebb_thread_fetch_model(int $topicId, int $page): array
{
    global $userlogindata_a, $QueryCount;

    corebb_mod_ensure_schema();
    corebb_private_ensure_schema();

    if ($topicId <= 0) {
        return [
            'missing' => true,
            'message' => 'The message you requested could not be found or has been removed by a moderator.',
        ];
    }

    $topic = db_one('SELECT * FROM topics WHERE id = ? AND is_deleted = 0 LIMIT 1', [$topicId]);
    $QueryCount++;
    if (!$topic) {
        return [
            'missing' => true,
            'message' => 'The message you requested could not be found or has been removed by a moderator.',
        ];
    }

    $boardId = (int)($topic['boardid'] ?? 0);
    if (!corebb_private_user_can_view_board_id($boardId)) {
        return [
            'missing' => true,
            'message' => 'The message you requested could not be found or has been removed by a moderator.',
        ];
    }
    $locked = (int)($topic['locked'] ?? 0) === 1;

    $perPage = corebb_current_thread_posts_per_page();

    // Use the live post count here so stale cached topic counters cannot create phantom pages.
    $postCount = max(0, (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0', [$topicId], 0));
    $totalPages = max(1, (int)ceil(max(1, $postCount) / $perPage));
    $currentPage = min(max(1, $page), $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $firstPost = db_one('SELECT * FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1', [$topicId]);
    $QueryCount++;
    $firstPost = is_array($firstPost) ? $firstPost : [];
    $topicTitle = $firstPost['title'] ?? ($topic['title'] ?? 'Untitled Topic');

    $boardRow = corebb_private_board_row($boardId);
    $QueryCount++;
    $boardRow = is_array($boardRow) ? $boardRow : [];
    $editTimer = (int)($boardRow['edittimer'] ?? 0);
    $archiveReadOnly = !corebb_secure_archive_user_can_write_board_row($boardRow, (int)($userlogindata_a['accesslevel'] ?? 0));
    $isLoggedIn = corebb_load_logged_in_user();

    $posts = [];
    // LIMIT/OFFSET are sanitized integers here. Keeping them literal avoids PDO
    // driver trouble with bound LIMIT values on older MySQL/shared-host setups.
    $postSql = "SELECT p.*, u.username AS author_username, u.regdate AS author_regdate, "
        . "u.posts AS author_posts, u.title AS author_title, u.accesslevel AS author_accesslevel, u.id AS author_id "
        . "FROM posts p LEFT JOIN users u ON u.id = p.posterid "
        . "WHERE p.threadid = ? AND p.is_deleted = 0 ORDER BY p.id ASC LIMIT " . (int)$offset . ', ' . (int)$perPage;
    $postRows = db_all($postSql, [$topicId]);
    $QueryCount++;
    foreach ($postRows as $row) {
        $posterId = (int)($row['posterid'] ?? 0);
        $canUserEdit = false;
        $editMinutesLeft = 0;
        if (!$archiveReadOnly && $isLoggedIn && (int)($userlogindata_a['id'] ?? 0) === $posterId && !$locked) {
            $postRaw = (int)($row['posttimeraw'] ?? 0);
            if ($postRaw > 0 && $editTimer > 0) {
                $elapsed = (int)((time() - $postRaw) / 60);
                if ($elapsed < $editTimer) {
                    $canUserEdit = true;
                    $editMinutesLeft = $editTimer - $elapsed;
                }
            }
        }
        $row['_poster_id'] = $posterId;
        $row['_author_username'] = (string)($row['author_username'] ?? 'Unknown');
        $row['_author_regdate'] = (string)($row['author_regdate'] ?? '');
        $row['_author_title'] = (string)($row['author_title'] ?? '');
        $row['_author_accesslevel'] = (int)($row['author_accesslevel'] ?? 0);
        $authorPostCount = (int)($row['author_posts'] ?? 0);
        $row['_author_posts'] = number_format($authorPostCount);
        $row['_can_user_edit'] = $canUserEdit;
        $row['_edit_minutes_left'] = $editMinutesLeft;
        $posts[] = corebb_thread_prepare_post_row(
            $row,
            $topicId,
            $boardId,
            $locked,
            $archiveReadOnly,
            $canUserEdit,
            $editMinutesLeft,
            (string)($boardRow['name'] ?? 'Board'),
            $isLoggedIn
        );
    }

    $canModerate = corebb_mod_can_moderate() && !$archiveReadOnly;
    $canReply = $isLoggedIn && !$archiveReadOnly && (!$locked || $canModerate);
    $pollUserId = $isLoggedIn ? (int)($userlogindata_a['id'] ?? 0) : 0;
    $pollMessage = corebb_poll_message_from_code((string)($_GET['pollmsg'] ?? ''));
    $threadUrlPattern = str_replace('/p999999/', '/p{page}/', corebb_thread_url($topicId, $boardId, 999999, (string)($boardRow['name'] ?? 'Board')));
    $pagination = $postCount > $perPage ? corebb_pagination_model($threadUrlPattern, $currentPage, $totalPages, 'MainMenuFont') : corebb_pagination_model('', $currentPage, $totalPages, 'MainMenuFont');
    $poll = corebb_thread_prepare_poll_model(
        array_merge(corebb_poll_thread_model($topicId, $pollUserId), ['archiveReadOnly' => $archiveReadOnly]),
        $isLoggedIn
    );

    return [
        'missing' => false,
        'topic' => $topic,
        'topicId' => $topicId,
        'boardId' => $boardId,
        'boardName' => (string)($boardRow['name'] ?? 'Board'),
        'topicTitle' => $topicTitle,
        'locked' => $locked,
        'canModerate' => $canModerate,
        'canReply' => $canReply,
        'isLoggedIn' => $isLoggedIn,
        'archiveReadOnly' => $archiveReadOnly,
        'perPage' => $perPage,
        'postCount' => $postCount,
        'totalPages' => $totalPages,
        'currentPage' => $currentPage,
        'posts' => $posts,
        'poll' => $poll,
        'pollMessage' => $pollMessage,
        'replyUrl' => corebb_reply_url($topicId, $boardId),
        'pagination' => $pagination,
    ];
}

/**
 * Usage: Decorate a raw post row with display fields and action URLs.
 * Referenced by: corebb_thread_fetch_model().
 *
 * @param array<string, mixed> $row Raw post row joined with author fields.
 * @param int $topicId Parent topic id.
 * @param int $boardId Parent board id.
 * @param bool $locked Whether the parent topic is locked.
 * @param bool $archiveReadOnly Whether the parent board is archive read-only for the viewer.
 * @param bool $canUserEdit Whether the viewer can edit this post through the user edit window.
 * @param int $editMinutesLeft Minutes remaining in the user edit window.
 * @param string $boardName Parent board name used for canonical URLs.
 * @param bool $showPostActions Whether post action links should be shown.
 * @return array<string, mixed> Post row enriched for thread_post.twig.
 */
function corebb_thread_prepare_post_row(
    array $row,
    int $topicId,
    int $boardId,
    bool $locked,
    bool $archiveReadOnly,
    bool $canUserEdit,
    int $editMinutesLeft,
    string $boardName,
    bool $showPostActions
): array {
    $postId = (int)($row['id'] ?? 0);
    $posterId = (int)($row['posterid'] ?? 0);
    $postTime = (string)($row['posttime'] ?? '');
    $authorPostCount = (int)($row['author_posts'] ?? 0);
    $editCount = max(1, (int)($row['editcount'] ?? 0));
    $editCountText = $editCount === 1 ? '1 edit total' : $editCount . ' edits total';

    $row['post_id'] = $postId;
    $row['poster_id'] = $posterId;
    $row['poster_name'] = (string)($row['author_username'] ?? 'Unknown');
    $row['post_title'] = (string)($row['title'] ?? '');
    $row['post_body'] = (string)($row['body'] ?? '');
    $row['post_time_display'] = $postTime === '' ? '-' : convert_to_vndate($postTime);
    $row['author_regdate'] = (string)($row['author_regdate'] ?? '');
    $row['author_title'] = (string)($row['author_title'] ?? '');
    $row['author_access_level'] = (int)($row['author_accesslevel'] ?? 0);
    $row['author_posts_count'] = $authorPostCount;
    $row['author_posts_display'] = number_format($authorPostCount);
    $row['signature'] = $posterId > 0 ? corebb_user_signature_text($posterId) : '';
    $row['can_user_edit'] = $canUserEdit;
    $row['edit_minutes_left'] = $editMinutesLeft;
    $row['show_actions'] = $showPostActions;
    $row['was_edited'] = (int)($row['wasedited'] ?? 0) === 1;
    $row['edit_time_display'] = $row['was_edited'] ? convert_to_vndate((string)($row['editdate'] ?? '')) : '';
    $row['edit_count_text'] = $editCountText;
    $row['edited_by_id'] = (int)($row['editedby'] ?? 0);
    $row['reply_url'] = corebb_reply_url($topicId, $boardId);
    $row['quote_url'] = corebb_reply_url($topicId, $boardId, $postId);
    $row['edit_url'] = corebb_edit_post_url($postId);
    $row['moderator_edit_url'] = corebb_edit_post_url($postId, true);
    $row['report_url'] = corebb_public_join_base_path('/report-message/' . $postId . '/');
    $row['pm_url'] = corebb_public_join_base_path('/private-messages/send/' . $posterId . '/');
    $row['post_history_url'] = $posterId > 0 ? '/profile/' . $posterId . '/posts/' : '';
    $row['admin_notes_url'] = $posterId > 0 ? '/admin/?act=admin_notes&userid=' . $posterId : '';
    $row['remove_url'] = corebb_public_join_base_path('/moderator/?act=remove_post&post=' . $postId);
    $row['ban_url'] = corebb_public_join_base_path('/moderator/?act=ban_user&post=' . $postId);
    $row['ipc_url'] = corebb_public_join_base_path('/moderator/?act=ipc&post=' . $postId);
    $row['lock_url'] = corebb_public_join_base_path('/moderator/?act=lock_topic&topic=' . $topicId);
    $row['lock_label'] = $locked ? 'Unlock' : 'Lock';
    $row['post_id_url'] = corebb_public_join_base_path('/post-id/' . $postId . '/');
    $row['question_icon_url'] = corebb_public_join_base_path('images/question.gif');
    $row['thread_url'] = corebb_thread_url($topicId, $boardId, 1, $boardName, $postId);

    return $row;
}

/**
 * Usage: Decorate a poll model with vote permissions, bar widths, and status text.
 * Referenced by: corebb_thread_fetch_model().
 *
 * @param array<string, mixed> $pollModel Poll model from poll helpers.
 * @param bool $isLoggedIn Whether the current viewer is logged in.
 * @return array<string, mixed> Poll model enriched for thread.twig.
 */
function corebb_thread_prepare_poll_model(array $pollModel, bool $isLoggedIn): array
{
    if (empty($pollModel['exists'])) {
        return $pollModel;
    }

    $archiveReadOnly = !empty($pollModel['archiveReadOnly']);
    $hasVoted = !empty($pollModel['hasVoted']);
    $isClosed = !empty($pollModel['isClosed']);
    $userId = (int)($pollModel['userId'] ?? 0);
    $totalVotes = (int)($pollModel['totalVotes'] ?? 0);
    $canVote = !$archiveReadOnly && $isLoggedIn && $userId > 0 && !$hasVoted && !$isClosed;

    $options = [];
    foreach (($pollModel['options'] ?? []) as $idx => $option) {
        $optionNumber = ($idx % 10) + 1;
        $votes = (int)($option['_votes'] ?? $option['votes'] ?? 0);
        $percent = (float)($option['_percent'] ?? 0);
        $options[] = array_merge($option, [
            'option_id' => (int)($option['id'] ?? 0),
            'votes' => $votes,
            'percent_int' => (int)$percent,
            'bar_width' => $totalVotes > 0 ? max(1, min(320, (int)round(($votes / $totalVotes) * 320))) : 1,
            'pixel_src' => corebb_public_join_base_path('images/pixel_poll_' . $optionNumber . '.gif'),
        ]);
    }

    $statusMessage = '';
    if (!$canVote) {
        if ($archiveReadOnly) {
            $statusMessage = 'Secure Archive poll voting is read-only.';
        } elseif ($hasVoted) {
            $statusMessage = 'You have already voted in this poll.';
        } elseif ($isClosed) {
            $statusMessage = 'This poll is closed.';
        } elseif (!$isLoggedIn) {
            $statusMessage = 'Log in to vote.';
        }
    }

    return array_merge($pollModel, [
        'options' => $options,
        'canVote' => $canVote,
        'voteAction' => corebb_pretty_path('poll/vote/'),
        'statusMessage' => $statusMessage,
    ]);
}
