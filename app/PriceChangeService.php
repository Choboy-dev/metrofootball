<?php

declare(strict_types=1);

namespace App;

/**
 * Free Price Change Predictions report (Hub-style single list).
 * Uses FPL price progress fields. No OpenAI.
 */
final class PriceChangeService
{
    private const CACHE_PREFIX = 'price-changes-v2-';
    private const CACHE_SECONDS = 600;
    private const PRED_LOG_PREFIX = 'price-pred-';
    private const SNAPSHOT_PREFIX = 'price-snap-';
    private const TZ = 'Europe/London';

    /** @var array<int, string> */
    private const POS_LABEL = [
        1 => 'GK',
        2 => 'DEF',
        3 => 'MID',
        4 => 'FWD',
    ];

    /** @var array<string, int> */
    private const WHEN_RANK = [
        'Tonight' => 0,
        'Tomorrow' => 1,
        '2 Days' => 2,
        '3 Days' => 3,
        '1 Week' => 4,
        '>1 Week' => 5,
        'Calibrating' => 6,
        'Locked' => 7,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'board';
        $cached = FplCache::get($cacheKey, self::CACHE_SECONDS);
        if (is_array($cached) && ($cached['model'] ?? '') === 'pcp-v5') {
            return $cached;
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $payload = self::composeBoard($elements, $teams);
        FplCache::put($cacheKey, $payload);

        return $payload;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int|string, array<string, mixed>> $teams
     * @return array<string, mixed>
     */
    private static function composeBoard(array $elements, array $teams): array
    {
        $teamsById = [];
        foreach ($teams as $team) {
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $players = [];
        foreach ($elements as $el) {
            $mapped = self::mapPlayer($el, $teamsById);
            if ($mapped !== null) {
                $players[] = $mapped;
            }
        }

        usort($players, static function (array $a, array $b): int {
            $wa = self::WHEN_RANK[$a['when']] ?? 50;
            $wb = self::WHEN_RANK[$b['when']] ?? 50;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            $cmp = abs((float) $b['pct']) <=> abs((float) $a['pct']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) $a['web_name'], (string) $b['web_name']);
        });

        $today = self::todayKey();
        self::rememberSnapshot($today, $players);
        self::rememberPredictions($today, $players);

        return [
            'model' => 'pcp-v5',
            'free' => true,
            'updated_at' => gmdate('c'),
            'updated_label' => self::formatUpdatedLabel(),
            'players' => $players,
            'page_size' => 100,
            'load_more_size' => 50,
        ];
    }

    /**
     * @param array<string, mixed> $el
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<string, mixed>|null
     */
    private static function mapPlayer(array $el, array $teamsById): ?array
    {
        $id = (int) ($el['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $status = (string) ($el['status'] ?? 'a');
        if ($status === 'u') {
            return null;
        }

        $pct = (float) ($el['price_change_percent'] ?? 0);
        if ($pct == 0.0) {
            return null;
        }

        $teamId = (int) ($el['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $code = (int) ($team['code'] ?? 0);
        $pos = (int) ($el['element_type'] ?? 0);
        $projections = is_array($el['price_change_projections'] ?? null)
            ? array_values($el['price_change_projections'])
            : [];
        $calibrating = !empty($el['price_change_calibrating']);
        $lockedUntil = $el['price_change_locked_until'] ?? null;
        $photoRaw = (string) ($el['photo'] ?? '');
        $photoCode = preg_replace('/\.(jpg|jpeg|png)$/i', '', $photoRaw) ?? '';
        $nowCost = (int) ($el['now_cost'] ?? 0);
        $hourlyRaw = (float) ($el['price_change_hourly_rate'] ?? 0);
        $hourly = round($hourlyRaw / 1000, 2);
        $direction = $pct > 0 ? 'rise' : 'fall';
        $when = self::timingWhen($pct, $projections, $calibrating, $lockedUntil);

        $shirt = null;
        if ($code > 0) {
            $shirt = $pos === 1
                ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $code . '_1-110.webp'
                : 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $code . '-110.webp';
        }

        return [
            'id' => $id,
            'web_name' => (string) ($el['web_name'] ?? 'Player'),
            'pos' => $pos,
            'pos_label' => self::POS_LABEL[$pos] ?? '?',
            'team_id' => $teamId,
            'team_short' => (string) ($team['short_name'] ?? ''),
            'team_name' => (string) ($team['name'] ?? ''),
            'photo' => $photoCode !== ''
                ? 'https://resources.premierleague.com/premierleague/photos/players/250x250/p' . $photoCode . '.png'
                : null,
            'shirt' => $shirt,
            'price' => round($nowCost / 10, 1),
            'now_cost' => $nowCost,
            'ownership' => round((float) ($el['selected_by_percent'] ?? 0), 1),
            'pct' => round($pct, 1),
            'pct_display' => (int) round($pct),
            'hourly' => $hourly,
            'direction' => $direction,
            'prediction' => $direction === 'rise' ? 'Increase' : 'Decrease',
            'when' => $when,
        ];
    }

    /**
     * Change time from FPL only.
     * Tonight = already at/over ±100%.
     * Otherwise first projection day that reaches ±100%.
     * If none of the published projections reach ±100%, show >1 Week.
     *
     * @param list<array<string, mixed>> $projections
     */
    private static function timingWhen(
        float $pct,
        array $projections,
        bool $calibrating,
        mixed $lockedUntil
    ): string {
        $abs = abs($pct);

        if ($lockedUntil !== null && $lockedUntil !== '') {
            return 'Locked';
        }
        if ($calibrating) {
            return 'Calibrating';
        }
        if ($abs >= 100) {
            return 'Tonight';
        }

        $hit = null;
        foreach ($projections as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (abs((float) ($row['projected_percent'] ?? 0)) >= 100) {
                $hit = $row;
                break;
            }
        }
        if (!is_array($hit)) {
            return '>1 Week';
        }

        $offset = (int) ($hit['offset'] ?? 0);
        if ($offset <= 0) {
            return 'Tonight';
        }
        if ($offset === 1) {
            return 'Tomorrow';
        }
        if ($offset <= 3) {
            return $offset . ' Days';
        }
        if ($offset <= 7) {
            return '1 Week';
        }
        return '>1 Week';
    }

    /**
     * @param list<array<string, mixed>> $players
     */
    private static function rememberSnapshot(string $dateKey, array $players): void
    {
        $key = self::SNAPSHOT_PREFIX . $dateKey;
        $existing = FplCache::get($key, 48 * 3600);
        if (is_array($existing) && !empty($existing['players'])) {
            return;
        }

        $slim = [];
        foreach ($players as $p) {
            $slim[(string) $p['id']] = [
                'id' => $p['id'],
                'now_cost' => $p['now_cost'],
                'pct' => $p['pct'],
                'when' => $p['when'],
                'direction' => $p['direction'],
            ];
        }
        FplCache::put($key, [
            'date' => $dateKey,
            'saved_at' => gmdate('c'),
            'players' => $slim,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $players
     */
    private static function rememberPredictions(string $dateKey, array $players): void
    {
        $key = self::PRED_LOG_PREFIX . $dateKey;
        $existing = FplCache::get($key, 14 * 24 * 3600);
        if (is_array($existing) && !empty($existing['calls'])) {
            return;
        }

        $calls = [];
        foreach ($players as $p) {
            if ($p['when'] !== 'Tonight' && abs((float) $p['pct']) < 70) {
                continue;
            }
            $calls[(string) $p['id']] = [
                'id' => $p['id'],
                'web_name' => $p['web_name'],
                'direction' => $p['direction'],
                'pct' => $p['pct'],
                'when' => $p['when'],
                'now_cost' => $p['now_cost'],
                'tonight' => $p['when'] === 'Tonight',
            ];
            if (count($calls) >= 50) {
                break;
            }
        }
        FplCache::put($key, [
            'date' => $dateKey,
            'saved_at' => gmdate('c'),
            'calls' => $calls,
        ]);
    }

    private static function todayKey(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    private static function formatUpdatedLabel(): string
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        return $dt->format('j M Y, g:ia') . ' UK';
    }
}
