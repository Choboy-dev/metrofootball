<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\FixtureDifficultyService;
use App\Response;
use App\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$premium = ($user['plan'] ?? '') === 'premium'
    && ($user['subscription_status'] ?? '') === 'active';
if (!$premium) {
    Response::json([
        'ok' => false,
        'error' => 'Fixture Difficulty Ticker is a Premium feature.',
        'redirect' => '/join',
    ], 403);
}

$horizon = isset($_GET['horizon']) ? (int) $_GET['horizon'] : 5;

try {
    $payload = FixtureDifficultyService::build($horizon);
    Response::json([
        'ok' => true,
        'ticker' => $payload,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
