<?php
/*-------------------------------------------------------
 | forum.php - Public board, topic, and board favorite controller.
 |
 | Keeps forum display/action routing in controllers while the public
 | URLs remain the long-standing rewritten board and topic paths.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);
$action = strtolower(trim((string)($_GET['action'] ?? 'board')));
$allowedActions = ['board', 'thread', 'favorite'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'board';
}

require_once $root . '/lib/helpers/bootstrap.php';

/**
 * Usage: Redirect the favorite-board action back to the forum index with a status message.
 * Referenced by: validation and write branches in the favorite action.
 *
 * @param string $message User-facing status message.
 * @return never
 */
function corebb_forum_favorite_redirect(string $message): void
{
    $url = corebb_public_join_base_path('/?msg=' . rawurlencode($message));
    header('Location: ' . $url);
    exit;
}

switch ($action) {
    case 'thread':
        require_once $root . '/lib/helpers/view.php';
        require_once $root . '/lib/models/layout_view_model.php';
        require_once $root . '/lib/models/thread_view_model.php';
        require_once $root . '/lib/helpers/mobile_helpers.php';

        $topicId = (int)($_GET['id'] ?? 0);
        $page = max(1, (int)($_GET['p'] ?? ($_GET['page'] ?? 1)));
        corebb_mobile_redirect('thread', ['id' => $topicId, 'page' => $page]);

        $GLOBALS['corebb_layout_script'] = 'forum:thread';
        $model = corebb_thread_fetch_model($topicId, $page);
        corebb_render_public('pages/thread.twig', ['model' => $model]);
        break;

    case 'favorite':
        require_once $root . '/lib/helpers/private_board_helpers.php';

        if (!corebb_load_logged_in_user()) {
            corebb_forum_favorite_redirect('Please log in.');
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            corebb_forum_favorite_redirect('Please use the board page to add favorites.');
        }

        $user = $GLOBALS['userlogindata_a'] ?? [];
        $userId = (int)($user['id'] ?? 0);
        $boardId = (int)($_GET['brd'] ?? $_GET['boardid'] ?? 0);

        if ($userId <= 0) {
            corebb_forum_favorite_redirect('Please log in again.');
        }

        if ($boardId <= 0) {
            corebb_forum_favorite_redirect('Please supply a board ID!');
        }

        $board = corebb_private_board_row($boardId);
        if (!$board || !corebb_private_user_can_view_board_row($board, $userId, (int)($user['accesslevel'] ?? 0))) {
            corebb_forum_favorite_redirect('Board does not exist');
        }

        $exists = db_exists('SELECT 1 FROM favoriteboards WHERE boardid = ? AND ownerid = ? LIMIT 1', [$boardId, $userId]);
        if ($exists) {
            corebb_forum_favorite_redirect('Board already exists in your favorites list!');
        }

        $now = convert_to_timestamp_raw(time());
        $ok = db_run('INSERT INTO favoriteboards (boardid, ownerid, adddate) VALUES (?, ?, ?)', [$boardId, $userId, $now]);

        corebb_forum_favorite_redirect($ok ? 'Board successfully added to favorites list!' : 'Error adding board to favorites list!');

    case 'board':
    default:
        require_once $root . '/lib/helpers/view.php';
        require_once $root . '/lib/models/layout_view_model.php';
        require_once $root . '/lib/models/board_view_model.php';
        require_once $root . '/lib/helpers/performance_helpers.php';
        require_once $root . '/lib/helpers/moderation_helpers.php';
        require_once $root . '/lib/helpers/mobile_helpers.php';

        corebb_mod_ensure_schema();

        $boardId = (int)($_GET['id'] ?? 0);
        $page = max(1, (int)($_GET['p'] ?? ($_GET['page'] ?? 1)));
        corebb_mobile_redirect('board', ['id' => $boardId, 'page' => $page]);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $GLOBALS['corebb_layout_script'] = 'forum:board';
        $model = corebb_board_fetch_model($boardId, $page);
        corebb_render_public('pages/board.twig', ['model' => $model]);
        break;
}
