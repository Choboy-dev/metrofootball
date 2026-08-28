<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\LeagueTableService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    Response::json([
        'ok' => true,
        'table' => LeagueTableService::build(),
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
