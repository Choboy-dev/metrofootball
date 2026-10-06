<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\BillingService;
use App\ChipStrategyService;
use App\PickTeamService;
use App\PlayerAiScout;
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

$premium = BillingService::isPremiumActive($user);
if (!$premium) {
    Response::json([
        'ok' => false,
        'error' => 'Chip Strategy Planner is a Premium feature.',
        'redirect' => '/join',
    ], 403);
}

try {
    $entryId = (int) $user['fpl_team_id'];
    $result = PickTeamService::buildForEntry($entryId);
    $pick = $result['pick'];
    $eventId = (int) ($pick['event']['id'] ?? 0);

    $scoreMap = PlayerAiScout::ensureScores($eventId);
    $squad = TeamScoreComposer::enrichPlayers(
        is_array($pick['squad'] ?? null) ? $pick['squad'] : [],
        $scoreMap
    );
    $composed = TeamScoreComposer::compose($squad, $scoreMap);

    $plan = ChipStrategyService::build($entryId, $pick, $squad, $composed);

    Response::json([
        'ok' => true,
        'plan' => $plan,
        'cache' => $result['cache'] ?? null,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
