<?php

declare(strict_types=1);

/**
 * One-time Metro Mail bootstrap for production (shared hosting without SSH).
 * 1. Set MAIL_SETUP_TOKEN, MAIL_ADMIN_EMAIL, MAIL_ADMIN_PASSWORD in config/.env
 * 2. POST /api/mail/setup.php with header X-Mail-Setup-Token: your-token
 * 3. Remove MAIL_SETUP_TOKEN from .env after success
 */

$root = dirname(__DIR__, 3);

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

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

Env::load($root . '/config/.env');

$expected = trim((string) Env::get('MAIL_SETUP_TOKEN', ''));
$provided = trim((string) ($_SERVER['HTTP_X_MAIL_SETUP_TOKEN'] ?? ''));

if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

MailService::ensureStorage();

define('MAIL_MIGRATIONS_INCLUDED', true);
ob_start();
try {
    require $root . '/scripts/run-mail-migrations.php';
    $migrationLog = trim(ob_get_clean());
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Migration failed: ' . $e->getMessage()]);
    exit;
}

try {
$email = strtolower(trim((string) Env::get('MAIL_ADMIN_EMAIL', 'hello@metrofootball.co.uk')));
$displayName = trim((string) Env::get('MAIL_ADMIN_DISPLAY_NAME', 'Metro Football'));
$password = (string) Env::get('MAIL_ADMIN_PASSWORD', '');

if ($password === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Set MAIL_ADMIN_PASSWORD in config/.env']);
    exit;
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
    $created = false;
} else {
    MailService::createUser($email, $displayName, $password, 'admin', true);
    $created = true;
}

echo json_encode([
    'ok' => true,
    'message' => 'Metro Mail is ready. Remove MAIL_SETUP_TOKEN from .env now.',
    'created' => $created,
    'email' => $email,
    'url' => MailService::url('/login'),
    'migrations' => $migrationLog,
], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
