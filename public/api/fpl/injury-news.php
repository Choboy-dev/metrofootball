<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\InjuryNewsService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $live = InjuryNewsService::getLive();
    Response::json([
        'ok' => true,
        'needs_generate' => (bool) ($live['needs_generate'] ?? false),
        'target_gw' => (int) ($live['target_gw'] ?? 0),
        'target_name' => (string) ($live['target_name'] ?? ''),
        'post' => $live['post'],
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
