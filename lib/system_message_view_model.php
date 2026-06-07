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
 |  system_message_view_model.php - System message view  |
 |  models.                                              |
 +-------------------------------------------------------+*/

if (!defined('COREBB_VIEW_LOADED')) {
    require_once __DIR__ . '/view.php';
}

/**
 * Usage: Build common system-message pages such as denied and error displays.
 * Referenced by: controllers/support.php denied and error actions.
 *
 * @param string $type Message type to render.
 * @param array<string, mixed> $request Optional request data for error messages.
 * @return array<string, mixed> System-message display state for Twig.
 */
function corebb_system_message_model(string $type, array $request = []): array
{
    $type = strtolower($type);

    if ($type === 'banned') {
        return [
            'title' => 'Banned',
            'message_type' => 'banned',
            'icon' => '',
        ];
    }

    if ($type === 'denied') {
        return [
            'title' => 'Access Denied!',
            'message_type' => 'denied',
            'message' => 'You do not have permission to access the requested page.',
            'icon' => 'warn',
        ];
    }

    $message = trim((string)($request['msg'] ?? 'A system error has occurred. We apologize for the inconvenience.'));
    $message = str_replace('+', ' ', $message);
    return [
        'title' => 'Board System Error',
        'message_type' => 'error',
        'message' => $message,
        'icon' => '',
        'date' => date('j-w-y g:ia'),
    ];
}
