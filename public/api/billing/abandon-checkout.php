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

$now = gmdate('c');
Database::pdo()->prepare(
    "UPDATE payments SET status = 'failed', updated_at = ? WHERE user_id = ? AND status = 'pending'"
)->execute([$now, $userId]);

$user = AuthService::revertUnpaidPremium($userId) ?: $user;
AuthService::consumeReturnPath();
Session::login($user);

Response::json([
    'ok' => true,
    'redirect' => '/join',
]);
