<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\PlayerAiScout;
use App\PickTeamService;
use App\Response;
use App\Session;
use App\TeamScoreComposer;
use App\TransferSuggestService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

$user = AuthService::findUserById($userId);
if (!$user || empty($user['fpl_team_id'])) {
    Response::json(['ok' => false, 'error' => 'Link your FPL team first.', 'redirect' => '/link-team'], 422);
}

Session::release();

$premium = ($user['plan'] ?? '') === 'premium'
    && ($user['subscription_status'] ?? '') === 'active';

$data = mf_read_json();
$squad = $data['squad'] ?? null;
if (!is_array($squad) || count($squad) < 11) {
    Response::json(['ok' => false, 'error' => 'Send your full edited squad for analysis.'], 422);
}

try {
    $entryId = (int) $user['fpl_team_id'];
    $base = PickTeamService::buildForEntry($entryId);
    $pick = $base['pick'];
    $eventId = (int) ($pick['event']['id'] ?? 0);
    $scoreMap = PlayerAiScout::ensureScores($eventId);

    $squad = TeamScoreComposer::enrichPlayers($squad, $scoreMap);
    $composed = TeamScoreComposer::compose($squad, $scoreMap);

    $suggested = [];
    if ($premium) {
        $catalogue = TeamScoreComposer::enrichPlayers($pick['players'], $scoreMap);
        $bank = isset($data['bank']) && is_numeric($data['bank'])
            ? (float) $data['bank']
            : (float) ($pick['bank'] ?? 0);
        $suggested = TransferSuggestService::suggest(
            $squad,
            $catalogue,
            $bank,
            (int) ($pick['free_transfers'] ?? 1)
        );
    }

    Response::json([
        'ok' => true,
        'strength' => $composed['strength'],
        'predicted_points' => $premium ? $composed['predicted_points'] : null,
        'suggested_transfers' => $suggested,
        'squad' => $squad,
        'source' => $composed['source'],
        'cached' => true,
        'premium' => $premium,
    ]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
