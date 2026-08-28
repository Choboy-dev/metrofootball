<?php

declare(strict_types=1);

namespace App;

/**
 * Auto Gameweek Recap: compute FPL facts, narrate briefly, optional personal strip.
 */
final class GameweekRecapService
{
    private const CACHE_PREFIX = 'gw-recap-v8-';
    private const PERSONAL_PREFIX = 'gw-recap-you-v1-';
    /** Shared recap while a gameweek is still live. */
    private const FRESH_LIVE = 300;
    /** Finished recaps change rarely; refresh once per day at most. */
    private const FRESH_FINAL = 86400;
    /** Serve stale shared recap during live GW if FPL is slow. */
    private const STALE_LIVE = 7200;
    /** Finished recap is identical for everyone; keep for 30 days. */
    private const STALE_FINAL = 2592000;
    private const TEMPLATE_OWN = 15.0;
    private const CAPTAIN_OWN = 20.0;
    /** Minimum FPL strength gap (1–5 scale) for a surprise win. */
    private const UPSET_STRENGTH_GAP = 1;

    /** @var array<int, string> */
    private const POS_LABEL = [
        1 => 'GK',
        2 => 'DEF',
        3 => 'MID',
        4 => 'FWD',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(?int $requestedGw = null, ?int $entryId = null): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];

        $meta = self::resolveEvent($events, $requestedGw);
        $eventId = (int) $meta['id'];
        $finished = !empty($meta['finished']);
        $fresh = $finished ? self::FRESH_FINAL : self::FRESH_LIVE;
        $stale = $finished ? self::STALE_FINAL : self::STALE_LIVE;
        $cacheKey = self::CACHE_PREFIX . $eventId;

        $hit = FplCache::inspect($cacheKey, $fresh, $stale);
        if ($hit !== null && is_array($hit['value']) && ($hit['value']['model'] ?? '') === 'recap-v8') {
            $payload = $hit['value'];
        } else {
            try {
                $live = FplClient::fetchEventLive($eventId, $finished);
                $fixtures = self::fixturesForEvent(FplClient::fetchFixtures(), $eventId);
                $payload = self::composeReport($eventId, $meta, $elements, $teams, $live, $fixtures, $events);
                FplCache::put($cacheKey, $payload);
            } catch (\Throwable $e) {
                if ($hit !== null && is_array($hit['value']) && ($hit['value']['model'] ?? '') === 'recap-v8') {
                    $payload = $hit['value'];
                } else {
                    throw $e;
                }
            }
        }

        $payload['your_gw'] = null;
        if ($entryId !== null && $entryId > 0) {
            try {
                $payload['your_gw'] = self::buildPersonalStrip($entryId, $eventId, $elements, $teams, $payload, $finished);
            } catch (\Throwable) {
                $payload['your_gw'] = [
                    'available' => false,
                    'message' => 'We could not load your team for this gameweek yet.',
                ];
            }
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function resolveEvent(array $events, ?int $requestedGw): array
    {
        $byId = [];
        $finished = [];
        $current = null;
        $previous = null;
        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $byId[$id] = $event;
            if (!empty($event['finished']) || !empty($event['data_checked'])) {
                $finished[] = $event;
            }
            if (!empty($event['is_current'])) {
                $current = $event;
            }
            if (!empty($event['is_previous'])) {
                $previous = $event;
            }
        }

        if ($requestedGw !== null && $requestedGw > 0 && isset($byId[$requestedGw])) {
            return self::eventMeta($byId[$requestedGw], $byId);
        }

        if ($previous) {
            return self::eventMeta($previous, $byId);
        }

        if ($finished) {
            usort($finished, static fn (array $a, array $b): int => ((int) $b['id']) <=> ((int) $a['id']));
            return self::eventMeta($finished[0], $byId);
        }

        if ($current) {
            return self::eventMeta($current, $byId);
        }

        if ($byId) {
            ksort($byId);
            return self::eventMeta(reset($byId), $byId);
        }

        throw new \RuntimeException('No gameweek data is available yet.');
    }

    /**
     * @param array<string, mixed> $event
     * @param array<int, array<string, mixed>> $byId
     * @return array<string, mixed>
     */
    private static function eventMeta(array $event, array $byId): array
    {
        $id = (int) $event['id'];
        $finished = !empty($event['finished']) || !empty($event['data_checked']);
        $ids = array_keys($byId);
        sort($ids);
        $idx = array_search($id, $ids, true);
        $prevId = ($idx !== false && $idx > 0) ? $ids[$idx - 1] : null;
        $nextId = ($idx !== false && $idx < count($ids) - 1) ? $ids[$idx + 1] : null;

        $available = [];
        foreach ($ids as $gwId) {
            $ev = $byId[$gwId];
            $available[] = [
                'id' => $gwId,
                'name' => (string) ($ev['name'] ?? ('Gameweek ' . $gwId)),
                'finished' => !empty($ev['finished']) || !empty($ev['data_checked']),
                'is_current' => !empty($ev['is_current']),
            ];
        }

        return [
            'id' => $id,
            'name' => (string) ($event['name'] ?? ('Gameweek ' . $id)),
            'finished' => $finished,
            'is_current' => !empty($event['is_current']),
            'deadline_time' => $event['deadline_time'] ?? null,
            'average_entry_score' => isset($event['average_entry_score']) ? (int) $event['average_entry_score'] : null,
            'highest_score' => isset($event['highest_score']) ? (int) $event['highest_score'] : null,
            'most_captained' => isset($event['most_captained']) ? (int) $event['most_captained'] : null,
            'most_vice_captained' => isset($event['most_vice_captained']) ? (int) $event['most_vice_captained'] : null,
            'most_selected' => isset($event['most_selected']) ? (int) $event['most_selected'] : null,
            'chip_plays' => is_array($event['chip_plays'] ?? null) ? $event['chip_plays'] : [],
            'prev_id' => $prevId,
            'next_id' => $nextId,
            'available' => $available,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @return list<array<string, mixed>>
     */
    private static function fixturesForEvent(array $fixtures, int $eventId): array
    {
        $out = [];
        foreach ($fixtures as $fx) {
            if ((int) ($fx['event'] ?? 0) !== $eventId) {
                continue;
            }
            $out[] = $fx;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int|string, array<string, mixed>> $teams
     * @param array<string, mixed> $live
     * @param list<array<string, mixed>> $fixtures
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function composeReport(
        int $eventId,
        array $meta,
        array $elements,
        array $teams,
        array $live,
        array $fixtures,
        array $events
    ): array {
        $teamsById = [];
        foreach ($teams as $team) {
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $liveById = [];
        foreach (($live['elements'] ?? []) as $row) {
            $liveById[(int) ($row['id'] ?? 0)] = $row;
        }

        $players = [];
        foreach ($elements as $el) {
            $id = (int) ($el['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $stats = is_array($liveById[$id]['stats'] ?? null) ? $liveById[$id]['stats'] : [];
            $minutes = (int) ($stats['minutes'] ?? 0);
            $points = (int) ($stats['total_points'] ?? 0);
            $played = $minutes > 0 || !empty($stats['played']) || $points !== 0;
            if (!$played && $points === 0) {
                continue;
            }

            $teamId = (int) ($el['team'] ?? 0);
            $team = $teamsById[$teamId] ?? [];
            $code = (int) ($team['code'] ?? 0);
            $pos = (int) ($el['element_type'] ?? 0);
            $own = (float) ($el['selected_by_percent'] ?? 0);
            $ep = (float) ($el['ep_this'] ?? 0);
            $xg = (float) ($stats['expected_goals'] ?? 0);
            $xa = (float) ($stats['expected_assists'] ?? 0);
            $goals = (int) ($stats['goals_scored'] ?? 0);
            $assists = (int) ($stats['assists'] ?? 0);
            $bonus = (int) ($stats['bonus'] ?? 0);
            $bps = (int) ($stats['bps'] ?? 0);

            $photoCode = (string) ($el['code'] ?? '');
            $players[] = [
                'id' => $id,
                'web_name' => (string) ($el['web_name'] ?? 'Player'),
                'first_name' => (string) ($el['first_name'] ?? ''),
                'second_name' => (string) ($el['second_name'] ?? ''),
                'pos' => $pos,
                'pos_label' => self::POS_LABEL[$pos] ?? '?',
                'team_id' => $teamId,
                'team_short' => (string) ($team['short_name'] ?? ''),
                'team_name' => (string) ($team['name'] ?? ''),
                'badge' => $code > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                    : null,
                'photo' => $photoCode !== ''
                    ? 'https://resources.premierleague.com/premierleague/photos/players/110x140/p' . $photoCode . '.png'
                    : null,
                'shirt' => $code > 0
                    ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $code . '-110.webp'
                    : null,
                'price' => round(((int) ($el['now_cost'] ?? 0)) / 10, 1),
                'ownership' => round($own, 1),
                'minutes' => $minutes,
                'points' => $points,
                'goals' => $goals,
                'assists' => $assists,
                'clean_sheets' => (int) ($stats['clean_sheets'] ?? 0),
                'bonus' => $bonus,
                'bps' => $bps,
                'saves' => (int) ($stats['saves'] ?? 0),
                'xg' => round($xg, 2),
                'xa' => round($xa, 2),
                'ep_this' => round($ep, 1),
                'delta_ep' => round($points - $ep, 1),
                'delta_xg' => round($goals - $xg, 2),
                'delta_xa' => round($assists - $xa, 2),
                'in_dreamteam' => !empty($stats['in_dreamteam']),
                'yellow_cards' => (int) ($stats['yellow_cards'] ?? 0),
                'red_cards' => (int) ($stats['red_cards'] ?? 0),
            ];
        }

        usort($players, static function (array $a, array $b): int {
            $pts = $b['points'] <=> $a['points'];
            if ($pts !== 0) {
                return $pts;
            }
            return $b['bps'] <=> $a['bps'];
        });

        $topScorers = array_slice($players, 0, 15);
        $scorerPool = array_slice($players, 0, 40);
        $byPosition = [];
        foreach ([1, 2, 3, 4] as $pos) {
            $slice = array_values(array_filter($players, static fn (array $p): bool => $p['pos'] === $pos));
            $byPosition[self::POS_LABEL[$pos]] = array_slice($slice, 0, 8);
        }

        $captainStory = self::buildCaptainStory($players, $meta, $elements);
        $bonusMoments = self::buildBonusMoments($players);
        $beatsMisses = self::buildBeatsMisses($players, $meta, $fixtures, $teamsById);
        $template = self::buildTemplatePulse($players);
        $fixtureSummary = self::buildFixtureSummary($fixtures, $teamsById, $elements, $live);
        $kpis = self::buildKpis($players, $meta, $fixtures);
        $narrative = self::buildNarrative($meta, $kpis, $topScorers, $captainStory, $beatsMisses, $template);
        $shareLine = self::buildShareLine($meta, $topScorers, $captainStory, $kpis);
        $pointsById = self::pointsMapFromLive($live);

        return [
            'model' => 'recap-v8',
            'event' => $meta,
            'status' => $meta['finished'] ? 'final' : 'provisional',
            'kpis' => $kpis,
            'narrative' => $narrative,
            'share_line' => $shareLine,
            'top_scorers' => $topScorers,
            'scorer_pool' => $scorerPool,
            'by_position' => $byPosition,
            'captain_story' => $captainStory,
            'bonus_moments' => $bonusMoments,
            'beats_misses' => $beatsMisses,
            'template' => $template,
            'fixtures' => $fixtureSummary,
            'points_by_id' => $pointsById,
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $live
     * @return array<int, int>
     */
    private static function pointsMapFromLive(array $live): array
    {
        $map = [];
        foreach (($live['elements'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $map[$id] = (int) (($row['stats']['total_points'] ?? 0));
        }
        return $map;
    }

    /**
     * @param list<array<string, mixed>> $players
     * @param array<string, mixed> $meta
     * @param array<int|string, array<string, mixed>> $elements
     * @return array<string, mixed>
     */
    private static function buildCaptainStory(array $players, array $meta, array $elements): array
    {
        $byId = [];
        foreach ($players as $p) {
            $byId[$p['id']] = $p;
        }

        $mostCapId = (int) ($meta['most_captained'] ?? 0);
        $mostSelectedId = (int) ($meta['most_selected'] ?? 0);

        $resolve = static function (int $id) use ($byId, $elements): ?array {
            if ($id < 1) {
                return null;
            }
            if (isset($byId[$id])) {
                return $byId[$id];
            }
            foreach ($elements as $el) {
                if ((int) ($el['id'] ?? 0) === $id) {
                    return [
                        'id' => $id,
                        'web_name' => (string) ($el['web_name'] ?? 'Player'),
                        'points' => (int) ($el['event_points'] ?? 0),
                        'ownership' => round((float) ($el['selected_by_percent'] ?? 0), 1),
                        'pos_label' => self::POS_LABEL[(int) ($el['element_type'] ?? 0)] ?? '?',
                        'team_short' => '',
                        'photo' => null,
                        'shirt' => null,
                    ];
                }
            }
            return null;
        };

        $byOwnership = $players;
        usort($byOwnership, static fn (array $a, array $b): int => $b['ownership'] <=> $a['ownership']);
        $mostOwnedFive = array_slice($byOwnership, 0, 5);

        $mostOwned = $resolve($mostSelectedId) ?: ($mostOwnedFive[0] ?? null);
        $mostCaptained = $resolve($mostCapId);

        $captainPool = array_values(array_filter(
            $players,
            static fn (array $p): bool => $p['ownership'] >= self::CAPTAIN_OWN && in_array($p['pos'], [3, 4], true)
        ));
        usort($captainPool, static function (array $a, array $b): int {
            $pts = $b['points'] <=> $a['points'];
            if ($pts !== 0) {
                return $pts;
            }
            return $b['ownership'] <=> $a['ownership'];
        });
        $bestCaptain = $captainPool[0] ?? $mostCaptained;

        $worstPool = $captainPool;
        usort($worstPool, static function (array $a, array $b): int {
            $pts = $a['points'] <=> $b['points'];
            if ($pts !== 0) {
                return $pts;
            }
            return $b['ownership'] <=> $a['ownership'];
        });
        $worstCaptains = array_slice($worstPool, 0, 5);

        return [
            'most_owned' => $mostOwned,
            'most_captained' => $mostCaptained ?: ($mostOwnedFive[0] ?? null),
            'best_captain' => $bestCaptain,
            'worst_captains' => $worstCaptains,
            'most_owned_five' => $mostOwnedFive,
            // Kept for Gameweek Summary cards.
            'most_selected' => $mostOwned,
        ];
    }

    /**
     * @param list<array<string, mixed>> $players
     * @return list<array<string, mixed>>
     */
    private static function buildBonusMoments(array $players): array
    {
        $withBonus = array_values(array_filter($players, static fn (array $p): bool => $p['bonus'] > 0));
        usort($withBonus, static function (array $a, array $b): int {
            $bonus = $b['bonus'] <=> $a['bonus'];
            if ($bonus !== 0) {
                return $bonus;
            }
            return $b['bps'] <=> $a['bps'];
        });

        $moments = [];
        foreach (array_slice($withBonus, 0, 12) as $p) {
            $moments[] = [
                'type' => 'bonus',
                'player' => $p,
            ];
        }

        return $moments;
    }

    /**
     * @param list<array<string, mixed>> $players
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<string, mixed>
     */
    private static function buildBeatsMisses(
        array $players,
        array $meta,
        array $fixtures,
        array $teamsById
    ): array {
        $gw = (int) ($meta['id'] ?? 0);
        $eligible = array_values(array_filter(
            $players,
            static fn (array $p): bool => $p['minutes'] >= 45 || $p['points'] >= 6
        ));

        $byEp = $eligible;
        usort($byEp, static function (array $a, array $b): int {
            $delta = $b['delta_ep'] <=> $a['delta_ep'];
            if ($delta !== 0) {
                return $delta;
            }
            return $b['points'] <=> $a['points'];
        });
        $over = array_values(array_filter(
            $byEp,
            static fn (array $p): bool => $p['delta_ep'] > 0
        ));
        $over = array_slice($over, 0, 5);

        return [
            'basis' => 'Who scored more or less than the prediction gameweek ' . $gw . '.',
            'over' => $over,
            'surprise_winners' => self::buildSurpriseWinners($fixtures, $teamsById),
        ];
    }

    /**
     * Underdogs that beat a stronger side (FPL overall strength by venue).
     *
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, mixed>>
     */
    private static function buildSurpriseWinners(array $fixtures, array $teamsById): array
    {
        $upsets = [];
        foreach ($fixtures as $fx) {
            if (empty($fx['finished']) && empty($fx['finished_provisional'])) {
                continue;
            }
            $homeId = (int) ($fx['team_h'] ?? 0);
            $awayId = (int) ($fx['team_a'] ?? 0);
            $home = $teamsById[$homeId] ?? [];
            $away = $teamsById[$awayId] ?? [];
            $scoreH = $fx['team_h_score'];
            $scoreA = $fx['team_a_score'];
            if ($scoreH === null || $scoreA === null) {
                continue;
            }
            $scoreH = (int) $scoreH;
            $scoreA = (int) $scoreA;
            if ($scoreH === $scoreA) {
                continue;
            }

            $homeStr = (int) ($home['strength_overall_home'] ?? $home['strength'] ?? 0);
            $awayStr = (int) ($away['strength_overall_away'] ?? $away['strength'] ?? 0);
            if ($homeStr < 1 || $awayStr < 1) {
                continue;
            }

            $homeWon = $scoreH > $scoreA;
            $gap = $homeWon ? ($awayStr - $homeStr) : ($homeStr - $awayStr);
            if ($gap < self::UPSET_STRENGTH_GAP) {
                continue;
            }

            $homeCode = (int) ($home['code'] ?? 0);
            $awayCode = (int) ($away['code'] ?? 0);
            $winnerIsHome = $homeWon;
            $winner = $winnerIsHome ? $home : $away;
            $loser = $winnerIsHome ? $away : $home;
            $winnerCode = $winnerIsHome ? $homeCode : $awayCode;

            $upsets[] = [
                'id' => (int) ($fx['id'] ?? 0),
                'kickoff' => $fx['kickoff_time'] ?? null,
                'score_h' => $scoreH,
                'score_a' => $scoreA,
                'margin' => abs($scoreH - $scoreA),
                'strength_gap' => $gap,
                'home' => [
                    'id' => $homeId,
                    'short' => (string) ($home['short_name'] ?? 'H'),
                    'name' => (string) ($home['name'] ?? ''),
                    'badge' => $homeCode > 0
                        ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $homeCode . '.png'
                        : null,
                ],
                'away' => [
                    'id' => $awayId,
                    'short' => (string) ($away['short_name'] ?? 'A'),
                    'name' => (string) ($away['name'] ?? ''),
                    'badge' => $awayCode > 0
                        ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $awayCode . '.png'
                        : null,
                ],
                'winner' => [
                    'id' => (int) ($winner['id'] ?? 0),
                    'short' => (string) ($winner['short_name'] ?? ''),
                    'name' => (string) ($winner['name'] ?? ''),
                    'side' => $winnerIsHome ? 'home' : 'away',
                    'badge' => $winnerCode > 0
                        ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $winnerCode . '.png'
                        : null,
                ],
                'loser' => [
                    'short' => (string) ($loser['short_name'] ?? ''),
                    'name' => (string) ($loser['name'] ?? ''),
                ],
                'blurb' => (string) ($winner['name'] ?? 'A team')
                    . ' beat '
                    . (string) ($loser['name'] ?? 'a bigger side')
                    . '.',
            ];
        }

        usort($upsets, static function (array $a, array $b): int {
            $gap = $b['strength_gap'] <=> $a['strength_gap'];
            if ($gap !== 0) {
                return $gap;
            }
            $margin = $b['margin'] <=> $a['margin'];
            if ($margin !== 0) {
                return $margin;
            }
            return strcmp((string) ($a['kickoff'] ?? ''), (string) ($b['kickoff'] ?? ''));
        });

        return array_slice($upsets, 0, 5);
    }

    /**
     * @param list<array<string, mixed>> $players
     * @return array<string, mixed>
     */
    private static function buildTemplatePulse(array $players): array
    {
        $template = array_values(array_filter(
            $players,
            static fn (array $p): bool => $p['ownership'] >= self::TEMPLATE_OWN
        ));
        usort($template, static fn (array $a, array $b): int => $b['ownership'] <=> $a['ownership']);

        $byPointsHigh = $template;
        usort($byPointsHigh, static function (array $a, array $b): int {
            $pts = $b['points'] <=> $a['points'];
            if ($pts !== 0) {
                return $pts;
            }
            return $b['ownership'] <=> $a['ownership'];
        });
        $best = array_slice($byPointsHigh, 0, 5);
        $bestIds = array_fill_keys(array_map(static fn (array $p): int => $p['id'], $best), true);

        $worstPool = array_values(array_filter(
            $template,
            static fn (array $p): bool => !isset($bestIds[$p['id']]) && $p['minutes'] >= 1
        ));
        usort($worstPool, static function (array $a, array $b): int {
            $pts = $a['points'] <=> $b['points'];
            if ($pts !== 0) {
                return $pts;
            }
            return $b['ownership'] <=> $a['ownership'];
        });
        $worst = array_slice($worstPool, 0, 5);

        return [
            'threshold' => self::TEMPLATE_OWN,
            'best' => $best,
            'worst' => $worst,
            // Kept for narrative helpers that still read blanks.
            'blanks' => $worst,
            'hauls' => $best,
            'players' => array_slice($template, 0, 20),
        ];
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<string, mixed> $live
     * @return list<array<string, mixed>>
     */
    private static function buildFixtureSummary(
        array $fixtures,
        array $teamsById,
        array $elements,
        array $live
    ): array {
        $elementsById = [];
        foreach ($elements as $el) {
            $id = (int) ($el['id'] ?? 0);
            if ($id > 0) {
                $elementsById[$id] = $el;
            }
        }

        /** @var array<int, array{home:list<array<string,mixed>>,away:list<array<string,mixed>>}> $eventsByFixture */
        $eventsByFixture = [];
        $fixtureHomeTeam = [];
        foreach ($fixtures as $fx) {
            $fid = (int) ($fx['id'] ?? 0);
            if ($fid > 0) {
                $fixtureHomeTeam[$fid] = (int) ($fx['team_h'] ?? 0);
            }
        }

        foreach (($live['elements'] ?? []) as $row) {
            $playerId = (int) ($row['id'] ?? 0);
            $el = $elementsById[$playerId] ?? null;
            if ($el === null) {
                continue;
            }
            $teamId = (int) ($el['team'] ?? 0);
            $webName = (string) ($el['web_name'] ?? 'Player');

            foreach (($row['explain'] ?? []) as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $fixtureId = (int) ($block['fixture'] ?? 0);
                if ($fixtureId < 1 || !isset($fixtureHomeTeam[$fixtureId])) {
                    continue;
                }
                $goals = 0;
                $assists = 0;
                foreach (($block['stats'] ?? []) as $stat) {
                    if (!is_array($stat)) {
                        continue;
                    }
                    $ident = (string) ($stat['identifier'] ?? '');
                    $value = (int) ($stat['value'] ?? 0);
                    if ($ident === 'goals_scored') {
                        $goals = $value;
                    } elseif ($ident === 'assists') {
                        $assists = $value;
                    }
                }
                if ($goals < 1 && $assists < 1) {
                    continue;
                }

                if (!isset($eventsByFixture[$fixtureId])) {
                    $eventsByFixture[$fixtureId] = ['home' => [], 'away' => []];
                }

                $side = $teamId === $fixtureHomeTeam[$fixtureId] ? 'home' : 'away';
                $eventsByFixture[$fixtureId][$side][] = [
                    'id' => $playerId,
                    'web_name' => $webName,
                    'goals' => $goals,
                    'assists' => $assists,
                ];
            }
        }

        $rows = [];
        foreach ($fixtures as $fx) {
            $homeId = (int) ($fx['team_h'] ?? 0);
            $awayId = (int) ($fx['team_a'] ?? 0);
            $home = $teamsById[$homeId] ?? [];
            $away = $teamsById[$awayId] ?? [];
            $homeCode = (int) ($home['code'] ?? 0);
            $awayCode = (int) ($away['code'] ?? 0);
            $fixtureId = (int) ($fx['id'] ?? 0);
            $homeEvents = self::sortMatchEvents($eventsByFixture[$fixtureId]['home'] ?? []);
            $awayEvents = self::sortMatchEvents($eventsByFixture[$fixtureId]['away'] ?? []);

            $rows[] = [
                'id' => $fixtureId,
                'kickoff' => $fx['kickoff_time'] ?? null,
                'finished' => !empty($fx['finished']) || !empty($fx['finished_provisional']),
                'home' => (string) ($home['short_name'] ?? 'H'),
                'away' => (string) ($away['short_name'] ?? 'A'),
                'home_name' => (string) ($home['name'] ?? ''),
                'away_name' => (string) ($away['name'] ?? ''),
                'home_badge' => $homeCode > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $homeCode . '.png'
                    : null,
                'away_badge' => $awayCode > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $awayCode . '.png'
                    : null,
                'score_h' => $fx['team_h_score'],
                'score_a' => $fx['team_a_score'],
                'home_events' => $homeEvents,
                'away_events' => $awayEvents,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['kickoff'] ?? ''), (string) ($b['kickoff'] ?? ''));
        });
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function sortMatchEvents(array $events): array
    {
        usort($events, static function (array $a, array $b): int {
            $goals = $b['goals'] <=> $a['goals'];
            if ($goals !== 0) {
                return $goals;
            }
            $assists = $b['assists'] <=> $a['assists'];
            if ($assists !== 0) {
                return $assists;
            }
            return strcmp((string) $a['web_name'], (string) $b['web_name']);
        });
        return $events;
    }

    /**
     * @param list<array<string, mixed>> $players
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $fixtures
     * @return array<string, mixed>
     */
    private static function buildKpis(array $players, array $meta, array $fixtures): array
    {
        $finishedFx = 0;
        foreach ($fixtures as $fx) {
            if (!empty($fx['finished']) || !empty($fx['finished_provisional'])) {
                $finishedFx++;
            }
        }
        $hauls = count(array_filter($players, static fn (array $p): bool => $p['points'] >= 10));
        $blanks = count(array_filter(
            $players,
            static fn (array $p): bool => $p['ownership'] >= self::TEMPLATE_OWN && $p['points'] <= 2 && $p['minutes'] >= 45
        ));
        $dream = count(array_filter($players, static fn (array $p): bool => !empty($p['in_dreamteam'])));

        return [
            'players_with_points' => count($players),
            'fixtures_total' => count($fixtures),
            'fixtures_finished' => $finishedFx,
            'average_entry_score' => $meta['average_entry_score'],
            'highest_score' => $meta['highest_score'],
            'hauls_10_plus' => $hauls,
            'template_blanks' => $blanks,
            'dream_team_size' => $dream,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $kpis
     * @param list<array<string, mixed>> $topScorers
     * @param array<string, mixed> $captainStory
     * @param array<string, mixed> $beatsMisses
     * @param array<string, mixed> $template
     * @return array{headline:string,paragraphs:list<string>,source:string}
     */
    private static function buildNarrative(
        array $meta,
        array $kpis,
        array $topScorers,
        array $captainStory,
        array $beatsMisses,
        array $template
    ): array {
        // Free deterministic copy only. Never call OpenAI on this page.
        return self::deterministicNarrative($meta, $kpis, $topScorers, $captainStory, $beatsMisses, $template);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $kpis
     * @param list<array<string, mixed>> $topScorers
     * @param array<string, mixed> $captainStory
     * @param array<string, mixed> $beatsMisses
     * @param array<string, mixed> $template
     * @return array{headline:string,paragraphs:list<string>,source:string}
     */
    private static function deterministicNarrative(
        array $meta,
        array $kpis,
        array $topScorers,
        array $captainStory,
        array $beatsMisses,
        array $template
    ): array {
        $leader = $topScorers[0] ?? null;
        $cap = $captainStory['most_captained'] ?? null;
        $over = $beatsMisses['over'][0] ?? null;
        $blank = $template['blanks'][0] ?? null;

        $status = !empty($meta['finished']) ? 'final' : 'still running';
        $headline = (string) ($meta['name'] ?? 'Gameweek') . ' recap';

        $p1 = 'Metro Football has wrapped ' . ($meta['name'] ?? 'this gameweek') . '. The report is ' . $status . '.';
        if ($leader) {
            $p1 .= ' ' . $leader['web_name'] . ' led the week with ' . $leader['points'] . ' points.';
        }

        $p2 = '';
        if ($cap) {
            $p2 = 'Most managers captained ' . $cap['web_name'] . ', who scored ' . $cap['points'] . ' points.';
        } elseif (!empty($captainStory['most_owned_five'][0])) {
            $m = $captainStory['most_owned_five'][0];
            $p2 = $m['web_name'] . ' was among the most owned players and scored ' . $m['points'] . ' points.';
        }
        if ($kpis['average_entry_score'] !== null) {
            $p2 .= ($p2 === '' ? '' : ' ') . 'The average FPL score was ' . $kpis['average_entry_score'] . ' points.';
        }

        $p3 = '';
        if ($over) {
            $p3 = $over['web_name'] . ' beat expected points by ' . abs((float) $over['delta_ep']) . '.';
        }
        if ($blank) {
            $p3 .= ($p3 === '' ? '' : ' ') . $blank['web_name'] . ' was owned by many managers and scored only ' . $blank['points'] . ' points.';
        }
        if ($p3 === '') {
            $p3 = 'Use the lists below to see who scored big, who got bonus points, and who popular picks let people down. Then plan the next deadline on Rate My Team.';
        }

        $paragraphs = array_values(array_filter([$p1, $p2, $p3], static fn (string $s): bool => trim($s) !== ''));

        return [
            'headline' => $headline,
            'paragraphs' => $paragraphs,
            'source' => 'deterministic',
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $topScorers
     * @param array<string, mixed> $captainStory
     * @param array<string, mixed> $kpis
     */
    private static function buildShareLine(array $meta, array $topScorers, array $captainStory, array $kpis): string
    {
        $leader = $topScorers[0]['web_name'] ?? 'the top scorer';
        $pts = $topScorers[0]['points'] ?? 0;
        $cap = $captainStory['most_captained']['web_name'] ?? null;
        $line = 'Metro Football ' . ($meta['name'] ?? 'GW') . ' recap: ' . $leader . ' led with ' . $pts . ' points.';
        if ($cap) {
            $line .= ' Most captained: ' . $cap . '.';
        }
        if ($kpis['average_entry_score'] !== null) {
            $line .= ' Average score ' . $kpis['average_entry_score'] . '.';
        }
        $line .= ' https://metrofootball.co.uk/gameweek-recap';
        return $line;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int|string, array<string, mixed>> $teams
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function buildPersonalStrip(
        int $entryId,
        int $eventId,
        array $elements,
        array $teams,
        array $payload,
        bool $finished
    ): array {
        $cacheKey = self::PERSONAL_PREFIX . $eventId . '-' . $entryId;
        $fresh = $finished ? 3600 : 300;
        $stale = $finished ? 604800 : 3600;
        $hit = FplCache::inspect($cacheKey, $fresh, $stale);
        if ($hit !== null && is_array($hit['value'])) {
            return $hit['value'];
        }

        try {
            $strip = self::composePersonalStrip($entryId, $eventId, $elements, $teams, $payload);
            FplCache::put($cacheKey, $strip);
            return $strip;
        } catch (\Throwable $e) {
            if ($hit !== null && is_array($hit['value'])) {
                return $hit['value'];
            }
            throw $e;
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int|string, array<string, mixed>> $teams
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function composePersonalStrip(
        int $entryId,
        int $eventId,
        array $elements,
        array $teams,
        array $payload
    ): array {
        $fetched = FplClient::getJsonMany([
            'history' => FplClient::entryHistoryUrl($entryId),
            'picks' => FplClient::eventPicksUrl($entryId, $eventId),
            'entry' => FplClient::entryUrl($entryId),
        ]);
        $history = $fetched['history'] ?? [];
        $picksPayload = $fetched['picks'] ?? [];
        $entry = $fetched['entry'] ?? [];

        $current = null;
        foreach (($history['current'] ?? []) as $row) {
            if ((int) ($row['event'] ?? 0) === $eventId) {
                $current = $row;
                break;
            }
        }

        $elementsById = [];
        foreach ($elements as $el) {
            $elementsById[(int) $el['id']] = $el;
        }
        $teamsById = [];
        foreach ($teams as $team) {
            $teamsById[(int) $team['id']] = $team;
        }

        $livePoints = is_array($payload['points_by_id'] ?? null) ? $payload['points_by_id'] : [];
        foreach (($payload['top_scorers'] ?? []) as $p) {
            $livePoints[(int) $p['id']] = (int) $p['points'];
        }
        foreach ($payload['template']['players'] ?? [] as $p) {
            if (isset($p['id'], $p['points'])) {
                $livePoints[(int) $p['id']] = (int) $p['points'];
            }
        }

        $xi = [];
        $bench = [];

        foreach (($picksPayload['picks'] ?? []) as $pick) {
            $pid = (int) ($pick['element'] ?? 0);
            $el = $elementsById[$pid] ?? null;
            if (!$el) {
                continue;
            }
            $mult = (int) ($pick['multiplier'] ?? 0);
            $pts = (int) ($livePoints[$pid] ?? 0);
            $team = $teamsById[(int) ($el['team'] ?? 0)] ?? [];
            $row = [
                'id' => $pid,
                'web_name' => (string) ($el['web_name'] ?? 'Player'),
                'pos' => (int) ($el['element_type'] ?? 0),
                'team_short' => (string) ($team['short_name'] ?? ''),
                'points' => $pts,
                'multiplier' => $mult,
                'position' => (int) ($pick['position'] ?? 0),
            ];
            if ($mult === 0) {
                $bench[] = $row;
            } else {
                $xi[] = $row;
            }
        }

        usort($xi, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        usort($bench, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $gwPoints = $current ? (int) ($current['points'] ?? 0) : null;
        $rank = $current ? (int) ($current['overall_rank'] ?? 0) : null;
        $rankGw = $current ? (int) ($current['rank'] ?? 0) : null;
        $avg = $payload['kpis']['average_entry_score'] ?? null;

        $squad = array_merge($xi, $bench);
        $best = null;
        foreach ($squad as $row) {
            if ($best === null || $row['points'] > $best['points']) {
                $best = $row;
            }
        }
        $worst = null;
        foreach ($xi as $row) {
            if ($worst === null || $row['points'] < $worst['points']) {
                $worst = $row;
            }
        }

        $enrich = static function (?array $row) use ($elementsById, $teamsById): ?array {
            if ($row === null) {
                return null;
            }
            $el = $elementsById[(int) $row['id']] ?? [];
            $team = $teamsById[(int) ($el['team'] ?? 0)] ?? [];
            $code = (int) ($team['code'] ?? 0);
            $photoCode = preg_replace('/\D+/', '', (string) ($el['photo'] ?? '')) ?: (string) ($el['code'] ?? '');
            $pos = (int) ($row['pos'] ?? 0);

            return [
                'id' => (int) $row['id'],
                'web_name' => (string) $row['web_name'],
                'team_short' => (string) $row['team_short'],
                'pos_label' => self::POS_LABEL[$pos] ?? '?',
                'points' => (int) $row['points'],
                'photo' => $photoCode !== ''
                    ? 'https://resources.premierleague.com/premierleague/photos/players/110x140/p' . $photoCode . '.png'
                    : null,
                'shirt' => $code > 0
                    ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $code . '-110.webp'
                    : null,
            ];
        };

        return [
            'available' => true,
            'entry_id' => $entryId,
            'team_name' => (string) ($entry['name'] ?? 'Your team'),
            'manager_name' => trim(((string) ($entry['player_first_name'] ?? '')) . ' ' . ((string) ($entry['player_last_name'] ?? ''))),
            'gw_points' => $gwPoints,
            'average_score' => $avg !== null ? (int) $avg : null,
            'gw_rank' => $rankGw ?: null,
            'overall_rank' => $rank ?: null,
            'best_player' => $enrich($best),
            'worst_player' => $enrich($worst),
        ];
    }
}
