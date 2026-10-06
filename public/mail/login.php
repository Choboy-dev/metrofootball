<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;
use App\Mail\MailSession;

mail_send_security_headers();

if (MailService::currentUser() !== null) {
    header('Location: ' . mail_url());
    exit;
}

$error = mail_flash('error');
$pageTitle = 'Sign in';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (MailSession::isLoginRateLimited()) {
        mail_flash('error', 'Too many sign in attempts. Try again in 15 minutes.');
        header('Location: ' . mail_url('login'));
        exit;
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $user = MailService::authenticate($email, $password);
        if ($user === null) {
            mail_flash('error', 'Email or password is wrong.');
        } else {
            MailService::login($user);
            $redirect = !empty($user['must_change_password'])
                ? mail_url('change-password')
                : mail_url();
            header('Location: ' . $redirect);
            exit;
        }
    } catch (\Throwable $e) {
        mail_flash('error', $e->getMessage());
    }

    header('Location: ' . mail_url('login'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <?php require __DIR__ . '/partials/head-meta.php'; ?>
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/mail.css?v=3')) ?>">
</head>
<body class="mail-auth">
    <main class="mail-auth__card">
        <header class="mail-auth__brand">
            <img class="site-logo__img" src="/images/metro-football-correct-dark-logo-315.webp?v=20260830logo" srcset="/images/metro-football-correct-dark-logo-315.webp?v=20260830logo 315w, /images/metro-football-correct-dark-logo-630.webp?v=20260830logo 630w" sizes="(min-width: 768px) 204px, 180px" alt="" width="938" height="313" decoding="async">
            <h1 class="mail-auth__title"><?= mail_h(MailService::appName()) ?></h1>
            <p class="mail-auth__lead">Sign in to your Metro Football mailbox.</p>
        </header>

        <?php if ($error !== null): ?>
            <div class="mail-flash mail-flash--error" role="alert"><?= mail_h($error) ?></div>
        <?php endif; ?>

        <form class="mail-auth__form" method="post" action="<?= mail_h(mail_url('login')) ?>">
            <?= csrf_field() ?>

            <label class="mail-field">
                <span class="mail-field__label">Email</span>
                <input
                    class="mail-field__input"
                    type="email"
                    name="email"
                    required
                    autocomplete="username"
                    inputmode="email"
                    spellcheck="false"
                >
            </label>

            <label class="mail-field">
                <span class="mail-field__label">Password</span>
                <input
                    class="mail-field__input"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    minlength="12"
                >
            </label>

            <button type="submit" class="mf-btn mf-btn--primary mf-btn--block mail-auth__submit">
                <span class="mf-btn__label">Sign in</span>
                <span class="mf-btn__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </button>
        </form>
    </main>
</body>
</html>
