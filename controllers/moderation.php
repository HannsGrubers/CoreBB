<?php
/*-------------------------------------------------------
 | moderation.php - Public moderator action controller.
 |
 | Handles moderator confirmation and action flows behind
 | the rewritten /moderator route.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

require_once $root . '/lib/helpers/bootstrap.php';
require_once $root . '/lib/helpers/view.php';
require_once $root . '/lib/models/layout_view_model.php';
require_once $root . '/lib/models/moderator_view_model.php';

corebb_mod_ensure_schema();

if (!corebb_load_logged_in_user() || !corebb_mod_can_moderate()) {
    header('Location: ' . corebb_public_join_base_path('/denied/'));
    exit;
}

$GLOBALS['corebb_layout_script'] = 'moderation:main';
$vm = corebb_moderator_build(corebb_moderator_request());

corebb_render_public('pages/moderator.twig', ['vm' => $vm]);
