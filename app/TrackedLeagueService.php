<?php

declare(strict_types=1);

namespace App;

final class TrackedLeagueService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listForUser(int $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, fpl_league_id, league_name, alerts_enabled, created_at, updated_at
             FROM tracked_leagues
             WHERE user_id = :user_id
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => self::formatRow($row), is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function add(int $userId, int $leagueId, string $name = ''): array
    {
        if ($leagueId < 1) {
            throw new \InvalidArgumentException('Enter a valid league ID.');
        }

        $existing = self::findForUser($userId, $leagueId);
        if ($existing !== null) {
            return $existing;
        }

        $count = self::countForUser($userId);
        if ($count >= 25) {
            throw new \InvalidArgumentException('You can track up to 25 leagues.');
        }

        $leagueMeta = FplClient::fetchClassicLeague($leagueId);
        $resolvedName = trim($name) !== ''
            ? trim($name)
            : (string) ($leagueMeta['name'] ?? ('League ' . $leagueId));

        $now = gmdate('c');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO tracked_leagues (user_id, fpl_league_id, league_name, alerts_enabled, created_at, updated_at)
             VALUES (:user_id, :fpl_league_id, :league_name, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'fpl_league_id' => $leagueId,
            'league_name' => $resolvedName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::findForUser($userId, $leagueId) ?? [
            'id' => (int) $pdo->lastInsertId(),
            'fpl_league_id' => $leagueId,
            'league_name' => $resolvedName,
            'alerts_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public static function remove(int $userId, int $leagueId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM tracked_leagues WHERE user_id = :user_id AND fpl_league_id = :fpl_league_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'fpl_league_id' => $leagueId,
        ]);
    }

    public static function setAlerts(int $userId, int $leagueId, bool $enabled): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE tracked_leagues
             SET alerts_enabled = :alerts_enabled, updated_at = :updated_at
             WHERE user_id = :user_id AND fpl_league_id = :fpl_league_id'
        );
        $stmt->execute([
            'alerts_enabled' => $enabled ? 1 : 0,
            'updated_at' => gmdate('c'),
            'user_id' => $userId,
            'fpl_league_id' => $leagueId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findForUser(int $userId, int $leagueId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, fpl_league_id, league_name, alerts_enabled, created_at, updated_at
             FROM tracked_leagues
             WHERE user_id = :user_id AND fpl_league_id = :fpl_league_id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'fpl_league_id' => $leagueId,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::formatRow($row) : null;
    }

    private static function countForUser(int $userId): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tracked_leagues WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'fpl_league_id' => (int) ($row['fpl_league_id'] ?? 0),
            'league_name' => (string) ($row['league_name'] ?? ''),
            'alerts_enabled' => ((int) ($row['alerts_enabled'] ?? 0)) === 1,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
