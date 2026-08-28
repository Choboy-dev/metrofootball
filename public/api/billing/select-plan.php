<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\Database;
use App\Response;
use App\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

$user = AuthService::findUserById($userId);
if (!$user) {
    Session::logout();
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

$data = mf_read_json();
$plan = AuthService::normalizePlan((string) ($data['plan'] ?? 'free'));
AuthService::rememberReturnPath((string) ($data['next'] ?? ''));
$intended = AuthService::resolveIntended(null);
$now = gmdate('c');

if ($plan === 'free') {
    Database::pdo()->prepare(
        'UPDATE users SET plan = ?, subscription_status = ?, billing_cycle = NULL, subscription_expires_at = NULL, updated_at = ? WHERE id = ?'
    )->execute(['free', 'none', $now, $userId]);

    $updated = AuthService::findUserById($userId);
    if ($updated) {
        Session::login($updated);
        Response::json(['ok' => true, 'redirect' => AuthService::postAuthPath($updated, $intended)]);
    }

    Response::json(['ok' => true, 'redirect' => '/link-team']);
}

// Premium path: keep active subscribers on their dashboard; unpaid upgrades go straight to payment
if (($user['subscription_status'] ?? '') === 'active' && ($user['plan'] ?? '') === 'premium') {
    Response::json(['ok' => true, 'redirect' => AuthService::postAuthPath($user, $intended)]);
}

Database::pdo()->prepare(
    'UPDATE users SET plan = ?, updated_at = ? WHERE id = ?'
)->execute(['premium', $now, $userId]);

$updated = AuthService::findUserById($userId);
if ($updated) {
    Session::login($updated);
}

Response::json(['ok' => true, 'redirect' => AuthService::appendReturnToPath('/payment', $intended)]);
