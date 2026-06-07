<?php
/*-------------------------------------------------------
 | moderation.php - Public moderator action controller.
 |
 | Handles moderator confirmation and action flows behind
 | the rewritten /moderator route.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

include $root . '/CookieEngine.php';
require_once $root . '/lib/view.php';
require_once $root . '/lib/layout_view_model.php';
require_once $root . '/lib/moderator_view_model.php';

corebb_mod_ensure_schema();

if (!loggedin() || !corebb_mod_can_moderate()) {
    header('Location: ' . (function_exists('corebb_public_url') ? corebb_public_url('/denied/') : '/denied/'));
    exit;
}

$GLOBALS['corebb_layout_script'] = 'moderation:main';
$vm = corebb_moderator_build(corebb_moderator_request());

corebb_render_public('pages/moderator.twig', ['vm' => $vm]);
