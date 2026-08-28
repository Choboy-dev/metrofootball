<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\PickDraftService;
use App\PlayerAiScout;
use App\PickTeamService;
use App\Response;
use App\Session;
use App\TeamScoreComposer;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

$user = AuthService::findUserById($userId);
if (!$user) {
    Session::logout();
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

if (empty($user['fpl_team_id'])) {
    Response::json(['ok' => false, 'error' => 'Link your FPL team first.', 'redirect' => '/link-team'], 422);
}

Session::release();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    Response::json(['ok' => false, 'error' => 'Invalid request body.'], 400);
}

$squad = $data['squad'] ?? null;
if (!is_array($squad)) {
    Response::json(['ok' => false, 'error' => 'Your squad is missing from the save request.'], 422);
}

$bank = isset($data['bank']) ? (float) $data['bank'] : 0.0;
$strength = is_array($data['strength'] ?? null) ? $data['strength'] : null;
$predictedPoints = is_array($data['predicted_points'] ?? null) ? $data['predicted_points'] : null;
$gameweekId = isset($data['gameweek_id']) ? (int) $data['gameweek_id'] : 0;

$entryId = (int) $user['fpl_team_id'];

try {
    $pickResult = PickTeamService::buildForEntry($entryId);
    $pickMeta = $pickResult['pick'];
    if (empty($pickMeta['editable'])) {
        Response::json([
            'ok' => false,
            'error' => 'Your team is locked while this gameweek is live on FPL.',
        ], 423);
    }

    if ($gameweekId < 1) {
        $gameweekId = (int) ($pickMeta['event']['id'] ?? 0);
    }
    if ($gameweekId < 1) {
        throw new \RuntimeException('No gameweek is available to save your team right now.');
    }
    if ($gameweekId !== (int) ($pickMeta['event']['id'] ?? 0)) {
        Response::json([
            'ok' => false,
            'error' => 'That gameweek is not open for planning right now.',
        ], 422);
    }

    $saved = PickDraftService::save(
        $userId,
        $entryId,
        $gameweekId,
        $squad,
        $bank,
        $strength,
        $predictedPoints
    );

    $eventId = $gameweekId;
    $scoreMap = PlayerAiScout::ensureScores($eventId);
    $enrichedSquad = TeamScoreComposer::enrichPlayers($squad, $scoreMap);
    $composed = TeamScoreComposer::compose($enrichedSquad, $scoreMap);

    Response::json([
        'ok' => true,
        'saved_at' => $saved['saved_at'],
        'has_saved_draft' => true,
        'strength' => $strength ?? $composed['strength'],
        'predicted_points' => $predictedPoints,
    ]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
