<?php
/*-------------------------------------------------------
 | support.php - Public support and system pages.
 |
 | Keeps small public support routes out of the web root:
 | banned, denied, FAQ/rules, contact moderators, report
 | message, and the standalone legacy error helper.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);
$action = strtolower(trim((string)($_GET['action'] ?? 'denied')));
$allowedActions = ['banned', 'denied', 'faq', 'contact', 'report', 'error'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'denied';
}

if ($action === 'error') {
    require_once $root . '/lib/view.php';
    require_once $root . '/lib/system_message_view_model.php';
    corebb_render('pages/error_standalone.twig', ['model' => corebb_system_message_model('error', $_GET)]);
    exit;
}

include $root . '/CookieEngine.php';
require_once $root . '/lib/view.php';
require_once $root . '/lib/layout_view_model.php';

switch ($action) {
    case 'banned':
        require_once $root . '/lib/unban_request_view_model.php';
        $GLOBALS['corebb_layout_script'] = 'support:banned';
        corebb_render_public('pages/banned.twig', [
            'model' => corebb_unban_request_model($GLOBALS['userlogindata_a'] ?? [], $_POST),
        ]);
        break;

    case 'faq':
        require_once $root . '/lib/board_rules_faq_view_model.php';
        $GLOBALS['corebb_layout_script'] = 'support:faq';
        corebb_render_public('pages/board_rules_faq.twig', ['model' => corebb_board_rules_faq_model()]);
        break;

    case 'contact':
        require_once $root . '/lib/contact_mods_view_model.php';
        $GLOBALS['corebb_layout_script'] = 'support:contact';
        $viewer = $GLOBALS['userlogindata_a'] ?? [];
        $model = corebb_contact_mods_public_model($viewer, $_GET, $_POST);
        corebb_render_public('pages/contact_mods.twig', ['model' => $model]);
        break;

    case 'report':
        require_once $root . '/lib/admin_mod_requests_view_model.php';
        $GLOBALS['corebb_layout_script'] = 'support:report';
        $viewer = $GLOBALS['userlogindata_a'] ?? [];
        $model = corebb_report_post_model($viewer, $_GET, $_POST);
        corebb_render_public('pages/report_post.twig', ['model' => $model]);
        break;

    case 'denied':
    default:
        require_once $root . '/lib/system_message_view_model.php';
        $GLOBALS['corebb_layout_script'] = 'support:denied';
        corebb_render_public('pages/system_message.twig', ['model' => corebb_system_message_model('denied')]);
        break;
}
