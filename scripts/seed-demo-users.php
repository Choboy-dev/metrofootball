<?php

declare(strict_types=1);

/**
 * Seed demo accounts from config/.env
 * Run: php scripts/seed-demo-users.php
 *
 * Set DEMO_ENABLED=1 in .env (default) while building on staging or production.
 * Set DEMO_ENABLED=0 before public launch to block re-seeding.
 */

require dirname(__DIR__) . '/public/api/bootstrap.php';

use App\Database;
use App\Env;

function demoEnabled(): bool
{
    $flag = strtolower(trim((string) (Env::get('DEMO_ENABLED', '1') ?? '1')));
    return !in_array($flag, ['0', 'false', 'no', 'off'], true);
}

if (!demoEnabled()) {
    echo "Demo seeding is disabled (DEMO_ENABLED=0).\n";
    exit(0);
}

$pdo = Database::pdo();
$now = gmdate('c');
$password = Env::get('DEMO_PASSWORD', 'Demo1234!') ?? 'Demo1234!';
$hash = password_hash($password, PASSWORD_DEFAULT);

// Retired demo account — remove if it still exists from older seeds.
$retiredEmail = 'unpaid@metrofootball.demo';
$retired = $pdo->prepare('SELECT id FROM users WHERE email = ? COLLATE NOCASE LIMIT 1');
$retired->execute([$retiredEmail]);
$retiredRow = $retired->fetch();
if ($retiredRow) {
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $retiredRow['id']]);
    echo "Removed retired demo account {$retiredEmail}\n";
}

$demos = [
    [
        'email' => Env::get('DEMO_FREE_EMAIL', 'free@metrofootball.demo') ?? 'free@metrofootball.demo',
        'plan' => 'free',
        'subscription_status' => 'none',
        'name' => 'Free Demo',
    ],
    [
        'email' => Env::get('DEMO_PREMIUM_EMAIL', 'premium@metrofootball.demo') ?? 'premium@metrofootball.demo',
        'plan' => 'premium',
        'subscription_status' => 'active',
        'name' => 'Premium Demo',
    ],
];

foreach ($demos as $demo) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([$demo['email']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, name = ?, plan = ?, subscription_status = ?, email_verified_at = ?, updated_at = ? WHERE id = ?'
        )->execute([$hash, $demo['name'], $demo['plan'], $demo['subscription_status'], $now, $now, (int) $existing['id']]);
        echo "Updated {$demo['email']}\n";
    } else {
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, google_id, name, plan, subscription_status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        )->execute([$demo['email'], $hash, $demo['name'], $demo['plan'], $demo['subscription_status'], $now, $now, $now]);
        echo "Created {$demo['email']}\n";
    }
}

$loginBase = rtrim(Env::get('APP_URL', '') ?? '', '/');
if ($loginBase === '') {
    $loginBase = 'https://metrofootball.co.uk';
}

echo "\nDemo password: {$password}\n";
echo "Free:    " . ($demos[0]['email']) . "\n";
echo "Premium: " . ($demos[1]['email']) . "\n";
echo "Login: {$loginBase}/login\n";
