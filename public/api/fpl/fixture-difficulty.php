<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\FixtureDifficultyService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
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
