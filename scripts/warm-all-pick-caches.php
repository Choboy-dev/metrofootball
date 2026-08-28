<?php

declare(strict_types=1);

/**
 * Warm pick cache for every linked FPL entry in the database.
 * Run: php scripts/warm-all-pick-caches.php
 */

require dirname(__DIR__) . '/public/api/bootstrap.php';

use App\Database;
use App\PickTeamService;
use App\PlayerAiScout;
use App\PlayerAiScoreStore;

$pdo = Database::pdo();
$stmt = $pdo->query(
    'SELECT DISTINCT fpl_team_id FROM users WHERE fpl_team_id IS NOT NULL AND fpl_team_id > 0 ORDER BY fpl_team_id ASC'
);
$entryIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if (!$entryIds) {
    echo "No linked FPL entries found.\n";
    exit(0);
}

$failures = 0;
foreach ($entryIds as $entryId) {
    echo "Warming entry {$entryId}...\n";
    try {
        $result = PickTeamService::buildForEntry($entryId);
        echo "  pick: {$result['cache']}\n";

        $eventId = (int) ($result['pick']['event']['id'] ?? 0);
        if ($eventId > 0) {
            $context = PickTeamService::lastFetchContext();
            PlayerAiScout::ensureScores(
                $eventId,
                $context['bootstrap'] ?? null,
                $context['fixtures'] ?? null
            );
            $map = PlayerAiScoreStore::loadMap($eventId);
            echo '  scores: ' . count($map) . " players\n";
        }
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, "  FAILED: {$e->getMessage()}\n");
    }
}

echo $failures ? "Finished with {$failures} failure(s).\n" : "All caches warmed.\n";
exit($failures ? 1 : 0);
