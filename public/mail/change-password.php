<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;
use App\Mail\MailSession;

mail_send_security_headers();

$user = MailService::requireUser();

if (empty($user['must_change_password'])) {
    header('Location: ' . mail_url());
    exit;
}

$error = mail_flash('error');
$pageTitle = 'Change password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $current = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== $confirm) {
        mail_flash('error', 'Passwords do not match.');
    } else {
        try {
            MailService::changePassword(mail_user_id($user), $current, $password);
            MailSession::forget('mail_compose_retry');
            mail_flash('success', 'Password updated. You can use mail now.');
            header('Location: ' . mail_url());
            exit;
        } catch (\Throwable $e) {
            mail_flash('error', $e->getMessage());
        }
    }

    header('Location: ' . mail_url('change-password'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <?php require __DIR__ . '/partials/head-meta.php'; ?>
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/mail.css?v=2')) ?>">
</head>
<body class="mail-auth">
    <main class="mail-auth__card">
        <header class="mail-auth__brand">
            <img class="site-logo__img" src="/images/metro-football-correct-dark-logo-315.webp?v=20260830logo" srcset="/images/metro-football-correct-dark-logo-315.webp?v=20260830logo 315w, /images/metro-football-correct-dark-logo-630.webp?v=20260830logo 630w" sizes="(min-width: 768px) 204px, 180px" alt="" width="938" height="313" decoding="async">
            <h1 class="mail-auth__title">Choose a new password</h1>
            <p class="mail-auth__lead">Enter the temporary password you were given, then choose a new one.</p>
        </header>

        <?php if ($error !== null): ?>
            <div class="mail-flash mail-flash--error" role="alert"><?= mail_h($error) ?></div>
        <?php endif; ?>

        <form class="mail-auth__form" method="post" action="<?= mail_h(mail_url('change-password')) ?>">
            <?= csrf_field() ?>

            <label class="mail-field">
                <span class="mail-field__label">Temporary password</span>
                <input
                    class="mail-field__input"
                    type="password"
                    name="current_password"
                    required
                    autocomplete="current-password"
                >
            </label>

            <label class="mail-field">
                <span class="mail-field__label">New password</span>
                <input
                    class="mail-field__input"
                    type="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    minlength="12"
                >
            </label>

            <label class="mail-field">
                <span class="mail-field__label">Confirm new password</span>
                <input
                    class="mail-field__input"
                    type="password"
                    name="password_confirm"
                    required
                    autocomplete="new-password"
                    minlength="12"
                >
            </label>

            <button type="submit" class="mf-btn mf-btn--primary mf-btn--block mail-auth__submit">
                <span class="mf-btn__label">Save password</span>
                <span class="mf-btn__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </button>
        </form>
    </main>
</body>
</html>
