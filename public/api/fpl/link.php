<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\Response;
use App\Session;
use App\TeamLinkService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId || !AuthService::findUserById($userId)) {
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

$data = mf_read_json();
$fplTeamId = (int) ($data['fpl_team_id'] ?? 0);

try {
    $result = TeamLinkService::link($userId, $fplTeamId, (string) ($data['next'] ?? ''));
    Response::json([
        'ok' => true,
        'team' => $result['team'],
        'redirect' => $result['redirect'],
    ]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
