<?php

declare(strict_types=1);

/**
 * Apply Metro Mail SQLite migrations idempotently.
 * Usage: php scripts/run-mail-migrations.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Env.php';
require_once $root . '/app/Database.php';

use App\Database;
use App\Env;

function mailMigrationFail(string $message): never
{
    if (defined('MAIL_MIGRATIONS_INCLUDED')) {
        throw new RuntimeException($message);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

Env::load($root . '/config/.env');

$pdo = Database::pdo();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS mail_migrations (
        id TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )'
);

$migrationsDir = $root . '/database/migrations';
$files = glob($migrationsDir . '/mail_*.sql') ?: [];
sort($files, SORT_STRING);

$applied = 0;
$skipped = 0;

foreach ($files as $file) {
    $id = basename($file, '.sql');
    $check = $pdo->prepare('SELECT 1 FROM mail_migrations WHERE id = ? LIMIT 1');
    $check->execute([$id]);
    if ($check->fetchColumn()) {
        echo "Skip {$id} (already applied)\n";
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        mailMigrationFail("Could not read {$file}");
    }

    $statements = splitSqlStatements($sql);
    try {
        $pdo->beginTransaction();
        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (isIgnorableMigrationError($e)) {
                    continue;
                }
                throw $e;
            }
        }
        $insert = $pdo->prepare('INSERT INTO mail_migrations (id, applied_at) VALUES (?, ?)');
        $insert->execute([$id, gmdate('c')]);
        $pdo->commit();
        echo "Applied {$id}\n";
        $applied++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mailMigrationFail("Failed {$id}: {$e->getMessage()}");
    }
}

echo "Done. Applied {$applied}, skipped {$skipped}.\n";

/**
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $buffer = '';
    $statements = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function isIgnorableMigrationError(PDOException $e): bool
{
    $message = strtolower($e->getMessage());
    return str_contains($message, 'duplicate column name')
        || str_contains($message, 'already exists');
}
