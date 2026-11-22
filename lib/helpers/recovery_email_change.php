<?php

require_once __DIR__ . '/password_recovery_helpers.php';
require_once __DIR__ . '/rate_limit_helpers.php';

function corebb_change_recovery_email(int $uid, array $post): string
{
    if (($post['step'] ?? '') === 'confirm') {
        return corebb_confirm_recovery_email($uid, trim((string)($post['code'] ?? '')));
    }
    $new = strtolower(trim((string)($post['new_email'] ?? '')));
    if ($uid <= 0 || strlen($new) > 255 || !filter_var($new, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid new recovery address.';
    }
    $user = db_one('SELECT username, privemail, password FROM users WHERE id = ?', [$uid]);
    if (!$user || empty(corebb_rate_limit_login_attempt($user['username'])['allowed'])
        || !corebb_auth_password_verify((string)($post['current_password'] ?? ''), $user['password'])) {
        return 'Unable to confirm your current password.';
    }
    if (strcasecmp($user['privemail'], $new) === 0 || db_exists('SELECT id FROM users WHERE LOWER(privemail) = ? LIMIT 1', [$new])) {
        return 'Choose a different recovery address.';
    }
    if (corebb_mail_disabled() || corebb_mail_redirect_to() !== '') {
        return 'Recovery email changes require direct mail delivery to the new address.';
    }
    $code = strtoupper(bin2hex(random_bytes(8)));
    $sent = corebb_mail_send($new, 'Confirm your CoreBB recovery address',
        "Enter this code in your CoreBB profile within 15 minutes to confirm your new recovery address.\n\n" . $code);
    if (empty($sent['sent'])) {
        return 'The confirmation email could not be sent. Your current recovery address is still active.';
    }
    $_SESSION['corebb_email_change'] = [
        'uid' => $uid, 'old' => $user['privemail'], 'new' => $new, 'expires' => time() + 900,
        'credential' => hash('sha256', $user['password']), 'code_hash' => hash('sha256', $code), 'tries' => 0,
    ];
    return 'Enter the code sent to your new address to finish the change.';
}

function corebb_confirm_recovery_email(int $uid, string $code): string
{
    $pending = $_SESSION['corebb_email_change'] ?? [];
    if (($pending['uid'] ?? null) !== $uid || ($pending['expires'] ?? 0) <= time() || $pending['tries'] >= 5) {
        unset($_SESSION['corebb_email_change']);
        return 'Request a fresh email confirmation code.';
    }
    $_SESSION['corebb_email_change']['tries']++;
    if (!hash_equals($pending['code_hash'], hash('sha256', strtoupper($code)))) {
        return 'The confirmation code is incorrect.';
    }
    corebb_password_recovery_ensure_schema();
    $failure = 'Unable to change your recovery address. Request a fresh code.';
    if (!db_begin()) {
        return $failure;
    }
    try {
        $user = db_one('SELECT privemail, password FROM users WHERE id = ? FOR UPDATE', [$uid]);
        $taken = db_value('SELECT COUNT(*) FROM users WHERE LOWER(privemail) = ? AND id <> ? FOR UPDATE', [$pending['new'], $uid], 1);
        if (!$user || $user['privemail'] !== $pending['old'] || (int)$taken !== 0
            || !hash_equals($pending['credential'], hash('sha256', $user['password']))
            || !db_run('UPDATE users SET privemail = ? WHERE id = ?', [$pending['new'], $uid])
            || !db_run('DELETE FROM password_resets WHERE user_id = ?', [$uid]) || !db_commit()) {
            db_rollback();
            return $failure;
        }
    } catch (Throwable $e) {
        db_rollback();
        return $failure;
    }
    unset($_SESSION['corebb_email_change']);
    if (filter_var($pending['old'], FILTER_VALIDATE_EMAIL)) {
        $notice = corebb_mail_send($pending['old'], 'Your CoreBB recovery email was changed',
            'The recovery address on your CoreBB account has changed. Contact the forum administrator if you did not authorize this.');
        if (empty($notice['sent'])) {
            return 'Recovery address updated. The notice to your previous address could not be delivered.';
        }
    }
    return 'Recovery email address updated.';
}
