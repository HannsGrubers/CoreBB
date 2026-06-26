<?php
/*-------------------------------------------------------
 | security_page_view_model.php - Public security page.  |
 +-------------------------------------------------------*/

if (!defined('COREBB_SECURITY_PAGE_VIEW_MODEL_LOADED')) {
    define('COREBB_SECURITY_PAGE_VIEW_MODEL_LOADED', true);
}

/**
 * Usage: Read the trusted security reporting mailbox for this install.
 * Referenced by: corebb_security_page_model().
 *
 * @return string Configured security email, or an empty string when unset.
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
        'github_private_reporting_url' => 'https://github.com/HannsGrubers/CoreBB-Forum/security/advisories/new',
        'latest_supported_version' => 'latest stable release',
    ];
}
