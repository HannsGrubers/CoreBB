<?php
/*-------------------------------------------------------
 | auth.php - Public authentication controller.
 |
 | Pretty public routes stay in .htaccess.  This file is
 | the internal dispatcher for login, logout, registration,
 | email verification, and password recovery.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

include $root . '/CookieEngine.php';
include_once $root . '/functions.php';
require_once $root . '/lib/auth_flow_helpers.php';

$action = strtolower(trim((string)($_GET['action'] ?? 'login')));
$allowedActions = ['login', 'login_submit', 'logout', 'register', 'recover', 'reset', 'verify', 'resend'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'login';
}

if ($action === 'login_submit') {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        corebb_auth_redirect('/login/');
    }
    corebb_auth_redirect(corebb_auth_login_submit_redirect($_POST));
}

if ($action === 'logout') {
    corebb_auth_redirect(corebb_auth_logout_redirect());
}

require_once $root . '/lib/view.php';
require_once $root . '/lib/layout_view_model.php';
require_once $root . '/lib/auth_view_model.php';
require_once $root . '/lib/mobile_helpers.php';

switch ($action) {
    case 'register':
        $GLOBALS['corebb_layout_script'] = 'auth:register';
        corebb_mobile_redirect('register');
        $model = corebb_registration_model($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        corebb_render_public('pages/register.twig', ['model' => $model]);
        break;

    case 'recover':
        $GLOBALS['corebb_layout_script'] = 'auth:recover';
        $model = corebb_recover_account_model($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        corebb_render_public('pages/recover_account.twig', ['model' => $model]);
        break;

    case 'reset':
        $GLOBALS['corebb_layout_script'] = 'auth:reset';
        $model = corebb_reset_password_model($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        corebb_render_public('pages/reset_password.twig', ['model' => $model]);
        break;

    case 'verify':
        $GLOBALS['corebb_layout_script'] = 'auth:verify';
        corebb_render_public('pages/verify_email.twig', corebb_verify_email_template_data($_GET));
        break;

    case 'resend':
        $GLOBALS['corebb_layout_script'] = 'auth:resend';
        $model = corebb_resend_verification_model($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        corebb_render_public('pages/resend_verification.twig', ['model' => $model]);
        break;

    case 'login':
    default:
        $GLOBALS['corebb_layout_script'] = 'auth:login';
        corebb_mobile_redirect('login');
        $model = corebb_login_model($_GET);
        corebb_render_public('pages/login.twig', ['model' => $model]);
        break;
}
