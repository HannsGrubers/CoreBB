<?php
/*-------------------------------------------------------
 | corebb_route_helpers.php - Pretty route URL builders.
 |
 | Keeps public board, topic, post, and composer URLs in
 | one explicit module instead of the broad functions file.
 +-------------------------------------------------------*/

require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/corebb_url_helpers.php';

/**
 * Usage: Detect the installed forum base path from SCRIPT_NAME.
 * Referenced by: corebb_pretty_path().
 *
 * @return string Base path without a trailing slash, or empty at web root.
 */
function corebb_url_base_path(): string {
    $base = rtrim(corebb_public_base_path(), '/');
    return $base === '/' ? '' : $base;
}

/**
 * Usage: Prefix a pretty-route path with the detected forum base path.
 * Referenced by: board/thread/post URL builders.
 *
 * @param string $path Pretty route path relative to the forum root.
 * @return string Root-relative pretty URL.
 */
function corebb_pretty_path(string $path): string {
    return corebb_url_base_path() . '/' . ltrim($path, '/');
}

/**
 * Usage: Collapse a board/topic label into the historical compact URL slug.
 * Referenced by: corebb_board_url() and corebb_thread_url().
 *
 * @param string $name Display name to slugify.
 * @param string $fallback Slug to use when the name has no valid characters.
 * @return string Lowercase alphanumeric slug.
 */
function corebb_url_slug(string $name, string $fallback = 'boards'): string {
    $name = strtolower(trim(strip_tags($name)));
    $name = preg_replace('/&[a-z0-9#]+;/i', '', $name);
    $name = preg_replace('/[^a-z0-9]+/i', '', $name);
    return $name !== '' ? $name : $fallback;
}

/**
 * Usage: Build a pretty URL for a board page.
 * Referenced by: board listings, breadcrumbs, and URL normalization.
 *
 * @param int $boardId Forum board id.
 * @param int $page 1-based page number.
 * @param string $boardName Optional display name for the compact slug.
 * @return string Pretty board URL, or the forum root for invalid board ids.
 */
function corebb_board_url(int $boardId, int $page = 1, string $boardName = ''): string {
    $boardId = max(0, $boardId);
    $page = max(1, $page);
    if ($boardId <= 0) {
        return corebb_pretty_path('');
    }
    $slug = corebb_url_slug($boardName, 'board');
    $suffix = $page > 1 ? 'p' . $page . '/' : '';
    return corebb_pretty_path($slug . '/b' . $boardId . '/' . $suffix);
}

/**
 * Usage: Build a pretty URL for a topic page, optionally anchored to a post.
 * Referenced by: topic lists, post links, redirects, and URL normalization.
 *
 * @param int $topicId Topic id.
 * @param int $boardId Board id; resolved when omitted and possible.
 * @param int $page 1-based page number.
 * @param string $boardName Optional display name for the compact slug.
 * @param int $postId Optional post id anchor.
 * @return string Pretty topic URL, or the forum root for invalid topic ids.
 */
function corebb_thread_url(int $topicId, int $boardId = 0, int $page = 1, string $boardName = '', int $postId = 0): string {
    $topicId = max(0, $topicId);
    $boardId = max(0, $boardId);
    $page = max(1, $page);
    if ($topicId <= 0) {
        return corebb_pretty_path('');
    }
    if ($boardId <= 0) {
        $boardId = (int)corebb_topic_board_id($topicId);
    }
    if ($boardId <= 0) {
        $url = corebb_pretty_path('topic/' . $topicId . '/p' . $page . '/');
        return $postId > 0 ? $url . '#post' . $postId : $url;
    }
    $slug = corebb_url_slug($boardName, 'board');
    $url = corebb_pretty_path($slug . '/b' . $boardId . '/' . $topicId . '/p' . $page . '/');
    return $postId > 0 ? $url . '#post' . $postId : $url;
}

/**
 * Usage: Build the reply composer URL for a topic.
 * Referenced by: thread action links and quote-reply controls.
 *
 * @param int $topicId Topic id being replied to.
 * @param int $boardId Board id containing the topic.
 * @param int $quotePostId Optional post id to quote.
 * @return string Pretty reply URL.
 */
function corebb_reply_url(int $topicId, int $boardId, int $quotePostId = 0): string {
    $topicId = max(0, $topicId);
    $boardId = max(0, $boardId);
    $path = 'post/reply/' . $topicId . '/b' . $boardId . '/';
    if ($quotePostId > 0) {
        $path .= 'q' . (int)$quotePostId . '/';
    }
    return corebb_pretty_path($path);
}

/**
 * Usage: Build the new-topic composer URL for a board.
 * Referenced by: board action links.
 *
 * @param int $boardId Board id receiving the new topic.
 * @return string Pretty new-topic URL.
 */
function corebb_new_topic_url(int $boardId): string {
    $boardId = max(0, $boardId);
    return corebb_pretty_path('post/new/b' . $boardId . '/');
}

/**
 * Usage: Build the new-poll composer URL for a board.
 * Referenced by: board action links.
 *
 * @param int $boardId Board id receiving the new poll.
 * @return string Pretty new-poll URL.
 */
function corebb_new_poll_url(int $boardId): string {
    $boardId = max(0, $boardId);
    return corebb_pretty_path('post/new/b' . $boardId . '/poll/');
}

/**
 * Usage: Build the post edit URL and preserve moderator mode when needed.
 * Referenced by: post action links and moderator workflows.
 *
 * @param int $postId Post id to edit.
 * @param bool $moderator Whether to append moderator-edit mode.
 * @return string Pretty edit-post URL.
 */
function corebb_edit_post_url(int $postId, bool $moderator = false): string {
    $postId = max(0, $postId);
    $url = corebb_pretty_path('post/edit/' . $postId . '/');
    return $moderator ? $url . '?mod=1' : $url;
}
