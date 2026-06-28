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
 |  pagination_helpers.php  - Shared pagination          |
 |  preference helpers.                                  |
 +-------------------------------------------------------+*/

/**
 * Usage: Define the page-size choices accepted by board/thread preferences.
 * Referenced by: corebb_normalize_page_size().
 *
 * @return array<int, int> Allowed page sizes.
 */
function corebb_allowed_page_sizes(): array
{
    return [5, 10, 15, 20, 25, 30, 35, 40, 45, 50];
}

/**
 * Usage: Clamp a stored/user-supplied page-size value to an allowed preference.
 * Referenced by: current board and thread page-size helpers.
 *
 * @param mixed $value Stored or submitted page-size value.
 * @param int $default Fallback size when the value is invalid.
 * @return int Allowed page size.
 */
function corebb_normalize_page_size($value, int $default): int
{
    $default = $default > 0 ? $default : 25;
    $size = (int)$value;
    return in_array($size, corebb_allowed_page_sizes(), true) ? $size : $default;
}

/**
 * Usage: Resolve the current viewer's posts-per-thread page size.
 * Referenced by: board, thread, profile-content, search, and notification URL builders.
 *
 * @return int Posts per thread page.
 */
function corebb_current_thread_posts_per_page(): int
{
    global $userlogindata_a;

    if (corebb_load_logged_in_user()) {
        return corebb_normalize_page_size($userlogindata_a['ThreadPages'] ?? 25, 25);
    }

    // Guests keep the old VN-like compact default.
    return 10;
}

/**
 * Usage: Resolve the current viewer's topics-per-board page size.
 * Referenced by: lib/models/board_view_model.php.
 *
 * @return int Topics per board page.
 */
function corebb_current_board_topics_per_page(): int
{
    global $userlogindata_a;

    if (corebb_load_logged_in_user()) {
        return corebb_normalize_page_size($userlogindata_a['BoardPages'] ?? 25, 25);
    }

    // Guests keep the old VN-like compact default.
    return 10;
}

/**
 * Usage: Build the compact numeric page sequence for large archive lists.
 * Referenced by: compact pagination and topic bracket helpers.
 *
 * Old VN-style pages listed every page number, which becomes unreadable with
 * hundreds of archive pages. This keeps first/last, nearby pages, and ellipses.
 *
 * @param mixed $currentPage Current 1-based page number.
 * @param mixed $totalPages Total page count.
 * @param mixed $radius Number of pages to keep on each side of current.
 * @return array<int, int|string> Ordered page numbers with "..." gap markers.
 */
function corebb_compact_page_sequence($currentPage, $totalPages, $radius = 2){
    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);
    $radius = max(0, (int)$radius);

    $wanted = [1 => true, $totalPages => true];
    for($i = $currentPage - $radius; $i <= $currentPage + $radius; $i++){
        if($i >= 1 && $i <= $totalPages){
            $wanted[$i] = true;
        }
    }

    ksort($wanted, SORT_NUMERIC);
    $out = [];
    $last = 0;
    foreach(array_keys($wanted) as $page){
        if($last && $page > $last + 1){
            $out[] = '...';
        }
        $out[] = $page;
        $last = $page;
    }
    return $out;
}

/**
 * Usage: Render compact linked pagination for boards, topics, and archive lists.
 * Referenced by: board/thread view helpers and page builders.
 *
 * @param mixed $urlPattern URL containing a {page} placeholder.
 * @param mixed $currentPage Current 1-based page number.
 * @param mixed $totalPages Total page count.
 * @param string $linkClass CSS class applied to links and labels.
 * @param string $label Optional leading label.
 * @param string $separator HTML/text separator between page links.
 * @param mixed $radius Number of nearby pages to show.
 * @return string Pagination HTML fragment, or an empty string for one page.
 */
function corebb_compact_pagination_html($urlPattern, $currentPage, $totalPages, $linkClass = 'MainMenuFont', $label = 'Pages:', $separator = ' | ', $radius = 2){
    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);
    if($totalPages <= 1){
        return '';
    }

    $safeClass = htmlspecialchars($linkClass, ENT_QUOTES);
    $html = '';
    if($label !== ''){
        $html .= "<a class='" . $safeClass . "'><b>" . htmlspecialchars($label, ENT_QUOTES) . "</b></a> ";
    }

    $parts = [];
    foreach(corebb_compact_page_sequence($currentPage, $totalPages, $radius) as $page){
        if($page === '...'){
            $parts[] = "<a class='" . $safeClass . "'>...</a>";
            continue;
        }
        $page = (int)$page;
        if($page === $currentPage){
            $parts[] = "<a class='" . $safeClass . "'><b>$page</b></a>";
        }else{
            $url = str_replace('{page}', (string)$page, $urlPattern);
            $parts[] = "<a class='" . $safeClass . "' href='" . htmlspecialchars($url, ENT_QUOTES) . "'>$page</a>";
        }
    }

    return $html . implode("<a class='" . $safeClass . "'>$separator</a>", $parts);
}

/**
 * Usage: Render the small bracketed page links shown beside topic titles.
 * Referenced by: corebb_topic_page_links() and topic-list view models.
 *
 * @param mixed $urlPattern URL containing a {page} placeholder.
 * @param mixed $totalPages Total page count.
 * @param string $linkClass CSS class applied to bracket/link text.
 * @param mixed $radius Number of early pages to expose around page one.
 * @return string Bracketed pagination HTML fragment, or empty for one page.
 */
function corebb_vn_topic_page_brackets_html($urlPattern, $totalPages, $linkClass = 'SmallText', $radius = 1){
    $totalPages = max(1, (int)$totalPages);
    if($totalPages <= 1){
        return '';
    }

    $safeClass = htmlspecialchars($linkClass, ENT_QUOTES);
    $sequence = corebb_compact_page_sequence(1, $totalPages, $radius);

    $parts = [];
    foreach($sequence as $page){
        if($page === '...'){
            $parts[] = "<span class='" . $safeClass . "'>...</span>";
            continue;
        }
        $page = (int)$page;
        $url = str_replace('{page}', (string)$page, $urlPattern);
        $parts[] = "<a class='" . $safeClass . "' href='" . htmlspecialchars($url, ENT_QUOTES) . "'>" . $page . "</a>";
    }

    return " <span class='" . $safeClass . "'>[</span>"
        . implode("<span class='" . $safeClass . "'>, </span>", $parts)
        . "<span class='" . $safeClass . "'>]</span>";
}

/**
 * Usage: Render previous/next/reload controls for paged views.
 * Referenced by: board and topic pagination templates.
 *
 * @param mixed $urlPattern URL containing a {page} placeholder.
 * @param mixed $currentPage Current 1-based page number.
 * @param mixed $totalPages Total page count.
 * @param string $linkClass CSS class applied to controls.
 * @return string Previous/next/reload HTML fragment, or empty for one page.
 */
function corebb_prev_next_reload_html($urlPattern, $currentPage, $totalPages, $linkClass = 'MainMenuFont'){
    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);
    if($totalPages <= 1){
        return '';
    }

    $safeClass = htmlspecialchars($linkClass, ENT_QUOTES);
    $prev = $currentPage - 1;
    $next = $currentPage + 1;
    $reloadUrl = str_replace('{page}', (string)$currentPage, $urlPattern);

    $html = "&nbsp;<a class='$safeClass'>-</a>&nbsp;";
    if($prev >= 1){
        $prevUrl = str_replace('{page}', (string)$prev, $urlPattern);
        $html .= "<a href='" . htmlspecialchars($prevUrl, ENT_QUOTES) . "' class='$safeClass'>Previous</a>";
    }else{
        $html .= "<a class='$safeClass'><strike>Previous</strike></a>";
    }
    $html .= " <a class='$safeClass'>|</a> ";
    if($next <= $totalPages){
        $nextUrl = str_replace('{page}', (string)$next, $urlPattern);
        $html .= "<a href='" . htmlspecialchars($nextUrl, ENT_QUOTES) . "' class='$safeClass'>Next</a>";
    }else{
        $html .= "<a class='$safeClass'><strike>Next</strike></a>";
    }
    $html .= " <a class='$safeClass'>|</a> <a class='$safeClass' href='" . htmlspecialchars($reloadUrl, ENT_QUOTES) . "'>Reload</a>";
    return $html;
}

/**
 * Usage: Render the compact page links beside topic titles.
 * Referenced by: board/topic listing code.
 *
 * @param mixed $resultsper Posts per page.
 * @param mixed $threadid Topic id to count posts for.
 * @return string Topic-page link HTML fragment, or empty for single-page topics.
 */
function corebb_topic_page_links($resultsper, $threadid){
    $resultsperpage = max(1, (int)$resultsper);
    $threadid = (int)$threadid;
    $resultcount = corebb_topic_post_count($threadid);
    if($resultcount <= $resultsperpage){
        return "";
    }

    $boardid = corebb_topic_board_id($threadid);
    $totalPages = (int)ceil($resultcount / $resultsperpage);
    $urlPattern = str_replace('/p999999/', '/p{page}/', corebb_thread_url($threadid, $boardid, 999999));
    return corebb_vn_topic_page_brackets_html($urlPattern, $totalPages, 'SmallText', 1);
}

/**
 * Usage: Build a Twig-ready pagination model from a URL pattern.
 * Referenced by: board, thread, avatar, search, and profile-content view models.
 *
 * @param string $urlPattern URL containing a {page} placeholder, or an empty string to hide pagination.
 * @param int $currentPage Current page number.
 * @param int $totalPages Total available pages.
 * @param string $class CSS class used by the pagination partial.
 * @return array<string, mixed> Pagination state for templates.
 */
function corebb_pagination_model(string $urlPattern, int $currentPage, int $totalPages, string $class = 'MainMenuFont'): array
{
    $currentPage = max(1, $currentPage);
    $totalPages = max(1, $totalPages);
    $visible = $urlPattern !== '' && $totalPages > 1;
    $items = [];

    if ($visible) {
        $sequence = corebb_compact_page_sequence($currentPage, $totalPages, 2);
        foreach ($sequence as $page) {
            if ($page === '...') {
                $items[] = ['type' => 'ellipsis'];
                continue;
            }
            $page = (int)$page;
            $items[] = [
                'type' => 'page',
                'page' => $page,
                'url' => str_replace('{page}', (string)$page, $urlPattern),
                'current' => $page === $currentPage,
            ];
        }
    }

    return [
        'visible' => $visible,
        'class' => $class,
        'label' => 'Pages:',
        'separator' => ' | ',
        'items' => $items,
        'prev' => [
            'enabled' => $visible && $currentPage > 1,
            'url' => $visible && $currentPage > 1 ? str_replace('{page}', (string)($currentPage - 1), $urlPattern) : '',
        ],
        'next' => [
            'enabled' => $visible && $currentPage < $totalPages,
            'url' => $visible && $currentPage < $totalPages ? str_replace('{page}', (string)($currentPage + 1), $urlPattern) : '',
        ],
        'reload_url' => $visible ? str_replace('{page}', (string)$currentPage, $urlPattern) : '',
    ];
}
