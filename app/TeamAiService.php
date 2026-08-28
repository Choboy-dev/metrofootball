<?php

declare(strict_types=1);

namespace App;

/**
 * Rate-limited short AI tip narrative. Numbers come from the scout sheet, not GPT.
 */
final class TeamAiService
{
    private const TIPS_PER_DAY = 3;
    private const TIP_CACHE_SECONDS = 86400;

    public static function tipsRemaining(int $userId): int
    {
        PlayerAiScoreStore::ensureSchema();
        $day = gmdate('Y-m-d');
        $stmt = Database::pdo()->prepare(
            'SELECT used_count FROM ai_tip_usage WHERE user_id = ? AND day_key = ?'
        );
        $stmt->execute([$userId, $day]);
        $used = (int) ($stmt->fetchColumn() ?: 0);
        return max(0, self::TIPS_PER_DAY - $used);
    }

    /**
     * @param array<string, mixed> $pickPayload
     * @param list<array<string, mixed>> $squad
     * @param array{gw:int,five_gw:int} $strength
     * @param array{gw:float,five_gw:float} $predicted
     * @param array<string, mixed> $constraints bank, free_transfers, chips, rank, hit_points
     */
    public static function narrativeTip(
        int $userId,
        array $pickPayload,
        array $squad,
        array $strength,
        array $predicted,
        array $constraints = []
    ): array {
        if (!OpenAiClient::configured()) {
            throw new \RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY in config/.env');
        }

        $remaining = self::tipsRemaining($userId);
        if ($remaining < 1) {
            throw new \RuntimeException('Daily AI tip limit reached. Try again tomorrow.');
        }

        $fingerprint = self::fingerprint(
            (int) ($pickPayload['entry']['id'] ?? 0),
            (int) ($pickPayload['event']['id'] ?? 0),
            $squad,
            $constraints
        );
        $cacheKey = 'ai-tip-v2-' . $fingerprint;
        $cached = FplCache::get($cacheKey, self::TIP_CACHE_SECONDS);
        if (is_array($cached) && isset($cached['notes'])) {
            return [
                'notes' => (string) $cached['notes'],
                'cached' => true,
                'remaining' => $remaining,
                'model' => (string) ($cached['model'] ?? OpenAiClient::model()),
            ];
        }

        $starters = [];
        $risks = [];
        foreach ($squad as $player) {
            if ((int) ($player['position'] ?? 99) > 11) {
                continue;
            }
            $tag = $player['ai']['tip_tag'] ?? null;
            $starters[] = [
                'name' => (string) ($player['web_name'] ?? ''),
                'pos' => (string) ($player['pos_label'] ?? ''),
                'captain' => !empty($player['is_captain']),
                'proj' => $player['ai']['projections'][0] ?? null,
                'tag' => $tag,
                'fixture' => $player['fixtures'][0]['label'] ?? null,
                'own' => $player['selected_by_percent'] ?? null,
            ];
            if (in_array($tag, ['avoid', 'injured', 'rotation'], true)) {
                $risks[] = (string) ($player['web_name'] ?? '');
            }
        }

        $result = OpenAiClient::chatJson(
            [
                [
                    'role' => 'system',
                    'content' => <<<'PROMPT'
You write one short Metro Football tip for an FPL manager.
Use scout projections and user constraints (bank, free transfers, hits, chips, rank risk).
Prefer actionable advice: captain, transfer priority, or hold.
Do not invent players or numbers. No exclamation marks. No dashes. Max 180 characters. Plain sentence.
PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'team' => $pickPayload['entry']['team_name'] ?? 'Team',
                        'event' => $pickPayload['event']['name'] ?? '',
                        'strength_gw' => $strength['gw'],
                        'predicted_gw' => $predicted['gw'],
                        'constraints' => [
                            'bank' => $constraints['bank'] ?? ($pickPayload['bank'] ?? null),
                            'free_transfers' => $constraints['free_transfers'] ?? ($pickPayload['free_transfers'] ?? 1),
                            'hit_points' => $constraints['hit_points'] ?? 0,
                            'chips' => $constraints['chips'] ?? ($pickPayload['chips'] ?? []),
                            'overall_rank' => $constraints['overall_rank'] ?? ($pickPayload['entry']['overall_rank'] ?? null),
                        ],
                        'risk_flags' => $risks,
                        'starters' => $starters,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'metro_team_tip_v2',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'notes' => ['type' => 'string'],
                ],
                'required' => ['notes'],
            ]
        );

        $notes = trim((string) ($result['notes'] ?? ''));
        if ($notes === '') {
            throw new \RuntimeException('OpenAI returned an empty tip.');
        }
        if (strlen($notes) > 200) {
            $notes = substr($notes, 0, 197) . '...';
        }

        FplCache::put($cacheKey, [
            'notes' => $notes,
            'model' => OpenAiClient::model(),
        ]);
        self::incrementTipUsage($userId);

        return [
            'notes' => $notes,
            'cached' => false,
            'remaining' => max(0, $remaining - 1),
            'model' => OpenAiClient::model(),
        ];
    }

    private static function incrementTipUsage(int $userId): void
    {
        PlayerAiScoreStore::ensureSchema();
        $day = gmdate('Y-m-d');
        $now = gmdate('c');
        Database::pdo()->prepare(
            'INSERT INTO ai_tip_usage (user_id, day_key, used_count, updated_at)
             VALUES (?, ?, 1, ?)
             ON CONFLICT(user_id, day_key) DO UPDATE SET
               used_count = used_count + 1,
               updated_at = excluded.updated_at'
        )->execute([$userId, $day, $now]);
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $constraints
     */
    private static function fingerprint(int $entryId, int $eventId, array $squad, array $constraints = []): string
    {
        $parts = [];
        foreach ($squad as $player) {
            if (!is_array($player)) {
                continue;
            }
            $parts[] = implode(':', [
                (int) ($player['id'] ?? 0),
                (int) ($player['position'] ?? 0),
                !empty($player['is_captain']) ? 1 : 0,
            ]);
        }
        sort($parts);
        $constraintKey = implode(':', [
            (string) ($constraints['bank'] ?? ''),
            (string) ($constraints['free_transfers'] ?? ''),
            (string) ($constraints['hit_points'] ?? ''),
        ]);
        return substr(hash('sha256', $entryId . '|' . $eventId . '|' . implode(',', $parts) . '|' . $constraintKey), 0, 32);
    }
}
