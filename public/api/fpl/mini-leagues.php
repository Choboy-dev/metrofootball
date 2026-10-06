<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\BillingService;
use App\MiniLeagueService;
use App\Response;
use App\Session;
use App\TrackedLeagueService;

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
        'error' => 'Mini League Tracker is a Premium feature.',
        'redirect' => '/join',
    ], 403);
}

$entryId = (int) $user['fpl_team_id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $leagueId = (int) ($_GET['league_id'] ?? 0);

        if ($leagueId > 0) {
            $dashboard = MiniLeagueService::buildDashboard($userId, $entryId, $leagueId);
            Response::json(['ok' => true, 'dashboard' => $dashboard]);
        }

        $overview = MiniLeagueService::buildOverview($userId, $entryId);
        Response::json(['ok' => true, 'overview' => $overview]);
    }

    if ($method === 'POST') {
        $payload = mf_read_json();
        $leagueId = (int) ($payload['league_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));

        $standings = \App\FplClient::fetchAllClassicLeagueStandings($leagueId);
        $found = false;
        foreach ($standings as $row) {
            if ((int) ($row['entry'] ?? 0) === $entryId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            Response::json(['ok' => false, 'error' => 'Your FPL team is not in that league.'], 422);
        }

        $tracked = TrackedLeagueService::add($userId, $leagueId, $name);
        $dashboard = MiniLeagueService::buildDashboard($userId, $entryId, $leagueId);

        Response::json([
            'ok' => true,
            'tracked' => $tracked,
            'dashboard' => $dashboard,
        ]);
    }

    if ($method === 'PATCH') {
        $payload = mf_read_json();
        $leagueId = (int) ($payload['league_id'] ?? 0);
        $enabled = !empty($payload['alerts_enabled']);

        $existing = TrackedLeagueService::findForUser($userId, $leagueId);
        if ($existing === null) {
            Response::json(['ok' => false, 'error' => 'League not found in your list.'], 404);
        }

        TrackedLeagueService::setAlerts($userId, $leagueId, $enabled);
        Response::json(['ok' => true, 'alerts_enabled' => $enabled]);
    }

    if ($method === 'DELETE') {
        $payload = mf_read_json();
        $leagueId = (int) ($payload['league_id'] ?? $_GET['league_id'] ?? 0);
        TrackedLeagueService::remove($userId, $leagueId);
        $overview = MiniLeagueService::buildOverview($userId, $entryId);
        Response::json(['ok' => true, 'overview' => $overview]);
    }

    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
