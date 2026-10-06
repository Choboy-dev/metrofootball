<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\BillingService;
use App\Response;
use App\RivalProfileService;
use App\Session;

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

if (!BillingService::isPremiumActive($user)) {
    Response::json([
        'ok' => false,
        'error' => 'Mini League Tracker is a Premium feature.',
        'redirect' => '/join',
    ], 403);
}

$entryId = (int) $user['fpl_team_id'];
$leagueSlug = trim((string) ($_GET['league'] ?? ''));
$rivalSlug = trim((string) ($_GET['rival'] ?? ''));
$gameweek = isset($_GET['gw']) ? (int) $_GET['gw'] : null;

if ($leagueSlug === '' || $rivalSlug === '') {
    Response::json(['ok' => false, 'error' => 'League and rival are required.'], 422);
}

try {
    $profile = RivalProfileService::buildProfile(
        $userId,
        $entryId,
        $leagueSlug,
        $rivalSlug,
        $gameweek !== null && $gameweek > 0 ? $gameweek : null
    );
    Response::json(['ok' => true, 'profile' => $profile]);
} catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
