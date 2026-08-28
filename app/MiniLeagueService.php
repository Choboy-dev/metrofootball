<?php

declare(strict_types=1);

namespace App;

final class MiniLeagueService
{
    private const RIVAL_POINTS_WINDOW = 20;
    private const MAX_RIVALS = 6;
    private const MAX_MOVERS = 5;
    private const MAX_ACTIVITY = 8;

    /**
     * @return array<string, mixed>
     */
    public static function buildOverview(int $userId, int $entryId): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $currentEvent = self::resolveCurrentEvent($events);
        $tracked = TrackedLeagueService::listForUser($userId);

        return [
            'event' => $currentEvent,
            'entry_id' => $entryId,
            'tracked_leagues' => $tracked,
            'active_league_id' => $tracked[0]['fpl_league_id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildDashboard(int $userId, int $entryId, int $leagueId): array
    {
        if ($leagueId < 1) {
            throw new \InvalidArgumentException('Choose a league to view.');
        }

        $tracked = TrackedLeagueService::findForUser($userId, $leagueId);
        if ($tracked === null) {
            throw new \InvalidArgumentException('Add this league before you open it.');
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $currentEvent = self::resolveCurrentEvent($events);

        $leagueMeta = FplClient::fetchClassicLeague($leagueId);
        $standings = FplClient::fetchAllClassicLeagueStandings($leagueId);

        if ($standings === []) {
            throw new \RuntimeException('We could not read this league right now.');
        }

        $you = self::findEntryRow($standings, $entryId);
        if ($you === null) {
            throw new \InvalidArgumentException('Your FPL team is not in this league.');
        }

        $leader = $standings[0] ?? null;
        $stats = self::buildStats($you, $standings, $leader);
        $rivals = self::buildRivals($you, $standings);
        $movers = self::buildMovers($standings);
        $activity = self::buildActivity($rivals, $entryId, $bootstrap);
        $table = self::buildStandingsView($standings, $entryId);
        $brief = self::buildBrief($you, $stats, $rivals, $movers, $currentEvent, $tracked);

        return [
            'league' => [
                'id' => $leagueId,
                'name' => (string) ($tracked['league_name'] ?: ($leagueMeta['name'] ?? ('League ' . $leagueId))),
                'created' => (string) ($leagueMeta['created'] ?? ''),
                'rank_count' => count($standings),
                'admin_entry' => (int) ($leagueMeta['admin_entry'] ?? 0),
            ],
            'tracked' => $tracked,
            'event' => $currentEvent,
            'entry_id' => $entryId,
            'you' => self::formatStandingRow($you, true),
            'stats' => $stats,
            'brief' => $brief,
            'standings' => $table,
            'rivals' => $rivals,
            'movers' => $movers,
            'activity' => $activity,
            'alerts_enabled' => (bool) ($tracked['alerts_enabled'] ?? false),
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function resolveCurrentEvent(array $events): array
    {
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!empty($event['is_current'])) {
                return [
                    'id' => (int) ($event['id'] ?? 0),
                    'name' => (string) ($event['name'] ?? 'Gameweek'),
                    'deadline_time' => (string) ($event['deadline_time'] ?? ''),
                    'finished' => !empty($event['finished']),
                ];
            }
        }

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!empty($event['is_next'])) {
                return [
                    'id' => (int) ($event['id'] ?? 0),
                    'name' => (string) ($event['name'] ?? 'Gameweek'),
                    'deadline_time' => (string) ($event['deadline_time'] ?? ''),
                    'finished' => !empty($event['finished']),
                ];
            }
        }

        return [
            'id' => 0,
            'name' => 'Gameweek',
            'deadline_time' => '',
            'finished' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $standings
     * @return array<string, mixed>|null
     */
    private static function findEntryRow(array $standings, int $entryId): ?array
    {
        foreach ($standings as $row) {
            if ((int) ($row['entry'] ?? 0) === $entryId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $you
     * @param list<array<string, mixed>> $standings
     * @param array<string, mixed>|null $leader
     * @return array<string, mixed>
     */
    private static function buildStats(array $you, array $standings, ?array $leader): array
    {
        $rank = (int) ($you['rank'] ?? 0);
        $lastRank = (int) ($you['last_rank'] ?? $rank);
        $rankChange = $lastRank > 0 && $rank > 0 ? $lastRank - $rank : 0;
        $yourTotal = (int) ($you['total'] ?? 0);
        $leaderTotal = (int) ($leader['total'] ?? $yourTotal);
        $gapToLeader = max(0, $leaderTotal - $yourTotal);

        $above = null;
        $below = null;
        foreach ($standings as $index => $row) {
            if ((int) ($row['entry'] ?? 0) === (int) ($you['entry'] ?? 0)) {
                $above = $standings[$index - 1] ?? null;
                $below = $standings[$index + 1] ?? null;
                break;
            }
        }

        $gapAbove = $above !== null ? max(0, (int) ($above['total'] ?? 0) - $yourTotal) : 0;
        $gapBelow = $below !== null ? max(0, $yourTotal - (int) ($below['total'] ?? 0)) : 0;

        return [
            'rank' => $rank,
            'last_rank' => $lastRank,
            'rank_change' => $rankChange,
            'total' => $yourTotal,
            'event_total' => (int) ($you['event_total'] ?? 0),
            'gap_to_leader' => $gapToLeader,
            'gap_to_above' => $gapAbove,
            'gap_to_below' => $gapBelow,
            'rivals_near' => self::countRivalsNear($you, $standings),
        ];
    }

    /**
     * @param array<string, mixed> $you
     * @param list<array<string, mixed>> $standings
     */
    private static function countRivalsNear(array $you, array $standings): int
    {
        $yourTotal = (int) ($you['total'] ?? 0);
        $count = 0;

        foreach ($standings as $row) {
            if ((int) ($row['entry'] ?? 0) === (int) ($you['entry'] ?? 0)) {
                continue;
            }
            $gap = abs((int) ($row['total'] ?? 0) - $yourTotal);
            if ($gap <= self::RIVAL_POINTS_WINDOW) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $you
     * @param list<array<string, mixed>> $standings
     * @return list<array<string, mixed>>
     */
    private static function buildRivals(array $you, array $standings): array
    {
        $yourTotal = (int) ($you['total'] ?? 0);
        $yourRank = (int) ($you['rank'] ?? 0);
        $rivals = [];

        foreach ($standings as $row) {
            if ((int) ($row['entry'] ?? 0) === (int) ($you['entry'] ?? 0)) {
                continue;
            }

            $gap = abs((int) ($row['total'] ?? 0) - $yourTotal);
            if ($gap > self::RIVAL_POINTS_WINDOW) {
                continue;
            }

            $formatted = self::formatStandingRow($row, false);
            $formatted['points_gap'] = (int) ($row['total'] ?? 0) - $yourTotal;
            $formatted['rank_gap'] = (int) ($row['rank'] ?? 0) - $yourRank;
            $rivals[] = $formatted;
        }

        usort($rivals, static function (array $a, array $b): int {
            return abs($a['points_gap']) <=> abs($b['points_gap']);
        });

        return array_slice($rivals, 0, self::MAX_RIVALS);
    }

    /**
     * @param list<array<string, mixed>> $standings
     * @return array{up: list<array<string, mixed>>, down: list<array<string, mixed>>}
     */
    private static function buildMovers(array $standings): array
    {
        $rows = [];

        foreach ($standings as $row) {
            $rank = (int) ($row['rank'] ?? 0);
            $lastRank = (int) ($row['last_rank'] ?? $rank);
            if ($rank <= 0 || $lastRank <= 0 || $rank === $lastRank) {
                continue;
            }

            $formatted = self::formatStandingRow($row, false);
            $formatted['rank_change'] = $lastRank - $rank;
            $rows[] = $formatted;
        }

        usort($rows, static fn(array $a, array $b): int => abs($b['rank_change']) <=> abs($a['rank_change']));

        $up = [];
        $down = [];
        foreach ($rows as $row) {
            if ($row['rank_change'] > 0 && count($up) < self::MAX_MOVERS) {
                $up[] = $row;
            } elseif ($row['rank_change'] < 0 && count($down) < self::MAX_MOVERS) {
                $down[] = $row;
            }
        }

        return ['up' => $up, 'down' => $down];
    }

    /**
     * @param list<array<string, mixed>> $rivals
     * @param array<string, mixed> $bootstrap
     * @return list<array<string, mixed>>
     */
    private static function buildActivity(array $rivals, int $entryId, array $bootstrap): array
    {
        $activity = [];
        $seen = [];
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $nameById = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $id = (int) ($element['id'] ?? 0);
            if ($id > 0) {
                $nameById[$id] = (string) ($element['web_name'] ?? 'Player');
            }
        }

        foreach ($rivals as $rival) {
            $rivalId = (int) ($rival['entry_id'] ?? 0);
            if ($rivalId < 1 || $rivalId === $entryId || isset($seen[$rivalId])) {
                continue;
            }
            $seen[$rivalId] = true;

            try {
                $transfers = FplClient::fetchEntryTransfers($rivalId);
            } catch (\Throwable) {
                continue;
            }

            $items = is_array($transfers ?? null) ? $transfers : [];
            $recent = array_slice(array_reverse($items), 0, 2);

            foreach ($recent as $move) {
                if (!is_array($move)) {
                    continue;
                }

                $inId = (int) ($move['element_in'] ?? 0);
                $outId = (int) ($move['element_out'] ?? 0);

                $activity[] = [
                    'entry_id' => $rivalId,
                    'entry_name' => (string) ($rival['entry_name'] ?? 'Rival'),
                    'player_in' => $nameById[$inId] ?? 'In',
                    'player_out' => $nameById[$outId] ?? 'Out',
                    'event' => (int) ($move['event'] ?? 0),
                    'time' => (string) ($move['time'] ?? ''),
                ];
            }
        }

        usort($activity, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));

        return array_slice($activity, 0, self::MAX_ACTIVITY);
    }

    /**
     * @param list<array<string, mixed>> $standings
     * @return list<array<string, mixed>>
     */
    private static function buildStandingsView(array $standings, int $entryId): array
    {
        $yourIndex = null;
        foreach ($standings as $index => $row) {
            if ((int) ($row['entry'] ?? 0) === $entryId) {
                $yourIndex = $index;
                break;
            }
        }

        if ($yourIndex === null) {
            return array_map(
                static fn(array $row): array => self::formatStandingRow($row, false),
                array_slice($standings, 0, 12)
            );
        }

        $start = max(0, $yourIndex - 4);
        $slice = array_slice($standings, $start, 10);

        return array_map(
            static fn(array $row): array => self::formatStandingRow(
                $row,
                (int) ($row['entry'] ?? 0) === $entryId
            ),
            $slice
        );
    }

    private static function pointsLabel(int $count): string
    {
        return $count === 1 ? '1 point' : $count . ' points';
    }

    private static function rankLabel(int $rank): string
    {
        if ($rank <= 0) {
            return 'unranked';
        }

        if ($rank % 100 >= 11 && $rank % 100 <= 13) {
            return $rank . 'th';
        }

        return match ($rank % 10) {
            1 => $rank . 'st',
            2 => $rank . 'nd',
            3 => $rank . 'rd',
            default => $rank . 'th',
        };
    }

    /**
     * @param array<string, mixed> $you
     * @param array<string, mixed> $stats
     * @param list<array<string, mixed>> $rivals
     * @param array{up: list<array<string, mixed>>, down: list<array<string, mixed>>} $movers
     * @param array<string, mixed> $event
     * @param array<string, mixed> $tracked
     */
    private static function buildBrief(
        array $you,
        array $stats,
        array $rivals,
        array $movers,
        array $event,
        array $tracked
    ): string {
        $rank = (int) ($stats['rank'] ?? 0);
        $change = (int) ($stats['rank_change'] ?? 0);
        $gapLeader = (int) ($stats['gap_to_leader'] ?? 0);
        $gwPts = (int) ($stats['event_total'] ?? 0);
        $leagueName = (string) ($tracked['league_name'] ?? 'this league');
        $eventName = (string) ($event['name'] ?? 'this gameweek');

        $parts = [];

        if ($rank === 1) {
            $parts[] = 'You are 1st in ' . $leagueName . ' with '
                . self::pointsLabel($gwPts) . ' in ' . $eventName . '.';
        } else {
            $parts[] = 'You are ' . self::rankLabel($rank) . ' in ' . $leagueName
                . '. The leader is ' . self::pointsLabel($gapLeader) . ' ahead of you.';
        }

        if ($change > 0) {
            $parts[] = 'You moved up ' . $change . ' places last week.';
        } elseif ($change < 0) {
            $parts[] = 'You moved down ' . abs($change) . ' places last week.';
        } else {
            $parts[] = 'Your place stayed the same as last week.';
        }

        if ($rivals !== []) {
            $closest = $rivals[0];
            $gap = (int) ($closest['points_gap'] ?? 0);
            $name = trim((string) ($closest['entry_name'] ?? 'a rival'));
            if ($gap === 0) {
                $parts[] = 'You and ' . $name . ' have the same points.';
            } elseif ($gap > 0) {
                $parts[] = $name . ' is ' . self::pointsLabel($gap) . ' ahead of you.';
            } else {
                $parts[] = $name . ' is ' . self::pointsLabel(abs($gap)) . ' behind you.';
            }
        }

        $climber = $movers['up'][0] ?? null;
        if ($climber !== null && (int) ($climber['rank_change'] ?? 0) >= 3) {
            $parts[] = (string) ($climber['entry_name'] ?? 'A rival') . ' moved up '
                . (int) $climber['rank_change'] . ' places this week.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatStandingRow(array $row, bool $isYou): array
    {
        $rank = (int) ($row['rank'] ?? 0);
        $lastRank = (int) ($row['last_rank'] ?? $rank);

        return [
            'entry_id' => (int) ($row['entry'] ?? 0),
            'entry_name' => (string) ($row['entry_name'] ?? ''),
            'player_name' => (string) ($row['player_name'] ?? ''),
            'rank' => $rank,
            'last_rank' => $lastRank,
            'rank_change' => $lastRank > 0 && $rank > 0 ? $lastRank - $rank : 0,
            'total' => (int) ($row['total'] ?? 0),
            'event_total' => (int) ($row['event_total'] ?? 0),
            'is_you' => $isYou,
        ];
    }
}
