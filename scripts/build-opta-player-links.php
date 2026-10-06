<?php

declare(strict_types=1);

/**
 * One-time (or occasional) build of stable Premier League / Opta stats URLs for every FPL player.
 *
 * Usage:
 *   php scripts/build-opta-player-links.php
 *
 * FPL element `code` maps to premierleague.com player id. Opta updates stats on that page over time.
 */

$root = dirname(__DIR__);
require_once $root . '/public/api/bootstrap.php';

use App\OptaPlayerLinks;

$started = microtime(true);
echo '[' . gmdate('c') . "] Building Opta player links\n";

try {
    $result = OptaPlayerLinks::buildFromBootstrap(static function (string $message): void {
        echo '[' . gmdate('H:i:s') . "] {$message}\n";
    });

    $sampleIds = [411, 1, 302];
    echo '[' . gmdate('H:i:s') . "] Sample URLs:\n";
    foreach ($sampleIds as $id) {
        $row = $result['players'][(string) $id] ?? null;
        if (!is_array($row)) {
            continue;
        }
        echo "  {$row['web_name']}: {$row['url']}\n";
    }

    $ms = (int) round((microtime(true) - $started) * 1000);
    echo '[' . gmdate('c') . "] Done count={$result['count']} missing={$result['missing']} ms={$ms}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . gmdate('c') . '] ERROR ' . $e->getMessage() . "\n");
    exit(1);
}
