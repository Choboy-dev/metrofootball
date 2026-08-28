<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/api/bootstrap.php';

use App\FplClient;
use App\PlayerAiScout;
use App\PlayerProjectionEngine;

$hub = [
    'B.Fernandes' => 6.8,
    'Haaland' => 6.1,
    'Mbeumo' => 5.4,
    'Palmer' => 5.1,
    'Szoboszlai' => 5.1,
    'João Pedro' => 5.1,
    'Virgil' => 4.9,
    'Gabriel' => 4.8,
    'E.Le Fée' => 4.8,
    'Isak' => 4.8,
];

$matchers = [
    'B.Fernandes' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Fernandes') !== false
        || stripos((string) ($e['second_name'] ?? ''), 'Fernandes') !== false,
    'Haaland' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Haaland') !== false,
    'Mbeumo' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Mbeumo') !== false,
    'Palmer' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Palmer') !== false
        && (int) ($e['element_type'] ?? 0) === 3,
    'Szoboszlai' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Szoboszlai') !== false,
    'João Pedro' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Pedro') !== false
        && stripos((string) ($e['first_name'] ?? ''), 'Jo') !== false,
    'Virgil' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Virgil') !== false
        || stripos((string) ($e['second_name'] ?? ''), 'Dijk') !== false,
    'Gabriel' => static fn (array $e): bool => ((string) ($e['web_name'] ?? '') === 'Gabriel'
            || stripos((string) ($e['second_name'] ?? ''), 'Magalh') !== false)
        && (int) ($e['element_type'] ?? 0) === 2
        && (int) ($e['team'] ?? 0) === 1,
    'E.Le Fée' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Le F') !== false
        || stripos((string) ($e['second_name'] ?? ''), 'Le F') !== false,
    'Isak' => static fn (array $e): bool => stripos((string) ($e['web_name'] ?? ''), 'Isak') !== false,
];

$bootstrap = FplClient::fetchBootstrapPick();
$teams = $bootstrap['teams'];
$eventId = 2;
foreach ($bootstrap['events'] as $event) {
    if (!empty($event['is_next']) || !empty($event['is_current'])) {
        $eventId = (int) $event['id'];
        if (!empty($event['is_next'])) {
            break;
        }
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

$ids = [];
$labelsById = [];
foreach ($hub as $label => $target) {
    $found = null;
    foreach ($bootstrap['elements'] as $el) {
        if (($matchers[$label])($el)) {
            $found = $el;
            break;
        }
    }
    if ($found === null) {
        echo "MISSING {$label}\n";
        continue;
    }
    $id = (int) $found['id'];
    $ids[$id] = true;
    $labelsById[$id] = ['label' => $label, 'target' => $target, 'web' => $found['web_name']];
}

$summaries = FplClient::fetchElementSummaries(array_keys($ids));
$sumAbs = 0.0;
$maxAbs = 0.0;
$within = 0;
$n = 0;

echo 'model=' . PlayerProjectionEngine::MODEL . " event={$eventId}\n";
echo str_pad('Hub label', 14) . str_pad('web', 14) . str_pad('Hub', 7) . str_pad('Ours', 7) . "Δ\n";

foreach ($labelsById as $id => $meta) {
    $el = $bootstrap['elements'][$id] ?? null;
    if ($el === null) {
        foreach ($bootstrap['elements'] as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $el = $candidate;
                break;
            }
        }
    }
    if ($el === null) {
        echo "NO ELEMENT {$meta['label']}\n";
        continue;
    }
    $card = PlayerAiScout::buildPlayerCard($el, $teams, $fixtureCtx, $summaries[$id] ?? []);
    $row = PlayerProjectionEngine::score($card);
    $xp = (float) ($row['projections'][0] ?? 0);
    $d = round($xp - (float) $meta['target'], 2);
    $sumAbs += abs($d);
    $maxAbs = max($maxAbs, abs($d));
    if (abs($d) <= 0.1) {
        $within++;
    }
    $n++;
    $fx0 = $card['fixtures'][0] ?? [];
    $fdr = $fx0['fdr'] ?? ($fx0['leg_rows'][0]['fdr'] ?? '?');
    $home = !empty($fx0['home']) || !empty(($fx0['leg_rows'][0]['home'] ?? false)) ? 'H' : 'A';
    $pens = $card['set_pieces']['pens'] ?? '-';
    $form = $card['form'] ?? 0;
    $minsC = $card['mins_certainty'] ?? 0;
    printf(
        "%-14s %-14s %-6.1f %-6.1f %-5.1f  FDR%s%s pens=%s form=%s minsC=%s\n",
        $meta['label'],
        (string) $meta['web'],
        (float) $meta['target'],
        $xp,
        $d,
        $fdr,
        $home,
        (string) $pens,
        (string) $form,
        (string) $minsC
    );
}

if ($n > 0) {
    echo "\nMAE=" . round($sumAbs / $n, 3) . ' MAX=' . round($maxAbs, 2) . " within±0.1: {$within}/{$n}\n";
}
