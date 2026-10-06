<?php

declare(strict_types=1);

namespace App;

final class LiveTeamService
{
    private const BASE = 'https://fantasy.premierleague.com/api';

    /** Serve from disk for 10 minutes as fresh; keep usable for 45 minutes. */
    private const LIVE_FRESH_SECONDS = 600;
    private const LIVE_STALE_SECONDS = 2700;

    /**
     * @return array{live: array<string, mixed>, cache: string, needs_refresh: bool}
     */
    public static function buildForEntry(int $entryId, ?int $userId = null): array
    {
        $key = 'live-entry-v3-' . $entryId;
        $hit = FplCache::inspect($key, self::LIVE_FRESH_SECONDS, self::LIVE_STALE_SECONDS);

        if ($hit !== null && $hit['fresh']) {
            return [
                'live' => $hit['value'],
                'cache' => 'HIT',
                'needs_refresh' => false,
            ];
        }

        if ($hit !== null) {
            return [
                'live' => $hit['value'],
                'cache' => 'STALE',
                'needs_refresh' => true,
            ];
        }

        $live = self::buildFresh($entryId, $userId);
        FplCache::put($key, $live);

        return [
            'live' => $live,
            'cache' => 'MISS',
            'needs_refresh' => false,
        ];
    }

    public static function refreshEntry(int $entryId, ?int $userId = null): void
    {
        $live = self::buildFresh($entryId, $userId);
        FplCache::put('live-entry-v3-' . $entryId, $live);
        FplCache::put('live-entry-v2-' . $entryId, $live);
    }

    /** @return array<string, mixed> */
    public static function buildFresh(int $entryId, ?int $userId = null): array
    {
        $bootstrap = FplClient::fetchBootstrapIndex();
        $elementsById = $bootstrap['elements'];
        $teamsById = $bootstrap['teams'];
        $events = $bootstrap['events'];

        $context = FplEntryPicksService::resolveContext($events);
        $current = $context['current'];
        $previous = $context['previous'];

        $urls = [
            'entry' => self::BASE . '/entry/' . $entryId . '/',
            'history' => self::BASE . '/entry/' . $entryId . '/history/',
        ];
        $bundle = FplClient::getJsonMany($urls);
        $entry = $bundle['entry'];
        if (empty($entry['id'])) {
            throw new \InvalidArgumentException('We could not find an FPL team with that ID.');
        }

        $resolved = ($current !== null && empty($current['finished']))
            ? FplEntryPicksService::resolveForGameweek(
                $entryId,
                $events,
                (int) $current['id'],
                $userId
            )
            : FplEntryPicksService::resolveActiveSquad($entryId, $events, $userId);

        $picksPayload = $resolved['payload'];
        $squadEventId = $resolved['event_id'];
        $scoresEventId = ($current !== null && empty($current['finished']))
            ? (int) $current['id']
            : (int) ($resolved['scores_event_id'] ?? $squadEventId);
        $showPlanningSquad = $squadEventId !== $scoresEventId;

        $scoresEventFinished = false;
        foreach ($events as $event) {
            if ((int) ($event['id'] ?? 0) === $scoresEventId) {
                $scoresEventFinished = !empty($event['finished']);
                break;
            }
        }

        $live = FplCache::get('event-live-' . $scoresEventId, 300);
        if ($live === null) {
            $live = FplClient::fetchEventLive(
                $scoresEventId,
                $current !== null && !empty($current['finished'])
            );
            FplCache::put('event-live-' . $scoresEventId, $live);
        }

        if (!$picksPayload || empty($picksPayload['picks'])) {
            throw new \RuntimeException(
                'Your picks are not available yet. This often happens before the gameweek deadline.'
            );
        }

        $historyCurrent = is_array($bundle['history']['current'] ?? null)
            ? $bundle['history']['current']
            : [];

        $liveByElement = [];
        foreach (($live['elements'] ?? []) as $row) {
            $liveByElement[(int) $row['id']] = $row;
        }

        $picks = $picksPayload['picks'] ?? [];
        $entryHistory = $picksPayload['entry_history'] ?? [];

        $xi = [];
        $bench = [];
        $xiPointsSum = 0;

        foreach ($picks as $pick) {
            $player = self::mapPlayer(
                $pick,
                $elementsById,
                $teamsById,
                $showPlanningSquad ? [] : $liveByElement
            );
            if ((int) $pick['position'] <= 11) {
                $xiPointsSum += (int) $player['team_points'];
                $xi[] = $player;
            } else {
                $bench[] = $player;
            }
        }

        $picksGwPoints = isset($entryHistory['points']) ? (int) $entryHistory['points'] : null;
        if ($scoresEventFinished) {
            $gwPoints = $xiPointsSum;
            foreach ($historyCurrent as $row) {
                if ((int) ($row['event'] ?? 0) === $scoresEventId) {
                    $gwPoints = (int) ($row['points'] ?? $gwPoints);
                    break;
                }
            }
        } elseif ($picksGwPoints !== null && (int) ($entryHistory['event'] ?? $scoresEventId) === $scoresEventId) {
            $gwPoints = $picksGwPoints;
        } else {
            $gwPoints = $xiPointsSum;
        }

        $rows = [
            'gkp' => [],
            'def' => [],
            'mid' => [],
            'fwd' => [],
        ];
        foreach ($xi as $player) {
            $rows[$player['row']][] = $player;
        }

        $lastGwRank = null;
        $liveRank = null;
        $rankChange = null;

        foreach ($historyCurrent as $row) {
            if ((int) ($row['event'] ?? 0) === $scoresEventId) {
                $liveRank = isset($row['overall_rank']) ? (int) $row['overall_rank'] : null;
            }
            if ($previous && (int) ($row['event'] ?? 0) === (int) $previous['id']) {
                $lastGwRank = isset($row['overall_rank']) ? (int) $row['overall_rank'] : null;
            }
        }

        if (
            !$scoresEventFinished
            && isset($entryHistory['overall_rank'])
            && (int) ($entryHistory['event'] ?? $scoresEventId) === $scoresEventId
        ) {
            $liveRank = (int) $entryHistory['overall_rank'];
        }

        if ($liveRank === null && isset($entry['summary_overall_rank'])) {
            $liveRank = (int) $entry['summary_overall_rank'];
        }

        if ($lastGwRank !== null && $liveRank !== null) {
            $rankChange = $lastGwRank - $liveRank;
        }

        $squadEventName = 'GW' . $squadEventId;
        $scoresEventName = 'GW' . $scoresEventId;
        foreach ($events as $event) {
            if ((int) $event['id'] === $squadEventId) {
                $squadEventName = (string) ($event['name'] ?? $squadEventName);
            }
            if ((int) $event['id'] === $scoresEventId) {
                $scoresEventName = (string) ($event['name'] ?? $scoresEventName);
            }
        }

        return [
            'entry' => FplClient::summariseEntry($entry),
            'event' => [
                'id' => $squadEventId,
                'name' => $squadEventName,
                'is_current' => $current && (int) $current['id'] === $squadEventId,
                'picks_fallback' => $resolved['fallback'],
                'picks_source' => $resolved['source'],
            ],
            'scores_event' => [
                'id' => $scoresEventId,
                'name' => $scoresEventName,
            ],
            'updated_at' => gmdate('c'),
            'stats' => [
                'gw_points' => $gwPoints,
                'live_rank' => $liveRank,
                'last_gw_rank' => $lastGwRank,
                'rank_change' => $rankChange,
            ],
            'active_chip' => $picksPayload['active_chip'] ?? null,
            'pitch' => $rows,
            'bench' => $bench,
        ];
    }

    /**
     * @param array<string, mixed> $pick
     * @param array<int, array<string, mixed>> $elementsById
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, array<string, mixed>> $liveByElement
     * @return array<string, mixed>
     */
    private static function mapPlayer(array $pick, array $elementsById, array $teamsById, array $liveByElement): array
    {
        $elementId = (int) $pick['element'];
        $element = $elementsById[$elementId] ?? [];
        $teamId = (int) ($element['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $short = (string) ($team['short_name'] ?? '');
        $teamCode = (int) ($team['code'] ?? 0);
        $elementType = (int) ($pick['element_type'] ?? $element['element_type'] ?? 0);
        $isGk = $elementType === 1;

        $live = $liveByElement[$elementId]['stats'] ?? [];
        $basePoints = $liveByElement === []
            ? 0
            : (isset($live['total_points']) ? (int) $live['total_points'] : (int) ($element['event_points'] ?? 0));
        $multiplier = (int) ($pick['multiplier'] ?? 1);

        $row = match ($elementType) {
            1 => 'gkp',
            2 => 'def',
            3 => 'mid',
            default => 'fwd',
        };

        $posLabel = match ($elementType) {
            1 => 'GK',
            2 => 'DEF',
            3 => 'MID',
            default => 'FWD',
        };

        $shirt = $teamCode > 0
            ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_'
                . $teamCode
                . ($isGk ? '_1' : '')
                . '-66.webp'
            : null;

        return [
            'id' => $elementId,
            'web_name' => (string) ($element['web_name'] ?? 'Player'),
            'team_short' => $short,
            'team_name' => (string) ($team['name'] ?? ''),
            'team_code' => $teamCode,
            'shirt' => $shirt,
            'element_type' => $elementType,
            'position' => (int) ($pick['position'] ?? 0),
            'row' => $row,
            'pos_label' => $posLabel,
            'is_captain' => !empty($pick['is_captain']),
            'is_vice_captain' => !empty($pick['is_vice_captain']),
            'multiplier' => $multiplier,
            'points' => $basePoints,
            'team_points' => $basePoints * max(0, $multiplier),
            'events' => [
                'goals' => (int) ($live['goals_scored'] ?? 0),
                'assists' => (int) ($live['assists'] ?? 0),
                'yellow' => (int) ($live['yellow_cards'] ?? 0),
                'red' => (int) ($live['red_cards'] ?? 0),
                'bonus' => (int) ($live['bonus'] ?? 0),
                'own_goals' => (int) ($live['own_goals'] ?? 0),
                'penalties_missed' => (int) ($live['penalties_missed'] ?? 0),
                'saves' => (int) ($live['saves'] ?? 0),
            ],
        ];
    }
}
