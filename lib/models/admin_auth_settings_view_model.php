<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
/*-------------------------------------------------------
 | admin_auth_settings_view_model.php - Auth settings.
 +-------------------------------------------------------*/

require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/../helpers/google_auth_helpers.php';

/**
 * Usage: Normalize posted authentication settings.
 * Referenced by: corebb_admin_auth_settings_save().
 *
 * @param array<string, mixed> $post Raw POST payload.
 * @return array<string, string> Normalized setting values keyed by systemsettings name.
 */
function corebb_admin_auth_settings_normalize(array $post): array
{
    $clientId = trim((string)($post['google_client_id'] ?? ''));
    $hostedDomain = strtolower(trim((string)($post['google_hosted_domain'] ?? '')));
    if ($hostedDomain !== '') {
        $hostedDomain = preg_replace('/[^a-z0-9.\-]/', '', $hostedDomain) ?? '';
    }

    return [
        'auth_google_enabled' => !empty($post['google_enabled']) ? '1' : '0',
        'auth_google_client_id' => $clientId,
        'auth_google_hosted_domain' => $hostedDomain,
        'auth_google_allow_auto_create' => !empty($post['google_allow_auto_create']) ? '1' : '0',
    ];
}

/**
 * Usage: Validate normalized authentication settings.
 * Referenced by: corebb_admin_auth_settings_save().
 *
 * @param array<string, string> $values Normalized setting values.
 * @return array<int, string> User-facing validation errors.
 */
function corebb_admin_auth_settings_validate(array $values): array
{
    $errors = [];
    $clientId = trim((string)($values['auth_google_client_id'] ?? ''));
    if (($values['auth_google_enabled'] ?? '0') === '1' && $clientId === '' && corebb_google_config_client_id() === '') {
        $errors[] = 'Enter a Google client ID before enabling Google sign-in.';
    }
    if (($values['auth_google_hosted_domain'] ?? '') !== '' && !preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+$/', $values['auth_google_hosted_domain'])) {
        $errors[] = 'Google hosted domain must be a valid domain name, such as example.com.';
    }
    return $errors;
}

/**
 * Usage: Save authentication settings from the admin panel.
 * Referenced by: corebb_admin_auth_settings_model().
 *
 * @param array<string, mixed> $post Raw POST payload.
 * @return array{messages: array<int, string>, errors: array<int, string>}
 */
function corebb_admin_auth_settings_save(array $post): array
{
    $values = corebb_admin_auth_settings_normalize($post);
    $errors = corebb_admin_auth_settings_validate($values);
    if ($errors) {
        return ['messages' => [], 'errors' => $errors];
    }

    foreach ($values as $name => $value) {
        if (!corebb_admin_setting_upsert($name, $value)) {
            $errors[] = 'Failed to save ' . $name . ': ' . db_error();
        }
    }

    return [
        'messages' => $errors ? [] : ['Authentication settings saved.'],
        'errors' => $errors,
    ];
}

/**
 * Usage: Build the admin authentication settings form model.
 * Referenced by: admin route act=auth_settings.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query/request values.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed> Template model.
 */
function corebb_admin_auth_settings_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $errors = [];

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && (string)($post['action'] ?? '') === 'save_auth') {
        $result = corebb_admin_auth_settings_save($post);
        $messages = $result['messages'];
        $errors = $result['errors'];
        if (!$errors ) {
            corebb_adminlog_entry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), 'Modified authentication settings');
        }
    }

    $systemClientId = corebb_google_system_client_id();
    $effectiveClientId = corebb_google_client_id();

    return [
        'viewer' => $viewer,
        'messages' => $messages,
        'errors' => $errors,
        'values' => [
            'google_enabled' => corebb_auth_setting_enabled('auth_google_enabled', $effectiveClientId !== ''),
            'google_client_id' => $systemClientId,
            'google_hosted_domain' => corebb_auth_setting_get('auth_google_hosted_domain', ''),
            'google_allow_auto_create' => corebb_auth_setting_enabled('auth_google_allow_auto_create', true),
        ],
        'effective' => [
            'google_configured' => $effectiveClientId !== '',
            'google_enabled' => corebb_google_enabled(),
            'client_id_source' => $systemClientId !== '' ? 'systemsettings' : ($effectiveClientId !== '' ? 'private config or environment' : 'not configured'),
            'login_url' => corebb_google_login_url(),
        ],
    ];
}
