<?php
/*-------------------------------------------------------
 | content.php - Public content display controller.
 |
 | Handles profile pages, profile post/topic history,
 | search, and the compact post-id helper.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);
$action = strtolower(trim((string)($_GET['action'] ?? 'search')));
$allowedActions = ['profile', 'profile_content', 'search', 'post_id'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'search';
}

if ($action === 'post_id') {
    $id = (int)($_GET['id'] ?? 0);
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Post ID</title>
<style>
body {
    font-family: Verdana, Arial, sans-serif;
    font-size: 10pt;
    margin: 8px;
    text-align: center;
}
</style>
</head>
<body>
Post ID<br>
<strong><?= htmlspecialchars((string)$id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
</body>
</html>
<?php
    exit;
}

require_once $root . '/lib/helpers/bootstrap.php';
require_once $root . '/lib/helpers/view.php';
require_once $root . '/lib/models/layout_view_model.php';

switch ($action) {
    case 'profile':
        require_once $root . '/lib/models/profile_view_model.php';
        require_once $root . '/lib/helpers/mobile_helpers.php';

        $userId = (int)($_GET['id'] ?? 0);
        corebb_mobile_redirect('profile', ['id' => $userId]);

        $GLOBALS['corebb_layout_script'] = 'content:profile';
        $model = corebb_profile_model($userId);
        corebb_render_public('pages/profile.twig', ['model' => $model]);
        break;

    case 'profile_content':
        require_once $root . '/lib/models/profile_content_view_model.php';

        $userId = (int)($_GET['id'] ?? 0);
        $type = (string)($_GET['type'] ?? 'topics');
        $page = (int)($_GET['p'] ?? 1);

        $GLOBALS['corebb_layout_script'] = 'content:profile_content';
        $model = corebb_profile_content_model($userId, $type, $page);
        corebb_render_public('pages/profile_content.twig', ['model' => $model]);
        break;

    case 'search':
    default:
        require_once $root . '/lib/models/search_view_model.php';

        $GLOBALS['corebb_layout_script'] = 'content:search';
        $model = corebb_search_model($_GET);
        corebb_render_public('pages/search.twig', ['model' => $model]);
        break;
}
