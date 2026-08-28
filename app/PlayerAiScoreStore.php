<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Shared nightly scout sheet. One row per FPL element for the planning event.
 */
final class PlayerAiScoreStore
{
    public static function ensureSchema(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS player_ai_scores (
                element_id INTEGER NOT NULL,
                event_id INTEGER NOT NULL,
                data_version TEXT NOT NULL,
                proj_gw REAL NOT NULL,
                proj_gw2 REAL NOT NULL,
                proj_gw3 REAL NOT NULL,
                proj_gw4 REAL NOT NULL,
                proj_gw5 REAL NOT NULL,
                strength REAL NOT NULL,
                tip_tag TEXT NULL,
                model TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT \'openai\',
                updated_at TEXT NOT NULL,
                PRIMARY KEY (element_id, event_id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ai_tip_usage (
                user_id INTEGER NOT NULL,
                day_key TEXT NOT NULL,
                used_count INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (user_id, day_key)
            )'
        );
    }

    /** @return array<int, array<string, mixed>> keyed by element_id */
    public static function allForEvent(int $eventId): array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM player_ai_scores WHERE event_id = ?'
        );
        $stmt->execute([$eventId]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(int) $row['element_id']] = self::normalizeRow($row);
        }
        return $out;
    }

    public static function countForEvent(int $eventId): int
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM player_ai_scores WHERE event_id = ?'
        );
        $stmt->execute([$eventId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function upsertMany(int $eventId, string $dataVersion, array $rows): void
    {
        self::ensureSchema();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO player_ai_scores (
                    element_id, event_id, data_version,
                    proj_gw, proj_gw2, proj_gw3, proj_gw4, proj_gw5,
                    strength, tip_tag, model, source, updated_at
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?
                )
                ON CONFLICT(element_id, event_id) DO UPDATE SET
                    data_version = excluded.data_version,
                    proj_gw = excluded.proj_gw,
                    proj_gw2 = excluded.proj_gw2,
                    proj_gw3 = excluded.proj_gw3,
                    proj_gw4 = excluded.proj_gw4,
                    proj_gw5 = excluded.proj_gw5,
                    strength = excluded.strength,
                    tip_tag = excluded.tip_tag,
                    model = excluded.model,
                    source = excluded.source,
                    updated_at = excluded.updated_at'
            );

            $now = gmdate('c');
            foreach ($rows as $row) {
                $projs = $row['projections'] ?? [0, 0, 0, 0, 0];
                $stmt->execute([
                    (int) $row['element_id'],
                    $eventId,
                    $dataVersion,
                    (float) ($projs[0] ?? 0),
                    (float) ($projs[1] ?? 0),
                    (float) ($projs[2] ?? 0),
                    (float) ($projs[3] ?? 0),
                    (float) ($projs[4] ?? 0),
                    (float) ($row['strength'] ?? 0),
                    $row['tip_tag'] ?? null,
                    (string) ($row['model'] ?? 'deterministic'),
                    (string) ($row['source'] ?? 'deterministic'),
                    $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $mirror = [];
        foreach ($rows as $row) {
            $projs = $row['projections'] ?? [0, 0, 0, 0, 0];
            $id = (int) $row['element_id'];
            $mirror[$id] = [
                'element_id' => $id,
                'event_id' => $eventId,
                'data_version' => $dataVersion,
                'projections' => array_map('floatval', array_slice(array_pad($projs, PlayerProjectionEngine::HORIZON, 0), 0, PlayerProjectionEngine::HORIZON)),
                'strength' => (float) ($row['strength'] ?? 0),
                'tip_tag' => $row['tip_tag'] ?? null,
                'model' => (string) ($row['model'] ?? 'deterministic'),
                'source' => (string) ($row['source'] ?? 'deterministic'),
            ];
        }

        FplCache::put('player-ai-scores-' . $eventId, [
            'event_id' => $eventId,
            'data_version' => $dataVersion,
            'updated_at' => gmdate('c'),
            'players' => $mirror,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function loadMap(int $eventId): array
    {
        $cached = FplCache::get('player-ai-scores-' . $eventId, 36 * 3600);
        if (is_array($cached) && isset($cached['players']) && is_array($cached['players'])) {
            $out = [];
            foreach ($cached['players'] as $id => $row) {
                $out[(int) $id] = $row;
            }
            if ($out) {
                return $out;
            }
        }
        return self::allForEvent($eventId);
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        return [
            'element_id' => (int) $row['element_id'],
            'event_id' => (int) $row['event_id'],
            'data_version' => (string) $row['data_version'],
            'projections' => [
                (float) $row['proj_gw'],
                (float) $row['proj_gw2'],
                (float) $row['proj_gw3'],
                (float) $row['proj_gw4'],
                (float) $row['proj_gw5'],
            ],
            'strength' => (float) $row['strength'],
            'tip_tag' => $row['tip_tag'] ?? null,
            'model' => (string) $row['model'],
            'source' => (string) ($row['source'] ?? 'openai'),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
