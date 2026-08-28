<?php

declare(strict_types=1);

namespace App;

final class FplClient
{
    private const BASE = 'https://fantasy.premierleague.com/api';

    /** @return array<string, mixed> */
    public static function fetchEntry(int $entryId): array
    {
        if ($entryId < 1) {
            throw new \InvalidArgumentException('Enter a valid FPL ID.');
        }

        return FplCache::rememberFreshOrStale(
            'entry-v1-' . $entryId,
            10 * 60,
            3600,
            static function () use ($entryId): array {
                $data = self::getJson(self::entryUrl($entryId));
                if (empty($data['id'])) {
                    throw new \InvalidArgumentException('We could not find an FPL team with that ID.');
                }
                return $data;
            }
        );
    }

    public static function entryUrl(int $entryId): string
    {
        return self::BASE . '/entry/' . $entryId . '/';
    }

    /**
     * Slim bootstrap used by Live (much faster than decoding the full 1.6MB payload every request).
     *
     * @return array{events: list<array>, teams: array<int, array>, elements: array<int, array>}
     */
    public static function fetchBootstrapIndex(): array
    {
        return FplCache::rememberFreshOrStale('bootstrap-index-v2', 6 * 3600, 24 * 3600, static function () {
            $data = self::fetchBootstrapStatic();
            if (empty($data['elements']) || empty($data['teams']) || empty($data['events'])) {
                throw new \RuntimeException('The FPL bootstrap data is unavailable right now.');
            }

            $teams = [];
            foreach ($data['teams'] as $team) {
                $teams[(int) $team['id']] = [
                    'id' => (int) $team['id'],
                    'name' => (string) $team['name'],
                    'short_name' => (string) $team['short_name'],
                    'code' => (int) $team['code'],
                ];
            }

            $elements = [];
            foreach ($data['elements'] as $element) {
                $elements[(int) $element['id']] = [
                    'id' => (int) $element['id'],
                    'web_name' => (string) $element['web_name'],
                    'team' => (int) $element['team'],
                    'element_type' => (int) $element['element_type'],
                    'event_points' => (int) ($element['event_points'] ?? 0),
                ];
            }

            $events = [];
            foreach ($data['events'] as $event) {
                $events[] = [
                    'id' => (int) $event['id'],
                    'name' => (string) ($event['name'] ?? ('GW' . $event['id'])),
                    'is_current' => !empty($event['is_current']),
                    'is_previous' => !empty($event['is_previous']),
                    'is_next' => !empty($event['is_next']),
                    'finished' => !empty($event['finished']),
                ];
            }

            return [
                'events' => $events,
                'teams' => $teams,
                'elements' => $elements,
            ];
        });
    }

    /**
     * Richer bootstrap for Pick / Rate My Team (still stripped vs raw 1.6MB).
     *
     * @return array{events: list<array>, teams: array<int, array>, elements: array<int, array>}
     */
    public static function fetchBootstrapPick(): array
    {
        return FplCache::rememberFreshOrStale('bootstrap-pick-v4', 6 * 3600, 24 * 3600, static function () {
            $data = self::fetchBootstrapStatic();
            if (empty($data['elements']) || empty($data['teams']) || empty($data['events'])) {
                throw new \RuntimeException('The FPL bootstrap data is unavailable right now.');
            }

            $teams = [];
            foreach ($data['teams'] as $team) {
                $teams[(int) $team['id']] = [
                    'id' => (int) $team['id'],
                    'name' => (string) $team['name'],
                    'short_name' => (string) $team['short_name'],
                    'code' => (int) $team['code'],
                    'strength' => (int) ($team['strength'] ?? 0),
                    'strength_overall_home' => (int) ($team['strength_overall_home'] ?? 0),
                    'strength_overall_away' => (int) ($team['strength_overall_away'] ?? 0),
                    'strength_attack_home' => (int) ($team['strength_attack_home'] ?? 0),
                    'strength_attack_away' => (int) ($team['strength_attack_away'] ?? 0),
                    'strength_defence_home' => (int) ($team['strength_defence_home'] ?? 0),
                    'strength_defence_away' => (int) ($team['strength_defence_away'] ?? 0),
                    'played' => (int) ($team['played'] ?? 0),
                    'win' => (int) ($team['win'] ?? 0),
                    'draw' => (int) ($team['draw'] ?? 0),
                    'loss' => (int) ($team['loss'] ?? 0),
                    'points' => (int) ($team['points'] ?? 0),
                    'position' => (int) ($team['position'] ?? 0),
                ];
            }

            $elements = [];
            foreach ($data['elements'] as $element) {
                $elements[(int) $element['id']] = [
                    'id' => (int) $element['id'],
                    'first_name' => (string) ($element['first_name'] ?? ''),
                    'second_name' => (string) ($element['second_name'] ?? ''),
                    'web_name' => (string) $element['web_name'],
                    'photo' => (string) ($element['photo'] ?? ''),
                    'team' => (int) $element['team'],
                    'element_type' => (int) $element['element_type'],
                    'now_cost' => (int) ($element['now_cost'] ?? 0),
                    'cost_change_event' => (int) ($element['cost_change_event'] ?? 0),
                    'cost_change_start' => (int) ($element['cost_change_start'] ?? 0),
                    'price_change_percent' => (string) ($element['price_change_percent'] ?? '0'),
                    'price_change_hourly_rate' => (float) ($element['price_change_hourly_rate'] ?? 0),
                    'price_change_projections' => is_array($element['price_change_projections'] ?? null)
                        ? array_values(array_map(static function ($row): array {
                            return [
                                'offset' => (int) ($row['offset'] ?? 0),
                                'projected_percent' => (string) ($row['projected_percent'] ?? '0'),
                                'likelihood' => (int) ($row['likelihood'] ?? 0),
                            ];
                        }, $element['price_change_projections']))
                        : [],
                    'price_change_locked_until' => $element['price_change_locked_until'] ?? null,
                    'price_change_calibrating' => !empty($element['price_change_calibrating']),
                    'selected_by_percent' => (string) ($element['selected_by_percent'] ?? '0'),
                    'transfers_in_event' => (int) ($element['transfers_in_event'] ?? 0),
                    'transfers_out_event' => (int) ($element['transfers_out_event'] ?? 0),
                    'transfers_in' => (int) ($element['transfers_in'] ?? 0),
                    'transfers_out' => (int) ($element['transfers_out'] ?? 0),
                    'total_points' => (int) ($element['total_points'] ?? 0),
                    'points_per_game' => (string) ($element['points_per_game'] ?? '0.0'),
                    'form' => (string) ($element['form'] ?? '0.0'),
                    'ep_this' => (string) ($element['ep_this'] ?? '0.0'),
                    'ep_next' => (string) ($element['ep_next'] ?? '0.0'),
                    'minutes' => (int) ($element['minutes'] ?? 0),
                    'starts' => (int) ($element['starts'] ?? 0),
                    'starts_per_90' => (float) ($element['starts_per_90'] ?? 0),
                    'goals_scored' => (int) ($element['goals_scored'] ?? 0),
                    'assists' => (int) ($element['assists'] ?? 0),
                    'clean_sheets' => (int) ($element['clean_sheets'] ?? 0),
                    'goals_conceded' => (int) ($element['goals_conceded'] ?? 0),
                    'saves' => (int) ($element['saves'] ?? 0),
                    'saves_per_90' => (float) ($element['saves_per_90'] ?? 0),
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
                    'expected_goals_conceded' => (string) ($element['expected_goals_conceded'] ?? '0.00'),
                    'expected_goals_per_90' => (float) ($element['expected_goals_per_90'] ?? 0),
                    'expected_assists_per_90' => (float) ($element['expected_assists_per_90'] ?? 0),
                    'expected_goal_involvements_per_90' => (float) ($element['expected_goal_involvements_per_90'] ?? 0),
                    'expected_goals_conceded_per_90' => (float) ($element['expected_goals_conceded_per_90'] ?? 0),
                    'penalties_order' => $element['penalties_order'] ?? null,
                    'corners_and_indirect_freekicks_order' => $element['corners_and_indirect_freekicks_order'] ?? null,
                    'direct_freekicks_order' => $element['direct_freekicks_order'] ?? null,
                    'status' => (string) ($element['status'] ?? 'a'),
                    'news' => (string) ($element['news'] ?? ''),
                    'news_added' => $element['news_added'] ?? null,
                    'chance_of_playing_this_round' => $element['chance_of_playing_this_round'] ?? null,
                    'chance_of_playing_next_round' => $element['chance_of_playing_next_round'] ?? null,
                    'scout_risks' => $element['scout_risks'] ?? null,
                ];
            }

            $events = [];
            foreach ($data['events'] as $event) {
                $events[] = [
                    'id' => (int) $event['id'],
                    'name' => (string) ($event['name'] ?? ('GW' . $event['id'])),
                    'deadline_time' => $event['deadline_time'] ?? null,
                    'is_current' => !empty($event['is_current']),
                    'is_previous' => !empty($event['is_previous']),
                    'is_next' => !empty($event['is_next']),
                    'finished' => !empty($event['finished']),
                    'data_checked' => !empty($event['data_checked']),
                    'average_entry_score' => isset($event['average_entry_score'])
                        ? (int) $event['average_entry_score']
                        : null,
                    'highest_score' => isset($event['highest_score'])
                        ? (int) $event['highest_score']
                        : null,
                    'highest_scoring_entry' => isset($event['highest_scoring_entry'])
                        ? (int) $event['highest_scoring_entry']
                        : null,
                    'most_captained' => isset($event['most_captained'])
                        ? (int) $event['most_captained']
                        : null,
                    'most_vice_captained' => isset($event['most_vice_captained'])
                        ? (int) $event['most_vice_captained']
                        : null,
                    'most_selected' => isset($event['most_selected'])
                        ? (int) $event['most_selected']
                        : null,
                    'chip_plays' => is_array($event['chip_plays'] ?? null)
                        ? $event['chip_plays']
                        : [],
                ];
            }

            return [
                'events' => $events,
                'teams' => $teams,
                'elements' => $elements,
            ];
        });
    }

    /**
     * Per-player history for minutes certainty + calibration (cached).
     *
     * @return array{fixtures?:list,history?:list,history_past?:list}
     */
    public static function fetchElementSummary(int $elementId): array
    {
        return FplCache::remember('element-summary-' . $elementId, 12 * 3600, static function () use ($elementId) {
            return self::getJson(self::BASE . '/element-summary/' . $elementId . '/');
        });
    }

    /**
     * Parallel fetch of many element summaries. Keys are element ids.
     *
     * @param list<int> $elementIds
     * @return array<int, array<string, mixed>>
     */
    public static function fetchElementSummaries(array $elementIds, ?callable $logger = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $elementIds)));
        $out = [];
        $missing = [];
        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }
            $cached = FplCache::get('element-summary-' . $id, 12 * 3600);
            if (is_array($cached)) {
                $out[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        $log = $logger ?? static function (string $message): void {
            // no-op
        };

        $chunks = array_chunk($missing, 25);
        foreach ($chunks as $index => $chunk) {
            $log('Element summaries batch ' . ($index + 1) . '/' . max(1, count($chunks)) . ' (' . count($chunk) . ')');
            $urls = [];
            foreach ($chunk as $id) {
                $urls[(string) $id] = self::BASE . '/element-summary/' . $id . '/';
            }
            $fetched = self::getJsonMany($urls);
            foreach ($fetched as $key => $payload) {
                $id = (int) $key;
                if (!is_array($payload)) {
                    continue;
                }
                FplCache::put('element-summary-' . $id, $payload);
                $out[$id] = $payload;
            }
            usleep(100000);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function fetchFixtures(): array
    {
        return FplCache::rememberFreshOrStale('fixtures-v1', 3600, 12 * 3600, static function () {
            $data = self::getJson(self::BASE . '/fixtures/');
            if (!is_array($data)) {
                throw new \RuntimeException('Fixtures are unavailable right now.');
            }
            return $data;
        });
    }

    /** Warm caches used by Live so the next request is quick. */
    public static function warmLiveCaches(): void
    {
        try {
            self::fetchBootstrapIndex();
        } catch (\Throwable $e) {
            // ignore warm failures
        }
    }

    /** Warm Pick bootstrap so Rate My Team does not wait on a cold FPL download. */
    public static function warmPickCaches(): void
    {
        try {
            self::fetchBootstrapPick();
            self::fetchFixtures();
        } catch (\Throwable $e) {
            // ignore warm failures
        }
    }

    /** @return array<string, mixed> */
    public static function fetchEntryHistory(int $entryId): array
    {
        if ($entryId < 1) {
            throw new \InvalidArgumentException('Enter a valid FPL ID.');
        }

        return FplCache::rememberFreshOrStale(
            'entry-history-v1-' . $entryId,
            10 * 60,
            3600,
            static fn (): array => self::getJson(self::entryHistoryUrl($entryId))
        );
    }

    public static function entryHistoryUrl(int $entryId): string
    {
        return self::BASE . '/entry/' . $entryId . '/history/';
    }

    /** @return array<string, mixed> */
    public static function fetchEventPicks(int $entryId, int $eventId): array
    {
        if ($entryId < 1 || $eventId < 1) {
            throw new \InvalidArgumentException('Enter a valid FPL ID.');
        }

        return FplCache::rememberFreshOrStale(
            'entry-picks-v1-' . $entryId . '-' . $eventId,
            15 * 60,
            7 * 86400,
            static fn (): array => self::getJson(self::eventPicksUrl($entryId, $eventId))
        );
    }

    /** @return array<string, mixed>|null */
    public static function tryFetchEventPicks(int $entryId, int $eventId): ?array
    {
        if ($entryId < 1 || $eventId < 1) {
            return null;
        }

        try {
            return self::fetchEventPicks($entryId, $eventId);
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'could not find')) {
                return null;
            }
            throw $e;
        }
    }

    public static function eventPicksUrl(int $entryId, int $eventId): string
    {
        return self::BASE . '/entry/' . $entryId . '/event/' . $eventId . '/picks/';
    }

    /** @return array<string, mixed> */
    public static function fetchEventLive(int $eventId, bool $finished = false): array
    {
        $fresh = $finished ? 86400 : 180;
        $stale = $finished ? 2592000 : 3600;

        return FplCache::rememberFreshOrStale(
            'event-live-v2-' . $eventId,
            $fresh,
            $stale,
            static fn (): array => self::getJson(self::BASE . '/event/' . $eventId . '/live/')
        );
    }

    /**
     * Shared raw bootstrap-static. Gzipped over the wire and cached so Pick/Live
     * do not each re-download the ~1.6MB payload on a cold miss.
     *
     * @return array<string, mixed>
     */
    private static function fetchBootstrapStatic(): array
    {
        return FplCache::rememberFreshOrStale(
            'bootstrap-static-raw-v1',
            6 * 3600,
            36 * 3600,
            static function () {
                $data = self::getJson(self::BASE . '/bootstrap-static/', 120, 3);
                if (empty($data['elements']) || empty($data['teams']) || empty($data['events'])) {
                    throw new \RuntimeException('The FPL bootstrap data is unavailable right now.');
                }
                return $data;
            }
        );
    }

    /**
     * Fetch multiple JSON endpoints sequentially (shared hosting cannot reliably run curl_multi).
     *
     * @param array<string, string> $urls
     * @return array<string, array<string, mixed>|null>
     */
    public static function getJsonMany(array $urls): array
    {
        if (!$urls) {
            return [];
        }

        $out = [];
        foreach ($urls as $key => $url) {
            try {
                $out[$key] = self::getJson($url);
            } catch (\InvalidArgumentException $e) {
                if (str_contains($e->getMessage(), 'could not find')) {
                    $out[$key] = null;
                    continue;
                }
                throw $e;
            }
        }

        return $out;
    }

    /**
     * Classic league metadata (from standings page one; the league detail route needs auth).
     *
     * @return array{id:int,name:string,created:string,admin_entry:int,league_type:string}
     */
    public static function fetchClassicLeague(int $leagueId): array
    {
        if ($leagueId < 1) {
            throw new \InvalidArgumentException('Enter a valid league ID.');
        }

        return FplCache::rememberFreshOrStale(
            'classic-league-meta-v1-' . $leagueId,
            30 * 60,
            6 * 3600,
            static function () use ($leagueId): array {
                $data = self::getJson(
                    self::BASE . '/leagues-classic/' . $leagueId . '/standings/?page_standings=1'
                );
                $league = $data['league'] ?? null;
                if (!is_array($league) || empty($league['id'])) {
                    throw new \InvalidArgumentException('We could not find that FPL league.');
                }

                return [
                    'id' => (int) $league['id'],
                    'name' => (string) ($league['name'] ?? ('League ' . $leagueId)),
                    'created' => (string) ($league['created'] ?? ''),
                    'admin_entry' => isset($league['admin_entry']) ? (int) $league['admin_entry'] : 0,
                    'league_type' => (string) ($league['league_type'] ?? ''),
                ];
            }
        );
    }

    /**
     * Full classic league standings (paginated upstream, merged here).
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchAllClassicLeagueStandings(int $leagueId): array
    {
        if ($leagueId < 1) {
            throw new \InvalidArgumentException('Enter a valid league ID.');
        }

        return FplCache::rememberFreshOrStale(
            'classic-league-standings-v1-' . $leagueId,
            15 * 60,
            2 * 3600,
            static function () use ($leagueId): array {
                $page = 1;
                $all = [];

                do {
                    $data = self::getJson(
                        self::BASE . '/leagues-classic/' . $leagueId . '/standings/?page_standings=' . $page
                    );
                    $standings = $data['standings'] ?? null;
                    if (!is_array($standings)) {
                        break;
                    }

                    $results = $standings['results'] ?? [];
                    if (!is_array($results) || $results === []) {
                        break;
                    }

                    foreach ($results as $row) {
                        if (is_array($row)) {
                            $all[] = $row;
                        }
                    }

                    $hasNext = !empty($standings['has_next']);
                    $page++;
                } while ($hasNext && $page <= 50);

                if ($all === []) {
                    throw new \InvalidArgumentException('We could not find that FPL league.');
                }

                return $all;
            }
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchEntryTransfers(int $entryId): array
    {
        if ($entryId < 1) {
            return [];
        }

        return FplCache::rememberFreshOrStale(
            'entry-transfers-v1-' . $entryId,
            10 * 60,
            3600,
            static function () use ($entryId): array {
                $data = self::getJson(self::BASE . '/entry/' . $entryId . '/transfers/');
                if (!is_array($data)) {
                    return [];
                }

                return array_values(array_filter($data, 'is_array'));
            }
        );
    }

    /** @return array{id:int,team_name:string,manager_name:string,overall_points:int|null,overall_rank:int|null,favourite_team:int|null} */
    public static function summariseEntry(array $entry): array
    {
        $first = trim((string) ($entry['player_first_name'] ?? ''));
        $last = trim((string) ($entry['player_last_name'] ?? ''));
        $manager = trim($first . ' ' . $last);

        return [
            'id' => (int) $entry['id'],
            'team_name' => (string) ($entry['name'] ?? 'FPL Team'),
            'manager_name' => $manager !== '' ? $manager : 'FPL Manager',
            'overall_points' => isset($entry['summary_overall_points']) ? (int) $entry['summary_overall_points'] : null,
            'overall_rank' => isset($entry['summary_overall_rank']) ? (int) $entry['summary_overall_rank'] : null,
            'favourite_team' => isset($entry['favourite_team']) ? (int) $entry['favourite_team'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function getJson(string $url, int $timeoutSeconds = 45, int $attempts = 2): array
    {
        $raw = self::get($url, $timeoutSeconds, $attempts);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('The FPL API returned invalid data.');
        }
        return $data;
    }

    private static function get(string $url, int $timeoutSeconds = 45, int $attempts = 2): string
    {
        $attempts = max(1, $attempts);
        $lastError = 'unknown error';

        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, self::curlOptions($timeoutSeconds));
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $status >= 200 && $status < 300) {
                return $body;
            }

            if ($status === 404) {
                throw new \InvalidArgumentException('We could not find that FPL data.');
            }

            $lastError = $error !== '' ? $error : ('HTTP ' . $status);
            $retryable = $body === false
                || $errno === CURLE_OPERATION_TIMEDOUT
                || $errno === CURLE_COULDNT_CONNECT
                || $errno === CURLE_RECV_ERROR
                || stripos($lastError, 'getaddrinfo') !== false
                || stripos($lastError, 'thread failed') !== false
                || ($status >= 500 && $status <= 599);

            if (!$retryable || $i === $attempts - 1) {
                break;
            }
            usleep(500000 * ($i + 1));
        }

        if (stripos($lastError, 'timed out') !== false || stripos($lastError, 'timeout') !== false) {
            throw new \RuntimeException(
                'Fantasy Premier League is slow right now. Please try again in a moment.'
            );
        }

        throw new \RuntimeException('Could not reach the FPL API: ' . $lastError);
    }

    /** @return array<int, mixed> */
    private static function curlOptions(int $timeoutSeconds = 45): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => max(20, $timeoutSeconds),
            CURLOPT_ENCODING => '', // accept gzip/br and shrink the 1.6MB bootstrap
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate, br',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
        ];
    }
}
