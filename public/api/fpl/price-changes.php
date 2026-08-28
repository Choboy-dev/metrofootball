<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\PriceChangeService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    Response::json([
        'ok' => true,
        'report' => PriceChangeService::build(),
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
