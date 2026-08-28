<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\LiveTeamService;
use App\Response;
use App\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.'], 401);
}

$user = AuthService::findUserById($userId);
if (!$user || empty($user['fpl_team_id'])) {
    Response::json(['ok' => false, 'error' => 'No linked team.'], 422);
}

try {
    LiveTeamService::refreshEntry((int) $user['fpl_team_id'], $userId);
    Response::json(['ok' => true, 'refreshed' => true]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
