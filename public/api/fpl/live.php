<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\LiveTeamService;
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

if (empty($user['fpl_team_id'])) {
    Response::json(['ok' => false, 'error' => 'Link your FPL team first.', 'redirect' => '/link-team'], 422);
}

Session::release();

$entryId = (int) $user['fpl_team_id'];

try {
    // Never rebuild FPL data inside this request when a snapshot exists.
    // Rebuilds happen via /api/fpl/live-refresh after the page has painted.
    $result = LiveTeamService::buildForEntry($entryId, $userId);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('X-MF-Cache: ' . $result['cache']);
    header('Cache-Control: private, max-age=30');

    echo json_encode([
        'ok' => true,
        'live' => $result['live'],
        'cache' => $result['cache'],
        'needs_refresh' => $result['needs_refresh'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
