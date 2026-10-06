<?php

declare(strict_types=1);

namespace App;

/**
 * Individual FPL player profile pages (/players/{club}/{player}).
 */
final class PlayerProfileService
{
    private const CACHE_PREFIX = 'player-profile-v7-';

    /** @var array<int, string> */
    private const POS_LABEL = [
        1 => 'GK',
        2 => 'DEF',
        3 => 'MID',
        4 => 'FWD',
    ];

    /** @var array<string, string> */
    private const STATUS_LABEL = [
        'a' => 'Fit',
        'd' => 'Doubtful',
        'i' => 'Injured',
        's' => 'Suspended',
        'u' => 'Unavailable',
    ];

    public static function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = str_replace(["'", '’', '.'], '', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }

    public static function profilePath(string $clubSlug, string $playerSlug): string
    {
        return '/players/' . rawurlencode($clubSlug) . '/' . rawurlencode($playerSlug);
    }

    public static function clubHubPath(string $clubSlug): string
    {
        return '/players/' . rawurlencode(self::slugify($clubSlug));
    }

    /**
     * @return list<array{slug:string,short_name:string,name:string,badge:?string,path:string,player_count:int}>
     */
    public static function listClubs(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $counts = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if ((string) ($el['status'] ?? 'a') === 'u') {
                continue;
            }
            $teamId = (int) ($el['team'] ?? 0);
            if ($teamId < 1) {
                continue;
            }
            $counts[$teamId] = (int) ($counts[$teamId] ?? 0) + 1;
        }

        $clubs = [];
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $slug = self::slugify((string) ($team['name'] ?? ''));
            $short = (string) ($team['short_name'] ?? '');
            if ($slug === '' || $short === '') {
                continue;
            }
            $tid = (int) ($team['id'] ?? 0);
            $code = (int) ($team['code'] ?? 0);
            $clubs[] = [
                'slug' => $slug,
                'short_name' => $short,
                'name' => (string) ($team['name'] ?? $short),
                'badge' => $code > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                    : null,
                'path' => self::clubHubPath($slug),
                'player_count' => (int) ($counts[$tid] ?? 0),
            ];
        }

        usort($clubs, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $clubs;
    }

    /**
     * Club hub page payload with full HTML-linkable roster.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveClubHub(string $clubSlug): ?array
    {
        $clubSlug = self::slugify($clubSlug);
        if ($clubSlug === '') {
            return null;
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $team = null;
        foreach ($teams as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (self::slugify((string) ($row['name'] ?? '')) === $clubSlug) {
                $team = $row;
                break;
            }
        }
        if ($team === null) {
            return null;
        }

        $teamId = (int) ($team['id'] ?? 0);
        $code = (int) ($team['code'] ?? 0);
        $short = (string) ($team['short_name'] ?? '');
        $name = (string) ($team['name'] ?? $short);
        $posOrder = [1 => 0, 2 => 1, 3 => 2, 4 => 3];
        $posLabels = [1 => 'Goalkeepers', 2 => 'Defenders', 3 => 'Midfielders', 4 => 'Forwards'];

        $players = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if ((int) ($el['team'] ?? 0) !== $teamId) {
                continue;
            }
            if ((string) ($el['status'] ?? 'a') === 'u') {
                continue;
            }

            $playerSlug = self::slugify((string) ($el['web_name'] ?? ''));
            if ($playerSlug === '') {
                continue;
            }

            $pos = (int) ($el['element_type'] ?? 0);
            $photo = self::playerPhoto((string) ($el['photo'] ?? ''));
            $shirt = self::playerShirt($code, $pos);
            $players[] = [
                'id' => (int) ($el['id'] ?? 0),
                'web_name' => (string) ($el['web_name'] ?? 'Player'),
                'pos' => $pos,
                'pos_label' => [1 => 'GK', 2 => 'DEF', 3 => 'MID', 4 => 'FWD'][$pos] ?? '?',
                'price' => round(((int) ($el['now_cost'] ?? 0)) / 10, 1),
                'ownership' => round((float) ($el['selected_by_percent'] ?? 0), 1),
                'total_points' => (int) ($el['total_points'] ?? 0),
                'photo' => $photo,
                'shirt' => $shirt,
                'path' => self::profilePath($clubSlug, $playerSlug),
            ];
        }

        usort($players, static function (array $a, array $b) use ($posOrder): int {
            $pa = $posOrder[(int) ($a['pos'] ?? 0)] ?? 9;
            $pb = $posOrder[(int) ($b['pos'] ?? 0)] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $own = (float) ($b['ownership'] ?? 0) <=> (float) ($a['ownership'] ?? 0);
            if ($own !== 0) {
                return $own;
            }

            return strcmp((string) ($a['web_name'] ?? ''), (string) ($b['web_name'] ?? ''));
        });

        $groups = [];
        foreach ([1, 2, 3, 4] as $pos) {
            $items = array_values(array_filter($players, static fn (array $p): bool => (int) ($p['pos'] ?? 0) === $pos));
            if ($items === []) {
                continue;
            }
            $groups[] = [
                'pos' => $pos,
                'label' => $posLabels[$pos],
                'players' => $items,
            ];
        }

        $otherClubs = [];
        foreach (self::listClubs() as $club) {
            if (($club['slug'] ?? '') === $clubSlug) {
                continue;
            }
            $otherClubs[] = $club;
        }

        return [
            'slug' => $clubSlug,
            'short_name' => $short,
            'name' => $name,
            'badge' => $code > 0
                ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                : null,
            'path' => self::clubHubPath($clubSlug),
            'player_count' => count($players),
            'players' => $players,
            'groups' => $groups,
            'other_clubs' => $otherClubs,
        ];
    }

    /**
     * @return list<array{loc: string, lastmod: string}>
     */
    public static function sitemapClubEntries(): array
    {
        $base = rtrim(AppUrl::base(), '/');
        $lastmod = gmdate('Y-m-d');
        $entries = [];
        foreach (self::listClubs() as $club) {
            $path = (string) ($club['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $entries[] = [
                'loc' => $base . $path,
                'lastmod' => $lastmod,
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolve(string $clubSlug, string $playerSlug): ?array
    {
        $clubSlug = self::slugify($clubSlug);
        $playerSlug = self::slugify($playerSlug);
        if ($clubSlug === '' || $playerSlug === '') {
            return null;
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $teamsById = [];
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $teamId = (int) ($el['team'] ?? 0);
            $team = $teamsById[$teamId] ?? null;
            if (!is_array($team)) {
                continue;
            }

            $teamSlug = self::slugify((string) ($team['name'] ?? ''));
            $nameSlug = self::slugify((string) ($el['web_name'] ?? ''));
            if ($teamSlug === $clubSlug && $nameSlug === $playerSlug) {
                return self::build((int) ($el['id'] ?? 0));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $elementId): array
    {
        if ($elementId < 1) {
            throw new \InvalidArgumentException('Player not found.');
        }

        $cacheKey = self::CACHE_PREFIX . $elementId;
        $cached = FplCache::get($cacheKey, 300);
        if (is_array($cached) && ($cached['model'] ?? '') === 'player-profile-v7') {
            return $cached;
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

        $el = $elements[$elementId] ?? null;
        if (!is_array($el)) {
            throw new \InvalidArgumentException('Player not found.');
        }

        $teamsById = [];
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $teamId = (int) ($el['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $summary = FplClient::fetchElementSummary($elementId);
        $fixtures = FplClient::fetchFixtures();

        $teamCode = (int) ($team['code'] ?? 0);
        $pos = (int) ($el['element_type'] ?? 0);
        $clubSlug = self::slugify((string) ($team['name'] ?? ''));
        $playerSlug = self::slugify((string) ($el['web_name'] ?? ''));
        $season = self::seasonLabel($events);
        $history = is_array($summary['history'] ?? null) ? $summary['history'] : [];
        $upcoming = is_array($summary['fixtures'] ?? null) ? $summary['fixtures'] : [];

        $historyRows = self::buildHistoryRows($history, $teamsById, $pos);
        $projectionPack = PlayerAiScout::profileProjections($el, $summary, $teamsById, $events, $fixtures);
        $projections = is_array($projectionPack['projections'] ?? null) ? $projectionPack['projections'] : [];
        $projectionsByEvent = is_array($projectionPack['projections_by_event'] ?? null)
            ? $projectionPack['projections_by_event']
            : [];
        $expectedMinutes = (int) ($projectionPack['expected_minutes'] ?? 0);

        $starts = max(1, (int) ($el['starts'] ?? 0));
        $minutes = (int) ($el['minutes'] ?? 0);
        $ppg = (float) ($el['points_per_game'] ?? 0);
        if ($ppg <= 0 && $starts > 0) {
            $ppg = round(((int) ($el['total_points'] ?? 0)) / $starts, 2);
        }

        $pointsChart = self::buildPointsChart($historyRows);
        $gameweekLog = self::buildGameweekLogFromRows($historyRows);
        $fixturesNext = self::buildFixturesNext(
            $upcoming,
            $teamsById,
            6,
            $projections,
            $projectionsByEvent,
            $expectedMinutes,
            (float) ($el['ep_next'] ?? 0)
        );
        $compareWith = self::buildCompareSuggestions($elements, $teamsById, $elementId, $pos);
        $clubMates = self::buildClubMates($elements, $teamsById, $teamId, $elementId);
        $explainer = self::buildExplainer($el, $team, $pos, $ppg, $pointsChart);
        $attackingThreat = self::buildAttackingThreat($el, $elements, $pos);
        $defensiveDetail = in_array($pos, [2, 3], true)
            ? self::buildDefensiveDetail($historyRows, $el)
            : null;

        $plCode = (int) ($el['code'] ?? 0);
        $firstName = (string) ($el['first_name'] ?? '');
        $secondName = (string) ($el['second_name'] ?? '');
        $optaStatsUrl = OptaPlayerLinks::statsUrl($elementId, $plCode, $firstName, $secondName);

        $payload = [
            'model' => 'player-profile-v7',
            'season' => $season,
            'seasons' => self::seasonOptions($season),
            'player' => [
                'id' => $elementId,
                'web_name' => (string) ($el['web_name'] ?? 'Player'),
                'full_name' => trim((string) ($el['first_name'] ?? '') . ' ' . (string) ($el['second_name'] ?? '')),
                'pos' => $pos,
                'pos_label' => self::POS_LABEL[$pos] ?? '?',
                'photo' => self::playerPhoto((string) ($el['photo'] ?? '')),
                'shirt' => self::playerShirt($teamCode, $pos),
                'price' => round(((int) ($el['now_cost'] ?? 0)) / 10, 1),
                'ownership' => round((float) ($el['selected_by_percent'] ?? 0), 1),
                'total_points' => (int) ($el['total_points'] ?? 0),
                'points_per_game' => round($ppg, 2),
                'minutes_per_start' => (int) round($minutes / $starts),
                'minutes' => $minutes,
                'starts' => (int) ($el['starts'] ?? 0),
                'status' => (string) ($el['status'] ?? 'a'),
                'status_label' => self::STATUS_LABEL[(string) ($el['status'] ?? 'a')] ?? 'Unknown',
                'news' => (string) ($el['news'] ?? ''),
                'chance_of_playing_next_round' => $el['chance_of_playing_next_round'] ?? null,
                'opta_stats_url' => $optaStatsUrl,
            ],
            'team' => [
                'id' => $teamId,
                'name' => (string) ($team['name'] ?? ''),
                'short_name' => (string) ($team['short_name'] ?? ''),
                'slug' => $clubSlug,
                'badge' => $teamCode > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $teamCode . '.png'
                    : null,
            ],
            'slugs' => [
                'club' => $clubSlug,
                'player' => $playerSlug,
            ],
            'profile_path' => self::profilePath($clubSlug, $playerSlug),
            'history' => $historyRows,
            'points_vs_expected' => $pointsChart,
            'gameweek_log' => $gameweekLog,
            'fixtures_next' => $fixturesNext,
            'premium' => [
                'attacking' => $attackingThreat,
                'defensive' => $defensiveDetail,
                'projection_model' => (string) ($projectionPack['model'] ?? PlayerProjectionEngine::MODEL),
            ],
            'explainer' => $explainer,
            'compare_with' => $compareWith,
            'club_mates' => $clubMates,
            'filter_meta' => self::filterMeta($season, $pointsChart),
            'updated_at' => gmdate('c'),
        ];

        FplCache::put($cacheKey, $payload);

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private static function seasonLabel(array $events): string
    {
        unset($events);
        $year = (int) gmdate('Y');
        $month = (int) gmdate('n');
        if ($month < 7) {
            return ($year - 1) . '/' . substr((string) $year, -2);
        }

        return $year . '/' . substr((string) ($year + 1), -2);
    }

    /**
     * @return list<string>
     */
    private static function seasonOptions(string $current): array
    {
        $parts = explode('/', $current);
        if (count($parts) !== 2) {
            return [$current];
        }
        $startYear = (int) $parts[0];
        $prev = ($startYear - 1) . '/' . substr((string) $startYear, -2);

        return [$current, $prev];
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, mixed>>
     */
    private static function buildHistoryRows(array $history, array $teamsById, int $pos): array
    {
        $rows = [];
        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $round = (int) ($row['round'] ?? 0);
            if ($round < 1) {
                continue;
            }

            $oppId = (int) ($row['opponent_team'] ?? 0);
            $opp = $teamsById[$oppId] ?? [];
            $wasHome = !empty($row['was_home']);

            $rows[] = [
                'gw' => $round,
                'was_home' => $wasHome,
                'opponent_short' => (string) ($opp['short_name'] ?? '?'),
                'venue' => $wasHome ? 'H' : 'A',
                'label' => (string) ($opp['short_name'] ?? '?') . ' (' . ($wasHome ? 'H' : 'A') . ')',
                'opponent_label' => (string) ($opp['short_name'] ?? '?') . ' (' . ($wasHome ? 'H' : 'A') . ')',
                'minutes' => (int) ($row['minutes'] ?? 0),
                'starts' => (int) ($row['starts'] ?? 0),
                'goals' => (int) ($row['goals_scored'] ?? 0),
                'assists' => (int) ($row['assists'] ?? 0),
                'xg' => round((float) ($row['expected_goals'] ?? 0), 2),
                'xa' => round((float) ($row['expected_assists'] ?? 0), 2),
                'xgc' => round((float) ($row['expected_goals_conceded'] ?? 0), 2),
                'defensive_contribution' => (int) ($row['defensive_contribution'] ?? 0),
                'points' => (int) ($row['total_points'] ?? 0),
                'expected_points' => self::estimateExpectedPoints($row, $pos),
                'clean_sheets' => (int) ($row['clean_sheets'] ?? 0),
                'bonus' => (int) ($row['bonus'] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['gw'] ?? 0) <=> ($b['gw'] ?? 0));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $historyRows
     * @return list<array<string, mixed>>
     */
    private static function buildPointsChart(array $historyRows): array
    {
        $rows = [];
        foreach ($historyRows as $row) {
            if ((int) ($row['minutes'] ?? 0) < 1) {
                continue;
            }

            $rows[] = [
                'gw' => (int) ($row['gw'] ?? 0),
                'opponent_short' => (string) ($row['opponent_short'] ?? '?'),
                'venue' => (string) ($row['venue'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'points' => (int) ($row['points'] ?? 0),
                'expected_points' => round((float) ($row['expected_points'] ?? 0), 1),
            ];
        }

        return $rows;
    }

    /**
     * Historical expected points from underlying FPL stats.
     * Uses xG / xA / xGC. Does not subtract actual goals conceded
     * (that was zeroing defenders who conceded).
     *
     * @param array<string, mixed> $row
     */
    private static function estimateExpectedPoints(array $row, int $pos): float
    {
        $minutes = (int) ($row['minutes'] ?? 0);
        if ($minutes < 1) {
            return 0.0;
        }

        $xg = (float) ($row['expected_goals'] ?? 0);
        $xa = (float) ($row['expected_assists'] ?? 0);
        $xgc = max(0.0, (float) ($row['expected_goals_conceded'] ?? 0));
        $saves = (int) ($row['saves'] ?? 0);
        $def = (int) ($row['defensive_contribution'] ?? 0);

        $goalPts = match ($pos) {
            1, 2 => 6.0,
            3 => 5.0,
            default => 4.0,
        };
        $csPts = match ($pos) {
            1, 2 => 4.0,
            3 => 1.0,
            default => 0.0,
        };
        $dcThreshold = match ($pos) {
            1, 2 => 10,
            3 => 12,
            default => 0,
        };

        $expected = $minutes >= 60 ? 2.0 : 1.0;
        $expected += ($xg * $goalPts) + ($xa * 3.0);

        if ($csPts > 0 && $minutes >= 60) {
            $cleanSheetProb = exp(-$xgc);
            $expected += $cleanSheetProb * $csPts;
            // FPL: -1 pt per 2 goals conceded for GK/DEF
            if ($pos === 1 || $pos === 2) {
                $expected -= $xgc / 2.0;
            }
        }

        if ($pos === 1 && $saves > 0) {
            $expected += intdiv($saves, 3);
        }

        if ($dcThreshold > 0 && $def > 0) {
            $expected += min(2.0, ($def / $dcThreshold) * 2.0);
        }

        return round(max(0.0, $expected), 1);
    }

    /**
     * @param list<array<string, mixed>> $historyRows
     * @return list<array<string, mixed>>
     */
    private static function buildGameweekLogFromRows(array $historyRows): array
    {
        $rows = array_map(static function (array $row): array {
            return [
                'gw' => (int) ($row['gw'] ?? 0),
                'opponent_label' => (string) ($row['opponent_label'] ?? ''),
                'minutes' => (int) ($row['minutes'] ?? 0),
                'starts' => (int) ($row['starts'] ?? 0),
                'goals' => (int) ($row['goals'] ?? 0),
                'assists' => (int) ($row['assists'] ?? 0),
                'xg' => (float) ($row['xg'] ?? 0),
                'xa' => (float) ($row['xa'] ?? 0),
                'clean_sheets' => (int) ($row['clean_sheets'] ?? 0),
                'points' => (int) ($row['points'] ?? 0),
            ];
        }, $historyRows);

        usort($rows, static fn (array $a, array $b): int => ($b['gw'] ?? 0) <=> ($a['gw'] ?? 0));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $upcoming
     * @param array<int, array<string, mixed>> $teamsById
     * @param list<float> $projections
     * @param array<int, float> $projectionsByEvent
     */
    private static function buildFixturesNext(
        array $upcoming,
        array $teamsById,
        int $limit,
        array $projections,
        array $projectionsByEvent,
        int $expectedMinutes,
        float $epNext
    ): array {
        $rows = [];
        $index = 0;
        foreach ($upcoming as $fx) {
            if (!is_array($fx) || !empty($fx['finished'])) {
                continue;
            }

            $isHome = !empty($fx['is_home']);
            $oppId = $isHome
                ? (int) ($fx['team_a'] ?? 0)
                : (int) ($fx['team_h'] ?? 0);
            $opp = $teamsById[$oppId] ?? [];
            $gw = (int) ($fx['event'] ?? 0);

            if ($gw > 0 && array_key_exists($gw, $projectionsByEvent)) {
                $modelPts = round((float) $projectionsByEvent[$gw], 1);
            } elseif (isset($projections[$index])) {
                $modelPts = round((float) $projections[$index], 1);
            } else {
                $modelPts = null;
            }

            if ($index === 0 && $epNext > 0) {
                $predicted = round($epNext, 1);
            } elseif ($modelPts !== null && $modelPts > 0) {
                $predicted = $modelPts;
            } else {
                $predicted = null;
            }

            $rows[] = [
                'gw' => $gw,
                'opponent_short' => (string) ($opp['short_name'] ?? '?'),
                'venue' => $isHome ? 'H' : 'A',
                'label' => (string) ($opp['short_name'] ?? '?') . ' (' . ($isHome ? 'H' : 'A') . ')',
                'difficulty' => (int) ($fx['difficulty'] ?? 3),
                'predicted_points' => $predicted,
                'expected_minutes' => $expectedMinutes > 0 ? $expectedMinutes : null,
            ];

            $index++;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, string>>
     */
    private static function buildCompareSuggestions(
        array $elements,
        array $teamsById,
        int $excludeId,
        int $pos
    ): array {
        $candidates = [];
        foreach ($elements as $el) {
            if (!is_array($el) || (int) ($el['id'] ?? 0) === $excludeId) {
                continue;
            }
            if ((int) ($el['element_type'] ?? 0) !== $pos) {
                continue;
            }
            if ((string) ($el['status'] ?? 'a') === 'u') {
                continue;
            }
            $ownership = (float) ($el['selected_by_percent'] ?? 0);
            if ($ownership < 2) {
                continue;
            }
            $team = $teamsById[(int) ($el['team'] ?? 0)] ?? [];
            $candidates[] = [
                'ownership' => $ownership,
                'web_name' => (string) ($el['web_name'] ?? ''),
                'club' => self::slugify((string) ($team['name'] ?? '')),
                'player' => self::slugify((string) ($el['web_name'] ?? '')),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => ($b['ownership'] ?? 0) <=> ($a['ownership'] ?? 0));

        $out = [];
        foreach (array_slice($candidates, 0, 3) as $row) {
            $out[] = [
                'label' => $row['web_name'],
                'path' => self::profilePath($row['club'], $row['player']),
            ];
        }

        return $out;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, string>>
     */
    private static function buildClubMates(
        array $elements,
        array $teamsById,
        int $teamId,
        int $excludeId
    ): array {
        $mates = [];
        foreach ($elements as $el) {
            if (!is_array($el) || (int) ($el['team'] ?? 0) !== $teamId) {
                continue;
            }
            if ((int) ($el['id'] ?? 0) === $excludeId) {
                continue;
            }
            if ((string) ($el['status'] ?? 'a') === 'u') {
                continue;
            }
            $ownership = (float) ($el['selected_by_percent'] ?? 0);
            if ($ownership < 1) {
                continue;
            }
            $team = $teamsById[$teamId] ?? [];
            $mates[] = [
                'ownership' => $ownership,
                'web_name' => (string) ($el['web_name'] ?? ''),
                'club' => self::slugify((string) ($team['name'] ?? '')),
                'player' => self::slugify((string) ($el['web_name'] ?? '')),
            ];
        }

        usort($mates, static fn (array $a, array $b): int => ($b['ownership'] ?? 0) <=> ($a['ownership'] ?? 0));

        $out = [];
        foreach (array_slice($mates, 0, 4) as $row) {
            $out[] = [
                'label' => $row['web_name'],
                'path' => self::profilePath($row['club'], $row['player']),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $el
     * @param array<string, mixed> $team
     * @param list<array<string, mixed>> $pointsChart
     */
    private static function buildExplainer(
        array $el,
        array $team,
        int $pos,
        float $ppg,
        array $pointsChart
    ): string {
        $name = (string) ($el['web_name'] ?? 'This player');
        $posLabel = self::POS_LABEL[$pos] ?? 'player';
        $teamName = (string) ($team['name'] ?? 'their club');
        $price = round(((int) ($el['now_cost'] ?? 0)) / 10, 1);
        $ownership = round((float) ($el['selected_by_percent'] ?? 0), 1);

        $appearances = count($pointsChart);
        $totalPts = array_sum(array_map(static fn (array $row): int => (int) ($row['points'] ?? 0), $pointsChart));
        $totalExp = array_sum(array_map(static fn (array $row): float => (float) ($row['expected_points'] ?? 0), $pointsChart));
        $expPpg = $appearances > 0 ? round($totalExp / $appearances, 2) : 0;

        $lead = sprintf(
            '%s is a %s for %s, priced at £%.1fm, and owned by %.1f%% of managers.',
            $name,
            $posLabel,
            $teamName,
            $price,
            $ownership
        );

        if ($appearances < 1) {
            return $lead . ' We will add deeper analysis once they have played this season.';
        }

        $tail = sprintf(
            ' So far they have %.2f points per appearance against an expected %.2f, with %d total points from %d gameweek%s.',
            $ppg,
            $expPpg,
            $totalPts,
            $appearances,
            $appearances === 1 ? '' : 's'
        );

        if ($totalPts > $totalExp + 2) {
            $tail .= ' Returns are running ahead of the underlying numbers.';
        } elseif ($totalPts + 2 < $totalExp) {
            $tail .= ' Returns are running behind the underlying numbers so far.';
        } else {
            $tail .= ' Returns are broadly in line with the underlying numbers.';
        }

        return $lead . $tail;
    }

    /**
     * @param array<string, mixed> $el
     * @param array<int|string, array<string, mixed>> $elements
     * @return array<string, mixed>
     */
    private static function buildAttackingThreat(array $el, array $elements, int $pos): array
    {
        $minMinutes = 90;
        $peers = [];
        foreach ($elements as $peer) {
            if (!is_array($peer) || (int) ($peer['element_type'] ?? 0) !== $pos) {
                continue;
            }
            if ((int) ($peer['minutes'] ?? 0) < $minMinutes) {
                continue;
            }
            if ((string) ($peer['status'] ?? 'a') === 'u') {
                continue;
            }
            $peers[] = $peer;
        }

        $peerCount = count($peers);
        $statDefs = self::attackingStatDefs($pos);
        $stats = [];

        foreach ($statDefs as $def) {
            $valuePer90 = self::elementStatValue($el, $def['key'], 'per90');
            $peerValues = array_map(
                static fn (array $peer): float => self::elementStatValue($peer, $def['key'], 'per90'),
                $peers
            );
            $rank = self::rankDesc($valuePer90, $peerValues);

            $stats[] = [
                'label' => $def['label'],
                'key' => $def['key'],
                'decimals' => (int) ($def['decimals'] ?? 2),
                'value_per90' => $valuePer90,
                'value_per_game' => self::elementStatValue($el, $def['key'], 'per_game'),
                'value_total' => self::elementStatValue($el, $def['key'], 'total'),
                'rank' => $rank,
                'peer_count' => $peerCount,
            ];
        }

        return [
            'stats' => $stats,
            'peer_count' => $peerCount,
            'pos_label' => self::POS_LABEL[$pos] ?? '?',
        ];
    }

    /**
     * @param list<array<string, mixed>> $historyRows
     * @param array<string, mixed> $el
     * @return array<string, mixed>
     */
    private static function buildDefensiveDetail(array $historyRows, array $el): array
    {
        $threshold = 12;
        $hits = 0;
        $closestMiss = 0;
        $totalDef = 0;
        $appearances = 0;
        foreach ($historyRows as $row) {
            if ((int) ($row['minutes'] ?? 0) < 1) {
                continue;
            }

            $def = (int) ($row['defensive_contribution'] ?? 0);
            $totalDef += $def;
            $appearances++;
            if ($def >= $threshold) {
                $hits++;
            } elseif ($def > $closestMiss) {
                $closestMiss = $def;
            }
        }

        return [
            'threshold' => $threshold,
            'appearances' => $appearances,
            'hits' => $hits,
            'hit_rate' => $appearances > 0 ? round(($hits / $appearances) * 100, 1) : 0.0,
            'avg_per_game' => $appearances > 0 ? round($totalDef / $appearances, 1) : 0.0,
            'season_total' => (int) ($el['defensive_contribution'] ?? 0),
            'closest_miss' => $closestMiss,
        ];
    }

    /**
     * @return list<array{label:string,key:string,decimals?:int}>
     */
    private static function attackingStatDefs(int $pos): array
    {
        return match ($pos) {
            1 => [
                ['label' => 'Clean sheets', 'key' => 'clean_sheets', 'decimals' => 0],
                ['label' => 'Expected goals conceded per 90', 'key' => 'expected_goals_conceded_per_90', 'decimals' => 2],
                ['label' => 'Saves per 90', 'key' => 'saves_per_90', 'decimals' => 2],
            ],
            2, 3 => [
                ['label' => 'Expected goals per 90', 'key' => 'expected_goals_per_90', 'decimals' => 2],
                ['label' => 'Expected assists per 90', 'key' => 'expected_assists_per_90', 'decimals' => 2],
                ['label' => 'Expected goal involvements per 90', 'key' => 'expected_goal_involvements_per_90', 'decimals' => 2],
                ['label' => 'Threat', 'key' => 'threat', 'decimals' => 1],
                ['label' => 'Creativity', 'key' => 'creativity', 'decimals' => 1],
            ],
            default => [
                ['label' => 'Expected goals per 90', 'key' => 'expected_goals_per_90', 'decimals' => 2],
                ['label' => 'Expected goal involvements per 90', 'key' => 'expected_goal_involvements_per_90', 'decimals' => 2],
                ['label' => 'Threat', 'key' => 'threat', 'decimals' => 1],
                ['label' => 'Expected assists per 90', 'key' => 'expected_assists_per_90', 'decimals' => 2],
            ],
        };
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function elementStatValue(array $el, string $key, string $basis): float
    {
        $starts = max(1, (int) ($el['starts'] ?? 0));
        $minutes = max(0, (int) ($el['minutes'] ?? 0));

        $per90Map = [
            'expected_goals_per_90' => (float) ($el['expected_goals_per_90'] ?? 0),
            'expected_assists_per_90' => (float) ($el['expected_assists_per_90'] ?? 0),
            'expected_goal_involvements_per_90' => (float) ($el['expected_goal_involvements_per_90'] ?? 0),
            'expected_goals_conceded_per_90' => (float) ($el['expected_goals_conceded_per_90'] ?? 0),
            'saves_per_90' => (float) ($el['saves_per_90'] ?? 0),
        ];

        $totalMap = [
            'expected_goals_per_90' => (float) ($el['expected_goals'] ?? 0),
            'expected_assists_per_90' => (float) ($el['expected_assists'] ?? 0),
            'expected_goal_involvements_per_90' => (float) ($el['expected_goal_involvements'] ?? 0),
            'expected_goals_conceded_per_90' => (float) ($el['expected_goals_conceded'] ?? 0),
            'saves_per_90' => (float) ($el['saves'] ?? 0),
            'clean_sheets' => (float) ((int) ($el['clean_sheets'] ?? 0)),
            'threat' => (float) ($el['threat'] ?? 0),
            'creativity' => (float) ($el['creativity'] ?? 0),
            'influence' => (float) ($el['influence'] ?? 0),
            'ict_index' => (float) ($el['ict_index'] ?? 0),
        ];

        if ($basis === 'per90') {
            if ($key === 'clean_sheets' && $minutes >= 90) {
                return round(((float) ((int) ($el['clean_sheets'] ?? 0))) / ($minutes / 90), 4);
            }
            if (isset($per90Map[$key])) {
                return round($per90Map[$key], 4);
            }
            if ($minutes >= 90) {
                return round(((float) ($totalMap[$key] ?? 0)) / ($minutes / 90), 4);
            }
            return 0.0;
        }

        if ($basis === 'per_game') {
            if (isset($per90Map[$key])) {
                $per90 = $per90Map[$key];
                if ($minutes > 0) {
                    return round($per90 * ($minutes / 90) / $starts, 4);
                }
            }
            return round(((float) ($totalMap[$key] ?? 0)) / $starts, 4);
        }

        return round((float) ($totalMap[$key] ?? 0), 4);
    }

    /**
     * @param list<float> $values
     */
    private static function rankDesc(float $value, array $values): int
    {
        if (!$values) {
            return 0;
        }
        $higher = 0;
        foreach ($values as $peerValue) {
            if ($peerValue > $value) {
                $higher++;
            }
        }

        return $higher + 1;
    }

    /**
     * @param list<array<string, mixed>> $pointsChart
     * @return array<string, mixed>
     */
    private static function filterMeta(string $season, array $pointsChart): array
    {
        $count = count($pointsChart);
        $shown = min(6, $count);

        return [
            'season' => $season,
            'venue' => 'all fixtures',
            'window' => $count > 0 ? $count . ' gameweek' . ($count === 1 ? '' : 's') : 'season',
            'basis' => 'per 90 minutes',
            'shown' => $shown > 0 ? 'last ' . $shown . ' shown' : 'season shown',
        ];
    }

    private static function playerPhoto(string $photo): ?string
    {
        if ($photo === '') {
            return null;
        }
        $photoId = preg_replace('/\.jpg$/i', '', $photo);
        if (!is_string($photoId) || $photoId === '') {
            return null;
        }

        return 'https://resources.premierleague.com/premierleague/photos/players/110x140/p' . $photoId . '.png';
    }

    private static function playerShirt(int $teamCode, int $elementType): ?string
    {
        if ($teamCode < 1) {
            return null;
        }
        $base = 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode;
        if ($elementType === 1) {
            return $base . '_1-66.png';
        }

        return $base . '-66.png';
    }

    /**
     * @return list<array{label:string,path:string,team_short:string,pos_label:string,photo:?string,shirt:?string}>
     */
    public static function search(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $needle = strtolower($query);
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $teamsById = [];
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $matches = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if ((string) ($el['status'] ?? 'a') === 'u') {
                continue;
            }

            $webName = (string) ($el['web_name'] ?? '');
            $fullName = trim((string) ($el['first_name'] ?? '') . ' ' . (string) ($el['second_name'] ?? ''));
            $haystack = strtolower($webName . ' ' . $fullName);
            if (!str_contains($haystack, $needle)) {
                continue;
            }

            $team = $teamsById[(int) ($el['team'] ?? 0)] ?? [];
            $pos = (int) ($el['element_type'] ?? 0);
            $teamCode = (int) ($team['code'] ?? 0);
            $matches[] = [
                'score' => str_starts_with(strtolower($webName), $needle) ? 2 : 1,
                'label' => $webName,
                'path' => self::profilePath(
                    self::slugify((string) ($team['name'] ?? '')),
                    self::slugify($webName)
                ),
                'team_short' => (string) ($team['short_name'] ?? ''),
                'pos_label' => self::POS_LABEL[$pos] ?? '?',
                'photo' => self::playerPhoto((string) ($el['photo'] ?? '')),
                'shirt' => self::playerShirt($teamCode, $pos),
            ];
        }

        usort($matches, static function (array $a, array $b): int {
            $cmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        $out = [];
        foreach (array_slice($matches, 0, $limit) as $row) {
            unset($row['score']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * All public player profile URLs for sitemap generation.
     *
     * @return list<array{loc: string, lastmod: string}>
     */
    public static function sitemapEntries(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $teamsById = [];
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $base = rtrim(AppUrl::base(), '/');
        $lastmod = gmdate('Y-m-d');
        $seen = [];
        $entries = [];

        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $teamId = (int) ($el['team'] ?? 0);
            $team = $teamsById[$teamId] ?? null;
            if (!is_array($team)) {
                continue;
            }

            $clubSlug = self::slugify((string) ($team['name'] ?? ''));
            $playerSlug = self::slugify((string) ($el['web_name'] ?? ''));
            if ($clubSlug === '' || $playerSlug === '') {
                continue;
            }

            $path = self::profilePath($clubSlug, $playerSlug);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $entries[] = [
                'loc' => $base . $path,
                'lastmod' => $lastmod,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['loc'], $b['loc']));

        return $entries;
    }
}
