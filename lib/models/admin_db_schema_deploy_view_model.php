<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  admin_db_schema_deploy_view_model.php - Admin GUI    |
 |  model for non-destructive schema deploys.            |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/db_schema_deploy_helpers.php';

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_schema_deploy_token(): string
{
    return corebb_security_named_token('admin_db_schema_deploy_token');
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_schema_deploy_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('admin_db_schema_deploy_token', $post, 'admin_db_schema_deploy_token');
}

/**
 * Usage: Build the empty form state for DB schema deployment.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_schema_deploy_default_form(): array
{
    return [
        'staging_source' => 'schema',
        'production_source' => 'schema',
        'staging_schema' => '',
        'production_schema' => '',
        'staging_host' => '',
        'staging_port' => '',
        'staging_database' => '',
        'staging_username' => '',
        'production_host' => '',
        'production_port' => '',
        'production_database' => '',
        'production_username' => '',
        'apply_confirm' => '',
    ];
}

/**
 * Usage: Rebuild DB schema deploy form state from submitted values.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_schema_deploy_form(array $post): array
{
    $form = corebb_admin_schema_deploy_default_form();
    foreach ($form as $key => $_) {
        if (array_key_exists($key, $post)) {
            $form[$key] = (string)$post[$key];
        }
    }
    $form['staging_source'] = in_array($form['staging_source'], ['schema', 'database'], true) ? $form['staging_source'] : 'schema';
    $form['production_source'] = in_array($form['production_source'], ['schema', 'database'], true) ? $form['production_source'] : 'schema';
    $form['apply_confirm'] = '';
    return $form;
}

/**
 * Usage: Extract a prefixed database credential block from submitted values.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @param string $prefix Form field prefix.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_schema_deploy_credentials(array $post, string $prefix): array
{
    return [
        'host' => (string)($post[$prefix . '_host'] ?? ''),
        'port' => (string)($post[$prefix . '_port'] ?? ''),
        'database' => (string)($post[$prefix . '_database'] ?? ''),
        'username' => (string)($post[$prefix . '_username'] ?? ''),
        'password' => (string)($post[$prefix . '_password'] ?? ''),
    ];
}

/**
 * Usage: Read an uploaded SQL file for schema deployment.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $files Uploaded files array from admin.php.
 * @param string $prefix Form field prefix.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_schema_deploy_uploaded_sql(array $files, string $prefix): string
{
    $key = $prefix . '_schema_file';
    if (empty($files[$key]) || !is_array($files[$key])) {
        return '';
    }
    $file = $files[$key];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Schema upload failed for ' . $prefix . ' with error ' . $error . '.');
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Schema upload for ' . $prefix . ' could not be read.');
    }
    return (string)file_get_contents($tmpName);
}

/**
 * Usage: Load one schema source from database credentials or an uploaded SQL file.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $prefix Form field prefix.
 * @param string $label Display label.
 * @param array $post Posted form data from admin.php.
 * @param array $files Uploaded files array from admin.php.
 * @param bool $forceDatabase Whether the database source is required.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_schema_deploy_load_source(string $prefix, string $label, array $post, array $files, bool $forceDatabase = false): array
{
    $sourceMode = $forceDatabase ? 'database' : (string)($post[$prefix . '_source'] ?? 'schema');
    if ($sourceMode === 'database') {
        $credentials = corebb_admin_schema_deploy_credentials($post, $prefix);
        $pdo = corebb_schema_connect($credentials);
        $schema = corebb_schema_current_db_schema_pdo($pdo, trim((string)$credentials['database']));
        return [
            'schema' => $schema,
            'pdo' => $pdo,
            'database' => trim((string)$credentials['database']),
            'source' => $label . ' database',
        ];
    }

    $sql = corebb_admin_schema_deploy_uploaded_sql($files, $prefix);
    if ($sql === '') {
        $sql = (string)($post[$prefix . '_schema'] ?? '');
    }
    if (trim($sql) === '') {
        throw new InvalidArgumentException($label . ' schema dump is required, or choose database credentials as the source.');
    }

    $schema = corebb_schema_parse_sql($sql);
    if (!$schema) {
        throw new RuntimeException($label . ' schema did not contain any CREATE TABLE statements.');
    }
    return [
        'schema' => $schema,
        'pdo' => null,
        'database' => '',
        'source' => $label . ' schema dump',
    ];
}

/**
 * Usage: Build and process the db schema deploy admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @param array $files Uploaded files array from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_db_schema_deploy_model(array $viewer, array $get, array $post, array $files): array
{
    $model = [
        'title' => 'DB Schema Deploy',
        'errors' => [],
        'messages' => [],
        'apply_messages' => [],
        'plan' => null,
        'plan_text' => '',
        'sources' => [],
        'token' => corebb_admin_schema_deploy_token(),
        'form' => corebb_admin_schema_deploy_form($post),
    ];

    if ((int)($viewer['accesslevel'] ?? 0) < 5) {
        $model['errors'][] = 'Administrator access is required.';
        return $model;
    }

    $action = (string)($post['action'] ?? '');
    if ($action === '') {
        return $model;
    }

    if (!corebb_admin_schema_deploy_token_ok($post)) {
        $model['errors'][] = 'Security token expired. Please reload the page and try again.';
        return $model;
    }

    try {
        $target = corebb_admin_schema_deploy_load_source('staging', 'Staging target', $post, $files);
        $forceProductionDb = $action === 'apply';
        $current = corebb_admin_schema_deploy_load_source('production', 'Production current', $post, $files, $forceProductionDb);

        $plan = corebb_schema_build_plan($current['schema'], $target['schema']);
        $model['plan'] = $plan;
        $model['plan_text'] = corebb_schema_plan_text($plan);
        $model['sources'] = [$target['source'], $current['source']];
        $model['messages'][] = 'Plan generated from ' . $target['source'] . ' to ' . $current['source'] . '.';

        if ($action === 'apply') {
            if (strtoupper(trim((string)($post['apply_confirm'] ?? ''))) !== 'APPLY SCHEMA') {
                $model['errors'][] = 'Type APPLY SCHEMA to confirm production schema changes.';
                return $model;
            }
            if (!$current['pdo'] instanceof PDO) {
                $model['errors'][] = 'Production database credentials are required for apply mode.';
                return $model;
            }
            if (!$plan['operations']) {
                $model['messages'][] = 'No schema changes were needed.';
                return $model;
            }
            $model['apply_messages'] = corebb_schema_apply_plan_pdo($current['pdo'], $plan, (string)$current['database']);
            $model['messages'][] = 'Apply completed using non-destructive operations.';
        } else {
            $model['messages'][] = 'Dry run only. No database changes were made.';
        }
    } catch (Throwable $e) {
        $model['errors'][] = $e->getMessage();
    }

    return $model;
}
