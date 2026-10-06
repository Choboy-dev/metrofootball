<?php

declare(strict_types=1);

namespace App;

/**
 * Premier League table + season leaders from official FPL data.
 */
final class LeagueTableService
{
    private const LEADERS_LIMIT = 15;

    /**
     * Chances created is estimated from per-match FPL live stats, calibrated against
     * Premier League app GW1 2026/27 totals. Uses creativity, influence, threat,
     * goals, assists, and minutes only (no per-90 carry-over).
     */
    private const CHANCE_DIVISOR = 11.4702;
    private const CHANCE_INF_LOW_THRESHOLD = 26.1358;
    private const CHANCE_LOW_INF_PENALTY = 0.1936;
    private const CHANCE_HIGH_INF_THRESHOLD = 49.7031;
    private const CHANCE_HIGH_INF_BOOST = 0.3483;
    private const CHANCE_GOAL_CREATIVE_MIN = 51.8295;
    private const CHANCE_THREAT_WEIGHT = 0.0634;
    private const CHANCE_ASSIST_SUBTRACT = 5.258;
    private const CHANCE_FULL_MINUTES = 90;
    private const CHANCE_HIGH_CREATIVITY = 53.1794;
    private const CHANCE_FULL_PENALTY = 6.8709;
    private const CHANCE_FULL_THREAT_WEIGHT = 0.4779;
    private const CHANCE_INVOLVEMENT_MINUTES = 88;
    private const CHANCE_MID_INF_THRESHOLD = 41.626;
    private const CHANCE_MID_INF_PENALTY = 0.1602;
    private const CHANCE_FINISHER_CR_LOW = 46.8275;
    private const CHANCE_FINISHER_CR_HIGH = 48.6332;
    private const CHANCE_FINISHER_MINUTES = 88;
    private const CHANCE_FINISHER_PENALTY = 6.9024;
    private const CHANCE_SUB_MINUTES = 30;
    private const CHANCE_SUB_BOOST = 1.0696;
    private const CHANCE_DEF_FACTOR = 0.9194;

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $fixtures = FplClient::fetchFixtures();
        $teamsById = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

        $standings = self::buildStandings($teamsById, $fixtures);
        $leaders = self::buildLeaders($elements, $teamsById, $events);

        return [
            'season' => self::seasonLabel($events),
            'event' => self::currentEventMeta($events),
            'standings' => $standings,
            'leaders' => $leaders,
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $teamsById
     * @param list<array<string, mixed>> $fixtures
     * @return list<array<string, mixed>>
     */
    private static function buildStandings(array $teamsById, array $fixtures): array
    {
        $stats = [];
        foreach ($teamsById as $team) {
            $id = (int) ($team['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $code = (int) ($team['code'] ?? 0);
            $stats[$id] = [
                'id' => $id,
                'name' => (string) ($team['name'] ?? 'Club'),
                'short_name' => (string) ($team['short_name'] ?? ''),
                'code' => $code,
                'badge' => $code > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                    : null,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'gf' => 0,
                'ga' => 0,
                'gd' => 0,
                'points' => 0,
                'form' => [],
            ];
        }

        foreach ($fixtures as $fx) {
            $finished = !empty($fx['finished']) || !empty($fx['finished_provisional']);
            if (!$finished) {
                continue;
            }
            if ($fx['team_h_score'] === null || $fx['team_a_score'] === null) {
                continue;
            }

            $homeId = (int) ($fx['team_h'] ?? 0);
            $awayId = (int) ($fx['team_a'] ?? 0);
            if (!isset($stats[$homeId], $stats[$awayId])) {
                continue;
            }

            $hs = (int) $fx['team_h_score'];
            $as = (int) $fx['team_a_score'];

            $stats[$homeId]['played']++;
            $stats[$awayId]['played']++;
            $stats[$homeId]['gf'] += $hs;
            $stats[$homeId]['ga'] += $as;
            $stats[$awayId]['gf'] += $as;
            $stats[$awayId]['ga'] += $hs;

            if ($hs > $as) {
                $stats[$homeId]['won']++;
                $stats[$homeId]['points'] += 3;
                $stats[$awayId]['lost']++;
                $stats[$homeId]['form'][] = 'W';
                $stats[$awayId]['form'][] = 'L';
            } elseif ($hs < $as) {
                $stats[$awayId]['won']++;
                $stats[$awayId]['points'] += 3;
                $stats[$homeId]['lost']++;
                $stats[$homeId]['form'][] = 'L';
                $stats[$awayId]['form'][] = 'W';
            } else {
                $stats[$homeId]['drawn']++;
                $stats[$awayId]['drawn']++;
                $stats[$homeId]['points']++;
                $stats[$awayId]['points']++;
                $stats[$homeId]['form'][] = 'D';
                $stats[$awayId]['form'][] = 'D';
            }
        }

        $rows = array_values($stats);
        foreach ($rows as &$row) {
            $row['gd'] = $row['gf'] - $row['ga'];
            $row['form'] = array_slice($row['form'], -5);
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $pts = $b['points'] <=> $a['points'];
            if ($pts !== 0) {
                return $pts;
            }
            $gd = $b['gd'] <=> $a['gd'];
            if ($gd !== 0) {
                return $gd;
            }
            $gf = $b['gf'] <=> $a['gf'];
            if ($gf !== 0) {
                return $gf;
            }
            return strcmp((string) $a['short_name'], (string) $b['short_name']);
        });

        $position = 1;
        foreach ($rows as &$row) {
            $row['position'] = $position;
            $row['zone'] = self::zoneFor($position);
            $position++;
        }
        unset($row);

        return $rows;
    }

    private static function zoneFor(int $position): string
    {
        if ($position <= 4) {
            return 'ucl';
        }
        if ($position <= 6) {
            return 'europe';
        }
        if ($position >= 18) {
            return 'relegation';
        }
        return 'mid';
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @return array{
     *   goals: list<array>,
     *   assists: list<array>,
     *   clean_sheets: list<array>,
     *   avg_rating: list<array>,
     *   chances_created: list<array>,
     *   minutes_played: list<array>,
     *   goals_plus_assists: list<array>,
     *   goals_per_90: list<array>,
     *   fpl_points: list<array>
     * }
     */
    private static function buildLeaders(array $elements, array $teamsById, array $events): array
    {
        $elementTypes = [];
        foreach ($elements as $element) {
            $elementId = (int) ($element['id'] ?? 0);
            if ($elementId > 0) {
                $elementTypes[$elementId] = (int) ($element['element_type'] ?? 0);
            }
        }

        $liveStats = self::buildEventLiveLeaderStats($events, $elementTypes);
        $avgRatings = $liveStats['avg_ratings'];
        $chancesCreated = $liveStats['chances_created'];
        $chanceTiebreak = $liveStats['chance_tiebreak'];
        $players = [];
        foreach ($elements as $element) {
            $teamId = (int) ($element['team'] ?? 0);
            $team = $teamsById[$teamId] ?? null;
            $code = (int) ($team['code'] ?? 0);
            $goals = (int) ($element['goals_scored'] ?? 0);
            $assists = (int) ($element['assists'] ?? 0);
            $minutes = (int) ($element['minutes'] ?? 0);
            $elementType = (int) ($element['element_type'] ?? 0);
            $players[] = [
                'id' => (int) ($element['id'] ?? 0),
                'name' => (string) ($element['web_name'] ?? 'Player'),
                'element_type' => $elementType,
                'team_id' => $teamId,
                'team' => (string) ($team['short_name'] ?? ''),
                'team_name' => (string) ($team['name'] ?? ''),
                'badge' => $code > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                    : null,
                'photo' => self::playerPhoto((string) ($element['photo'] ?? '')),
                'shirt' => self::playerShirt($code, $elementType),
                'goals' => $goals,
                'assists' => $assists,
                'clean_sheets' => (int) ($element['clean_sheets'] ?? 0),
                'avg_rating' => (float) ($avgRatings[(int) ($element['id'] ?? 0)] ?? 0),
                'chances_created' => (int) ($chancesCreated[(int) ($element['id'] ?? 0)] ?? 0),
                'chance_tiebreak' => (float) ($chanceTiebreak[(int) ($element['id'] ?? 0)] ?? 0),
                'minutes' => $minutes,
                'goals_plus_assists' => $goals + $assists,
                'goals_per_90' => self::goalsPer90($goals, $minutes),
                'total_points' => (int) ($element['total_points'] ?? 0),
            ];
        }

        $keepers = array_values(array_filter(
            $players,
            static fn (array $player): bool => (int) ($player['element_type'] ?? 0) === 1
        ));

        return [
            'goals' => self::rankLeaders($players, 'goals'),
            'assists' => self::rankLeaders($players, 'assists'),
            'clean_sheets' => self::rankLeaders($keepers, 'clean_sheets'),
            'avg_rating' => self::rankLeaders(
                $players,
                'avg_rating',
                1.0,
                static fn (float $value): string => number_format($value, 1)
            ),
            'chances_created' => self::rankLeaders(
                $players,
                'chances_created',
                1,
                static fn (float $value): string => (string) (int) $value,
                'chance_tiebreak'
            ),
            'minutes_played' => self::rankLeaders(
                $players,
                'minutes',
                1,
                static fn (float $value): string => (string) (int) $value
            ),
            'goals_plus_assists' => self::rankLeaders($players, 'goals_plus_assists'),
            'goals_per_90' => self::rankLeaders(
                $players,
                'goals_per_90',
                0.01,
                static fn (float $value): string => number_format($value, 2)
            ),
            'fpl_points' => self::rankLeaders($players, 'total_points'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $players
     * @return list<array<string, mixed>>
     */
    private static function rankLeaders(
        array $players,
        string $stat,
        float $minValue = 1.0,
        ?callable $formatValue = null,
        ?string $tiebreakStat = null
    ): array {
        usort($players, static function (array $a, array $b) use ($stat, $tiebreakStat): int {
            $cmp = ((float) $b[$stat]) <=> ((float) $a[$stat]);
            if ($cmp !== 0) {
                return $cmp;
            }
            if ($tiebreakStat !== null) {
                $tie = ((float) $b[$tiebreakStat]) <=> ((float) $a[$tiebreakStat]);
                if ($tie !== 0) {
                    return $tie;
                }
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        $top = array_slice($players, 0, self::LEADERS_LIMIT);
        $rank = 1;
        $out = [];
        foreach ($top as $player) {
            $value = (float) $player[$stat];
            if ($value < $minValue) {
                break;
            }
            $out[] = [
                'rank' => $rank,
                'id' => $player['id'],
                'name' => $player['name'],
                'team' => $player['team'],
                'team_name' => $player['team_name'],
                'badge' => $player['badge'],
                'photo' => $player['photo'],
                'shirt' => $player['shirt'],
                'value' => $formatValue ? $formatValue($value) : (string) (int) $value,
            ];
            $rank++;
        }

        return $out;
    }

    private static function goalsPer90(int $goals, int $minutes): float
    {
        if ($minutes < 90) {
            return 0.0;
        }

        return round(($goals * 90.0) / $minutes, 2);
    }

    /**
     * Per-gameweek live stats rolled up for leader tabs that FPL bootstrap does not expose directly.
     *
     * @param list<array<string, mixed>> $events
     * @param array<int, int> $elementTypes
     * @return array{avg_ratings: array<int, float>, chances_created: array<int, int>, chance_tiebreak: array<int, float>}
     */
    private static function buildEventLiveLeaderStats(array $events, array $elementTypes): array
    {
        $bpsBounds = self::collectBpsBounds($events);
        $ratingSums = [];
        $ratingCounts = [];
        $chanceTotals = [];
        $influenceTotals = [];

        foreach ($events as $event) {
            if (empty($event['finished']) || empty($event['data_checked'])) {
                continue;
            }
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId < 1) {
                continue;
            }

            try {
                $live = FplClient::fetchEventLive($eventId);
            } catch (\Throwable) {
                continue;
            }

            $rows = $live['elements'] ?? null;
            if (!is_array($rows)) {
                continue;
            }

            $gameweekRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $stats = $row['stats'] ?? null;
                if (!is_array($stats)) {
                    continue;
                }
                $minutes = (int) ($stats['minutes'] ?? 0);
                if ($minutes < 1) {
                    continue;
                }

                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }

                $gameweekRows[] = [
                    'id' => $id,
                    'minutes' => $minutes,
                    'bps' => (int) ($stats['bps'] ?? 0),
                    'creativity' => (float) ($stats['creativity'] ?? 0),
                    'influence' => (float) ($stats['influence'] ?? 0),
                    'threat' => (float) ($stats['threat'] ?? 0),
                    'goals_scored' => (int) ($stats['goals_scored'] ?? 0),
                    'assists' => (int) ($stats['assists'] ?? 0),
                    'element_type' => $elementTypes[$id] ?? 0,
                ];
            }

            foreach ($gameweekRows as $gameweekRow) {
                $id = $gameweekRow['id'];

                $rating = self::bpsToMatchRating($gameweekRow['bps'], $bpsBounds);
                $ratingSums[$id] = ($ratingSums[$id] ?? 0.0) + $rating;
                $ratingCounts[$id] = ($ratingCounts[$id] ?? 0) + 1;
                $influenceTotals[$id] = ($influenceTotals[$id] ?? 0.0) + (float) $gameweekRow['influence'];

                $matchChances = self::matchChancesFromLiveRow($gameweekRow);
                if ($matchChances < 1) {
                    continue;
                }

                $chanceTotals[$id] = ($chanceTotals[$id] ?? 0) + $matchChances;
            }
        }

        $avgRatings = [];
        foreach ($ratingSums as $id => $sum) {
            $count = $ratingCounts[$id] ?? 0;
            if ($count < 1) {
                continue;
            }
            $avgRatings[$id] = round($sum / $count, 1);
        }

        return [
            'avg_ratings' => $avgRatings,
            'chances_created' => $chanceTotals,
            'chance_tiebreak' => $influenceTotals,
        ];
    }

    /**
     * Estimate chances created for one finished match from FPL live stats.
     *
     * @param array{
     *   creativity: float,
     *   influence: float,
     *   threat: float,
     *   minutes: int,
     *   goals_scored: int,
     *   assists: int,
     *   element_type: int
     * } $row
     */
    private static function matchChancesFromLiveRow(array $row): int
    {
        if ((int) $row['element_type'] === 1) {
            return 0;
        }

        $creativity = (float) $row['creativity'];
        $influence = (float) $row['influence'];
        $threat = (float) $row['threat'];
        $minutes = (int) $row['minutes'];
        $goals = (int) $row['goals_scored'];
        $assists = (int) $row['assists'];

        $score = $creativity;

        if ($influence < self::CHANCE_INF_LOW_THRESHOLD) {
            $score -= self::CHANCE_LOW_INF_PENALTY * (self::CHANCE_INF_LOW_THRESHOLD - $influence);
        }

        if (
            $influence > self::CHANCE_HIGH_INF_THRESHOLD
            && $goals > 0
            && $creativity > self::CHANCE_GOAL_CREATIVE_MIN
        ) {
            $score += self::CHANCE_HIGH_INF_BOOST * ($influence - self::CHANCE_HIGH_INF_THRESHOLD);
        }

        $score -= self::CHANCE_THREAT_WEIGHT * $threat;
        $score -= self::CHANCE_ASSIST_SUBTRACT * $assists;

        if ($minutes >= self::CHANCE_FULL_MINUTES && $creativity > self::CHANCE_HIGH_CREATIVITY && $assists === 0) {
            $score -= self::CHANCE_FULL_PENALTY + (self::CHANCE_FULL_THREAT_WEIGHT * $threat);
        }

        if (
            $minutes >= self::CHANCE_INVOLVEMENT_MINUTES
            && $influence > self::CHANCE_MID_INF_THRESHOLD
            && $goals === 0
            && $assists === 0
        ) {
            $score -= self::CHANCE_MID_INF_PENALTY * ($influence - self::CHANCE_MID_INF_THRESHOLD);
        }

        if (
            $goals > 0
            && $creativity >= self::CHANCE_FINISHER_CR_LOW
            && $creativity <= self::CHANCE_FINISHER_CR_HIGH
            && $minutes >= self::CHANCE_FINISHER_MINUTES
        ) {
            $score -= self::CHANCE_FINISHER_PENALTY;
        }

        if ($minutes < self::CHANCE_SUB_MINUTES) {
            $score *= self::CHANCE_SUB_BOOST;
        }

        if ((int) $row['element_type'] === 2) {
            $score *= self::CHANCE_DEF_FACTOR;
        }

        return (int) max(0, round($score / self::CHANCE_DIVISOR));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{min: int, max: int}
     */
    private static function collectBpsBounds(array $events): array
    {
        $min = null;
        $max = null;

        foreach ($events as $event) {
            if (empty($event['finished']) || empty($event['data_checked'])) {
                continue;
            }
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId < 1) {
                continue;
            }

            try {
                $live = FplClient::fetchEventLive($eventId);
            } catch (\Throwable) {
                continue;
            }

            $rows = $live['elements'] ?? null;
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $stats = $row['stats'] ?? null;
                if (!is_array($stats)) {
                    continue;
                }
                if ((int) ($stats['minutes'] ?? 0) < 1) {
                    continue;
                }

                $bps = (int) ($stats['bps'] ?? 0);
                $min = $min === null ? $bps : min($min, $bps);
                $max = $max === null ? $bps : max($max, $bps);
            }
        }

        return [
            'min' => $min ?? 0,
            'max' => $max ?? 0,
        ];
    }

    /**
     * Map FPL bonus point scores to a 1 to 10 match rating scale.
     * The best BPS in finished gameweeks maps to 9.1; the worst maps to 1.0.
     *
     * @param array{min: int, max: int} $bpsBounds
     */
    private static function bpsToMatchRating(int $bps, array $bpsBounds): float
    {
        $bpsMin = $bpsBounds['min'];
        $bpsMax = $bpsBounds['max'];

        if ($bpsMax <= $bpsMin) {
            return 5.0;
        }

        $rating = 1.0 + (($bps - $bpsMin) / ($bpsMax - $bpsMin)) * 8.1;

        return round(max(1.0, min(10.0, $rating)), 1);
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
     * @param list<array<string, mixed>> $events
     */
    private static function seasonLabel(array $events): string
    {
        $year = (int) gmdate('Y');
        $month = (int) gmdate('n');
        if ($month < 7) {
            return ($year - 1) . '/' . substr((string) $year, -2);
        }
        return $year . '/' . substr((string) ($year + 1), -2);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{id:int,name:string}
     */
    private static function currentEventMeta(array $events): array
    {
        foreach ($events as $event) {
            if (!empty($event['is_current'])) {
                return [
                    'id' => (int) ($event['id'] ?? 0),
                    'name' => (string) ($event['name'] ?? 'Gameweek'),
                ];
            }
        }
        foreach ($events as $event) {
            if (!empty($event['is_next'])) {
                return [
                    'id' => (int) ($event['id'] ?? 0),
                    'name' => (string) ($event['name'] ?? 'Gameweek'),
                ];
            }
        }
        return ['id' => 0, 'name' => 'Premier League'];
    }
}
