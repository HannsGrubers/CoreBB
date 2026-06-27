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
 |  index.php  - Template backed index initializer.      |
 +-------------------------------------------------------+*/
require_once __DIR__ . '/lib/bootstrap.php';
require_once('lib/view.php');
require_once('lib/layout_view_model.php');
require_once('lib/index_view_model.php');
require_once('lib/mobile_helpers.php');

corebb_mobile_redirect('index');

$categoryId = null;
if (isset($_GET['b']) && $_GET['b'] !== '') {
    $categoryId = (int)$_GET['b'];
}

$collapsedCategoryId = null;
if (isset($_GET['collapse']) && $_GET['collapse'] !== '') {
    $collapsedCategoryId = (int)$_GET['collapse'];
}

$showEmptyArchiveBoards = isset($_GET['show_empty']) && (string)$_GET['show_empty'] === '1';
$model = corebb_fetch_index_model($categoryId, $showEmptyArchiveBoards, $collapsedCategoryId);
corebb_render_public('pages/index.twig', ['model' => $model]);
