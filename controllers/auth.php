<?php
/*-------------------------------------------------------
 | auth.php - Public authentication controller.
 |
 | Pretty public routes stay in .htaccess.  This file is
 | the internal dispatcher for login, logout, registration,
 | email verification, password recovery, and Google sign-in.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

require_once $root . '/lib/helpers/bootstrap.php';
require_once $root . '/lib/helpers/auth_flow_helpers.php';
require_once $root . '/lib/helpers/google_auth_helpers.php';
require_once $root . '/lib/helpers/oauth_provider_helpers.php';

$action = strtolower(trim((string)($_GET['action'] ?? 'login')));
$allowedActions = ['login', 'login_submit', 'logout', 'register', 'recover', 'reset', 'verify', 'resend', 'google', 'google_complete', 'oauth_start', 'oauth_callback', 'oauth_complete', 'oauth_debug', 'sso_start', 'sso_callback', 'sso_complete', 'sso_debug'];
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

if ($action === 'google') {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        corebb_auth_redirect('/login/');
    }
    corebb_auth_redirect(corebb_google_handle_callback($_POST));
}

if ($action === 'oauth_start' || $action === 'sso_start') {
    corebb_auth_redirect(corebb_oauth_start_redirect((string)($_GET['provider'] ?? $_GET['p'] ?? '')));
}

if ($action === 'oauth_callback' || $action === 'sso_callback') {
    corebb_auth_redirect(corebb_oauth_callback_redirect((string)($_GET['provider'] ?? $_GET['p'] ?? ''), $_GET));
}

if ($action === 'oauth_debug' || $action === 'sso_debug') {
    corebb_oauth_debug_response((string)($_GET['provider'] ?? $_GET['p'] ?? ''));
}

require_once $root . '/lib/helpers/view.php';
require_once $root . '/lib/models/layout_view_model.php';
require_once $root . '/lib/models/auth_view_model.php';
require_once $root . '/lib/helpers/mobile_helpers.php';

switch ($action) {
    case 'register':
        $GLOBALS['corebb_layout_script'] = 'auth:register';
        corebb_mobile_redirect('register');
        $model = corebb_registration_model($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($model['rate'] !== null && empty($model['rate']['allowed'])) {
            http_response_code(429);
            header('Retry-After: ' . max(1, (int)($model['rate']['retry_after'] ?? 60)));
        }
        $model['google'] = corebb_google_button_model();
        $model['oauthProviders'] = corebb_oauth_button_model();
        corebb_render_public('pages/register.twig', ['model' => $model]);
        break;

    case 'oauth_complete':
    case 'sso_complete':
        $GLOBALS['corebb_layout_script'] = 'auth:oauth_complete';
        $model = corebb_oauth_complete_model($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!empty($model['redirect'])) {
            corebb_auth_redirect((string)$model['redirect']);
        }
        corebb_render_public('pages/oauth_account_complete.twig', ['model' => $model]);
        break;

    case 'google_complete':
        $GLOBALS['corebb_layout_script'] = 'auth:google_complete';
        $model = corebb_google_complete_model($_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!empty($model['redirect'])) {
            corebb_auth_redirect((string)$model['redirect']);
        }
        corebb_render_public('pages/google_account_complete.twig', ['model' => $model]);
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
        $model['google'] = corebb_google_button_model();
        $model['oauthProviders'] = corebb_oauth_button_model();
        corebb_render_public('pages/login.twig', ['model' => $model]);
        break;
}
