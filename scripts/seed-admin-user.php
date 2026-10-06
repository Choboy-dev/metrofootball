<?php

declare(strict_types=1);

/**
 * Create or update the Metro Football admin account.
 * Usage: php scripts/seed-admin-user.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/AuthService.php';
require_once $root . '/app/AdminService.php';

use App\AdminService;
use App\Env;

Env::load($root . '/config/.env');

$email = strtolower(trim((string) Env::get('ADMIN_EMAIL', 'admin@metrofootball.co.uk')));
$password = (string) Env::get('ADMIN_PASSWORD', 'MfAdmin2026!Metro');

if ($password === '') {
    fwrite(STDERR, "Set ADMIN_PASSWORD in config/.env before running this script.\n");
    exit(1);
}

$user = AdminService::ensureAdminUser($email, $password, 'Metro Football Admin');

echo "Admin account ready\n";
echo "  Email:    {$email}\n";
echo "  Password: {$password}\n";
echo "  URL:      /admin/login\n";
echo "  User id:  " . ($user['id'] ?? '?') . "\n";
