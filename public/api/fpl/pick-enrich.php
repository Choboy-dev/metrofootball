<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\PickPageService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(180);
ini_set('max_execution_time', '180');

try {
    $auth = PickPageService::authenticate();
    $gameweekId = isset($_GET['gw']) ? (int) $_GET['gw'] : null;
    if ($gameweekId !== null && $gameweekId < 1) {
        $gameweekId = null;
    }
    $enriched = PickPageService::enrich(
        $auth['user_id'],
        $auth['entry_id'],
        $auth['premium'],
        $gameweekId
    );

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('X-MF-Cache: ' . $enriched['cache']);
    header('X-MF-AI-Source: ' . $enriched['ai_source']);
    header('X-MF-Pick-Phase: enrich');
    header('Cache-Control: private, max-age=30');

    echo json_encode([
        'ok' => true,
        'pick' => $enriched['pick'],
        'cache' => $enriched['cache'],
        'premium' => $auth['premium'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
