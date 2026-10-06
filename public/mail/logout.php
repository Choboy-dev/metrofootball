<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

$user = MailService::requireUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mail_url());
    exit;
}

require_csrf();
MailService::logout();
mail_flash('info', 'You signed out.');

header('Location: ' . mail_url('login'));
exit;
