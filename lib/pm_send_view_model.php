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
 |  pm_send_view_model.php  - View-model layer for       |
 |  composing private messages.                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/pm_helpers.php';
require_once __DIR__ . '/pm_view_model.php';

/**
 * Usage: Resolve a numeric recipient id from the route into a username string.
 * Referenced by: corebb_pm_send_model().
 *
 * @param mixed $userId Recipient user id from the query string.
 * @return string Username to prefill, or an empty string when invalid.
 */
function corebb_pm_prefill_recipient($userId): string
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return '';
    }
    return (string)db_value('SELECT username FROM users WHERE id = ? LIMIT 1', [(int)$userId], '');
}

/**
 * Usage: Normalize an optional reply subject for the compose form.
 * Referenced by: corebb_pm_send_model().
 *
 * @param mixed $title Raw title query value from a reply link.
 * @return string Clean subject prefixed with RE: when appropriate.
 */
function corebb_pm_reply_title($title): string
{
    $title = corebb_pm_clean_subject(str_replace('+', ' ', (string)$title));
    if ($title === '') {
        return '';
    }
    $replyTitle = stripos($title, 'RE:') === 0 ? $title : 'RE:' . $title;
    return corebb_pm_clean_subject($replyTitle);
}

/**
 * Usage: Build the private-message compose form model.
 * Referenced by: controllers/messages.php send route.
 *
 * The model contains folder counts, optional recipient/title prefill values,
 * and the user-visible recipient limit label. It does not write messages.
 *
 * @param array $user Current logged-in user row.
 * @param array $get Query parameters from the PM compose route.
 * @return array Template model consumed by views/pages/pm_send.twig.
 */
function corebb_pm_send_model(array $user, array $get): array
{
    $userId = corebb_pm_user_id($user);
    return [
        'counts' => corebb_pm_counts($userId),
        'recipient' => corebb_pm_prefill_recipient($get['usr'] ?? ''),
        'title' => corebb_pm_reply_title($get['title'] ?? ''),
        'recipient_limit' => ((int)($user['accesslevel'] ?? 0) > 1) ? 'Unlimited' : '5',
    ];
}
?>
