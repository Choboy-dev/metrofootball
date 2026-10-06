<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\PlayerProfileService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$club = trim((string) ($_GET['club'] ?? ''));
$player = trim((string) ($_GET['player'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));

if ($query !== '') {
    try {
        Response::json([
            'ok' => true,
            'results' => PlayerProfileService::search($query),
        ]);
    } catch (Throwable $e) {
        Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($club === '' || $player === '') {
    Response::json(['ok' => false, 'error' => 'Club and player are required.'], 422);
}

try {
    $profile = PlayerProfileService::resolve($club, $player);
    if ($profile === null) {
        Response::json(['ok' => false, 'error' => 'Player not found.'], 404);
    }

    Response::json([
        'ok' => true,
        'profile' => $profile,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
