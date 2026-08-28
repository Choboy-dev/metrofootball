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
$query = (string) ($data['query'] ?? '');

try {
    $result = TeamLinkService::search($query);
    Response::json(['ok' => true] + $result);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
