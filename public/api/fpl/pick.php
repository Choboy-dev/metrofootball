<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\PickPageService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(120);
ini_set('max_execution_time', '120');

try {
    $auth = PickPageService::authenticate();
    $shell = PickPageService::buildShell($auth['user_id'], $auth['entry_id'], $auth['premium']);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('X-MF-Cache: ' . $shell['cache']);
    header('X-MF-Pick-Phase: shell');
    header('Cache-Control: private, max-age=15');

    echo json_encode([
        'ok' => true,
        'pick' => $shell['pick'],
        'cache' => $shell['cache'],
        'premium' => $shell['premium'],
        'event_id' => $shell['event_id'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
