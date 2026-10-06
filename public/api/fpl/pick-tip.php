<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\BillingService;
use App\PlayerAiScout;
use App\PickTeamService;
use App\Response;
use App\Session;
use App\TeamAiService;
use App\TeamScoreComposer;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.'], 401);
}

$user = AuthService::findUserById($userId);
if (!$user || empty($user['fpl_team_id'])) {
    Response::json(['ok' => false, 'error' => 'Link your FPL team first.'], 422);
}

$premium = BillingService::isPremiumActive($user);
if (!$premium) {
    Response::json(['ok' => false, 'error' => 'AI tips are a Premium feature.', 'redirect' => '/join'], 403);
}

$data = mf_read_json();
$squad = $data['squad'] ?? null;
if (!is_array($squad) || count($squad) < 11) {
    Response::json(['ok' => false, 'error' => 'Send your squad for a tip.'], 422);
}

try {
    $base = PickTeamService::buildForEntry((int) $user['fpl_team_id']);
    $pick = $base['pick'];
    $eventId = (int) ($pick['event']['id'] ?? 0);
    $scoreMap = PlayerAiScout::ensureScores($eventId);
    $squad = TeamScoreComposer::enrichPlayers($squad, $scoreMap);
    $composed = TeamScoreComposer::compose($squad, $scoreMap);

    $constraints = [
        'bank' => isset($data['bank']) ? (float) $data['bank'] : (float) ($pick['bank'] ?? 0),
        'free_transfers' => isset($data['free_transfers'])
            ? (int) $data['free_transfers']
            : (int) ($pick['free_transfers'] ?? 1),
        'hit_points' => isset($data['hit_points']) ? (int) $data['hit_points'] : 0,
        'chips' => $data['chips'] ?? ($pick['chips'] ?? []),
        'overall_rank' => $pick['entry']['overall_rank'] ?? null,
    ];

    $tip = TeamAiService::narrativeTip(
        $userId,
        $pick,
        $squad,
        $composed['strength'],
        $composed['predicted_points'],
        $constraints
    );

    Response::json([
        'ok' => true,
        'notes' => $tip['notes'],
        'cached' => $tip['cached'],
        'remaining' => $tip['remaining'],
        'model' => $tip['model'],
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
