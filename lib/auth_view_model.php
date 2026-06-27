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
 |  auth_view_model.php  - Auth/register view-model      |
 |  helpers.                                             |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/email_verification_helpers.php';
require_once __DIR__ . '/password_recovery_helpers.php';
require_once __DIR__ . '/tos_helpers.php';

/**
 * Usage: Build the login-page state from the optional message query string.
 * Referenced by: controllers/auth.php action=login.
 *
 * @param array<string, mixed> $get Query parameters passed into the login page.
 * @return array<string, mixed> Template data for the login form.
 */
function corebb_login_model(array $get = []): array
{
    $message = (string)($get['message'] ?? '');
    $error = '';

    if ($message === '3') {
        $error = 'No Username and/or Password entered.';
    } elseif ($message === '1') {
        $error = 'Incorrect Login Details: Unknown Account.';
    } elseif ($message !== '') {
        $error = $message;
    }

    return [
        'error' => $error,
        'expiryOptions' => [
            1209600 => 'Once Every 2 Weeks',
            604800  => 'Once Every Week',
            172800  => 'Every 48 Hours',
            86400   => 'Every 24 Hours',
            43200   => 'Every 12 Hours',
            28800   => 'Every 8 Hours',
            14400   => 'Every 4 Hours',
            7200    => 'Every 2 Hours',
            3600    => 'Every 1 Hour',
            1800    => 'Every 30 Minutes',
            900     => 'Every 15 Minutes',
        ],
        'defaultExpiry' => 28800,
    ];
}

/**
 * Usage: Build the account-recovery request page and handle submitted emails.
 * Referenced by: controllers/auth.php action=recover.
 *
 * @param array<string, mixed> $post Submitted recovery form fields.
 * @param string $method HTTP request method for this page load.
 * @return array<string, mixed> Template state for recover_account.twig.
 */
function corebb_recover_account_model(array $post = [], string $method = 'GET'): array
{
    $model = [
        'submitted' => false,
        'message' => '',
        'mail_error' => '',
        'debug_status' => [],
        'email' => '',
    ];

    if (strtoupper($method) === 'POST') {
        $email = trim((string)($post['email'] ?? ''));
        $result = corebb_password_recovery_send_request($email);
        $model['submitted'] = true;
        $model['message'] = (string)($result['message'] ?? 'If that email address belongs to an account, a password reset link has been sent.');
        $model['mail_error'] = (string)($result['mail_error'] ?? '');
        $model['debug_status'] = is_array($result['debug_status'] ?? null) ? $result['debug_status'] : [];
        $model['email'] = $email;
    }

    return $model;
}

/**
 * Usage: Build the password-reset page and process a submitted replacement.
 * Referenced by: controllers/auth.php action=reset.
 *
 * @param array<string, mixed> $get Query parameters containing the reset token.
 * @param array<string, mixed> $post Submitted password fields.
 * @param string $method HTTP request method for this page load.
 * @return array<string, mixed> Template state for reset_password.twig.
 */
function corebb_reset_password_model(array $get = [], array $post = [], string $method = 'GET'): array
{
    $method = strtoupper($method);
    $token = (string)($post['token'] ?? $get['token'] ?? '');
    $status = corebb_password_recovery_token_status($token);

    $model = [
        'token' => corebb_password_recovery_clean_token($token),
        'valid' => !empty($status['valid']),
        'done' => false,
        'message' => (string)($status['message'] ?? ''),
        'username' => is_array($status['row'] ?? null) ? (string)(($status['row']['username'] ?? '')) : '',
    ];

    if ($method === 'POST') {
        $result = corebb_password_recovery_complete(
            $token,
            (string)($post['pass1'] ?? ''),
            (string)($post['pass2'] ?? '')
        );
        $model['done'] = !empty($result['ok']);
        $model['valid'] = empty($result['ok']);
        $model['message'] = (string)($result['message'] ?? '');
    }

    return $model;
}

/**
 * Usage: Verify an email-confirmation token and format Twig variables.
 * Referenced by: controllers/auth.php action=verify.
 *
 * @param array<string, mixed> $get Query parameters containing token or t.
 * @return array<string, mixed> Template variables for verify_email.twig.
 */
function corebb_verify_email_template_data(array $get = []): array
{
    $token = (string)($get['token'] ?? $get['t'] ?? '');
    $result = corebb_email_verification_verify_token($token);

    $verified = !empty($result['verified']);
    $message = trim((string)($result['message'] ?? ''));
    if ($message === '') {
        $message = $verified
            ? 'Email verified. You may now log in.'
            : 'Unable to verify email address.';
        $result['message'] = $message;
    }
    $detail = trim((string)($result['detail'] ?? ''));

    return [
        'model' => $result,
        'verified' => $verified,
        'message' => $message,
        'detail' => $detail,
    ];
}

/**
 * Usage: Build the resend-verification page and handle submitted emails.
 * Referenced by: controllers/auth.php action=resend.
 *
 * @param array<string, mixed> $post Submitted resend form fields.
 * @param string $method HTTP request method for this page load.
 * @return array<string, mixed> Template state for resend_verification.twig.
 */
function corebb_resend_verification_model(array $post = [], string $method = 'GET'): array
{
    $model = [
        'submitted' => false,
        'message' => '',
        'mail_error' => '',
        'email' => '',
    ];

    if (strtoupper($method) === 'POST') {
        $email = trim((string)($post['email'] ?? ''));
        $result = corebb_email_verification_resend_by_email($email);
        $model['submitted'] = true;
        $model['message'] = (string)($result['message'] ?? 'If that email address has an unverified account, a new verification link has been sent.');
        $model['mail_error'] = (string)($result['mail_error'] ?? '');
        $model['email'] = $email;
    }

    return $model;
}

/**
 * Usage: Load the registration Terms of Service body and describe its format.
 * Referenced by: corebb_registration_model().
 *
 * @return array{body: string, body_format: string} TOS text and rendering mode.
 */
function corebb_registration_tos_model(): array
{
    $tos = corebb_tos_load_text();
    if ($tos !== '') {
        return [
            'body' => $tos,
            'body_format' => 'stored_html',
        ];
    }

    return [
        'body' => 'By registering, you agree to follow the board rules.',
        'body_format' => 'plain',
    ];
}

/**
 * Usage: Validate a registration attempt and return all template-facing state.
 * Referenced by: controllers/auth.php action=register and api/v1/index.php.
 *
 * @param array<string, mixed> $post Submitted registration fields.
 * @param string $method HTTP request method used for the current request.
 * @return array<string, mixed> Errors, success state, preserved form fields, and TOS data.
 */
function corebb_registration_model(array $post = [], string $method = 'GET'): array
{
    $errors = [];
    $success = false;
    $mailWarning = '';
    $old = [
        'username' => '',
        'email' => '',
    ];

    if (strtoupper($method) === 'POST') {
        $old['username'] = trim((string)($post['username'] ?? ''));
        $old['email'] = trim((string)($post['email'] ?? ''));
        $pass1 = (string)($post['pass1'] ?? '');
        $pass2 = (string)($post['pass2'] ?? '');
        $agree = !empty($post['agree_tos']);
        $ageConfirmed = !empty($post['confirm_age_13']);
        $honeypot = trim((string)($post['website'] ?? ''));

        if ($honeypot !== '') {
            $errors[] = 'Registration rejected.';
        }
        if ($old['username'] === '' || $old['email'] === '' || $pass1 === '' || $pass2 === '') {
            $errors[] = 'One or more required fields were left empty.';
        }
        if ($old['username'] !== '' && !preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $old['username'])) {
            $errors[] = 'Screen name must be 3-20 characters and use only letters, numbers, spaces, underscores, or dashes.';
        }
        if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($pass1 !== $pass2) {
            $errors[] = 'Passwords did not match.';
        }
        if (strlen($pass1) > 0 && strlen($pass1) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if (!$agree) {
            $errors[] = 'You must agree to the Terms of Service.';
        }
        if (!$ageConfirmed) {
            $errors[] = 'You must confirm that you are at least 13 years old.';
        }

        if (!$errors) {
            $create = corebb_create_user($old['username'], $old['email'], $pass1);
            if ($create['ok']) {
                $userid = (int)$create['id'];
                $mailResult = corebb_email_verification_send($userid, $old['username'], $old['email']);
                $success = true;
                if (empty($mailResult['sent'])) {
                    $mailWarning = 'Account created, but the verification email could not be sent: ' . (string)($mailResult['error'] ?? 'unknown mail error');
                }
                $old = ['username' => '', 'email' => ''];
            } else {
                $errors[] = $create['error'] !== '' ? $create['error'] : 'Unable to create account.';
            }
        }
    }

    return [
        'errors' => $errors,
        'success' => $success,
        'mailWarning' => $mailWarning,
        'old' => $old,
        'tos' => corebb_registration_tos_model(),
    ];
}
