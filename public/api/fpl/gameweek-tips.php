<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\GameweekTipsService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$metaOnly = isset($_GET['meta']) && (string) $_GET['meta'] === '1';
$requestedGw = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
if ($requestedGw !== null && $requestedGw < 1) {
    $requestedGw = null;
}

try {
    if ($metaOnly) {
        Response::json(GameweekTipsService::getMeta());
    }

    $payload = GameweekTipsService::getForRequest($requestedGw);
    Response::json($payload);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
