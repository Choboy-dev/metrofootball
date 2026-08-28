<?php

declare(strict_types=1);

/**
 * Injury Risk Digest publish tooling.
 *
 * Usage:
 *   php scripts/publish-injury-news.php              # research (if needed) + write once per GW
 *   php scripts/publish-injury-news.php --research   # Tavily only, stores research pack
 *   php scripts/publish-injury-news.php --write      # OpenAI write from stored research only
 *   php scripts/publish-injury-news.php --force-research  # new Tavily run even if research exists
 *   php scripts/publish-injury-news.php --edit "Remove AI phrases from the intro"
 *   php scripts/publish-injury-news.php --edit "Soften the Gibbs-White section" --player 480
 */

require dirname(__DIR__) . '/public/api/bootstrap.php';

use App\InjuryNewsService;

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
        echo "Editing published post from stored research (no Tavily)...\n";
        $post = InjuryNewsService::edit($editInstructions, $playerId > 0 ? $playerId : null);
        echo 'Updated: ' . ($post['seo_title'] ?? 'FPL injury news') . "\n";
        echo 'Players: ' . count($post['players'] ?? []) . "\n";
        exit(0);
    }

    if ($researchOnly) {
        echo $forceResearch
            ? "Running fresh Tavily research for this gameweek...\n"
            : "Researching (reuses stored pack if it exists)...\n";
        $research = InjuryNewsService::research($forceResearch);
        echo 'Stored research for ' . ($research['gameweek_name'] ?? '?') . "\n";
        echo 'Players researched: ' . ($research['player_count'] ?? 0) . "\n";
        echo 'Tavily calls: ' . ($research['tavily_calls'] ?? 0) . "\n";
        echo 'Cache key: injury-news-research-v1-' . ($research['gameweek'] ?? '?') . "\n";
        exit(0);
    }

    if ($writeOnly) {
        echo "Writing article from stored research (OpenAI only)...\n";
        $post = InjuryNewsService::publishFromResearch($force);
        echo 'Published: ' . ($post['seo_title'] ?? 'FPL injury news') . "\n";
        echo 'Players: ' . count($post['players'] ?? []) . "\n";
        exit(0);
    }

    $before = InjuryNewsService::getLive();
    if (!$force && !$forceResearch && empty($before['needs_generate']) && is_array($before['post'])) {
        echo 'Already published for ' . ($before['target_name'] ?? 'this gameweek') . ". No API spend.\n";
        echo 'Use --write to rewrite from stored research, or --edit for tone fixes.' . "\n";
        exit(0);
    }

    echo "Full publish: research (if needed) + write...\n";
    $post = InjuryNewsService::generate($force, $forceResearch);
    echo 'Published: ' . ($post['seo_title'] ?? 'FPL injury news') . "\n";
    echo 'Gameweek: ' . ($post['gameweek_name'] ?? $post['gameweek'] ?? '?') . "\n";
    echo 'Players: ' . count($post['players'] ?? []) . "\n";
    if (isset($post['cost_controls'])) {
        echo 'Tavily calls this run: ' . ($post['cost_controls']['tavily_calls'] ?? 0) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
