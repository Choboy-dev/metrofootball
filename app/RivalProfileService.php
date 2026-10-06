<?php

declare(strict_types=1);

namespace App;

final class RivalProfileService
{
    private const CHIP_LABELS = [
        'bboost' => 'Bench Boost',
        '3xc' => 'Triple Captain',
        'freehit' => 'Free Hit',
        'wildcard' => 'Wildcard',
    ];

    public static function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = str_replace(["'", '’', '.'], '', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    public static function profilePath(string $leagueSlug, string $rivalSlug): string
    {
        return '/mini-leagues/'
            . rawurlencode(self::slugify($leagueSlug))
            . '/'
            . rawurlencode(self::slugify($rivalSlug));
    }

    /**
     * @return array{league_id: int, league_name: string, slug: string}|null
     */
    public static function resolveLeagueForUser(int $userId, string $leagueSlug): ?array
    {
        $leagueSlug = self::slugify($leagueSlug);
        if ($leagueSlug === '') {
            return null;
        }

        foreach (TrackedLeagueService::listForUser($userId) as $tracked) {
            $name = (string) ($tracked['league_name'] ?? '');
            if (self::slugify($name) === $leagueSlug) {
                return [
                    'league_id' => (int) ($tracked['fpl_league_id'] ?? 0),
                    'league_name' => $name,
                    'slug' => $leagueSlug,
                ];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $standings
     * @return array<string, mixed>|null
     */
    public static function resolveRivalInStandings(array $standings, string $rivalSlug): ?array
    {
        $rivalSlug = self::slugify($rivalSlug);
        if ($rivalSlug === '') {
            return null;
        }

        foreach ($standings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['entry_name'] ?? '');
            if (self::slugify($name) === $rivalSlug) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildProfile(
        int $userId,
        int $viewerEntryId,
        string $leagueSlug,
        string $rivalSlug,
        ?int $gameweekId = null
    ): array {
        $league = self::resolveLeagueForUser($userId, $leagueSlug);
        if ($league === null) {
            throw new \InvalidArgumentException('We could not find that league in your list.');
        }

        $leagueId = (int) $league['league_id'];
        $standings = FplClient::fetchAllClassicLeagueStandings($leagueId);
        if ($standings === []) {
            throw new \RuntimeException('We could not read this league right now.');
        }

        $viewerInLeague = false;
        foreach ($standings as $row) {
            if ((int) ($row['entry'] ?? 0) === $viewerEntryId) {
                $viewerInLeague = true;
                break;
            }
        }
        if (!$viewerInLeague) {
            throw new \InvalidArgumentException('Your FPL team is not in this league.');
        }

        $rivalRow = self::resolveRivalInStandings($standings, $rivalSlug);
        if ($rivalRow === null) {
            throw new \InvalidArgumentException('We could not find that rival in this league.');
        }

        $rivalEntryId = (int) ($rivalRow['entry'] ?? 0);
        $bootstrap = FplClient::fetchBootstrapIndex();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

        $history = FplClient::fetchEntryHistory($rivalEntryId);
        $historyCurrent = is_array($history['current'] ?? null) ? $history['current'] : [];
        $chipsRaw = is_array($history['chips'] ?? null) ? $history['chips'] : [];

        $gameweekOptions = self::buildGameweekOptions($events, $historyCurrent);
        $selectedGw = $gameweekId > 0 ? $gameweekId : self::defaultGameweekId($events, $gameweekOptions);
        $selectedValid = false;
        foreach ($gameweekOptions as $option) {
            if ((int) ($option['id'] ?? 0) === $selectedGw) {
                $selectedValid = true;
                break;
            }
        }
        if (!$selectedValid && $gameweekOptions !== []) {
            $selectedGw = (int) ($gameweekOptions[array_key_last($gameweekOptions)]['id'] ?? $selectedGw);
        }

        $squadView = self::buildGameweekSquad($rivalEntryId, $selectedGw, $bootstrap, $historyCurrent);
        $transfers = self::transfersForGameweek($rivalEntryId, $selectedGw, $bootstrap);
        $scoreHistory = self::buildScoreHistory($historyCurrent, $events);
        $chipsUsed = self::formatChipsUsed($chipsRaw, $events);

        $rivalSlugClean = self::slugify((string) ($rivalRow['entry_name'] ?? ''));
        $isViewer = $rivalEntryId === $viewerEntryId;

        return [
            'league' => [
                'id' => $leagueId,
                'name' => (string) $league['league_name'],
                'slug' => (string) $league['slug'],
            ],
            'rival' => [
                'entry_id' => $rivalEntryId,
                'entry_name' => (string) ($rivalRow['entry_name'] ?? ''),
                'player_name' => (string) ($rivalRow['player_name'] ?? ''),
                'slug' => $rivalSlugClean,
                'profile_path' => self::profilePath((string) $league['slug'], $rivalSlugClean),
                'is_you' => $isViewer,
            ],
            'league_standing' => [
                'rank' => (int) ($rivalRow['rank'] ?? 0),
                'total' => (int) ($rivalRow['total'] ?? 0),
                'event_total' => (int) ($rivalRow['event_total'] ?? 0),
                'rank_change' => self::rankChangeFromRow($rivalRow),
            ],
            'gameweek' => [
                'selected_id' => $selectedGw,
                'options' => $gameweekOptions,
            ],
            'squad' => $squadView,
            'transfers' => $transfers,
            'score_history' => $scoreHistory,
            'chips_used' => $chipsUsed,
            'back_path' => '/mini-leagues',
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $historyCurrent
     * @return list<array<string, mixed>>
     */
    private static function buildGameweekOptions(array $events, array $historyCurrent): array
    {
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
            if ($id < 1 || !array_key_exists($id, $pointsByEvent)) {
                continue;
            }
            $options[] = [
                'id' => $id,
                'name' => (string) ($event['name'] ?? ('GW' . $id)),
                'finished' => !empty($event['finished']),
                'is_current' => !empty($event['is_current']),
                'points' => $pointsByEvent[$id],
            ];
        }

        return $options;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $options
     */
    private static function defaultGameweekId(array $events, array $options): int
    {
        if ($options === []) {
            return 1;
        }

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!empty($event['is_current'])) {
                return (int) ($event['id'] ?? $options[array_key_last($options)]['id']);
            }
        }

        return (int) ($options[array_key_last($options)]['id'] ?? 1);
    }

    /**
     * @param list<array<string, mixed>> $historyCurrent
     * @return array<string, mixed>
     */
    private static function buildGameweekSquad(
        int $entryId,
        int $eventId,
        array $bootstrap,
        array $historyCurrent
    ): array {
        $elementsById = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teamsById = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

        $eventMeta = null;
        foreach ($events as $event) {
            if (is_array($event) && (int) ($event['id'] ?? 0) === $eventId) {
                $eventMeta = $event;
                break;
            }
        }

        $picksPayload = FplClient::tryFetchEventPicks($entryId, $eventId);
        if ($picksPayload === null || empty($picksPayload['picks'])) {
            return [
                'hidden' => true,
                'reason' => 'This squad unlocks after the gameweek deadline.',
                'event_id' => $eventId,
                'event_name' => (string) ($eventMeta['name'] ?? ('GW' . $eventId)),
                'formation' => null,
                'gw_points' => null,
                'active_chip' => null,
                'pitch' => ['gkp' => [], 'def' => [], 'mid' => [], 'fwd' => []],
                'bench' => [],
            ];
        }

        $finished = !empty($eventMeta['finished']);
        $liveByElement = [];
        if ($finished || !empty($eventMeta['is_current'])) {
            try {
                $live = FplClient::fetchEventLive($eventId, $finished);
                foreach (($live['elements'] ?? []) as $row) {
                    if (is_array($row)) {
                        $liveByElement[(int) ($row['id'] ?? 0)] = $row;
                    }
                }
            } catch (\Throwable) {
                $liveByElement = [];
            }
        }

        $picks = is_array($picksPayload['picks'] ?? null) ? $picksPayload['picks'] : [];
        $entryHistory = is_array($picksPayload['entry_history'] ?? null) ? $picksPayload['entry_history'] : [];

        $xi = [];
        $bench = [];
        $xiPointsSum = 0;

        foreach ($picks as $pick) {
            if (!is_array($pick)) {
                continue;
            }
            $player = self::mapPickPlayer($pick, $elementsById, $teamsById, $liveByElement);
            if ((int) ($pick['position'] ?? 0) <= 11) {
                $xiPointsSum += (int) $player['team_points'];
                $xi[] = $player;
            } else {
                $bench[] = $player;
            }
        }

        $gwPoints = isset($entryHistory['points']) ? (int) $entryHistory['points'] : $xiPointsSum;
        foreach ($historyCurrent as $row) {
            if (is_array($row) && (int) ($row['event'] ?? 0) === $eventId) {
                $gwPoints = (int) ($row['points'] ?? $gwPoints);
                break;
            }
        }

        $pitch = ['gkp' => [], 'def' => [], 'mid' => [], 'fwd' => []];
        foreach ($xi as $player) {
            $pitch[$player['row']][] = $player;
        }

        $activeChip = $picksPayload['active_chip'] ?? null;
        $chipLabel = is_string($activeChip) && $activeChip !== ''
            ? self::chipLabel($activeChip)
            : null;

        return [
            'hidden' => false,
            'reason' => null,
            'event_id' => $eventId,
            'event_name' => (string) ($eventMeta['name'] ?? ('GW' . $eventId)),
            'formation' => self::formationFromPitch($pitch),
            'gw_points' => $gwPoints,
            'active_chip' => $chipLabel,
            'pitch' => $pitch,
            'bench' => $bench,
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pitch
     */
    private static function formationFromPitch(array $pitch): string
    {
        $def = count($pitch['def'] ?? []);
        $mid = count($pitch['mid'] ?? []);
        $fwd = count($pitch['fwd'] ?? []);

        return $def . '-' . $mid . '-' . $fwd;
    }

    /**
     * @param array<string, mixed> $pick
     * @param array<int, array<string, mixed>> $elementsById
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, array<string, mixed>> $liveByElement
     * @return array<string, mixed>
     */
    private static function mapPickPlayer(
        array $pick,
        array $elementsById,
        array $teamsById,
        array $liveByElement
    ): array {
        $elementId = (int) ($pick['element'] ?? 0);
        $element = $elementsById[$elementId] ?? [];
        $teamId = (int) ($element['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $teamCode = (int) ($team['code'] ?? 0);
        $elementType = (int) ($pick['element_type'] ?? $element['element_type'] ?? 0);
        $isGk = $elementType === 1;

        $live = $liveByElement[$elementId]['stats'] ?? [];
        $basePoints = $liveByElement === []
            ? (int) ($element['event_points'] ?? 0)
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

        $teamName = (string) ($team['name'] ?? '');
        $webName = (string) ($element['web_name'] ?? 'Player');
        $clubSlug = PlayerProfileService::slugify($teamName);
        $playerSlug = PlayerProfileService::slugify($webName);

        return [
            'id' => $elementId,
            'web_name' => $webName,
            'team_name' => $teamName,
            'team_short' => (string) ($team['short_name'] ?? ''),
            'profile_path' => $clubSlug !== '' && $playerSlug !== ''
                ? PlayerProfileService::profilePath($clubSlug, $playerSlug)
                : null,
            'shirt' => $shirt,
            'element_type' => $elementType,
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
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bootstrap
     * @return list<array<string, mixed>>
     */
    private static function transfersForGameweek(int $entryId, int $eventId, array $bootstrap): array
    {
        $elementsById = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $nameById = [];
        foreach ($elementsById as $id => $element) {
            if (is_array($element)) {
                $nameById[(int) $id] = (string) ($element['web_name'] ?? 'Player');
            }
        }

        try {
            $rows = FplClient::fetchEntryTransfers($entryId);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $move) {
            if (!is_array($move) || (int) ($move['event'] ?? 0) !== $eventId) {
                continue;
            }
            $out[] = [
                'player_in' => $nameById[(int) ($move['element_in'] ?? 0)] ?? 'In',
                'player_out' => $nameById[(int) ($move['element_out'] ?? 0)] ?? 'Out',
                'event' => $eventId,
                'time' => (string) ($move['time'] ?? ''),
            ];
        }

        return array_reverse($out);
    }

    /**
     * @param list<array<string, mixed>> $historyCurrent
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function buildScoreHistory(array $historyCurrent, array $events): array
    {
        $namesById = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $id = (int) ($event['id'] ?? 0);
                if ($id > 0) {
                    $namesById[$id] = (string) ($event['name'] ?? ('GW' . $id));
                }
            }
        }

        $rows = [];
        foreach ($historyCurrent as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eventId = (int) ($row['event'] ?? 0);
            if ($eventId < 1) {
                continue;
            }
            $rows[] = [
                'event' => $eventId,
                'name' => $namesById[$eventId] ?? ('GW' . $eventId),
                'points' => (int) ($row['points'] ?? 0),
                'total_points' => (int) ($row['total_points'] ?? 0),
                'overall_rank' => isset($row['overall_rank']) ? (int) $row['overall_rank'] : null,
                'rank' => isset($row['rank']) ? (int) $row['rank'] : null,
                'event_transfers' => (int) ($row['event_transfers'] ?? 0),
                'event_transfers_cost' => (int) ($row['event_transfers_cost'] ?? 0),
            ];
        }

        return array_reverse($rows);
    }

    /**
     * @param list<array<string, mixed>> $chipsRaw
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function formatChipsUsed(array $chipsRaw, array $events): array
    {
        $namesById = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $id = (int) ($event['id'] ?? 0);
                if ($id > 0) {
                    $namesById[$id] = (string) ($event['name'] ?? ('GW' . $id));
                }
            }
        }

        $out = [];
        foreach ($chipsRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::normaliseChipCode((string) ($row['name'] ?? ''));
            $eventId = (int) ($row['event'] ?? 0);
            if ($code === '' || $eventId < 1) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => self::CHIP_LABELS[$code] ?? ucfirst($code),
                'event' => $eventId,
                'event_name' => $namesById[$eventId] ?? ('GW' . $eventId),
            ];
        }

        usort($out, static fn(array $a, array $b): int => (int) ($b['event'] ?? 0) <=> (int) ($a['event'] ?? 0));

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rankChangeFromRow(array $row): int
    {
        $rank = (int) ($row['rank'] ?? 0);
        $lastRank = (int) ($row['last_rank'] ?? $rank);

        return $lastRank > 0 && $rank > 0 ? $lastRank - $rank : 0;
    }

    private static function normaliseChipCode(string $name): string
    {
        $key = strtolower(trim($name));

        return match ($key) {
            'bboost', 'bench boost', 'benchboost' => 'bboost',
            '3xc', 'triple captain', 'triplecaptain' => '3xc',
            'freehit', 'free hit', 'freehit1', 'freehit2' => 'freehit',
            'wildcard', 'wildcard1', 'wildcard2' => 'wildcard',
            default => '',
        };
    }

    private static function chipLabel(string $chip): string
    {
        $code = self::normaliseChipCode($chip);

        return self::CHIP_LABELS[$code] ?? ucfirst($chip);
    }
}
