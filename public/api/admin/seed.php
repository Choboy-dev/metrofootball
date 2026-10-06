<?php

declare(strict_types=1);

/**
 * One-time admin bootstrap for production.
 * 1. Set ADMIN_SETUP_TOKEN, ADMIN_EMAIL, ADMIN_PASSWORD in config/.env
 * 2. POST /api/admin/seed.php with header X-Admin-Setup-Token: your-token
 * 3. Remove ADMIN_SETUP_TOKEN from .env after success
 */

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminService;
use App\Env;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'POST only'], 405);
}

$expected = trim((string) Env::get('ADMIN_SETUP_TOKEN', ''));
$provided = trim((string) ($_SERVER['HTTP_X_ADMIN_SETUP_TOKEN'] ?? ''));

if ($expected === '' || !hash_equals($expected, $provided)) {
    Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$email = strtolower(trim((string) Env::get('ADMIN_EMAIL', 'admin@metrofootball.co.uk')));
$password = (string) Env::get('ADMIN_PASSWORD', '');

if ($password === '') {
    Response::json(['ok' => false, 'error' => 'Set ADMIN_PASSWORD in config/.env'], 422);
}

$user = AdminService::ensureAdminUser($email, $password, 'Metro Football Admin');

Response::json([
    'ok' => true,
    'message' => 'Admin account ready. Remove ADMIN_SETUP_TOKEN from .env now.',
    'email' => $email,
    'url' => '/admin/login',
    'user_id' => (int) ($user['id'] ?? 0),
]);
