<?php

declare(strict_types=1);

/**
 * Nightly shared player scout.
 *
 * Usage:
 *   php scripts/build-player-ai-scores.php
 *
 * Cron example (Hostinger / crontab):
 *   15 4 * * * /usr/bin/php /path/to/Metro\ Football\ UK/scripts/build-player-ai-scores.php >> /path/to/storage/logs/ai-scout.log 2>&1
 */

$root = dirname(__DIR__);
require_once $root . '/public/api/bootstrap.php';

use App\PlayerAiScout;

$started = microtime(true);
echo '[' . gmdate('c') . "] Metro Football AI scout starting\n";

try {
    $result = PlayerAiScout::build(static function (string $message): void {
        echo '[' . gmdate('H:i:s') . "] {$message}\n";
    });
    $ms = (int) round((microtime(true) - $started) * 1000);
    echo '[' . gmdate('c') . "] Done event={$result['event_id']} players={$result['count']} source={$result['source']} model={$result['model']} batches={$result['batches']} ms={$ms}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . gmdate('c') . '] ERROR ' . $e->getMessage() . "\n");
    exit(1);
}
