<?php

declare(strict_types=1);

namespace App;

final class TeamLinkService
{
    /** @return array{matches: list<array<string, mixed>>, query: string} */
    public static function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Enter your FPL ID or team name.');
        }

        if (preg_match('/^\d+$/', $query) === 1) {
            $entry = FplClient::fetchEntry((int) $query);
            return [
                'query' => $query,
                'matches' => [FplClient::summariseEntry($entry)],
            ];
        }

        // Official FPL API has no public team-name search. Search teams already linked on Metro Football.
        $stmt = Database::pdo()->prepare(
            'SELECT fpl_team_id AS id, fpl_team_name AS team_name, fpl_manager_name AS manager_name
             FROM users
             WHERE fpl_team_id IS NOT NULL
               AND (
                 fpl_team_name LIKE ? COLLATE NOCASE
                 OR fpl_manager_name LIKE ? COLLATE NOCASE
               )
             ORDER BY fpl_linked_at DESC
             LIMIT 8'
        );
        $like = '%' . $query . '%';
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll() ?: [];

        $matches = [];
        foreach ($rows as $row) {
            $matches[] = [
                'id' => (int) $row['id'],
                'team_name' => (string) $row['team_name'],
                'manager_name' => (string) ($row['manager_name'] ?? 'FPL Manager'),
                'overall_points' => null,
                'overall_rank' => null,
                'favourite_team' => null,
            ];
        }

        if (!$matches) {
            throw new \InvalidArgumentException(
                'Enter your FPL ID from the Points page URL on the official FPL site.'
            );
        }

        return [
            'query' => $query,
            'matches' => $matches,
        ];
    }

    /** @return array{user: array, redirect: string, team: array<string, mixed>} */
    public static function link(int $userId, int $fplTeamId, ?string $intended = null): array
    {
        $entry = FplClient::fetchEntry($fplTeamId);
        $summary = FplClient::summariseEntry($entry);
        $now = gmdate('c');

        Database::pdo()->prepare(
            'UPDATE users
             SET fpl_team_id = ?, fpl_team_name = ?, fpl_manager_name = ?, fpl_linked_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $summary['id'],
            $summary['team_name'],
            $summary['manager_name'],
            $now,
            $now,
            $userId,
        ]);

        $user = AuthService::findUserById($userId);
        if (!$user) {
            throw new \RuntimeException('Account not found.');
        }

        Session::login($user);

        // Build Live + Pick snapshots now so post-link pages open quickly.
        try {
            LiveTeamService::refreshEntry((int) $summary['id']);
        } catch (\Throwable $e) {
            FplCache::forget('live-entry-' . $summary['id']);
        }
        try {
            PickTeamService::refreshEntry((int) $summary['id']);
        } catch (\Throwable $e) {
            FplCache::forget('pick-entry-v6-' . $summary['id']);
            FplCache::forget('pick-entry-v5-' . $summary['id']);
            FplCache::forget('pick-entry-v4-' . $summary['id']);
            FplCache::forget('pick-entry-v3-' . $summary['id']);
            FplCache::forget('pick-entry-v2-' . $summary['id']);
            FplCache::forget('pick-entry-' . $summary['id']);
        }

        return [
            'user' => $user,
            'team' => $summary,
            'redirect' => AuthService::postAuthPath($user, AuthService::resolveIntended($intended)),
        ];
    }
}
