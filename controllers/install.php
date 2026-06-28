<?php
/*-------------------------------------------------------
 | install.php - Standalone CoreBB installer controller.
 |
 | This route intentionally avoids browser bootstrap and
 | config.php because a fresh install has no private
 | configuration yet.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

require_once $root . '/lib/helpers/install_helpers.php';
require_once $root . '/lib/helpers/view.php';

$model = corebb_install_model($_POST, $_SERVER);

header('Content-Type: text/html; charset=utf-8');
echo corebb_twig()->render('pages/install.twig', ['model' => $model]);
