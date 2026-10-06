<?php

declare(strict_types=1);

/**
 * Bootstrap the Metro Mail admin mailbox.
 * Usage: php scripts/setup-mail-admin.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Env.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/Mail/MailSession.php';
require_once $root . '/app/Mail/MailCsrf.php';
require_once $root . '/app/Mail/MailHtml.php';
require_once $root . '/app/Mail/ResendMailClient.php';
require_once $root . '/app/Mail/MailService.php';

use App\Env;
use App\Mail\MailService;

Env::load($root . '/config/.env');

$email = strtolower(trim((string) Env::get('MAIL_ADMIN_EMAIL', 'hello@metrofootball.co.uk')));
$displayName = trim((string) Env::get('MAIL_ADMIN_DISPLAY_NAME', 'Metro Football'));
$domain = strtolower(trim((string) Env::get('MAIL_DOMAIN', 'metrofootball.co.uk')));

if (!str_ends_with($email, '@' . $domain)) {
    fwrite(STDERR, "MAIL_ADMIN_EMAIL must be an @{$domain} address.\n");
    exit(1);
}

$password = (string) Env::get('MAIL_ADMIN_PASSWORD', '');
if ($password === '') {
    $password = bin2hex(random_bytes(8)) . 'Aa1!';
    echo "Generated temporary password (save it now):\n  {$password}\n\n";
}

$existing = MailService::findUserByEmail($email);
if ($existing) {
    MailService::updateUser((int) $existing['id'], [
        'display_name' => $displayName,
        'role' => 'admin',
        'is_active' => 1,
        'password' => $password,
        'must_change_password' => 1,
    ]);
    echo "Updated existing admin mailbox\n";
} else {
    MailService::createUser($email, $displayName, $password, 'admin', true);
    echo "Created admin mailbox\n";
}

echo "  Email:    {$email}\n";
echo "  Name:     {$displayName}\n";
echo "  Password: {$password}\n";
echo "  URL:      " . MailService::url('/login') . "\n";
