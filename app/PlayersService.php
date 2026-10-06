<?php

declare(strict_types=1);

namespace App;

/**
 * Players index: in-form leaders from official FPL live data.
 */
final class PlayersService
{
    private const CACHE_PREFIX = 'players-index-v5-';
    private const CACHE_SECONDS = 600;
    private const FORM_WINDOW = 4;
    private const IN_FORM_LIMIT = 24;
    private const MOST_OWNED_LIMIT = 5;
    private const LOOKUP_LIMIT = 20;

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
    public static function build(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $cacheKey = self::CACHE_PREFIX . 'board';
        $cacheSeconds = self::cacheSeconds($events);
        $cached = FplCache::get($cacheKey, $cacheSeconds);
        if (is_array($cached) && ($cached['model'] ?? '') === 'players-v5') {
            return $cached;
        }

        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $payload = self::compose($elements, $teams, $events);
        FplCache::put($cacheKey, $payload);

        return $payload;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function lookupByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $ids = array_slice($ids, 0, self::LOOKUP_LIMIT);

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $teamsById = [];
        foreach ($teams as $team) {
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $byId = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $elementId = (int) ($el['id'] ?? 0);
            if ($elementId > 0) {
                $byId[$elementId] = $el;
            }
        }

        $players = [];
        foreach ($ids as $id) {
            $el = $byId[$id] ?? null;
            if (!is_array($el)) {
                continue;
            }
            $mapped = self::mapPlayer($el, $teamsById);
            if ($mapped !== null) {
                $players[] = $mapped;
            }
        }

        return $players;
    }

    /**
     * @return array<string, mixed>
     */
    public static function playersByClub(string $shortName): array
    {
        $shortName = strtoupper(trim($shortName));
        if ($shortName === '') {
            throw new \InvalidArgumentException('Club code required.');
        }

        $cacheKey = self::CACHE_PREFIX . 'club-' . $shortName;
        $cached = FplCache::get($cacheKey, self::CACHE_SECONDS);
        if (is_array($cached) && ($cached['short_name'] ?? '') === $shortName) {
            return $cached;
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];

        $teamsById = [];
        $clubMeta = null;
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
            if (strtoupper((string) ($team['short_name'] ?? '')) === $shortName) {
                $code = (int) ($team['code'] ?? 0);
                $clubMeta = [
                    'id' => $tid,
                    'short_name' => $shortName,
                    'name' => (string) ($team['name'] ?? $shortName),
                    'badge' => $code > 0
                        ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                        : null,
                ];
            }
        }

        if ($clubMeta === null) {
            throw new \RuntimeException('Club not found.');
        }

        $teamId = (int) ($clubMeta['id'] ?? 0);
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
            $mapped = self::mapPlayer($el, $teamsById);
            if ($mapped !== null) {
                $players[] = $mapped;
            }
        }

        usort($players, static function (array $a, array $b): int {
            $cmp = (float) ($b['ownership'] ?? 0) <=> (float) ($a['ownership'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['web_name'] ?? ''), (string) ($b['web_name'] ?? ''));
        });

        $payload = array_merge($clubMeta, ['players' => $players]);
        FplCache::put($cacheKey, $payload);

        return $payload;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int|string, array<string, mixed>> $teams
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function compose(array $elements, array $teams, array $events): array
    {
        $teamsById = [];
        foreach ($teams as $team) {
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }

        $formWindow = self::sumLastGameweekPoints($events, self::FORM_WINDOW);
        $pointsMap = $formWindow['points'];
        $gameweeks = $formWindow['gameweeks'];

        $inForm = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $id = (int) ($el['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $status = (string) ($el['status'] ?? 'a');
            if ($status === 'u') {
                continue;
            }

            $pts = (int) ($pointsMap[$id] ?? 0);
            if ($pts < 1) {
                continue;
            }

            $mapped = self::mapPlayer($el, $teamsById);
            if ($mapped === null) {
                continue;
            }

            $mapped['form_points'] = $pts;
            $inForm[] = $mapped;
        }

        usort($inForm, static function (array $a, array $b): int {
            $cmp = (int) ($b['form_points'] ?? 0) <=> (int) ($a['form_points'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['web_name'] ?? ''), (string) ($b['web_name'] ?? ''));
        });

        $inForm = array_slice($inForm, 0, self::IN_FORM_LIMIT);

        return [
            'model' => 'players-v5',
            'season' => self::seasonLabel($events),
            'event' => self::currentEventMeta($events),
            'in_form' => [
                'window' => $gameweeks,
                'target_window' => self::FORM_WINDOW,
                'event_ids' => $formWindow['event_ids'],
                'players' => $inForm,
            ],
            'most_owned' => self::buildMostOwned($elements, $teamsById),
            'clubs' => self::buildClubs($teams),
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, mixed>>
     */
    private static function buildMostOwned(array $elements, array $teamsById): array
    {
        $players = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $status = (string) ($el['status'] ?? 'a');
            if ($status === 'u') {
                continue;
            }
            $mapped = self::mapPlayer($el, $teamsById);
            if ($mapped !== null) {
                $players[] = $mapped;
            }
        }

        usort($players, static function (array $a, array $b): int {
            $cmp = (float) ($b['ownership'] ?? 0) <=> (float) ($a['ownership'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['web_name'] ?? ''), (string) ($b['web_name'] ?? ''));
        });

        return array_slice($players, 0, self::MOST_OWNED_LIMIT);
    }

    /**
     * @param array<int|string, array<string, mixed>> $teams
     * @return list<array<string, mixed>>
     */
    private static function buildClubs(array $teams): array
    {
        $clubs = [];

        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }

            $shortName = (string) ($team['short_name'] ?? '');
            if ($shortName === '') {
                continue;
            }

            $code = (int) ($team['code'] ?? 0);

            $clubs[] = [
                'id' => (int) ($team['id'] ?? 0),
                'short_name' => $shortName,
                'name' => (string) ($team['name'] ?? $shortName),
                'slug' => \App\PlayerProfileService::slugify((string) ($team['name'] ?? $shortName)),
                'path' => \App\PlayerProfileService::clubHubPath((string) ($team['name'] ?? $shortName)),
                'badge' => $code > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                    : null,
            ];
        }

        usort($clubs, static fn (array $a, array $b): int => strcmp(
            (string) ($a['short_name'] ?? ''),
            (string) ($b['short_name'] ?? '')
        ));

        return $clubs;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{points: array<int, int>, gameweeks: int, event_ids: list<int>}
     */
    private static function sumLastGameweekPoints(array $events, int $window): array
    {
        $gameweekIds = self::resolveFormGameweekIds($events, $window);
        $points = [];

        foreach ($gameweekIds as $entry) {
            $eventId = (int) ($entry['id'] ?? 0);
            if ($eventId < 1) {
                continue;
            }

            $isLiveGameweek = !empty($entry['live']);

            try {
                $live = FplClient::fetchEventLive($eventId, !$isLiveGameweek);
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
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $stats = $row['stats'] ?? null;
                if (!is_array($stats)) {
                    continue;
                }
                $pts = (int) ($stats['total_points'] ?? 0);
                $points[$id] = ($points[$id] ?? 0) + $pts;
            }
        }

        $eventIds = array_values(array_map(static fn (array $entry): int => (int) ($entry['id'] ?? 0), $gameweekIds));

        return [
            'points' => $points,
            'gameweeks' => count($gameweekIds),
            'event_ids' => $eventIds,
        ];
    }

    /**
     * Finished gameweeks plus the live current GW (partial scores count).
     *
     * @param list<array<string, mixed>> $events
     * @return list<array{id:int,live:bool}>
     */
    private static function resolveFormGameweekIds(array $events, int $window): array
    {
        $eligible = [];

        foreach ($events as $event) {
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId < 1) {
                continue;
            }

            if (!empty($event['finished']) && !empty($event['data_checked'])) {
                $eligible[] = ['id' => $eventId, 'live' => false];
                continue;
            }

            if (!empty($event['is_current'])) {
                $eligible[] = ['id' => $eventId, 'live' => true];
                continue;
            }

            if (!empty($event['finished']) && !empty($event['is_previous'])) {
                $eligible[] = ['id' => $eventId, 'live' => true];
            }
        }

        usort($eligible, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($eligible, -$window);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private static function cacheSeconds(array $events): int
    {
        foreach ($events as $event) {
            if (!empty($event['is_current']) && empty($event['finished'])) {
                return 120;
            }
        }

        return self::CACHE_SECONDS;
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

        $teamId = (int) ($el['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $code = (int) ($team['code'] ?? 0);
        $pos = (int) ($el['element_type'] ?? 0);
        $nowCost = (int) ($el['now_cost'] ?? 0);

        return [
            'id' => $id,
            'web_name' => (string) ($el['web_name'] ?? 'Player'),
            'pos' => $pos,
            'pos_label' => self::POS_LABEL[$pos] ?? '?',
            'team_id' => $teamId,
            'team_short' => (string) ($team['short_name'] ?? ''),
            'team_name' => (string) ($team['name'] ?? ''),
            'photo' => self::playerPhoto((string) ($el['photo'] ?? '')),
            'shirt' => self::playerShirt($code, $pos),
            'price' => round($nowCost / 10, 1),
            'ownership' => round((float) ($el['selected_by_percent'] ?? 0), 1),
            'event_points' => (int) ($el['event_points'] ?? 0),
            'total_points' => (int) ($el['total_points'] ?? 0),
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
        return ['id' => 0, 'name' => 'Gameweek'];
    }
}
