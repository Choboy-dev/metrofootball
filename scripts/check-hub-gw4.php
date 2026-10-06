<?php

declare(strict_types=1);

/**
 * Compare our GW4–6 projections to Fantasy Football Hub anchors (screenshot GW4 squad).
 */

$root = dirname(__DIR__);
require_once $root . '/public/api/bootstrap.php';

use App\FplClient;
use App\PlayerAiScout;
use App\PlayerProjectionEngine;

// Hub predicted points for next 3 GWs (from Hub pick screen, GW4 deadline).
// Opponent codes are informational; home = uppercase on Hub.
$hub = [
    28 => ['name' => 'Martinez', 'pts' => [5.2, 3.6, 4.2]],      // CHE GK
    8 => ['name' => 'Calafiori', 'pts' => [4.0, 3.4, 4.1]],     // ARS DEF
    504 => ['name' => 'Vuskovic', 'pts' => [3.9, 2.9, 3.8]],     // BHA DEF
    4 => ['name' => 'Gabriel', 'pts' => [5.5, 4.7, 5.6]],        // ARS DEF
    31 => ['name' => 'Konsa', 'pts' => [4.3, 3.7, 4.3]],          // ARS DEF
    480 => ['name' => 'Gibbs-White', 'pts' => [4.0, 5.2, 4.5]],  // NFO MID
    426 => ['name' => 'B.Fernandes', 'pts' => [5.2, 5.9, 6.2]],  // MUN MID
    427 => ['name' => 'Mbeumo', 'pts' => [4.3, 4.5, 4.8]],       // MUN MID
    154 => ['name' => 'Palmer', 'pts' => [7.1, 4.8, 6.0]],       // CHE MID
    367 => ['name' => 'Gakpo', 'pts' => [4.9, 4.3, 4.0]],        // LIV MID
    165 => ['name' => 'João Pedro', 'pts' => [6.2, 4.6, 5.6]],   // CHE FWD
    497 => ['name' => 'Dubravka', 'pts' => [0.0, 0.0, 0.0]],     // TOT GK bench
    464 => ['name' => 'Wissa', 'pts' => [3.4, 4.7, 3.9]],        // NEW FWD
    304 => ['name' => "O'Shea", 'pts' => [3.6, 3.4, 3.9]],       // IPS DEF
    249 => ['name' => 'Barry', 'pts' => [3.4, 4.4, 3.9]],        // EVE FWD
];

$bootstrap = FplClient::fetchBootstrapPick();
$teams = $bootstrap['teams'];
$eventId = 4;
foreach ($bootstrap['events'] as $event) {
    if (!empty($event['is_next'])) {
        $eventId = (int) $event['id'];
        break;
    }
}

$buildFx = new ReflectionMethod(PlayerAiScout::class, 'buildFixtureContext');
$buildFx->setAccessible(true);
$fixtureCtx = $buildFx->invoke(
    null,
    FplClient::fetchFixtures(),
    $eventId,
    $teams,
    $bootstrap['events']
);

$elementsById = [];
foreach ($bootstrap['elements'] as $el) {
    $elementsById[(int) $el['id']] = $el;
}

$summaries = FplClient::fetchElementSummaries(array_keys($hub));

$absErr = [];
$rows = [];

echo "MODEL " . PlayerProjectionEngine::MODEL . "  eventId={$eventId}\n";
echo str_pad('Player', 14) . str_pad('Slot', 6) . str_pad('Hub', 6) . str_pad('Ours', 6) . str_pad('Err', 7)
    . "FDR  H/A  Opp\n";
echo str_repeat('-', 72) . "\n";

foreach ($hub as $id => $meta) {
    $el = $elementsById[$id] ?? null;
    if ($el === null) {
        echo "MISSING id={$id} {$meta['name']}\n";
        continue;
    }
    $player = PlayerAiScout::buildPlayerCard($el, $teams, $fixtureCtx, $summaries[$id] ?? []);
    $scored = PlayerProjectionEngine::score($player);
    $proj = $scored['projections'];
    $fixtures = $player['fixtures'] ?? [];

    for ($i = 0; $i < 3; $i++) {
        $hubPts = (float) $meta['pts'][$i];
        $ours = (float) ($proj[$i] ?? 0);
        $err = round($ours - $hubPts, 2);
        $absErr[] = abs($err);
        $fx = $fixtures[$i] ?? [];
        $legs = [];
        if (!empty($fx['legs']) && is_array($fx['legs'])) {
            $legs = $fx['legs'];
        } elseif (!empty($fx['leg_rows']) && is_array($fx['leg_rows'])) {
            $legs = $fx['leg_rows'];
        }
        $leg = $legs[0] ?? $fx;
        $fdr = (int) ($leg['fdr'] ?? $fx['fdr'] ?? 0);
        $home = !empty($leg['home'] ?? $fx['home']);
        $opp = (string) ($leg['opp'] ?? $leg['opp_short'] ?? $fx['opp'] ?? $fx['opp_short'] ?? '?');
        $pos = (int) ($player['pos'] ?? $el['element_type']);
        $rows[] = [
            'id' => $id,
            'name' => $meta['name'],
            'pos' => $pos,
            'slot' => $i,
            'hub' => $hubPts,
            'ours' => $ours,
            'err' => $err,
            'fdr' => $fdr,
            'home' => $home,
            'opp' => $opp,
            'price' => ((int) ($el['now_cost'] ?? 0)) / 10.0,
            'play' => (float) ($player['mins_certainty'] ?? 0),
            'form' => (float) ($player['form'] ?? 0),
            'past_ppg' => (float) (($player['past']['ppg'] ?? 0)),
            'pens' => $player['set_pieces']['pens'] ?? null,
        ];
        echo sprintf(
            "%-14s GW%-3d %5.1f %5.1f %+6.1f  %d   %s  %s\n",
            $meta['name'],
            $eventId + $i,
            $hubPts,
            $ours,
            $err,
            $fdr,
            $home ? 'H' : 'A',
            $opp
        );
    }
    echo "\n";
}

$mae = array_sum($absErr) / max(1, count($absErr));
$maxAbs = max($absErr ?: [0]);
echo sprintf("MAE=%.3f  maxAbs=%.1f  n=%d\n", $mae, $maxAbs, count($absErr));

// Position MAE
$byPos = [];
foreach ($rows as $r) {
    $byPos[$r['pos']][] = abs($r['err']);
}
foreach ($byPos as $pos => $errs) {
    echo sprintf("pos %d MAE=%.3f n=%d\n", $pos, array_sum($errs) / count($errs), count($errs));
}
