<?php

declare(strict_types=1);

namespace App;

/**
 * Nightly shared scout: score every FPL player once, serve all users.
 * Pulls rich FPL bootstrap + element-summary history (not a custom trained model).
 */
final class PlayerAiScout
{
    private const BATCH_SIZE = 20;

    /**
     * @return array{event_id:int,count:int,source:string,model:string,batches:int}
     */
    public static function build(?callable $logger = null): array
    {
        $log = $logger ?? static function (string $message): void {
            // no-op
        };

        $bootstrap = FplClient::fetchBootstrapPick();
        $events = $bootstrap['events'];
        $planning = null;
        foreach ($events as $event) {
            if (!empty($event['is_next']) || !empty($event['is_current'])) {
                $planning = $event;
                if (!empty($event['is_next'])) {
                    break;
                }
            }
        }
        if (!$planning) {
            throw new \RuntimeException('No planning gameweek found for AI scout.');
        }

        $eventId = (int) $planning['id'];
        $teams = $bootstrap['teams'];
        $rawFixtures = FplClient::fetchFixtures();
        $fixtureCtx = self::buildFixtureContext($rawFixtures, $eventId, $teams, $events);

        $log('Fetching element summaries for minutes + history (' . count($bootstrap['elements']) . ' players)');
        $summaries = FplClient::fetchElementSummaries(array_keys($bootstrap['elements']), $log);

        $players = [];
        foreach ($bootstrap['elements'] as $element) {
            $id = (int) $element['id'];
            $players[] = self::buildPlayerCard(
                $element,
                $teams,
                $fixtureCtx,
                $summaries[$id] ?? []
            );
        }

        $dataVersion = substr(hash('sha256', $eventId . '|' . PlayerProjectionEngine::MODEL . '|' . count($players) . '|' . gmdate('Y-m-d')), 0, 16);
        $rows = [];
        // Numeric xP is always the position-aware engine. OpenAI is not trusted for raw points.
        $source = 'deterministic';
        $model = PlayerProjectionEngine::MODEL;
        $batches = 0;
        $log('Building ' . PlayerProjectionEngine::MODEL . ' projections for GW' . $eventId . ' (' . count($players) . ' players)');
        foreach ($players as $player) {
            $rows[] = self::deterministicScore($player);
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['element_id']] = $row;
        }
        foreach ($players as $player) {
            $id = (int) $player['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = self::deterministicScore($player);
            }
        }

        PlayerAiScoreStore::upsertMany($eventId, $dataVersion, array_values($byId));
        $log('Saved ' . count($byId) . ' player scores for event ' . $eventId);

        return [
            'event_id' => $eventId,
            'count' => count($byId),
            'source' => $source,
            'model' => $model,
            'batches' => $batches,
        ];
    }

    /**
     * Ensure scores exist for an event (builds deterministic sheet if empty).
     * Web path stays fast: no element-summary crawl here.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ensureScores(int $eventId, ?array $bootstrap = null, ?array $fixtures = null): array
    {
        $map = PlayerAiScoreStore::loadMap($eventId);
        // Prefer any complete horizon sheet over a thin no-history rebuild.
        // Model upgrades must run scripts/build-player-ai-scores.php (with summaries).
        if (count($map) >= 100 && self::mapHasHorizon($map)) {
            if (self::mapUsesEngine($map)) {
                return $map;
            }
            // Stale model still beats empty past-ppg sheets on the hot web path.
            return $map;
        }

        $bootstrap = $bootstrap ?? FplClient::fetchBootstrapPick();
        $teams = $bootstrap['teams'];
        $fixtureList = $fixtures ?? FplClient::fetchFixtures();
        $fixtureCtx = self::buildFixtureContext(
            $fixtureList,
            $eventId,
            $teams,
            $bootstrap['events']
        );
        $rows = [];
        foreach ($bootstrap['elements'] as $element) {
            $card = self::buildPlayerCard($element, $teams, $fixtureCtx, []);
            $rows[] = self::deterministicScore($card);
        }
        PlayerAiScoreStore::upsertMany(
            $eventId,
            PlayerProjectionEngine::MODEL . '-' . gmdate('YmdH'),
            $rows
        );
        return PlayerAiScoreStore::loadMap($eventId);
    }

    /** @param array<int, array<string, mixed>> $map */
    private static function mapUsesEngine(array $map): bool
    {
        $sample = array_slice($map, 0, 12, true);
        foreach ($sample as $row) {
            if (($row['model'] ?? '') !== PlayerProjectionEngine::MODEL) {
                return false;
            }
        }
        return true;
    }

    /** @param array<int, array<string, mixed>> $map */
    private static function mapHasHorizon(array $map): bool
    {
        $horizon = PlayerProjectionEngine::HORIZON;
        $sample = array_slice($map, 0, 12, true);
        foreach ($sample as $row) {
            if (count($row['projections'] ?? []) < $horizon) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array<string, mixed>> $chunk
     * @return list<array<string, mixed>>
     */
    private static function scoreBatchWithOpenAi(array $chunk, int $eventId): array
    {
        $slim = array_map(static function (array $p): array {
            return [
                'id' => $p['id'],
                'name' => $p['name'],
                'pos' => $p['pos'],
                'team' => $p['team'],
                'price' => $p['price'],
                'own' => $p['own'],
                'form' => $p['form'],
                'ppg' => $p['ppg'],
                'ep_next' => $p['ep_next'],
                'avail' => $p['avail'],
                'status' => $p['status'],
                'news' => $p['news'],
                'minutes' => $p['minutes'],
                'starts' => $p['starts'],
                'mins_certainty' => $p['mins_certainty'],
                'recent' => $p['recent'],
                'past' => $p['past'],
                'xg' => $p['xg'],
                'xa' => $p['xa'],
                'xgi' => $p['xgi'],
                'xgc' => $p['xgc'],
                'xg90' => $p['xg90'],
                'xa90' => $p['xa90'],
                'xgi90' => $p['xgi90'],
                'xgc90' => $p['xgc90'],
                'ict' => $p['ict'],
                'saves90' => $p['saves90'],
                'cs' => $p['cs'],
                'set_pieces' => $p['set_pieces'],
                'market' => $p['market'],
                'team_ctx' => $p['team_ctx'],
                'fixtures' => $p['fixtures'],
            ];
        }, $chunk);

        $payload = [
            'event_id' => $eventId,
            'scoring_rules' => self::scoringRulesBrief(),
            'players' => $slim,
        ];

        $result = OpenAiClient::chatJson(
            [
                [
                    'role' => 'system',
                    'content' => self::systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'metro_player_scout_rich_v2',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'players' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'proj' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'number'],
                                    'minItems' => 5,
                                    'maxItems' => 5,
                                ],
                                'strength' => ['type' => 'number'],
                                'tip_tag' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'proj', 'strength', 'tip_tag'],
                        ],
                    ],
                ],
                'required' => ['players'],
            ]
        );

        $out = [];
        foreach (($result['players'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $proj = array_values(array_map('floatval', array_slice(array_pad($row['proj'] ?? [], PlayerProjectionEngine::HORIZON, 0), 0, PlayerProjectionEngine::HORIZON)));
            $out[] = [
                'element_id' => (int) $row['id'],
                'projections' => array_map(static fn ($n) => max(0, min(20, round($n, 2))), $proj),
                'strength' => max(0, min(100, (float) ($row['strength'] ?? 0))),
                'tip_tag' => substr(trim((string) ($row['tip_tag'] ?? 'steady')), 0, 32),
                'model' => OpenAiClient::model(),
                'source' => 'openai',
            ];
        }
        return $out;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Metro Football's shared Premier League Fantasy (FPL) scout.
You score EVERY player in the JSON using only provided fields. Do not invent match events.

Decision order (always):
1) Minutes certainty first. Low mins_certainty or bad news/status means cut projections hard.
2) Role and set pieces. Penalties order 1 and corners matter a lot for attackers.
3) Underlying rates (xg90, xa90, xgi90, xgc90, saves90) over raw season totals when minutes are thin.
4) Fixture quality using opp_def, opp_att, fdr, home/away, blanks, doubles, congestion_hours.
5) Team attack/defence context (team_ctx).
6) Market (own, transfers) informs tip_tag only, never inflate raw points for popularity.
7) Past seasons (past) calibrate early-season noise.

FPL points reality:
- Typical outfield starter next GW is often about 2 to 7 points. Captains can be higher.
- Blank GW projection for that slot should be near 0.
- Double GW: sum both legs into that slot's projection.
- GK/DEF clean sheet upside when opp_att is weak and team_def is strong.
- Avoid overrating early-season totals when minutes sample is tiny; lean on past + rates + fixtures.

Return for each input id:
- proj[5]: expected FPL points for the next 5 fixture slots (proj[0] = next slot). Use 0 for blanks.
- strength 0-100: how strong this asset is for the next GW relative to a typical starter in their position (50 = average).
- tip_tag: one of steady, avoid, differential, premium, budget, injured, rotation.

Score every player. Be realistic, not optimistic marketing.
PROMPT;
    }

    /** @return array<string, string> */
    private static function scoringRulesBrief(): array
    {
        return [
            'goal_gk_def' => '6',
            'goal_mid' => '5',
            'goal_fwd' => '4',
            'assist' => '3',
            'cs_gk_def' => '4',
            'cs_mid' => '1',
            'appearance_60' => '2',
            'appearance_1_59' => '1',
            'bonus' => '1-3',
            'save_3' => '1',
            'penalty_save' => '5',
            'yellow' => '-1',
            'red' => '-3',
            'own_goal' => '-2',
            'goals_conceded_2_gk_def' => '-1',
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @param array<int, array<string, mixed>> $teams
     * @param array{by_team:array<int,list>,counts:array<int,array<int,int>>} $fixtureCtx
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function buildPlayerCard(array $element, array $teams, array $fixtureCtx, array $summary): array
    {
        $teamId = (int) ($element['team'] ?? 0);
        $team = $teams[$teamId] ?? [];
        $pos = (int) ($element['element_type'] ?? 0);
        $chance = $element['chance_of_playing_next_round'] ?? null;
        $avail = $chance === null ? 1.0 : max(0.0, ((float) $chance) / 100.0);
        $status = (string) ($element['status'] ?? 'a');
        if ($status === 'i' || $status === 's' || $status === 'u') {
            $avail = min($avail, 0.2);
        } elseif ($status === 'd') {
            $avail = min($avail, $avail > 0 ? $avail : 0.5);
        }

        $history = is_array($summary['history'] ?? null) ? $summary['history'] : [];
        $past = is_array($summary['history_past'] ?? null) ? $summary['history_past'] : [];
        $recent = self::summariseRecentHistory($history);
        $pastSummary = self::summarisePastSeasons($past);
        $minsCertainty = self::minutesCertainty($element, $recent, $avail, $status);

        $fixtures = array_values($fixtureCtx['by_team'][$teamId] ?? []);
        $fixtures = array_slice($fixtures, 0, PlayerProjectionEngine::HORIZON);

        return [
            'id' => (int) ($element['id'] ?? 0),
            'name' => (string) ($element['web_name'] ?? ''),
            'pos' => $pos,
            'team' => (string) ($team['short_name'] ?? ''),
            'team_id' => $teamId,
            'price' => ((int) ($element['now_cost'] ?? 0)) / 10,
            'own' => (float) ($element['selected_by_percent'] ?? 0),
            'form' => (float) ($element['form'] ?? 0),
            'ppg' => (float) ($element['points_per_game'] ?? 0),
            'total_points' => (int) ($element['total_points'] ?? 0),
            'ep_next' => (float) ($element['ep_next'] ?? 0),
            'ep_this' => (float) ($element['ep_this'] ?? 0),
            'avail' => round($avail, 2),
            'status' => $status,
            'news' => substr(trim((string) ($element['news'] ?? '')), 0, 160),
            'scout_risks' => $element['scout_risks'] ?? null,
            'minutes' => (int) ($element['minutes'] ?? 0),
            'starts' => (int) ($element['starts'] ?? 0),
            'starts_per_90' => (float) ($element['starts_per_90'] ?? 0),
            'mins_certainty' => $minsCertainty,
            'recent' => $recent,
            'past' => $pastSummary,
            'h2h' => self::summariseH2H($history, $fixtures),
            'xg' => (float) ($element['expected_goals'] ?? 0),
            'xa' => (float) ($element['expected_assists'] ?? 0),
            'xgi' => (float) ($element['expected_goal_involvements'] ?? 0),
            'xgc' => (float) ($element['expected_goals_conceded'] ?? 0),
            'xg90' => (float) ($element['expected_goals_per_90'] ?? 0),
            'xa90' => (float) ($element['expected_assists_per_90'] ?? 0),
            'xgi90' => (float) ($element['expected_goal_involvements_per_90'] ?? 0),
            'xgc90' => (float) ($element['expected_goals_conceded_per_90'] ?? 0),
            'ict' => (float) ($element['ict_index'] ?? 0),
            'threat' => (float) ($element['threat'] ?? 0),
            'creativity' => (float) ($element['creativity'] ?? 0),
            'influence' => (float) ($element['influence'] ?? 0),
            'saves' => (int) ($element['saves'] ?? 0),
            'saves90' => (float) ($element['saves_per_90'] ?? 0),
            'pen_saves' => (int) ($element['penalties_saved'] ?? 0),
            'cs' => (int) ($element['clean_sheets'] ?? 0),
            'gc' => (int) ($element['goals_conceded'] ?? 0),
            'goals' => (int) ($element['goals_scored'] ?? 0),
            'assists' => (int) ($element['assists'] ?? 0),
            'def_con' => (int) ($element['defensive_contribution'] ?? 0),
            'bps' => (int) ($element['bps'] ?? 0),
            'bonus' => (int) ($element['bonus'] ?? 0),
            'set_pieces' => [
                'pens' => $element['penalties_order'] ?? null,
                'corners' => $element['corners_and_indirect_freekicks_order'] ?? null,
                'fk' => $element['direct_freekicks_order'] ?? null,
            ],
            'market' => [
                'tin' => (int) ($element['transfers_in_event'] ?? 0),
                'tout' => (int) ($element['transfers_out_event'] ?? 0),
                'delta_price_gw' => ((int) ($element['cost_change_event'] ?? 0)) / 10,
                'delta_price_start' => ((int) ($element['cost_change_start'] ?? 0)) / 10,
            ],
            'team_ctx' => [
                'att_h' => (int) ($team['strength_attack_home'] ?? 0) ?: (int) ($team['strength_overall_home'] ?? 0),
                'att_a' => (int) ($team['strength_attack_away'] ?? 0) ?: (int) ($team['strength_overall_away'] ?? 0),
                'def_h' => (int) ($team['strength_defence_home'] ?? 0) ?: (int) ($team['strength_overall_home'] ?? 0),
                'def_a' => (int) ($team['strength_defence_away'] ?? 0) ?: (int) ($team['strength_overall_away'] ?? 0),
                'overall_h' => (int) ($team['strength_overall_home'] ?? 0),
                'overall_a' => (int) ($team['strength_overall_away'] ?? 0),
                'table_pos' => (int) ($team['position'] ?? 0),
            ],
            'fixtures' => $fixtures,
            // Back-compat keys used by older deterministic callers.
            'chance_next' => $chance,
            'ppg_legacy' => (string) ($element['points_per_game'] ?? '0.0'),
        ];
    }

    /** @param array<string, mixed> $player */
    public static function deterministicScore(array $player): array
    {
        return PlayerProjectionEngine::score($player);
    }

    /**
     * Fixture projections for an individual player profile page.
     *
     * @param array<string, mixed> $element
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $teamsById
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $allFixtures
     * @return array{projections:list<float>,projections_by_event:array<int,float>,expected_minutes:int,model:string}
     */
    public static function profileProjections(
        array $element,
        array $summary,
        array $teamsById,
        array $events,
        array $allFixtures
    ): array {
        $eventId = 1;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!empty($event['is_next'])) {
                $eventId = (int) ($event['id'] ?? 1);
                break;
            }
            if (!empty($event['is_current'])) {
                $eventId = (int) ($event['id'] ?? 1);
            }
        }

        $fixtureCtx = self::buildFixtureContext($allFixtures, $eventId, $teamsById, $events);
        $card = self::buildPlayerCard($element, $teamsById, $fixtureCtx, $summary);
        $score = self::deterministicScore($card);
        $teamId = (int) ($element['team'] ?? 0);

        $fixtures = array_values($fixtureCtx['by_team'][$teamId] ?? []);
        $fixtures = array_slice($fixtures, 0, PlayerProjectionEngine::HORIZON);
        $projections = array_values($score['projections'] ?? []);
        $projectionsByEvent = [];
        foreach ($fixtures as $i => $fx) {
            $event = (int) ($fx['event'] ?? 0);
            if ($event > 0 && array_key_exists($i, $projections)) {
                $projectionsByEvent[$event] = (float) $projections[$i];
            }
        }

        return [
            'projections' => $projections,
            'projections_by_event' => $projectionsByEvent,
            'expected_minutes' => self::profileExpectedMinutes($element),
            'model' => (string) ($score['model'] ?? PlayerProjectionEngine::MODEL),
        ];
    }

    /** @param array<string, mixed> $element */
    private static function profileExpectedMinutes(array $element): int
    {
        $starts = max(1, (int) ($element['starts'] ?? 0));
        $minutes = (int) ($element['minutes'] ?? 0);
        $avg = (int) round($minutes / $starts);
        $chance = $element['chance_of_playing_next_round'] ?? null;
        if ($chance !== null && $chance !== '') {
            $avg = (int) round($avg * max(0.0, min(1.0, ((float) $chance) / 100.0)));
        }

        return max(0, min(90, $avg));
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $fixtures
     * @return array<int, array{games:int,avg_pts:float,cs_rate:float}>
     */
    private static function summariseH2H(array $history, array $fixtures): array
    {
        $oppIds = [];
        foreach ($fixtures as $fx) {
            if (!empty($fx['blank'])) {
                continue;
            }
            $opp = (int) ($fx['opp_id'] ?? 0);
            if ($opp > 0) {
                $oppIds[$opp] = true;
            }
            foreach (($fx['leg_rows'] ?? []) as $leg) {
                $legOpp = (int) ($leg['opp_id'] ?? 0);
                if ($legOpp > 0) {
                    $oppIds[$legOpp] = true;
                }
            }
        }
        if (!$oppIds) {
            return [];
        }

        /** @var array<int, array{games:int,pts:float,cs:int}> $acc */
        $acc = [];
        foreach ($history as $row) {
            $opp = (int) ($row['opponent_team'] ?? 0);
            if ($opp < 1 || !isset($oppIds[$opp])) {
                continue;
            }
            if (!isset($acc[$opp])) {
                $acc[$opp] = ['games' => 0, 'pts' => 0.0, 'cs' => 0];
            }
            $acc[$opp]['games']++;
            $acc[$opp]['pts'] += (float) ($row['total_points'] ?? 0);
            $acc[$opp]['cs'] += (int) ($row['clean_sheets'] ?? 0);
        }

        $out = [];
        foreach ($acc as $opp => $row) {
            $n = max(1, $row['games']);
            $out[$opp] = [
                'games' => $row['games'],
                'avg_pts' => round($row['pts'] / $n, 2),
                'cs_rate' => round($row['cs'] / $n, 2),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array{
     *   games:int,
     *   avg_mins:float,
     *   avg_pts:float,
     *   starts:int,
     *   xgi:float,
     *   cs_rate:float,
     *   goals:float,
     *   assists:float,
     *   avg_gc:float,
     *   avg_bonus:float
     * }
     */
    private static function summariseRecentHistory(array $history): array
    {
        $slice = array_slice($history, -5);
        if (!$slice) {
            return [
                'games' => 0,
                'avg_mins' => 0.0,
                'avg_pts' => 0.0,
                'starts' => 0,
                'xgi' => 0.0,
                'cs_rate' => 0.0,
                'goals' => 0.0,
                'assists' => 0.0,
                'avg_gc' => 0.0,
                'avg_bonus' => 0.0,
            ];
        }
        $mins = 0;
        $pts = 0;
        $starts = 0;
        $xgi = 0.0;
        $cs = 0;
        $goals = 0;
        $assists = 0;
        $gc = 0;
        $bonus = 0;
        foreach ($slice as $row) {
            $mins += (int) ($row['minutes'] ?? 0);
            $pts += (int) ($row['total_points'] ?? 0);
            $starts += (int) ($row['starts'] ?? 0);
            $xgi += (float) ($row['expected_goal_involvements'] ?? 0);
            $cs += (int) ($row['clean_sheets'] ?? 0);
            $goals += (int) ($row['goals_scored'] ?? 0);
            $assists += (int) ($row['assists'] ?? 0);
            $gc += (int) ($row['goals_conceded'] ?? 0);
            $bonus += (int) ($row['bonus'] ?? 0);
        }
        $n = count($slice);
        return [
            'games' => $n,
            'avg_mins' => round($mins / $n, 1),
            'avg_pts' => round($pts / $n, 2),
            'starts' => $starts,
            'xgi' => round($xgi, 2),
            'cs_rate' => round($cs / $n, 2),
            'goals' => round($goals / $n, 2),
            'assists' => round($assists / $n, 2),
            'avg_gc' => round($gc / $n, 2),
            'avg_bonus' => round($bonus / $n, 2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $past
     * @return array{seasons:int,ppg:float,minutes:int,points:int}
     */
    private static function summarisePastSeasons(array $past): array
    {
        if (!$past) {
            return ['seasons' => 0, 'ppg' => 0.0, 'minutes' => 0, 'points' => 0];
        }
        $slice = array_slice($past, -3);
        $points = 0;
        $minutes = 0;
        $starts = 0;
        foreach ($slice as $row) {
            $points += (int) ($row['total_points'] ?? 0);
            $minutes += (int) ($row['minutes'] ?? 0);
            $starts += (int) ($row['starts'] ?? 0);
        }
        $games = max(1, (int) round($minutes / 90));
        return [
            'seasons' => count($slice),
            'ppg' => round($points / $games, 2),
            'minutes' => $minutes,
            'points' => $points,
        ];
    }

    /** @param array<string, mixed> $element */
    private static function minutesCertainty(array $element, array $recent, float $avail, string $status): float
    {
        $score = 0.55;
        $avgMins = (float) ($recent['avg_mins'] ?? 0);
        $games = (int) ($recent['games'] ?? 0);
        $minutesTotal = (int) ($element['minutes'] ?? 0);
        $price = ((int) ($element['now_cost'] ?? 0)) / 10.0;
        $epNext = (float) ($element['ep_next'] ?? 0);
        $pos = (int) ($element['element_type'] ?? 0);

        if ($games >= 3) {
            if ($avgMins >= 80) {
                $score = 0.95;
            } elseif ($avgMins >= 60) {
                $score = 0.82;
            } elseif ($avgMins >= 45) {
                $score = 0.65;
            } elseif ($avgMins >= 20) {
                $score = 0.4;
            } else {
                $score = 0.2;
            }
        } else {
            // Early season: lean on starts_per_90, price/EP, and recent full appearances.
            $startsPer90 = (float) ($element['starts_per_90'] ?? 0);
            if ($games >= 1 && $avgMins >= 85) {
                $score = 0.9;
            } elseif ($minutesTotal >= 90 && $startsPer90 >= 0.9) {
                $score = 0.85;
            } elseif ($minutesTotal >= 60) {
                $score = 0.68;
            } elseif ($minutesTotal > 0) {
                // Short cameo: premiums still projected as likely involved (hub-style).
                if ($price >= 7.0 || $epNext >= 4.0) {
                    $score = 0.74;
                } elseif ($price >= 6.5 || $epNext >= 3.0) {
                    $score = 0.62;
                } else {
                    $score = 0.45;
                }
            } elseif ($price >= 7.0 || $epNext >= 4.0) {
                // Unused premium / new signing: not a 1.4-point ghost.
                $score = 0.78;
            } elseif ($price >= 6.0 || $epNext >= 2.8) {
                $score = 0.64;
            } else {
                $score = 0.35;
            }
        }

        // Unused backup GK / out-of-squad: Hub shows 0.0 across the horizon.
        if ($pos === 1 && $minutesTotal === 0 && $epNext < 1.5 && $price <= 4.5) {
            $score = min($score, 0.08);
        }

        // Rotational DEF who has already featured: Hub still projects starter-like
        // minutes (Konsa-class), not a 1.8 ghost.
        if ($pos === 2 && $status === 'a' && $minutesTotal >= 45 && $games >= 1 && $avgMins < 55) {
            if ($price >= 4.0 && $price <= 5.5) {
                $score = max($score, 0.78);
            } elseif ($price > 5.5) {
                $score = max($score, 0.82);
            }
        }

        if ($status === 'd') {
            $score *= 0.7;
        }
        if ($status === 'i' || $status === 's') {
            $score *= 0.25;
        }
        return round(max(0.05, min(1.0, $score * max(0.2, $avail))), 2);
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @param list<array<string, mixed>> $events
     * @return array{by_team:array<int,list<array<string,mixed>>>,counts:array<int,array<int,int>>}
     */
    private static function buildFixtureContext(array $fixtures, int $fromEvent, array $teamsById, array $events): array
    {
        $eventIds = [];
        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            if ($id >= $fromEvent) {
                $eventIds[] = $id;
            }
        }
        $eventIds = array_slice(array_values(array_unique($eventIds)), 0, 8);
        $teamForm = self::teamFormFromFixtures($fixtures);
        $scoring = self::teamScoringRatesFromFixtures($fixtures);

        /** @var array<int, array<int, int>> $counts */
        $counts = [];
        /** @var array<int, list<array<string, mixed>>> $rawByTeam */
        $rawByTeam = [];

        foreach ($fixtures as $fx) {
            $event = isset($fx['event']) ? (int) $fx['event'] : 0;
            if ($event < $fromEvent || !empty($fx['finished'])) {
                continue;
            }
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home < 1 || $away < 1) {
                continue;
            }
            $counts[$home][$event] = ($counts[$home][$event] ?? 0) + 1;
            $counts[$away][$event] = ($counts[$away][$event] ?? 0) + 1;

            $kickoff = (string) ($fx['kickoff_time'] ?? '');
            $homeTeam = $teamsById[$home] ?? [];
            $awayTeam = $teamsById[$away] ?? [];
            $homeRates = $scoring[$home] ?? ['gf' => 1.2, 'ga' => 1.2];
            $awayRates = $scoring[$away] ?? ['gf' => 1.2, 'ga' => 1.2];

            $rawByTeam[$home][] = [
                'event' => $event,
                'label' => ((string) ($awayTeam['short_name'] ?? '')) . ' (H)',
                'fdr' => (int) ($fx['team_h_difficulty'] ?? 3),
                'home' => true,
                'kickoff' => $kickoff,
                'opp_id' => $away,
                'opp_att' => self::teamStrength($awayTeam, 'attack', false),
                'opp_def' => self::teamStrength($awayTeam, 'defence', false),
                'team_att' => self::teamStrength($homeTeam, 'attack', true),
                'team_def' => self::teamStrength($homeTeam, 'defence', true),
                'opp_gf' => $awayRates['gf'],
                'opp_ga' => $awayRates['ga'],
                'team_gf' => $homeRates['gf'],
                'team_ga' => $homeRates['ga'],
                'opp_form' => $teamForm[$away] ?? 1.0,
                'team_form' => $teamForm[$home] ?? 1.0,
            ];
            $rawByTeam[$away][] = [
                'event' => $event,
                'label' => ((string) ($homeTeam['short_name'] ?? '')) . ' (A)',
                'fdr' => (int) ($fx['team_a_difficulty'] ?? 3),
                'home' => false,
                'kickoff' => $kickoff,
                'opp_id' => $home,
                'opp_att' => self::teamStrength($homeTeam, 'attack', true),
                'opp_def' => self::teamStrength($homeTeam, 'defence', true),
                'team_att' => self::teamStrength($awayTeam, 'attack', false),
                'team_def' => self::teamStrength($awayTeam, 'defence', false),
                'opp_gf' => $homeRates['gf'],
                'opp_ga' => $homeRates['ga'],
                'team_gf' => $awayRates['gf'],
                'team_ga' => $awayRates['ga'],
                'opp_form' => $teamForm[$home] ?? 1.0,
                'team_form' => $teamForm[$away] ?? 1.0,
            ];
        }

        $byTeam = [];
        foreach ($teamsById as $teamId => $_team) {
            $teamId = (int) $teamId;
            $rows = $rawByTeam[$teamId] ?? [];
            usort($rows, static function ($a, $b) {
                $ae = $a['event'] <=> $b['event'];
                if ($ae !== 0) {
                    return $ae;
                }
                return strcmp((string) ($a['kickoff'] ?? ''), (string) ($b['kickoff'] ?? ''));
            });

            // Attach congestion + double legs, then collapse to next 5 slots (GW-aware with blanks).
            $prevKick = null;
            foreach ($rows as &$row) {
                $hours = 168.0;
                if ($prevKick && !empty($row['kickoff'])) {
                    $t0 = strtotime($prevKick);
                    $t1 = strtotime((string) $row['kickoff']);
                    if ($t0 && $t1 && $t1 > $t0) {
                        $hours = ($t1 - $t0) / 3600;
                    }
                }
                $row['congestion_hours'] = (int) round($hours);
                $row['legs'] = 1;
                $row['blank'] = false;
                $prevKick = (string) ($row['kickoff'] ?? $prevKick);
            }
            unset($row);

            $slots = [];
            foreach ($eventIds as $eid) {
                $legs = array_values(array_filter($rows, static fn ($r) => (int) $r['event'] === $eid));
                if (!$legs) {
                    $slots[] = [
                        'event' => $eid,
                        'label' => 'BLANK',
                        'fdr' => 3,
                        'home' => null,
                        'kickoff' => null,
                        'opp_att' => 0,
                        'opp_def' => 0,
                        'team_att' => 0,
                        'team_def' => 0,
                        'congestion_hours' => 168,
                        'legs' => 0,
                        'blank' => true,
                    ];
                    continue;
                }
                // Keep every DGW leg so xP can score opponents separately.
                $first = $legs[0];
                $first['legs'] = count($legs);
                $first['label'] = implode(' + ', array_map(static fn ($l) => (string) $l['label'], $legs));
                $first['fdr'] = (int) round(array_sum(array_map(static fn ($l) => (int) $l['fdr'], $legs)) / count($legs));
                $first['blank'] = false;
                $first['leg_rows'] = $legs;
                $slots[] = $first;
                if (count($slots) >= PlayerProjectionEngine::HORIZON) {
                    break;
                }
            }

            // If event list was short, fall back to raw fixtures.
            if (count($slots) < PlayerProjectionEngine::HORIZON) {
                foreach ($rows as $row) {
                    if (count($slots) >= PlayerProjectionEngine::HORIZON) {
                        break;
                    }
                    $already = false;
                    foreach ($slots as $s) {
                        if ((int) $s['event'] === (int) $row['event'] && empty($s['blank'])) {
                            $already = true;
                            break;
                        }
                    }
                    if (!$already) {
                        $slots[] = $row;
                    }
                }
            }

            $byTeam[$teamId] = array_slice($slots, 0, PlayerProjectionEngine::HORIZON);
        }

        return ['by_team' => $byTeam, 'counts' => $counts];
    }

    /**
     * Rolling team form as average points per finished match (0–3).
     *
     * @param list<array<string, mixed>> $fixtures
     * @return array<int, float>
     */
    /**
     * Season goals for / against per game from finished fixtures.
     * Hub-style CS and attack EV need this when FPL zeroes attack/defence strengths.
     *
     * @param list<array<string, mixed>> $fixtures
     * @return array<int, array{gf:float,ga:float}>
     */
    private static function teamScoringRatesFromFixtures(array $fixtures): array
    {
        /** @var array<int, list<int>> $gf */
        $gf = [];
        /** @var array<int, list<int>> $ga */
        $ga = [];
        foreach ($fixtures as $fx) {
            if (empty($fx['finished'])) {
                continue;
            }
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home < 1 || $away < 1) {
                continue;
            }
            $hs = (int) ($fx['team_h_score'] ?? 0);
            $as = (int) ($fx['team_a_score'] ?? 0);
            $gf[$home][] = $hs;
            $ga[$home][] = $as;
            $gf[$away][] = $as;
            $ga[$away][] = $hs;
        }

        $out = [];
        $teamIds = array_unique(array_merge(array_keys($gf), array_keys($ga)));
        foreach ($teamIds as $teamId) {
            $gfs = $gf[$teamId] ?? [];
            $gas = $ga[$teamId] ?? [];
            $n = max(1, count($gfs));
            $out[(int) $teamId] = [
                'gf' => round(array_sum($gfs) / $n, 3),
                'ga' => round(array_sum($gas) / $n, 3),
            ];
        }
        return $out;
    }

    private static function teamFormFromFixtures(array $fixtures): array
    {
        /** @var array<int, list<float>> $points */
        $points = [];
        foreach ($fixtures as $fx) {
            if (empty($fx['finished']) && empty($fx['finished_provisional'])) {
                continue;
            }
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            $hs = $fx['team_h_score'] ?? null;
            $as = $fx['team_a_score'] ?? null;
            if ($home < 1 || $away < 1 || $hs === null || $as === null) {
                continue;
            }
            $hs = (int) $hs;
            $as = (int) $as;
            $homePts = $hs > $as ? 3.0 : ($hs === $as ? 1.0 : 0.0);
            $awayPts = $as > $hs ? 3.0 : ($hs === $as ? 1.0 : 0.0);
            $points[$home][] = $homePts;
            $points[$away][] = $awayPts;
        }

        $out = [];
        foreach ($points as $teamId => $list) {
            $slice = array_slice($list, -6);
            $out[$teamId] = round(array_sum($slice) / max(1, count($slice)), 3);
        }
        return $out;
    }

    /**
     * Prefer attack/defence strengths; fall back to overall when FPL zeroes them.
     *
     * @param array<string, mixed> $team
     */
    private static function teamStrength(array $team, string $axis, bool $home): int
    {
        $side = $home ? 'home' : 'away';
        $specific = (int) ($team['strength_' . $axis . '_' . $side] ?? 0);
        if ($specific > 0) {
            return $specific;
        }
        return (int) ($team['strength_overall_' . $side] ?? 0);
    }
}
