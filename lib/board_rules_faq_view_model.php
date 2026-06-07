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
 |  board_rules_faq_view_model.php  - Public rules/FAQ   |
 |  view model.                                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/tos_helpers.php';

/**
 * Usage: Load Terms of Service content for the board-rules/FAQ page.
 * Referenced by: corebb_board_rules_faq_model().
 *
 * @return array{body: string, body_format: string} Terms content and render mode.
 */
function corebb_tos_content_model(): array
{
    $tos = corebb_tos_load_text();
    if ($tos === '') {
        $tos = '<p>The Terms of Service have not been configured yet.</p>';
    }

    return [
        'body' => $tos,
        'body_format' => 'stored_html',
    ];
}

/**
 * Usage: Build the public board-rules/FAQ page model.
 * Referenced by: controllers/support.php action=faq.
 *
 * @return array<string, mixed> Board-rules/FAQ display state for Twig.
 */
function corebb_board_rules_faq_model(): array
{
    return [
        'terms' => corebb_tos_content_model(),
    ];
}
