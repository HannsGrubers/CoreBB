<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
/*-------------------------------------------------------
 | admin_auth_settings_view_model.php - Auth settings.
 +-------------------------------------------------------*/

require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/../helpers/oauth_provider_helpers.php';

/**
 * Usage: Normalize posted authentication settings.
 * Referenced by: corebb_admin_auth_settings_save().
 *
 * @param array<string, mixed> $post Raw POST payload.
 * @return array<string, array<string, mixed>> Normalized provider values keyed by provider.
 */
function corebb_admin_auth_settings_normalize(array $post): array
{
    $posted = is_array($post['authp'] ?? null)
        ? $post['authp']
        : (is_array($post['providers'] ?? null) ? $post['providers'] : []);
    $catalog = corebb_oauth_provider_catalog();
    $stored = corebb_oauth_stored_provider_settings();
    $values = [];

    foreach ($catalog as $key => $template) {
        $row = is_array($posted[$key] ?? null) ? $posted[$key] : [];
        $existing = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        $clientId = trim((string)($row['cid'] ?? $row['client_id'] ?? ''));
        $clientSecret = trim((string)($row['ckey'] ?? $row['client_secret'] ?? ''));
        if ($clientSecret === '' && !empty($existing['client_secret'])) {
            $clientSecret = (string)$existing['client_secret'];
        }

        $hostedDomain = strtolower(trim((string)($row['hd'] ?? $row['hosted_domain'] ?? '')));
        if ($hostedDomain !== '') {
            $hostedDomain = preg_replace('/[^a-z0-9.\-]/', '', $hostedDomain) ?? '';
        }

        $values[$key] = [
            'enabled' => !empty($row['en']) || !empty($row['enabled']),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'hosted_domain' => !empty($template['supports_hosted_domain']) ? $hostedDomain : '',
            'allow_auto_create' => !empty($row['ac']) || !empty($row['allow_auto_create']),
        ];
    }

    return $values;
}

/**
 * Usage: Validate normalized authentication settings.
 * Referenced by: corebb_admin_auth_settings_save().
 *
 * @param array<string, array<string, mixed>> $values Normalized provider values.
 * @return array<int, string> User-facing validation errors.
 */
function corebb_admin_auth_settings_validate(array $values): array
{
    $errors = [];
    $catalog = corebb_oauth_provider_catalog();
    foreach ($values as $key => $provider) {
        if (empty($provider['enabled'])) {
            continue;
        }
        $label = (string)($catalog[$key]['label'] ?? $key);
        $clientId = trim((string)($provider['client_id'] ?? ''));
        $clientSecret = trim((string)($provider['client_secret'] ?? ''));
        if ($clientId === '') {
            $clientId = corebb_oauth_config_value($key, 'client_id');
        }
        if ($clientSecret === '') {
            $clientSecret = corebb_oauth_config_value($key, 'client_secret');
        }
        $requiresSecret = !array_key_exists('requires_client_secret', $catalog[$key] ?? [])
            || !empty($catalog[$key]['requires_client_secret']);
        if ($clientId === '') {
            $errors[] = 'Enter a client ID before enabling ' . $label . '.';
        }
        if ($requiresSecret && $clientSecret === '') {
            $errors[] = 'Enter a client secret before enabling ' . $label . '.';
        }
        if (($provider['hosted_domain'] ?? '') !== '' && !preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+$/', (string)$provider['hosted_domain'])) {
            $errors[] = $label . ' hosted domain must be a valid domain name, such as example.com.';
        }
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

    $json = json_encode($values, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || !corebb_admin_setting_upsert('auth_oauth_providers', $json)) {
        $errors[] = 'Failed to save OAuth provider settings: ' . db_error();
    }

    // Keep the previous Google setting keys in sync for older callbacks and
    // installs that have not yet removed the Google Identity Services path.
    if (isset($values['google'])) {
        $google = $values['google'];
        $legacy = [
            'auth_google_enabled' => !empty($google['enabled']) ? '1' : '0',
            'auth_google_client_id' => (string)($google['client_id'] ?? ''),
            'auth_google_hosted_domain' => (string)($google['hosted_domain'] ?? ''),
            'auth_google_allow_auto_create' => !empty($google['allow_auto_create']) ? '1' : '0',
        ];
        foreach ($legacy as $name => $value) {
            if (!corebb_admin_setting_upsert($name, $value)) {
                $errors[] = 'Failed to save ' . $name . ': ' . db_error();
            }
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

    $providers = corebb_oauth_providers(true);
    $stored = corebb_oauth_stored_provider_settings();
    $enabledCount = 0;
    foreach ($providers as $provider) {
        if (!empty($provider['enabled']) && !empty($provider['configured'])) {
            $enabledCount++;
        }
    }

    $adminProviders = [];
    foreach ($providers as $provider) {
        $key = (string)$provider['key'];
        $saved = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        $savedClientId = trim((string)($saved['client_id'] ?? ''));
        if ($key === 'google' && $savedClientId === '') {
            $savedClientId = corebb_oauth_setting_get('auth_google_client_id', '');
        }
        $secretConfigured = trim((string)($saved['client_secret'] ?? '')) !== '' || corebb_oauth_config_value($key, 'client_secret') !== '';
        $adminProviders[$key] = $provider + [
            'saved_client_id' => $savedClientId,
            'secret_configured' => $secretConfigured,
            'client_id_source' => $savedClientId !== '' ? 'systemsettings' : (corebb_oauth_config_value($key, 'client_id') !== '' ? 'private config or environment' : 'not configured'),
            'secret_source' => trim((string)($saved['client_secret'] ?? '')) !== '' ? 'systemsettings' : (corebb_oauth_config_value($key, 'client_secret') !== '' ? 'private config or environment' : 'not configured'),
        ];
    }

    return [
        'viewer' => $viewer,
        'messages' => $messages,
        'errors' => $errors,
        'providers' => $adminProviders,
        'effective' => [
            'enabled_count' => $enabledCount,
            'provider_count' => count($providers),
        ],
    ];
}
