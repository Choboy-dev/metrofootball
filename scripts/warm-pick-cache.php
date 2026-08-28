<?php

declare(strict_types=1);

/**
 * Pre-build pick cache for one or more FPL entry IDs.
 * Run: php scripts/warm-pick-cache.php 6519589
 */

require dirname(__DIR__) . '/public/api/bootstrap.php';

use App\PickTeamService;

$entryIds = array_values(array_filter(array_map('intval', array_slice($argv, 1))));
if (!$entryIds) {
    $entryIds = [6519589];
}

foreach ($entryIds as $entryId) {
    if ($entryId < 1) {
        continue;
    }

    echo "Warming pick cache for entry {$entryId}...\n";
    try {
        $result = PickTeamService::buildForEntry($entryId);
        echo "  OK ({$result['cache']})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  FAILED: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Done.\n";
