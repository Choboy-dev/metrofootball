<?php

declare(strict_types=1);

/**
 * Gameweek Tips publish tooling.
 *
 * Usage:
 *   php scripts/publish-gameweek-tips.php
 *   php scripts/publish-gameweek-tips.php --research
 *   php scripts/publish-gameweek-tips.php --write
 *   php scripts/publish-gameweek-tips.php --force-research
 *   php scripts/publish-gameweek-tips.php --edit "Soften the captain section"
 *   php scripts/publish-gameweek-tips.php --edit "Shorten intro" --player 430
 */

require dirname(__DIR__) . '/public/api/bootstrap.php';

use App\GameweekTipsService;

$force = in_array('--force', $argv, true);
$forceResearch = in_array('--force-research', $argv, true);
$researchOnly = in_array('--research', $argv, true);
$writeOnly = in_array('--write', $argv, true);

$editInstructions = null;
$playerId = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--edit' && isset($argv[$i + 1])) {
        $editInstructions = $argv[$i + 1];
    }
    if ($arg === '--player' && isset($argv[$i + 1])) {
        $playerId = (int) $argv[$i + 1];
    }
}

try {
    if ($editInstructions !== null) {
        echo "Editing published tips from stored research (no Tavily)...\n";
        $post = GameweekTipsService::edit($editInstructions, $playerId > 0 ? $playerId : null);
        echo 'Updated: ' . ($post['seo_title'] ?? 'FPL Gameweek tips') . "\n";
        echo 'Tips: ' . count($post['tips'] ?? []) . "\n";
        echo 'URL: ' . ($post['path'] ?? '') . "\n";
        exit(0);
    }

    if ($researchOnly) {
        echo $forceResearch
            ? "Running fresh Tavily research for this gameweek...\n"
            : "Researching (reuses stored pack if it exists)...\n";
        $research = GameweekTipsService::research($forceResearch);
        echo 'Stored research for ' . ($research['gameweek_name'] ?? '?') . "\n";
        echo 'Tips researched: ' . ($research['tip_count'] ?? 0) . "\n";
        echo 'Tavily calls: ' . ($research['tavily_calls'] ?? 0) . "\n";
        exit(0);
    }

    if ($writeOnly) {
        echo "Writing article from stored research (OpenAI only)...\n";
        $post = GameweekTipsService::publishFromResearch($force);
        echo 'Published: ' . ($post['seo_title'] ?? 'FPL Gameweek tips') . "\n";
        echo 'Tips: ' . count($post['tips'] ?? []) . "\n";
        echo 'URL: ' . ($post['path'] ?? '') . "\n";
        exit(0);
    }

    $meta = GameweekTipsService::getMeta();
    $gw = (int) ($meta['upcoming_gw'] ?? 0);
    $existing = $gw > 0 ? GameweekTipsService::getForRequest($gw) : null;
    if (
        !$force
        && !$forceResearch
        && is_array($existing)
        && empty($existing['needs_generate'])
        && is_array($existing['post'])
    ) {
        echo 'Already published for ' . ($meta['upcoming_name'] ?? 'this gameweek') . ". No API spend.\n";
        echo 'Use --write to rewrite from stored research, or --edit for tone fixes.' . "\n";
        exit(0);
    }

    echo "Full publish: research (if needed) + write...\n";
    $post = GameweekTipsService::generate($force, $forceResearch);
    echo 'Published: ' . ($post['seo_title'] ?? 'FPL Gameweek tips') . "\n";
    echo 'Gameweek: ' . ($post['gameweek_name'] ?? $post['gameweek'] ?? '?') . "\n";
    echo 'Tips: ' . count($post['tips'] ?? []) . "\n";
    echo 'URL: ' . ($post['path'] ?? '') . "\n";
    if (isset($post['cost_controls'])) {
        echo 'Tavily calls this run: ' . ($post['cost_controls']['tavily_calls'] ?? 0) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
