<?php
/*-------------------------------------------------------
 | support_view_model.php - Small public support models. |
 +-------------------------------------------------------*/

require_once __DIR__ . '/../helpers/tos_helpers.php';

/**
 * Usage: Read the trusted security reporting mailbox for this install.
 * Referenced by: corebb_security_page_model().
 */
function corebb_security_page_email(): string
{
    if (defined('COREBB_SECURITY_EMAIL')) {
        $configured = trim((string)COREBB_SECURITY_EMAIL);
        if ($configured !== '') {
            return $configured;
        }
    }

    $env = getenv('COREBB_SECURITY_EMAIL');
    $configured = is_string($env) ? trim($env) : '';
    return $configured !== '' ? $configured : 'security@corebb.net';
}

/**
 * Usage: Build display data for the public security overview page.
 * Referenced by: controllers/support.php action=security.
 *
 * @return array<string, mixed> Security page template model.
 */
function corebb_security_page_model(): array
{
    return [
        'security_email' => corebb_security_page_email(),
        'github_private_reporting_url' => 'https://github.com/HannsGrubers/CoreBB/security/advisories/new',
        'latest_supported_version' => 'latest stable release',
    ];
}

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
