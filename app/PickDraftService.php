<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Saved Pick / Rate My Team drafts per user, entry, and planning gameweek.
 * Live FPL stays on Live; Pick loads the last saved draft when one exists.
 */
final class PickDraftService
{
    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $userId, int $entryId, int $gameweekId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT squad_json, bank, strength_json, predicted_points_json, saved_at, updated_at
             FROM pick_drafts
             WHERE user_id = :user_id AND fpl_entry_id = :entry_id AND gameweek_id = :gw
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'entry_id' => $entryId,
            'gw' => $gameweekId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $squad = json_decode((string) ($row['squad_json'] ?? ''), true);
        if (!is_array($squad) || $squad === []) {
            return null;
        }

        return [
            'squad' => $squad,
            'bank' => (float) ($row['bank'] ?? 0),
            'strength' => self::decodeJsonObject($row['strength_json'] ?? null),
            'predicted_points' => self::decodeJsonObject($row['predicted_points_json'] ?? null),
            'saved_at' => (string) ($row['saved_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed>|null $strength
     * @param array<string, mixed>|null $predictedPoints
     * @return array<string, mixed>
     */
    public static function save(
        int $userId,
        int $entryId,
        int $gameweekId,
        array $squad,
        float $bank,
        ?array $strength = null,
        ?array $predictedPoints = null
    ): array {
        if (count($squad) !== 15) {
            throw new \InvalidArgumentException('Your squad must have 15 players before you save.');
        }

        $now = gmdate('c');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO pick_drafts (
                user_id, fpl_entry_id, gameweek_id, squad_json, bank,
                strength_json, predicted_points_json, saved_at, updated_at
            ) VALUES (
                :user_id, :entry_id, :gw, :squad, :bank,
                :strength, :predicted, :saved_at, :updated_at
            )
            ON CONFLICT(user_id, fpl_entry_id, gameweek_id) DO UPDATE SET
                squad_json = excluded.squad_json,
                bank = excluded.bank,
                strength_json = excluded.strength_json,
                predicted_points_json = excluded.predicted_points_json,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'entry_id' => $entryId,
            'gw' => $gameweekId,
            'squad' => json_encode($squad, JSON_THROW_ON_ERROR),
            'bank' => $bank,
            'strength' => $strength !== null ? json_encode($strength, JSON_THROW_ON_ERROR) : null,
            'predicted' => $predictedPoints !== null ? json_encode($predictedPoints, JSON_THROW_ON_ERROR) : null,
            'saved_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'saved_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonObject(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
