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

    if (function_exists('loggedin') && loggedin()) {
        return corebb_normalize_page_size($userlogindata_a['ThreadPages'] ?? 25, 25);
    }

    // Guests keep the old VN-like compact default.
    return 10;
}

/**
 * Usage: Resolve the current viewer's topics-per-board page size.
 * Referenced by: lib/board_view_model.php.
 *
 * @return int Topics per board page.
 */
function corebb_current_board_topics_per_page(): int
{
    global $userlogindata_a;

    if (function_exists('loggedin') && loggedin()) {
        return corebb_normalize_page_size($userlogindata_a['BoardPages'] ?? 25, 25);
    }

    // Guests keep the old VN-like compact default.
    return 10;
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
        $sequence = function_exists('corebb_compact_page_sequence')
            ? corebb_compact_page_sequence($currentPage, $totalPages, 2)
            : range(1, $totalPages);
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
