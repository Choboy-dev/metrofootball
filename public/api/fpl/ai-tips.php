<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AiTipsService;
use App\AuthService;
use App\PlayerAiScout;
use App\PickTeamService;
use App\Response;
use App\Session;
use App\TeamScoreComposer;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$premium = ($user['plan'] ?? '') === 'premium'
    && ($user['subscription_status'] ?? '') === 'active';
if (!$premium) {
    Response::json([
        'ok' => false,
        'error' => 'AI tips are a Premium feature.',
        'redirect' => '/join',
    ], 403);
}

try {
    $result = PickTeamService::buildForEntry((int) $user['fpl_team_id']);
    $pick = $result['pick'];
    $eventId = (int) ($pick['event']['id'] ?? 0);

    // Serve gameweek cache before OpenAI work. Rebuild transfer swaps from scout
    // so uniqueness and plain-English reasons stay current without re-narrating.
    $cached = AiTipsService::getCached($userId, $eventId);
    if ($cached !== null) {
        $scoreMap = PlayerAiScout::ensureScores($eventId);
        $squad = TeamScoreComposer::enrichPlayers(
            is_array($pick['squad'] ?? null) ? $pick['squad'] : [],
            $scoreMap
        );
        $catalogue = TeamScoreComposer::enrichPlayers(
            is_array($pick['players'] ?? null) ? $pick['players'] : [],
            $scoreMap
        );
        $cached = AiTipsService::refreshTransferSection(
            $cached,
            $squad,
            $catalogue,
            (float) ($pick['bank'] ?? 0),
            (int) ($pick['free_transfers'] ?? 1)
        );
        $cached = AiTipsService::refreshCaptainSection($cached, $squad);
        $cached = AiTipsService::refreshDropWatchSection($cached, $squad);
        $cached = AiTipsService::refreshSnipersSection($cached, $squad, $catalogue);
        Response::json([
            'ok' => true,
            'tips' => $cached,
            'cache' => $result['cache'] ?? null,
        ]);
    }

    $scoreMap = PlayerAiScout::ensureScores($eventId);
    $squad = TeamScoreComposer::enrichPlayers($pick['squad'], $scoreMap);
    $catalogue = TeamScoreComposer::enrichPlayers($pick['players'], $scoreMap);
    $composed = TeamScoreComposer::compose($squad, $scoreMap);

    $tips = AiTipsService::build(
        $userId,
        $pick,
        $squad,
        $catalogue,
        $composed['strength'],
        $composed['predicted_points']
    );

    Response::json([
        'ok' => true,
        'tips' => $tips,
        'cache' => $result['cache'] ?? null,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
