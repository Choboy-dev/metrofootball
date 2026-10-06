<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AiSeasonService;
use App\AuthService;
use App\BillingService;
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

$premium = BillingService::isPremiumActive($user);
if (!$premium) {
    Response::json([
        'ok' => false,
        'error' => 'AI Teams is a Premium feature.',
        'redirect' => '/join',
    ], 403);
}

try {
    $viewGw = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    if ($viewGw !== null && $viewGw < 1) {
        $viewGw = null;
    }
    $payload = AiSeasonService::getPayload($viewGw);
    Response::json([
        'ok' => true,
        'ai_teams' => $payload,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
