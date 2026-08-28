<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\GameweekRecapService;
use App\Response;
use App\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$entryId = null;
Session::start();
$userId = Session::userId();
if ($userId) {
    $user = AuthService::findUserById($userId);
    if ($user && isset($user['fpl_team_id']) && $user['fpl_team_id'] !== null && $user['fpl_team_id'] !== '') {
        $entryId = (int) $user['fpl_team_id'];
    }
}
Session::release();

$requestedGw = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
if ($requestedGw !== null && $requestedGw < 1) {
    $requestedGw = null;
}

try {
    $recap = GameweekRecapService::build($requestedGw, $entryId);
    $finished = !empty($recap['event']['finished']);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    if ($finished) {
        header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
    } else {
        header('Cache-Control: private, max-age=60');
    }

    echo json_encode([
        'ok' => true,
        'recap' => $recap,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
