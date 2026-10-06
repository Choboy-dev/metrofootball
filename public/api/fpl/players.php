<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\PlayersService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $idsRaw = isset($_GET['ids']) ? trim((string) $_GET['ids']) : '';
    if ($idsRaw !== '') {
        $ids = array_values(array_filter(
            array_map(static fn (string $part): int => (int) trim($part), explode(',', $idsRaw)),
            static fn (int $id): bool => $id > 0
        ));

        Response::json([
            'ok' => true,
            'players' => PlayersService::lookupByIds($ids),
        ]);
    }

    $club = isset($_GET['club']) ? trim((string) $_GET['club']) : '';
    if ($club !== '') {
        Response::json([
            'ok' => true,
            'club' => PlayersService::playersByClub($club),
        ]);
    }

    Response::json([
        'ok' => true,
        'report' => PlayersService::build(),
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
