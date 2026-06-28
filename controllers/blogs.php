<?php
/*-------------------------------------------------------
 | blogs.php - Public blog controller.
 |
 | Handles blog home, owner redirect, open/close actions,
 | entry views, edits, and deletes behind /blogs routes.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

require_once $root . '/lib/helpers/bootstrap.php';
require_once $root . '/lib/helpers/view.php';
require_once $root . '/lib/models/layout_view_model.php';
require_once $root . '/lib/models/blog_view_model.php';

/**
 * Usage: Send blog routes to their next public page after a read or write action.
 * Referenced by: every branch in this controller that finishes with a redirect.
 *
 * @param string $url Public path for the Location header.
 * @return never
 */
function corebb_blog_redirect(string $url): void
{
    $url = corebb_public_join_base_path($url);
    header('Location: ' . $url);
    exit;
}

/**
 * Usage: Handle the owner-only blog open/close POST action.
 * Referenced by: the /blogs/modify/ route and rendered by blog_sidebar.twig.
 *
 * @param array<string, mixed> $post Submitted form data.
 * @param string $requestMethod HTTP request method.
 * @return never
 */
function corebb_blog_modify_redirect(array $post, string $requestMethod): void
{
    corebb_blog_ensure_schema();
    if (!corebb_load_logged_in_user()) {
        corebb_blog_redirect('/blogs/?msg=Sorry,+you+must+be+logged+in+to+modify+your+blog!');
    }
    if (strtoupper($requestMethod) !== 'POST') {
        corebb_blog_redirect('/blogs/?msg=Please+use+the+blog+controls+to+modify+your+blog!');
    }

    $method = (string)($post['method'] ?? '');
    $userId = corebb_blog_current_user_id();
    if ($method === 'Lock') {
        if (corebb_blog_is_locked($userId)) {
            corebb_blog_redirect('/blogs/?msg=Your+blog+is+already+locked!');
        }
        $ok = db_run('UPDATE users SET LockedBlog = 1 WHERE id = ?', [$userId]);
        corebb_blog_redirect('/blogs/?msg=' . ($ok ? 'Successfully+locked+blog!' : 'Error+locking+your+blog!'));
    }

    if ($method === 'Open') {
        if (!corebb_blog_is_locked($userId)) {
            corebb_blog_redirect('/blogs/?msg=Your+blog+is+already+unlocked!');
        }
        $ok = db_run('UPDATE users SET LockedBlog = 0 WHERE id = ?', [$userId]);
        corebb_blog_redirect('/blogs/?msg=' . ($ok ? 'Successfully+unlocked+blog!' : 'Error+unlocking+your+blog!'));
    }

    corebb_blog_redirect('/blogs/');
}

$action = strtolower((string)($_GET['action'] ?? 'home'));
$view = 'pages/blogs.twig';
$model = [];

switch ($action) {
    case 'my':
        if (!corebb_load_logged_in_user()) {
            corebb_blog_redirect('/blogs/?msg=Sorry,+you+must+be+logged+in+to+view+your+blog!');
        }
        corebb_blog_redirect('/blogs/user/' . corebb_blog_current_user_id() . '/');
        break;

    case 'modify':
        corebb_blog_modify_redirect($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        break;

    case 'viewblog':
        $model = corebb_blog_viewblog_model($_GET['id'] ?? '');
        $model['page'] = $model['page'] ?? 'viewblog';
        $view = 'pages/blog_viewblog.twig';
        break;

    case 'viewentry':
        $model = corebb_blog_viewentry_model((int)($_GET['id'] ?? 0));
        $model['page'] = $model['page'] ?? 'viewentry';
        $view = 'pages/blog_viewentry.twig';
        break;

    case 'edit':
        $model = corebb_blog_edit_model($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $model['page'] = $model['page'] ?? 'edit';
        $view = 'pages/blog_edit.twig';
        break;

    case 'delete':
        $model = corebb_blog_delete_model($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $model['page'] = $model['page'] ?? 'delete';
        $view = 'pages/blog_delete.twig';
        break;

    case 'home':
    default:
        $model = corebb_blog_home_model();
        $model['page'] = $model['page'] ?? 'home';
        $view = 'pages/blogs.twig';
        break;
}

corebb_render_public($view, ['model' => $model]);
