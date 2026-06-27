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
 |  contact_mods_view_model.php  - Public Contact Mods   |
 |  view-model.                                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/contact_mods_helpers.php';
require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/rate_limit_helpers.php';

/**
 * Usage: Build the public Contact Mods form model and process new requests.
 * Referenced by: controllers/support.php action=contact.
 *
 * Logged-in users can submit a subject/message to the moderator inbox. Successful
 * POSTs are persisted in contact_mod_requests, flashed into the session, and
 * redirected back to the originating page with a 303 response. Anonymous viewers
 * receive a non-submitting model so the template can show the login state.
 *
 * @param array<string, mixed> $viewer Current logged-in user row, or an empty row for guests.
 * @param array<string, mixed> $get Query parameters, including the optional return URL.
 * @param array<string, mixed> $post POST payload for Contact Mods submissions.
 * @return array<string, mixed> Template model consumed by views/pages/contact_mods.twig.
 */
function corebb_contact_mods_public_model(array $viewer, array $get, array $post): array
{
    $messages = [];
    $errors = [];
    $viewerId = (int)($viewer['id'] ?? 0);
    $baseContactUrl = corebb_public_join_base_path('/contact-mods/');
    $returnUrl = corebb_contact_mods_request_return_url($get, $post);
    $contactUrl = corebb_contact_mods_url_with_return($baseContactUrl, $returnUrl);

    if (!corebb_load_logged_in_user() || $viewerId <= 0) {
        return [
            'viewer' => $viewer,
            'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
            'logged_in' => false,
            'messages' => [],
            'errors' => [],
            'submitted' => false,
            'token' => corebb_contact_mods_token(),
            'return_url' => $returnUrl,
            'contact_url' => $contactUrl,
            'input' => ['subject' => '', 'message' => ''],
        ];
    }

    corebb_contact_mods_ensure_schema();

    $subject = corebb_contact_mods_clean_text((string)($post['subject'] ?? ''), 160);
    $message = corebb_contact_mods_clean_text((string)($post['message'] ?? ''), 65535);
    $submitted = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!corebb_contact_mods_check_token($post)) {
            $errors[] = 'Invalid request token. Please try again.';
        }
        if ($subject === '') {
            $errors[] = 'Please enter a subject.';
        }
        if ($message === '') {
            $errors[] = 'Please enter your message.';
        }

        $dupeRow = false;
        if (!$errors) {
            $dupeRow = db_one(
                "SELECT id FROM contact_mod_requests WHERE userid = ? AND subject = ? AND message = ? AND status = 'new' LIMIT 1",
                [$viewerId, $subject, $message]
            );
            if ($dupeRow) {
                $messages[] = 'You already have that exact Contact Mods request in the moderator inbox.';
                $submitted = true;
                corebb_contact_mods_flash_set($messages, []);
                header('Location: ' . $returnUrl, true, 303);
                exit;
            }
        }

        if (!$dupeRow && !$errors) {
            $rate = corebb_rate_limit_report_submit($viewer);
            if (empty($rate['allowed'])) {
                $errors[] = corebb_rate_limit_message($rate, 'Contact Mods requests');
            }
        }

        if (!$dupeRow && !$errors) {
            $ok = db_run(
                'INSERT INTO contact_mod_requests (userid, subject, message, status, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?)',
                [$viewerId, $subject, $message, 'new', date('Y-m-d H:i:s'), corebb_mod_current_ip()]
            );
            if ($ok) {
                $messages[] = 'Your Contact Mods request has been submitted. A moderator response will arrive by private message.';
                $submitted = true;
                $subject = '';
                $message = '';
                corebb_contact_mods_flash_set($messages, []);
                header('Location: ' . $returnUrl, true, 303);
                exit;
            } else {
                $errors[] = 'Could not save your request: ' . db_error();
            }
        }
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'logged_in' => true,
        'messages' => $messages,
        'errors' => $errors,
        'submitted' => $submitted,
        'token' => corebb_contact_mods_token(),
        'return_url' => $returnUrl,
        'contact_url' => $contactUrl,
        'input' => ['subject' => $subject, 'message' => $message],
    ];
}
?>
