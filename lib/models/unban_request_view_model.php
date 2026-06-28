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
 |  unban_request_view_model.php  - Public               |
 |  banned-account unban request form.                   |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_moderation_view_model.php';

/**
 * Return the CSRF token used by the public unban request form.
 *
 * Usage: render and validate the banned-account request form.
 * Referenced by: corebb_unban_token_ok() and corebb_unban_request_model().
 *
 * @return string Session-backed unban request token.
 */
function corebb_unban_token(): string
{
    return corebb_security_named_token('unban_request_token');
}

/**
 * Validate a submitted public unban request token.
 *
 * Usage: reject stale or forged unban form submissions.
 * Referenced by: corebb_unban_request_model().
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @return bool True when the submitted token matches the session token.
 */
function corebb_unban_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('unban_request_token', $post, 'unban_request_token');
}

/**
 * Fetch recent unban requests for one banned user.
 *
 * Usage: show the account holder their latest request history on the banned page.
 * Referenced by: corebb_unban_request_model().
 *
 * @param int $userId User id whose requests should be listed.
 * @return array<int, array<string, mixed>> Recent request summaries.
 */
function corebb_unban_latest_requests(int $userId): array
{
    $items = [];
    if ($userId <= 0) {
        return $items;
    }
    foreach (db_all('SELECT * FROM unban_requests WHERE userid = ? ORDER BY id DESC LIMIT 5', [$userId]) as $row) {
        $items[] = corebb_admin_mod_request_summary($row);
    }
    return $items;
}

/**
 * Build the public banned-account request view model.
 *
 * Usage: render the banned page and process submit_unban_request posts for logged-in
 * banned accounts.
 * Referenced by: controllers/support.php action=banned.
 *
 * @param array<string, mixed> $viewer Current user/session row.
 * @param array<string, mixed> $post Submitted POST data.
 * @return array<string, mixed> View model for pages/banned.twig.
 */
function corebb_unban_request_model(array $viewer, array $post): array
{
    corebb_admin_mod_ensure_schema();

    $userId = (int)($viewer['id'] ?? 0);
    $username = (string)($viewer['username'] ?? '');
    $isBanned = ((string)($viewer['status'] ?? '') === '2');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    $model = [
        'title' => 'Account Banned',
        'user_id' => $userId,
        'username' => $username,
        'is_banned' => $isBanned,
        'token' => corebb_unban_token(),
        'messages' => [],
        'errors' => [],
        'form' => [
            'contact_email' => (string)($viewer['privemail'] ?? $viewer['pubemail'] ?? ''),
            'request_text' => '',
        ],
        'requests' => [],
        'ban_reason' => (string)($viewer['ban_reason'] ?? ''),
        'banned_at' => (string)($viewer['banned_at'] ?? ''),
    ];

    if ($isPost && (string)($post['action'] ?? '') === 'submit_unban_request') {
        $contactEmail = trim((string)($post['contact_email'] ?? ''));
        $requestText = trim((string)($post['request_text'] ?? ''));
        $model['form'] = ['contact_email' => $contactEmail, 'request_text' => $requestText];

        if (!$isBanned || $userId <= 0) {
            $model['errors'][] = 'Only a logged-in banned account can submit an unban request.';
        } elseif (!corebb_unban_token_ok($post)) {
            $model['errors'][] = 'Security token expired. Please reload this page and try again.';
        } elseif ($requestText === '') {
            $model['errors'][] = 'Please include a short explanation for your unban request.';
        } else {
            $now = corebb_admin_mod_now();
            $ip = corebb_admin_mod_current_ip();
            $existing = db_one("SELECT * FROM unban_requests WHERE userid = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
            if ($existing) {
                $ok = db_run(
                    'UPDATE unban_requests SET username = ?, contact_email = ?, ip_address = ?, request_text = ?, updated_at = ? WHERE id = ?',
                    [$username, $contactEmail, $ip, $requestText, $now, (int)$existing['id']]
                );
                if ($ok) {
                    $model['messages'][] = 'Your existing pending unban request was updated.';
                    $model['form']['request_text'] = '';
                } else {
                    $model['errors'][] = 'Error updating unban request: ' . db_error();
                }
            } else {
                $ok = db_run(
                    "INSERT INTO unban_requests (userid, username, contact_email, ip_address, request_text, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)",
                    [$userId, $username, $contactEmail, $ip, $requestText, $now, $now]
                );
                if ($ok) {
                    $model['messages'][] = 'Your unban request was submitted.';
                    $model['form']['request_text'] = '';
                } else {
                    $model['errors'][] = 'Error submitting unban request: ' . db_error();
                }
            }
        }
    }

    $model['requests'] = corebb_unban_latest_requests($userId);
    return $model;
}
