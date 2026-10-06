<?php

declare(strict_types=1);

namespace App;

final class PickTeamService
{
    private const BASE = 'https://fantasy.premierleague.com/api';
    private const FRESH_SECONDS = 600;
    private const STALE_SECONDS = 7200;
    private const LIVE_FRESH_SECONDS = 90;
    private const MAX_BANKED_FREE_TRANSFERS = 5;
    private const FIXTURE_HORIZON = 6;

    /** @var null|array{bootstrap: array<string, mixed>, fixtures: list<array<string, mixed>>} */
    private static ?array $lastFetchContext = null;

    /**
     * Bootstrap + fixtures from the latest in-request buildFresh call.
     *
     * @return null|array{bootstrap: array<string, mixed>, fixtures: list<array<string, mixed>>}
     */
    public static function lastFetchContext(): ?array
    {
        return self::$lastFetchContext;
    }

    /**
     * @return array{pick: array<string, mixed>, cache: string}
     */
    public static function buildForEntry(int $entryId, ?int $gameweekId = null): array
    {
        $gwKey = $gameweekId !== null && $gameweekId > 0 ? (string) $gameweekId : 'auto';
        $key = 'pick-entry-v7-' . $entryId . '-gw-' . $gwKey;
        $hit = FplCache::inspect($key, self::FRESH_SECONDS, self::STALE_SECONDS);
        if ($hit !== null) {
            $cached = $hit['value'];
            $liveStale = is_array($cached)
                && !empty($cached['is_live_locked'])
                && ($cached['view_mode'] ?? '') !== 'history'
                && $hit['age'] >= self::LIVE_FRESH_SECONDS;
            if (!$liveStale) {
                return [
                    'pick' => $cached,
                    'cache' => $hit['fresh'] ? 'HIT' : 'STALE',
                ];
            }
        }

        try {
            $pick = self::buildFresh($entryId, $gameweekId);
            FplCache::put($key, $pick);

            return [
                'pick' => $pick,
                'cache' => 'MISS',
            ];
        } catch (\Throwable $e) {
            if ($hit !== null && is_array($hit['value'])) {
                return [
                    'pick' => $hit['value'],
                    'cache' => 'STALE-ERROR',
                ];
            }

            throw $e;
        }
    }

    public static function refreshEntry(int $entryId): void
    {
        FplCache::put('pick-entry-v7-' . $entryId . '-gw-auto', self::buildFresh($entryId, null));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{
     *   current: ?array<string, mixed>,
     *   next: ?array<string, mixed>,
     *   planning_event: array<string, mixed>,
     *   editable: bool,
     *   is_live_locked: bool,
     *   locked_reason: ?string
     * }
     */
    private static function resolvePickContext(array $events): array
    {
        $current = null;
        $next = null;
        foreach ($events as $event) {
            if (!empty($event['is_current'])) {
                $current = $event;
            }
            if (!empty($event['is_next'])) {
                $next = $event;
            }
        }

        $editable = $next !== null;
        $isLiveLocked = $current !== null
            && empty($current['finished'])
            && $next === null;

        $planningEvent = $next ?? $current;
        if (!$planningEvent) {
            throw new \RuntimeException('No gameweek is available for picks right now.');
        }

        return [
            'current' => $current,
            'next' => $next,
            'planning_event' => $planningEvent,
            'editable' => $editable,
            'is_live_locked' => $isLiveLocked,
            'locked_reason' => $isLiveLocked ? 'live_gameweek' : null,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function formatEventMeta(array $event): array
    {
        $deadline = $event['deadline_time'] ?? null;
        $deadlineLabel = null;
        $deadlinePassed = false;
        if (is_string($deadline) && $deadline !== '') {
            try {
                $dt = new \DateTimeImmutable($deadline);
                $deadlinePassed = $dt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $deadlineLabel = $dt->setTimezone(new \DateTimeZone('Europe/London'))
                    ->format('D j M H:i');
            } catch (\Throwable $e) {
                $deadlineLabel = $deadline;
            }
        }

        return [
            'id' => (int) ($event['id'] ?? 0),
            'name' => (string) ($event['name'] ?? ('GW' . ($event['id'] ?? ''))),
            'deadline_time' => $deadline,
            'deadline_label' => $deadlineLabel,
            'deadline_passed' => $deadlinePassed,
            'is_next' => !empty($event['is_next']),
            'is_current' => !empty($event['is_current']),
            'finished' => !empty($event['finished']),
        ];
    }

    /** @return array<string, mixed> */
    public static function buildFresh(int $entryId, ?int $requestedGameweekId = null): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elementsById = $bootstrap['elements'];
        $teamsById = $bootstrap['teams'];
        $events = $bootstrap['events'];

        $context = FplEntryPicksService::resolveContext($events);
        $current = $context['current'];
        $planningEvent = $context['planning_event'];
        $editablePlanning = $context['editable'];
        $isLiveLocked = $context['is_live_locked'];

        $urls = [
            'entry' => self::BASE . '/entry/' . $entryId . '/',
            'history' => self::BASE . '/entry/' . $entryId . '/history/',
        ];
        $bundle = FplClient::getJsonMany($urls);
        $entry = $bundle['entry'];
        if (empty($entry['id'])) {
            throw new \InvalidArgumentException('We could not find an FPL team with that ID.');
        }

        $historyCurrent = is_array($bundle['history']['current'] ?? null)
            ? $bundle['history']['current']
            : [];

        $planningEventId = (int) ($planningEvent['id'] ?? 0);
        $gameweekOptions = self::buildGameweekOptions($events, $historyCurrent, $planningEventId);
        $selectedGw = self::resolveSelectedGameweek($requestedGameweekId, $gameweekOptions, $planningEventId);

        $selectedEvent = null;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if ((int) ($event['id'] ?? 0) === $selectedGw) {
                $selectedEvent = $event;
                break;
            }
        }
        if ($selectedEvent === null) {
            $selectedEvent = $planningEvent;
            $selectedGw = $planningEventId;
        }

        $isPlanningView = $selectedGw === $planningEventId;
        $viewMode = !$isPlanningView
            ? 'history'
            : ($isLiveLocked ? 'live' : 'plan');

        $resolved = FplEntryPicksService::resolveForGameweek($entryId, $events, $selectedGw);
        $picksPayload = $resolved['payload'];
        $picksEventId = $resolved['event_id'];
        $picksFallback = $resolved['fallback'];

        if (!$picksPayload || empty($picksPayload['picks'])) {
            throw new \RuntimeException(
                'Your picks are not available yet. This often happens before the gameweek deadline.'
            );
        }

        $fixtures = FplClient::fetchFixtures();
        $fixturesByTeam = $viewMode === 'plan'
            ? self::fixturesByTeam($fixtures, $planningEventId, $teamsById)
            : self::fixturesForEventByTeam($fixtures, $selectedGw, $teamsById);

        self::$lastFetchContext = [
            'bootstrap' => $bootstrap,
            'fixtures' => $fixtures,
        ];

        $liveByElement = [];
        if ($viewMode !== 'plan') {
            $finished = !empty($selectedEvent['finished']);
            try {
                $live = FplClient::fetchEventLive($selectedGw, $finished);
                foreach (($live['elements'] ?? []) as $row) {
                    if (is_array($row)) {
                        $liveByElement[(int) ($row['id'] ?? 0)] = $row;
                    }
                }
            } catch (\Throwable) {
                $liveByElement = [];
            }
        }

        $squad = [];
        $xiPointsSum = 0;
        foreach ($picksPayload['picks'] as $pick) {
            $elementId = (int) ($pick['element'] ?? 0);
            $element = $elementsById[$elementId] ?? null;
            if (!$element) {
                continue;
            }
            $mapped = self::mapSquadPlayer($pick, $element, $teamsById, $fixturesByTeam);
            if ($viewMode !== 'plan') {
                $liveStats = $liveByElement[$elementId]['stats'] ?? [];
                $basePoints = $liveByElement === []
                    ? (int) ($element['event_points'] ?? 0)
                    : (isset($liveStats['total_points'])
                        ? (int) $liveStats['total_points']
                        : (int) ($element['event_points'] ?? 0));
                $multiplier = (int) ($mapped['multiplier'] ?? 1);
                $mapped['points'] = $basePoints;
                $mapped['team_points'] = $basePoints * max(0, $multiplier);
                if ((int) ($mapped['position'] ?? 0) <= 11) {
                    $xiPointsSum += (int) $mapped['team_points'];
                }
            }
            $squad[] = $mapped;
        }

        usort($squad, static fn ($a, $b) => $a['position'] <=> $b['position']);

        $entryHistory = is_array($picksPayload['entry_history'] ?? null)
            ? $picksPayload['entry_history']
            : [];

        $bankTenths = (int) ($entry['last_deadline_bank'] ?? 0);
        if (isset($entryHistory['bank'])) {
            $bankTenths = (int) $entryHistory['bank'];
        }

        $freeTransfers = $viewMode === 'plan'
            ? self::estimateFreeTransfers($bundle['history'] ?? [], $planningEvent)
            : max(0, (int) ($entryHistory['event_transfers'] ?? 0));

        $gwPoints = null;
        if ($viewMode !== 'plan') {
            $gwPoints = isset($entryHistory['points']) ? (int) $entryHistory['points'] : $xiPointsSum;
            foreach ($historyCurrent as $row) {
                if (is_array($row) && (int) ($row['event'] ?? 0) === $selectedGw) {
                    $gwPoints = (int) ($row['points'] ?? $gwPoints);
                    break;
                }
            }
        }

        $catalogue = [];
        foreach ($elementsById as $element) {
            $catalogue[] = self::mapCataloguePlayer($element, $teamsById, $fixturesByTeam);
        }

        usort($catalogue, static fn ($a, $b) => $b['now_cost'] <=> $a['now_cost']);

        $eventMeta = self::formatEventMeta($selectedEvent);
        $currentEvent = $current ? self::formatEventMeta($current) : null;
        $editable = $viewMode === 'plan' && $editablePlanning && !$isLiveLocked;
        $lockedReason = null;
        if ($isLiveLocked && $viewMode === 'live') {
            $lockedReason = 'live_gameweek';
        } elseif ($viewMode === 'history') {
            $lockedReason = 'past_gameweek';
        } elseif (!$editable && $viewMode === 'plan') {
            $lockedReason = 'live_gameweek';
        }

        return [
            'entry' => FplClient::summariseEntry($entry),
            'event' => $eventMeta,
            'current_event' => $currentEvent,
            'gameweek' => [
                'selected_id' => $selectedGw,
                'options' => $gameweekOptions,
            ],
            'view_mode' => $viewMode,
            'editable' => $editable,
            'is_live_locked' => $isLiveLocked && $viewMode !== 'history',
            'locked_reason' => $lockedReason,
            'picks_event' => [
                'id' => $picksEventId,
                'fallback' => $picksFallback,
                'source' => $resolved['source'] ?? 'event_picks',
            ],
            'bank' => $bankTenths / 10,
            'team_value' => ((int) ($entry['last_deadline_value'] ?? 0)) / 10,
            'free_transfers' => $freeTransfers,
            'transfer_cost' => 4,
            'active_chip' => $picksPayload['active_chip'] ?? null,
            'gw_points' => $gwPoints,
            'strength' => null,
            'predicted_points' => null,
            'squad' => $squad,
            'players' => $catalogue,
            'locks' => [
                'editing' => !$editable,
                'predicted' => true,
                'suggested_transfers' => true,
                'optimise' => true,
                'chips' => true,
                'premium_card_stats' => true,
            ],
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $historyCurrent
     * @return list<array<string, mixed>>
     */
    private static function buildGameweekOptions(
        array $events,
        array $historyCurrent,
        int $planningEventId
    ): array {
        $pointsByEvent = [];
        foreach ($historyCurrent as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eventId = (int) ($row['event'] ?? 0);
            if ($eventId > 0) {
                $pointsByEvent[$eventId] = (int) ($row['points'] ?? 0);
            }
        }

        $options = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $id = (int) ($event['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $inHistory = array_key_exists($id, $pointsByEvent);
            $isPlanning = $id === $planningEventId;
            $isCurrent = !empty($event['is_current']);
            if (!$inHistory && !$isPlanning && !$isCurrent) {
                continue;
            }

            $options[] = [
                'id' => $id,
                'name' => (string) ($event['name'] ?? ('GW' . $id)),
                'finished' => !empty($event['finished']),
                'is_current' => $isCurrent,
                'is_planning' => $isPlanning,
                'points' => $pointsByEvent[$id] ?? null,
            ];
        }

        usort($options, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

        return $options;
    }

    /**
     * @param list<array<string, mixed>> $options
     */
    private static function resolveSelectedGameweek(
        ?int $requestedGameweekId,
        array $options,
        int $planningEventId
    ): int {
        $ids = [];
        foreach ($options as $option) {
            $ids[(int) ($option['id'] ?? 0)] = true;
        }

        if ($requestedGameweekId !== null && $requestedGameweekId > 0 && isset($ids[$requestedGameweekId])) {
            return $requestedGameweekId;
        }

        if (isset($ids[$planningEventId])) {
            return $planningEventId;
        }

        if ($options !== []) {
            return (int) ($options[array_key_last($options)]['id'] ?? $planningEventId);
        }

        return max(1, $planningEventId);
    }

    /**
     * Fixtures for one finished or live gameweek (for historical pitch labels).
     *
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<int, list<array<string, mixed>>>
     */
    private static function fixturesForEventByTeam(array $fixtures, int $eventId, array $teamsById): array
    {
        $byTeam = [];
        foreach ($fixtures as $fx) {
            if ((int) ($fx['event'] ?? 0) !== $eventId) {
                continue;
            }

            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home < 1 || $away < 1) {
                continue;
            }

            $homeShort = (string) ($teamsById[$home]['short_name'] ?? '');
            $awayShort = (string) ($teamsById[$away]['short_name'] ?? '');

            $byTeam[$home][] = [
                'event' => $eventId,
                'opponent' => $awayShort,
                'opponent_id' => $away,
                'was_home' => true,
                'fdr' => (int) ($fx['team_h_difficulty'] ?? 3),
                'label' => $awayShort . ' (H)',
            ];
            $byTeam[$away][] = [
                'event' => $eventId,
                'opponent' => $homeShort,
                'opponent_id' => $home,
                'was_home' => false,
                'fdr' => (int) ($fx['team_a_difficulty'] ?? 3),
                'label' => $homeShort . ' (A)',
            ];
        }

        return $byTeam;
    }

    public static function estimateFreeTransfersPublic(array $history, array $planningEvent): int
    {
        return self::estimateFreeTransfers($history, $planningEvent);
    }

    /**
     * @param array<string, mixed> $history
     * @param array<string, mixed> $planningEvent
     */
    private static function estimateFreeTransfers(array $history, array $planningEvent): int
    {
        $rows = is_array($history['current'] ?? null) ? $history['current'] : [];
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['event'] ?? 0)) <=> ((int) ($b['event'] ?? 0)));

        $planningGw = (int) ($planningEvent['id'] ?? 0);
        // GW1 has no transfer limit on FPL. Banking rules start from GW2, which always opens on 1 FT.
        if ($planningGw <= 2) {
            return 1;
        }

        $ft = 1;
        foreach ($rows as $row) {
            $event = (int) ($row['event'] ?? 0);
            if ($event < 2 || ($planningGw > 0 && $event >= $planningGw)) {
                continue;
            }
            $transfers = max(0, (int) ($row['event_transfers'] ?? 0));
            $freeUsed = min($ft, $transfers);
            $ft = min(self::MAX_BANKED_FREE_TRANSFERS, $ft - $freeUsed + 1);
        }

        return max(0, min(self::MAX_BANKED_FREE_TRANSFERS, $ft));
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<int, list<array<string, mixed>>>
     */
    private static function fixturesByTeam(array $fixtures, int $fromEvent, array $teamsById): array
    {
        $byTeam = [];
        foreach ($fixtures as $fx) {
            $event = isset($fx['event']) ? (int) $fx['event'] : 0;
            if ($event < $fromEvent) {
                continue;
            }
            if (!empty($fx['finished'])) {
                continue;
            }

            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home < 1 || $away < 1) {
                continue;
            }

            $homeShort = (string) ($teamsById[$home]['short_name'] ?? '');
            $awayShort = (string) ($teamsById[$away]['short_name'] ?? '');

            $byTeam[$home][] = [
                'event' => $event,
                'opponent' => $awayShort,
                'opponent_id' => $away,
                'was_home' => true,
                'fdr' => (int) ($fx['team_h_difficulty'] ?? 3),
                'label' => $awayShort . ' (H)',
            ];
            $byTeam[$away][] = [
                'event' => $event,
                'opponent' => $homeShort,
                'opponent_id' => $home,
                'was_home' => false,
                'fdr' => (int) ($fx['team_a_difficulty'] ?? 3),
                'label' => $homeShort . ' (A)',
            ];
        }

        foreach ($byTeam as $teamId => $rows) {
            usort($rows, static fn ($a, $b) => $a['event'] <=> $b['event']);
            $byTeam[$teamId] = array_slice($rows, 0, self::FIXTURE_HORIZON);
        }

        return $byTeam;
    }

    /**
     * @param array<string, mixed> $pick
     * @param array<string, mixed> $element
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, list<array<string, mixed>>> $fixturesByTeam
     * @return array<string, mixed>
     */
    private static function mapSquadPlayer(
        array $pick,
        array $element,
        array $teamsById,
        array $fixturesByTeam
    ): array {
        $mapped = self::mapCataloguePlayer($element, $teamsById, $fixturesByTeam);
        $mapped['position'] = (int) ($pick['position'] ?? 0);
        $mapped['multiplier'] = (int) ($pick['multiplier'] ?? 1);
        $mapped['is_captain'] = !empty($pick['is_captain']);
        $mapped['is_vice_captain'] = !empty($pick['is_vice_captain']);
        $mapped['purchase_price'] = isset($pick['purchase_price'])
            ? ((int) $pick['purchase_price']) / 10
            : $mapped['now_cost'];
        $mapped['selling_price'] = isset($pick['selling_price'])
            ? ((int) $pick['selling_price']) / 10
            : $mapped['now_cost'];
        return $mapped;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, list<array<string, mixed>>> $fixturesByTeam
     * @return array<string, mixed>
     */
    private static function mapCataloguePlayer(
        array $element,
        array $teamsById,
        array $fixturesByTeam
    ): array {
        $teamId = (int) ($element['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $teamCode = (int) ($team['code'] ?? 0);
        $elementType = (int) ($element['element_type'] ?? 0);
        $isGk = $elementType === 1;

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
        $photo = (string) ($element['photo'] ?? '');
        $photoId = $photo !== '' ? preg_replace('/\.jpg$/i', '', $photo) : '';
        $headshot = $photoId !== ''
            ? 'https://resources.premierleague.com/premierleague/photos/players/110x140/p'
                . $photoId
                . '.png'
            : null;

        $status = (string) ($element['status'] ?? 'a');
        $chance = $element['chance_of_playing_next_round'] ?? null;

        return [
            'id' => (int) $element['id'],
            'first_name' => (string) ($element['first_name'] ?? ''),
            'second_name' => (string) ($element['second_name'] ?? ''),
            'web_name' => (string) ($element['web_name'] ?? 'Player'),
            'display_name' => trim((string) ($element['first_name'] ?? '') . ' ' . (string) ($element['second_name'] ?? '')),
            'team' => $teamId,
            'team_short' => (string) ($team['short_name'] ?? ''),
            'team_name' => (string) ($team['name'] ?? ''),
            'team_code' => $teamCode,
            'shirt' => $shirt,
            'photo' => $photo,
            'headshot' => $headshot,
            'element_type' => $elementType,
            'row' => $row,
            'pos_label' => $posLabel,
            'now_cost' => ((int) ($element['now_cost'] ?? 0)) / 10,
            'cost_change_event' => ((int) ($element['cost_change_event'] ?? 0)) / 10,
            'price_change_percent' => (float) ($element['price_change_percent'] ?? 0),
            'price_change_hourly_rate' => (float) ($element['price_change_hourly_rate'] ?? 0),
            'price_change_projections' => is_array($element['price_change_projections'] ?? null)
                ? array_values($element['price_change_projections'])
                : [],
            'price_change_locked_until' => $element['price_change_locked_until'] ?? null,
            'price_change_calibrating' => !empty($element['price_change_calibrating']),
            'selected_by_percent' => (string) ($element['selected_by_percent'] ?? '0'),
            'total_points' => (int) ($element['total_points'] ?? 0),
            'points_per_game' => (string) ($element['points_per_game'] ?? '0.0'),
            'form' => (string) ($element['form'] ?? '0.0'),
            'ep_next' => (string) ($element['ep_next'] ?? '0.0'),
            'ep_this' => (string) ($element['ep_this'] ?? '0.0'),
            'minutes' => (int) ($element['minutes'] ?? 0),
            'starts' => (int) ($element['starts'] ?? 0),
            'goals_scored' => (int) ($element['goals_scored'] ?? 0),
            'assists' => (int) ($element['assists'] ?? 0),
            'clean_sheets' => (int) ($element['clean_sheets'] ?? 0),
            'goals_conceded' => (int) ($element['goals_conceded'] ?? 0),
            'saves' => (int) ($element['saves'] ?? 0),
            'bonus' => (int) ($element['bonus'] ?? 0),
            'bps' => (int) ($element['bps'] ?? 0),
            'recoveries' => (int) ($element['recoveries'] ?? 0),
            'tackles' => (int) ($element['tackles'] ?? 0),
            'defensive_contribution' => (int) ($element['defensive_contribution'] ?? 0),
            'influence' => (string) ($element['influence'] ?? '0.0'),
            'creativity' => (string) ($element['creativity'] ?? '0.0'),
            'threat' => (string) ($element['threat'] ?? '0.0'),
            'ict_index' => (string) ($element['ict_index'] ?? '0.0'),
            'expected_goals' => (string) ($element['expected_goals'] ?? '0.00'),
            'expected_assists' => (string) ($element['expected_assists'] ?? '0.00'),
            'expected_goal_involvements' => (string) ($element['expected_goal_involvements'] ?? '0.00'),
            'penalties_order' => $element['penalties_order'] ?? null,
            'corners_and_indirect_freekicks_order' => $element['corners_and_indirect_freekicks_order'] ?? null,
            'direct_freekicks_order' => $element['direct_freekicks_order'] ?? null,
            'transfers_in_event' => (int) ($element['transfers_in_event'] ?? 0),
            'transfers_out_event' => (int) ($element['transfers_out_event'] ?? 0),
            'status' => $status,
            'news' => (string) ($element['news'] ?? ''),
            'chance_of_playing_next_round' => $chance === null ? null : (int) $chance,
            'fixtures' => $fixturesByTeam[$teamId] ?? [],
        ];
    }

    /**
     * Draft squads store fixture snapshots; refresh from the live catalogue.
     *
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return list<array<string, mixed>>
     */
    public static function syncSquadFixturesFromCatalogue(array $squad, array $catalogue): array
    {
        $byId = [];
        foreach ($catalogue as $player) {
            if (!is_array($player)) {
                continue;
            }
            $id = (int) ($player['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $player;
            }
        }

        foreach ($squad as &$player) {
            if (!is_array($player)) {
                continue;
            }
            $id = (int) ($player['id'] ?? 0);
            if ($id < 1 || !isset($byId[$id]['fixtures']) || !is_array($byId[$id]['fixtures'])) {
                continue;
            }
            $player['fixtures'] = $byId[$id]['fixtures'];
        }
        unset($player);

        return $squad;
    }
}
