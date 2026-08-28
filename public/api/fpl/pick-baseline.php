<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\FplClient;
use App\FplEntryPicksService;
use App\PickDraftService;
use App\PickTeamService;
use App\PlayerAiScout;
use App\Response;
use App\Session;
use App\TeamScoreComposer;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(180);
ini_set('max_execution_time', '180');

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

$entryId = (int) $user['fpl_team_id'];

try {
    $bootstrap = FplClient::fetchBootstrapPick();
    $events = $bootstrap['events'];
    $context = FplEntryPicksService::resolveContext($events);
    if (empty($context['editable'])) {
        Response::json([
            'ok' => false,
            'error' => 'Your team is locked while this gameweek is live on FPL.',
        ], 423);
    }

    $planningEventId = (int) ($context['planning_event']['id'] ?? 0);
    $resolved = FplEntryPicksService::resolveForGameweek($entryId, $events, $planningEventId, $userId);

    $squad = null;
    $bank = 0.0;
    if ($resolved['source'] === 'saved_draft') {
        $draft = PickDraftService::get($userId, $entryId, $planningEventId);
        if ($draft !== null) {
            $squad = $draft['squad'];
            $bank = (float) $draft['bank'];
        }
    }

    if ($squad === null) {
        $pickResult = PickTeamService::buildForEntry($entryId);
        $pick = $pickResult['pick'];
        $squad = $pick['squad'];
        $bank = (float) ($pick['bank'] ?? 0);
        $players = $pick['players'];
    } else {
        $pickResult = PickTeamService::buildForEntry($entryId);
        $players = $pickResult['pick']['players'];
    }

    $scoreMap = PlayerAiScout::ensureScores($planningEventId);
    $squad = TeamScoreComposer::enrichPlayers($squad, $scoreMap);
    $squad = PickTeamService::syncSquadFixturesFromCatalogue($squad, $players);

    $history = FplClient::getJsonMany([
        'history' => 'https://fantasy.premierleague.com/api/entry/' . $entryId . '/history/',
    ]);
    $freeTransfers = PickTeamService::estimateFreeTransfersPublic(
        $history['history'] ?? [],
        $context['planning_event']
    );

    Response::json([
        'ok' => true,
        'squad' => $squad,
        'bank' => $bank,
        'free_transfers' => $freeTransfers,
        'picks_event' => [
            'id' => $resolved['event_id'],
            'fallback' => $resolved['fallback'],
            'source' => $resolved['source'],
        ],
    ]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
