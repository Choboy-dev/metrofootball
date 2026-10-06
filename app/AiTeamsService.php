<?php

declare(strict_types=1);

namespace App;

/**
 * Shared Premium AI Teams for the planning gameweek.
 * Four philosophies, one cache for every Premium member.
 */
final class AiTeamsService
{
    private const BUDGET = 100.0;
    private const MIN_STRENGTH = 90;
    private const CACHE_SECONDS = 1209600; // 14 days
    private const MAX_PER_CLUB = 3;

    /** @var list<array{id:string,title:string,lead:string}> */
    private const PHILOSOPHIES = [
        [
            'id' => 'value',
            'title' => 'Best value',
            'lead' => 'The strongest affordable picks in every position for this gameweek.',
        ],
        [
            'id' => 'attackers',
            'title' => 'Premium attackers',
            'lead' => 'Spend on top forwards, then fill the rest with sharp value.',
        ],
        [
            'id' => 'midfielders',
            'title' => 'Premium midfielders',
            'lead' => 'Build around the best premium midfielders, then balance the squad.',
        ],
        [
            'id' => 'defenders',
            'title' => 'Premium defenders',
            'lead' => 'Invest in the strongest premium defence, then complete the XI.',
        ],
    ];

    public static function cacheKey(int $eventId): string
    {
        return 'ai-teams-gw-v3-' . $eventId;
    }

    /** @return list<array{id:string,title:string,lead:string}> */
    public static function philosophies(): array
    {
        return self::PHILOSOPHIES;
    }

    /**
     * Build the initial 15 for a philosophy (no chip, no GW layout).
     *
     * @param list<array<string, mixed>> $pool
     * @return list<array<string, mixed>>
     */
    public static function buildInitialSquad(array $pool, string $philosophyId): array
    {
        $squad = self::selectSquad($pool, $philosophyId);
        $ordered = self::optimiseXi($squad);
        $scores = TeamScoreComposer::compose($ordered, self::scoreMapFromSquad($ordered));

        $guard = 0;
        while (($scores['strength']['gw'] ?? 0) < self::MIN_STRENGTH && $guard < 12) {
            $guard++;
            $upgraded = self::tryUpgrade($ordered, $pool, $philosophyId);
            if ($upgraded === null) {
                break;
            }
            $ordered = self::optimiseXi($upgraded);
            $scores = TeamScoreComposer::compose($ordered, self::scoreMapFromSquad($ordered));
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $bootstrap
     * @return list<array<string, mixed>>
     */
    public static function buildPlayerPool(array $bootstrap, int $eventId): array
    {
        $scoreMap = PlayerAiScout::ensureScores($eventId);
        $teamsById = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $fixturesByTeam = self::fixturesByTeam(
            FplClient::fetchFixtures(),
            $eventId,
            $teamsById
        );

        $pool = [];
        foreach ($bootstrap['elements'] as $element) {
            if (!is_array($element)) {
                continue;
            }
            $status = (string) ($element['status'] ?? 'a');
            if (in_array($status, ['u', 's'], true)) {
                continue;
            }
            $chance = $element['chance_of_playing_next_round'] ?? null;
            if ($chance !== null && (int) $chance <= 25) {
                continue;
            }

            $card = self::mapPlayer($element, $teamsById, $fixturesByTeam, $scoreMap);
            if ($card['proj'] <= 0.15) {
                continue;
            }
            $pool[] = $card;
        }

        return $pool;
    }

    /**
     * Refresh prices, fixtures and scout rows for one gameweek.
     *
     * @param list<array<string, mixed>> $squad
     * @param array<int, float> $purchasePrices id => purchase price
     * @return list<array<string, mixed>>
     */
    public static function hydrateSquadForGameweek(
        array $squad,
        array $bootstrap,
        int $eventId,
        array $purchasePrices = []
    ): array {
        $elementsById = [];
        foreach ($bootstrap['elements'] as $element) {
            if (is_array($element)) {
                $elementsById[(int) ($element['id'] ?? 0)] = $element;
            }
        }
        $teamsById = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $scoreMap = PlayerAiScout::ensureScores($eventId);
        $fixturesByTeam = self::fixturesByTeam(FplClient::fetchFixtures(), $eventId, $teamsById);

        $out = [];
        foreach ($squad as $player) {
            if (!is_array($player)) {
                continue;
            }
            $id = (int) ($player['id'] ?? 0);
            $element = $elementsById[$id] ?? null;
            if (!$element) {
                $out[] = $player;
                continue;
            }
            $card = self::mapPlayer($element, $teamsById, $fixturesByTeam, $scoreMap);
            $purchase = (float) ($purchasePrices[$id] ?? $player['purchase_price'] ?? $card['now_cost']);
            $card['purchase_price'] = round($purchase, 1);
            $card['selling_price'] = self::sellingPrice($purchase, (float) $card['now_cost']);
            $out[] = array_merge($player, $card);
        }

        return $out;
    }

    public static function sellingPrice(float $purchase, float $current): float
    {
        if ($current <= $purchase) {
            return round($current, 1);
        }
        $rise = $current - $purchase;
        $profit = floor($rise * 5.0) / 10.0;

        return round($purchase + $profit, 1);
    }

    /**
     * @param list<array<string, mixed>> $ordered
     * @return array{pitch: array<string, list<array<string, mixed>>>, bench: list<array<string, mixed>>}
     */
    public static function splitPitchBench(array $ordered): array
    {
        $pitch = ['gkp' => [], 'def' => [], 'mid' => [], 'fwd' => []];
        $bench = [];
        foreach ($ordered as $player) {
            $pos = (int) ($player['position'] ?? 99);
            if ($pos > 11) {
                $bench[] = $player;
                continue;
            }
            $row = (string) ($player['row'] ?? 'fwd');
            if (!isset($pitch[$row])) {
                $row = 'fwd';
            }
            $pitch[$row][] = $player;
        }

        return ['pitch' => $pitch, 'bench' => $bench];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getOrBuild(): array
    {
        return AiSeasonService::getPayload();
    }

    /**
     * @param array<string, mixed> $bootstrap
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function buildFresh(array $bootstrap, array $event): array
    {
        $eventId = (int) $event['id'];
        $scoreMap = PlayerAiScout::ensureScores($eventId);
        $teamsById = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $fixturesByTeam = self::fixturesByTeam(
            FplClient::fetchFixtures(),
            $eventId,
            $teamsById
        );

        $pool = [];
        foreach ($bootstrap['elements'] as $element) {
            if (!is_array($element)) {
                continue;
            }
            $status = (string) ($element['status'] ?? 'a');
            if (in_array($status, ['u', 's'], true)) {
                continue;
            }
            $chance = $element['chance_of_playing_next_round'] ?? null;
            if ($chance !== null && (int) $chance <= 25) {
                continue;
            }

            $card = self::mapPlayer($element, $teamsById, $fixturesByTeam, $scoreMap);
            if ($card['proj'] <= 0.15) {
                continue;
            }
            $pool[] = $card;
        }

        $teams = [];
        foreach (self::PHILOSOPHIES as $meta) {
            $teams[] = self::buildPhilosophyTeam($pool, $meta);
        }

        return [
            'event' => [
                'id' => $eventId,
                'name' => (string) ($event['name'] ?? ('GW' . $eventId)),
            ],
            'budget' => self::BUDGET,
            'min_strength' => self::MIN_STRENGTH,
            'teams' => $teams,
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @param array{id:string,title:string,lead:string} $meta
     * @return array<string, mixed>
     */
    private static function buildPhilosophyTeam(array $pool, array $meta): array
    {
        $squad = self::selectSquad($pool, $meta['id']);
        $ordered = self::optimiseXi($squad);
        $scores = TeamScoreComposer::compose($ordered, self::scoreMapFromSquad($ordered));

        // Upgrade loop if strength is under the Premium bar.
        $guard = 0;
        while (($scores['strength']['gw'] ?? 0) < self::MIN_STRENGTH && $guard < 12) {
            $guard++;
            $upgraded = self::tryUpgrade($ordered, $pool, $meta['id']);
            if ($upgraded === null) {
                break;
            }
            $ordered = self::optimiseXi($upgraded);
            $scores = TeamScoreComposer::compose($ordered, self::scoreMapFromSquad($ordered));
        }

        $chip = self::chooseChip($ordered, $scores);
        $ordered = self::applyChipToSquad($ordered, $chip);
        $predicted = self::predictedWithChip($ordered, $scores, $chip);

        $cost = 0.0;
        foreach ($ordered as $player) {
            $cost += (float) ($player['now_cost'] ?? 0);
        }

        $pitch = ['gkp' => [], 'def' => [], 'mid' => [], 'fwd' => []];
        $bench = [];
        foreach ($ordered as $player) {
            $pos = (int) ($player['position'] ?? 99);
            if ($pos > 11) {
                $bench[] = $player;
                continue;
            }
            $row = (string) ($player['row'] ?? 'fwd');
            if (!isset($pitch[$row])) {
                $row = 'fwd';
            }
            $pitch[$row][] = $player;
        }

        return [
            'id' => $meta['id'],
            'title' => $meta['title'],
            'lead' => $meta['lead'],
            'strength' => (int) ($scores['strength']['gw'] ?? 0),
            'predicted_points' => $predicted,
            'cost' => round($cost, 1),
            'bank' => round(max(0, self::BUDGET - $cost), 1),
            'formation' => self::formationLabel($pitch),
            'meets_strength' => ((int) ($scores['strength']['gw'] ?? 0)) >= self::MIN_STRENGTH,
            'chip' => $chip,
            'pitch' => $pitch,
            'bench' => $bench,
            'squad' => $ordered,
        ];
    }

    /**
     * Pick a chip for this AI squad. Wildcard is never used (teams refresh each GW).
     *
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $scores
     * @return array{action:string,chip:?string,label:string,why:string,uplift:float}
     */
    private static function chooseChip(array $squad, array $scores): array
    {
        $benchProj = 0.0;
        $captainProj = 0.0;
        $blankish = 0;
        $xiCount = 0;

        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? ($player['proj'] ?? 0));
            $fixtures = is_array($player['fixtures'] ?? null) ? $player['fixtures'] : [];
            $next = $fixtures[0] ?? null;

            if ($pos <= 11) {
                $xiCount++;
                if (!empty($player['is_captain'])) {
                    $captainProj = $proj;
                }
                if ($next === null) {
                    $blankish++;
                }
            } else {
                $benchProj += $proj;
            }
        }

        $predGw = (float) ($scores['predicted_points']['gw'] ?? 0);
        $hold = [
            'action' => 'hold',
            'chip' => null,
            'label' => 'No chip this week',
            'why' => 'This squad looks strong without a chip. Save Bench Boost and Triple Captain for a bigger week.',
            'uplift' => 0.0,
        ];

        // Free Hit only if the XI is broken by blanks (rare for AI builds).
        if ($blankish >= 3 && $xiCount >= 8) {
            return [
                'action' => 'play',
                'chip' => 'freehit',
                'label' => 'Play Free Hit',
                'why' => 'Too many starters blank this week. Free Hit can field a full set of players who all play.',
                'uplift' => 0.0,
            ];
        }

        $bbUplift = round($benchProj, 1);
        $tcUplift = round($captainProj, 1); // already counted x2; TC adds one more copy

        $bbOk = $bbUplift >= 10.0 && $predGw >= 50;
        $tcOk = $tcUplift >= 6.5 && $predGw >= 52;

        if ($bbOk && (!$tcOk || $bbUplift >= $tcUplift)) {
            return [
                'action' => 'play',
                'chip' => 'bboost',
                'label' => 'Play Bench Boost',
                'why' => 'The bench projects about ' . number_format($bbUplift, 1) . ' points. Bench Boost turns that into a bigger gameweek total.',
                'uplift' => $bbUplift,
            ];
        }

        if ($tcOk) {
            return [
                'action' => 'play',
                'chip' => '3xc',
                'label' => 'Play Triple Captain',
                'why' => 'The captain projects ' . number_format($tcUplift, 1) . ' points. Triple Captain can turn that into a huge score.',
                'uplift' => $tcUplift,
            ];
        }

        // Soft play: still recommend the better chip when uplift is clear enough.
        if ($bbUplift >= 8.0 && $bbUplift >= $tcUplift) {
            return [
                'action' => 'play',
                'chip' => 'bboost',
                'label' => 'Play Bench Boost',
                'why' => 'The bench looks useful this week. Bench Boost can add about ' . number_format($bbUplift, 1) . ' extra points.',
                'uplift' => $bbUplift,
            ];
        }

        if ($tcUplift >= 6.0) {
            return [
                'action' => 'play',
                'chip' => '3xc',
                'label' => 'Play Triple Captain',
                'why' => 'The captain is the standout pick this week. Triple Captain is the chip to play.',
                'uplift' => $tcUplift,
            ];
        }

        return $hold;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array{action:string,chip:?string,label:string,why:string,uplift:float} $chip
     * @return list<array<string, mixed>>
     */
    private static function applyChipToSquad(array $squad, array $chip): array
    {
        $code = (string) ($chip['chip'] ?? '');
        foreach ($squad as &$player) {
            if (!is_array($player)) {
                continue;
            }
            $pos = (int) ($player['position'] ?? 99);
            if ($code === '3xc' && !empty($player['is_captain']) && $pos <= 11) {
                $player['multiplier'] = 3;
            } elseif ($code === 'bboost' && $pos > 11) {
                $player['multiplier'] = 1;
                $player['bench_boost'] = true;
            }
        }
        unset($player);

        return $squad;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $scores
     * @param array{action:string,chip:?string,label:string,why:string,uplift:float} $chip
     */
    private static function predictedWithChip(array $squad, array $scores, array $chip): float
    {
        $pred = (float) ($scores['predicted_points']['gw'] ?? 0);
        $code = (string) ($chip['chip'] ?? '');

        if ($code === '3xc') {
            foreach ($squad as $player) {
                if (!empty($player['is_captain']) && (int) ($player['position'] ?? 99) <= 11) {
                    $base = (float) (($player['ai']['projections'][0] ?? null) ?? ($player['proj'] ?? 0));
                    $pred += $base; // was x2, now x3
                    break;
                }
            }
        } elseif ($code === 'bboost') {
            foreach ($squad as $player) {
                if ((int) ($player['position'] ?? 99) > 11) {
                    $pred += (float) (($player['ai']['projections'][0] ?? null) ?? ($player['proj'] ?? 0));
                }
            }
        }

        return round($pred, 1);
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @return list<array<string, mixed>>
     */
    private static function selectSquad(array $pool, string $philosophy): array
    {
        $needs = [1 => 2, 2 => 5, 3 => 5, 4 => 3];
        $picked = [];
        $clubCount = [];
        $spent = 0.0;

        $byType = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($pool as $player) {
            $type = (int) ($player['element_type'] ?? 0);
            if (!isset($byType[$type])) {
                continue;
            }
            $byType[$type][] = $player;
        }
        foreach ($byType as $type => $list) {
            usort($list, static function (array $a, array $b) use ($philosophy): int {
                return self::rankScore($b, $philosophy) <=> self::rankScore($a, $philosophy)
                    ?: ((float) $b['proj'] <=> (float) $a['proj']);
            });
            $byType[$type] = $list;
        }

        // Premium focus: seed expensive stars first.
        if ($philosophy === 'attackers') {
            self::seedPremium($picked, $clubCount, $spent, $byType[4], $needs, 4, 3, 7.5);
        } elseif ($philosophy === 'midfielders') {
            self::seedPremium($picked, $clubCount, $spent, $byType[3], $needs, 3, 4, 8.0);
        } elseif ($philosophy === 'defenders') {
            self::seedPremium($picked, $clubCount, $spent, $byType[2], $needs, 2, 4, 5.0);
        }

        $slots = [];
        foreach ($needs as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $slots[] = $type;
            }
        }

        // Fill remaining cheapest-first for floors, pick highest ranked that fits.
        while (array_sum($needs) > 0) {
            $floor = self::minRemainingCost($byType, $needs, $picked);
            $remainingSlots = array_sum($needs);
            $budgetLeft = self::BUDGET - $spent;
            $best = null;
            $bestType = null;
            $bestScore = -INF;

            foreach ($needs as $type => $count) {
                if ($count < 1) {
                    continue;
                }
                foreach ($byType[$type] as $player) {
                    if (isset($picked[$player['id']])) {
                        continue;
                    }
                    $teamId = (int) $player['team'];
                    if (($clubCount[$teamId] ?? 0) >= self::MAX_PER_CLUB) {
                        continue;
                    }
                    $cost = (float) $player['now_cost'];
                    $otherFloor = $floor - self::cheapestUnusedCost($byType[$type], $picked, $type);
                    // After this pick, need enough for remaining-1 slots.
                    $needAfter = max(0, $remainingSlots - 1);
                    $minAfter = self::minCostForCount($byType, $needs, $picked, $type, $needAfter);
                    if ($budgetLeft - $cost + 0.001 < $minAfter) {
                        continue;
                    }
                    $score = self::rankScore($player, $philosophy);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $player;
                        $bestType = $type;
                    }
                    break; // lists are pre-sorted
                }
            }

            if ($best === null || $bestType === null) {
                // Emergency: take absolute cheapest legal fill.
                $best = self::cheapestLegal($byType, $needs, $picked, $clubCount, $budgetLeft);
                if ($best === null) {
                    break;
                }
                $bestType = (int) $best['element_type'];
            }

            $picked[$best['id']] = $best;
            $spent += (float) $best['now_cost'];
            $teamId = (int) $best['team'];
            $clubCount[$teamId] = ($clubCount[$teamId] ?? 0) + 1;
            $needs[$bestType]--;
        }

        return array_values($picked);
    }

    /**
     * @param array<int, array<string, mixed>> $picked
     * @param array<int, int> $clubCount
     * @param list<array<string, mixed>> $candidates
     * @param array<int, int> $needs
     */
    private static function seedPremium(
        array &$picked,
        array &$clubCount,
        float &$spent,
        array $candidates,
        array &$needs,
        int $type,
        int $want,
        float $minCost
    ): void {
        $taken = 0;
        foreach ($candidates as $player) {
            if ($taken >= $want || ($needs[$type] ?? 0) < 1) {
                break;
            }
            if ((float) $player['now_cost'] < $minCost) {
                continue;
            }
            $teamId = (int) $player['team'];
            if (($clubCount[$teamId] ?? 0) >= self::MAX_PER_CLUB) {
                continue;
            }
            $cost = (float) $player['now_cost'];
            // Leave at least £3.9 * remaining rough floor.
            $remainingAfter = array_sum($needs) - 1;
            if (self::BUDGET - $spent - $cost < $remainingAfter * 3.9) {
                continue;
            }
            $picked[$player['id']] = $player;
            $spent += $cost;
            $clubCount[$teamId] = ($clubCount[$teamId] ?? 0) + 1;
            $needs[$type]--;
            $taken++;
        }
    }

    /**
     * @param array<string, mixed> $player
     */
    private static function rankScore(array $player, string $philosophy): float
    {
        $proj = (float) ($player['proj'] ?? 0);
        $cost = max(0.1, (float) ($player['now_cost'] ?? 4));
        $value = $proj / $cost;
        $type = (int) ($player['element_type'] ?? 0);

        return match ($philosophy) {
            'attackers' => $type === 4
                ? ($proj * 4.0 + $cost * 0.35)
                : ($value * 55.0 + $proj * 0.2),
            'midfielders' => $type === 3
                ? ($proj * 4.0 + $cost * 0.3)
                : ($value * 55.0 + $proj * 0.2),
            'defenders' => $type === 2
                ? ($proj * 4.2 + $cost * 0.45)
                : ($value * 55.0 + $proj * 0.2),
            default => ($value * 100.0) + ($proj * 0.35),
        };
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return list<array<string, mixed>>
     */
    public static function optimiseXi(array $squad): array
    {
        if (count($squad) < 11) {
            return array_values(array_map(static function (array $p, int $i): array {
                $p['position'] = $i + 1;
                $p['is_captain'] = false;
                $p['is_vice_captain'] = false;
                $p['multiplier'] = $i < 11 ? 1 : 0;
                return $p;
            }, $squad, array_keys($squad)));
        }

        $byPos = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($squad as $player) {
            $type = (int) ($player['element_type'] ?? 0);
            if (isset($byPos[$type])) {
                $byPos[$type][] = $player;
            }
        }
        foreach ($byPos as &$list) {
            usort($list, static fn ($a, $b) => ((float) $b['proj'] <=> (float) $a['proj']));
        }
        unset($list);

        $formations = [
            [3, 4, 3], [3, 5, 2], [4, 4, 2], [4, 3, 3], [4, 5, 1], [5, 4, 1], [5, 3, 2], [5, 2, 3],
        ];
        $best = null;
        $bestTotal = -INF;
        foreach ($formations as [$def, $mid, $fwd]) {
            if (count($byPos[1]) < 1 || count($byPos[2]) < $def || count($byPos[3]) < $mid || count($byPos[4]) < $fwd) {
                continue;
            }
            $starters = array_merge(
                array_slice($byPos[1], 0, 1),
                array_slice($byPos[2], 0, $def),
                array_slice($byPos[3], 0, $mid),
                array_slice($byPos[4], 0, $fwd)
            );
            $total = 0.0;
            foreach ($starters as $p) {
                $total += (float) $p['proj'];
            }
            if ($total > $bestTotal) {
                $bestTotal = $total;
                $best = $starters;
            }
        }
        if ($best === null) {
            $best = array_slice($squad, 0, 11);
        }

        $starterIds = [];
        foreach ($best as $p) {
            $starterIds[(int) $p['id']] = true;
        }

        usort($best, static fn ($a, $b) => ((int) $a['element_type'] <=> (int) $b['element_type'])
            ?: ((float) $b['proj'] <=> (float) $a['proj']));

        $bench = [];
        foreach ($squad as $player) {
            if (!isset($starterIds[(int) $player['id']])) {
                $bench[] = $player;
            }
        }
        usort($bench, static function (array $a, array $b): int {
            if ((int) $a['element_type'] === 1 && (int) $b['element_type'] !== 1) {
                return -1;
            }
            if ((int) $b['element_type'] === 1 && (int) $a['element_type'] !== 1) {
                return 1;
            }
            return (float) $b['proj'] <=> (float) $a['proj'];
        });

        $captain = null;
        foreach ($best as $player) {
            if ($captain === null || (float) $player['proj'] > (float) $captain['proj']) {
                $captain = $player;
            }
        }
        $vice = null;
        foreach ($best as $player) {
            if ($captain && (int) $player['id'] === (int) $captain['id']) {
                continue;
            }
            if ($vice === null || (float) $player['proj'] > (float) $vice['proj']) {
                $vice = $player;
            }
        }

        $ordered = array_merge($best, $bench);
        foreach ($ordered as $i => &$player) {
            $player['position'] = $i + 1;
            $player['is_captain'] = $captain && (int) $player['id'] === (int) $captain['id'];
            $player['is_vice_captain'] = $vice && (int) $player['id'] === (int) $vice['id'];
            $player['multiplier'] = $i < 11 ? ($player['is_captain'] ? 2 : 1) : 0;
        }
        unset($player);

        return $ordered;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $pool
     * @return list<array<string, mixed>>|null
     */
    private static function tryUpgrade(array $squad, array $pool, string $philosophy): ?array
    {
        $cost = 0.0;
        $clubCount = [];
        $byId = [];
        foreach ($squad as $player) {
            $cost += (float) $player['now_cost'];
            $byId[(int) $player['id']] = $player;
            $teamId = (int) $player['team'];
            $clubCount[$teamId] = ($clubCount[$teamId] ?? 0) + 1;
        }
        $bank = self::BUDGET - $cost;

        $weak = null;
        foreach ($squad as $player) {
            if ((int) ($player['position'] ?? 99) > 11) {
                continue;
            }
            if ($philosophy === 'attackers' && (int) $player['element_type'] === 4) {
                continue;
            }
            if ($philosophy === 'midfielders' && (int) $player['element_type'] === 3) {
                continue;
            }
            if ($philosophy === 'defenders' && (int) $player['element_type'] === 2) {
                continue;
            }
            if ($weak === null || (float) $player['proj'] < (float) $weak['proj']) {
                $weak = $player;
            }
        }
        if ($weak === null) {
            return null;
        }

        $type = (int) $weak['element_type'];
        $bestIn = null;
        $bestGain = 0.0;
        foreach ($pool as $candidate) {
            if ((int) $candidate['element_type'] !== $type) {
                continue;
            }
            if (isset($byId[(int) $candidate['id']])) {
                continue;
            }
            $gain = (float) $candidate['proj'] - (float) $weak['proj'];
            if ($gain < 0.15) {
                continue;
            }
            $delta = (float) $candidate['now_cost'] - (float) $weak['now_cost'];
            if ($delta > $bank + 0.05) {
                continue;
            }
            $teamId = (int) $candidate['team'];
            $count = $clubCount[$teamId] ?? 0;
            if ((int) $weak['team'] === $teamId) {
                $count--;
            }
            if ($count >= self::MAX_PER_CLUB) {
                continue;
            }
            if ($gain > $bestGain) {
                $bestGain = $gain;
                $bestIn = $candidate;
            }
        }
        if ($bestIn === null) {
            return null;
        }

        $next = [];
        foreach ($squad as $player) {
            if ((int) $player['id'] === (int) $weak['id']) {
                $next[] = $bestIn;
            } else {
                $next[] = $player;
            }
        }
        return $next;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array<int, array<string, mixed>>
     */
    private static function scoreMapFromSquad(array $squad): array
    {
        $map = [];
        foreach ($squad as $player) {
            $id = (int) ($player['id'] ?? 0);
            $projs = $player['ai']['projections'] ?? [0, 0, 0, 0, 0];
            $map[$id] = [
                'projections' => $projs,
                'strength' => (float) ($player['ai']['strength'] ?? 0),
                'tip_tag' => $player['ai']['tip_tag'] ?? null,
                'source' => $player['ai']['source'] ?? 'scout',
            ];
        }
        return $map;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pitch
     */
    public static function formationLabel(array $pitch): string
    {
        return count($pitch['def'] ?? []) . '-' . count($pitch['mid'] ?? []) . '-' . count($pitch['fwd'] ?? []);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byType
     * @param array<int, int> $needs
     * @param array<int, array<string, mixed>> $picked
     */
    private static function minRemainingCost(array $byType, array $needs, array $picked): float
    {
        $total = 0.0;
        foreach ($needs as $type => $count) {
            $total += self::sumCheapest($byType[$type] ?? [], $picked, $count);
        }
        return $total;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byType
     * @param array<int, int> $needs
     * @param array<int, array<string, mixed>> $picked
     */
    private static function minCostForCount(
        array $byType,
        array $needs,
        array $picked,
        int $skipType,
        int $slotsNeeded
    ): float {
        if ($slotsNeeded < 1) {
            return 0.0;
        }
        $options = [];
        foreach ($needs as $type => $count) {
            $take = $type === $skipType ? max(0, $count - 1) : $count;
            $costs = [];
            foreach ($byType[$type] ?? [] as $player) {
                if (isset($picked[$player['id']])) {
                    continue;
                }
                $costs[] = (float) $player['now_cost'];
            }
            sort($costs);
            for ($i = 0; $i < $take && $i < count($costs); $i++) {
                $options[] = $costs[$i];
            }
        }
        sort($options);
        return array_sum(array_slice($options, 0, $slotsNeeded));
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<int, array<string, mixed>> $picked
     */
    private static function sumCheapest(array $list, array $picked, int $count): float
    {
        $costs = [];
        foreach ($list as $player) {
            if (isset($picked[$player['id']])) {
                continue;
            }
            $costs[] = (float) $player['now_cost'];
        }
        sort($costs);
        return array_sum(array_slice($costs, 0, max(0, $count)));
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<int, array<string, mixed>> $picked
     */
    private static function cheapestUnusedCost(array $list, array $picked, int $type): float
    {
        foreach ($list as $player) {
            if (isset($picked[$player['id']])) {
                continue;
            }
            return (float) $player['now_cost'];
        }
        return 4.0;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byType
     * @param array<int, int> $needs
     * @param array<int, array<string, mixed>> $picked
     * @param array<int, int> $clubCount
     * @return array<string, mixed>|null
     */
    private static function cheapestLegal(
        array $byType,
        array $needs,
        array $picked,
        array $clubCount,
        float $budgetLeft
    ): ?array {
        $best = null;
        foreach ($needs as $type => $count) {
            if ($count < 1) {
                continue;
            }
            foreach ($byType[$type] as $player) {
                if (isset($picked[$player['id']])) {
                    continue;
                }
                $teamId = (int) $player['team'];
                if (($clubCount[$teamId] ?? 0) >= self::MAX_PER_CLUB) {
                    continue;
                }
                $cost = (float) $player['now_cost'];
                if ($cost > $budgetLeft + 0.05) {
                    continue;
                }
                if ($best === null || $cost < (float) $best['now_cost']) {
                    $best = $player;
                }
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, list<array<string, mixed>>> $fixturesByTeam
     * @param array<int, array<string, mixed>> $scoreMap
     * @return array<string, mixed>
     */
    private static function mapPlayer(
        array $element,
        array $teamsById,
        array $fixturesByTeam,
        array $scoreMap
    ): array {
        $id = (int) ($element['id'] ?? 0);
        $teamId = (int) ($element['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $teamCode = (int) ($team['code'] ?? 0);
        $elementType = (int) ($element['element_type'] ?? 0);
        $isGk = $elementType === 1;
        $score = $scoreMap[$id] ?? null;
        $projs = array_values(array_map(
            'floatval',
            array_slice(array_pad($score['projections'] ?? [0, 0, 0, 0, 0], 5, 0), 0, 5)
        ));
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
        $headshot = is_string($photoId) && $photoId !== ''
            ? 'https://resources.premierleague.com/premierleague/photos/players/110x140/p' . $photoId . '.png'
            : null;

        return [
            'id' => $id,
            'web_name' => (string) ($element['web_name'] ?? 'Player'),
            'team' => $teamId,
            'team_short' => (string) ($team['short_name'] ?? ''),
            'team_name' => (string) ($team['name'] ?? ''),
            'team_code' => $teamCode,
            'shirt' => $shirt,
            'photo' => $headshot,
            'element_type' => $elementType,
            'row' => $row,
            'pos_label' => $posLabel,
            'now_cost' => ((int) ($element['now_cost'] ?? 0)) / 10,
            'fixtures' => array_slice($fixturesByTeam[$teamId] ?? [], 0, 3),
            'proj' => (float) ($projs[0] ?? 0),
            'ai' => [
                'projections' => $projs,
                'strength' => (float) ($score['strength'] ?? 0),
                'tip_tag' => $score['tip_tag'] ?? null,
                'source' => $score['source'] ?? 'scout',
            ],
        ];
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
                'label' => $awayShort . ' (H)',
                'fdr' => (int) ($fx['team_h_difficulty'] ?? 3),
                'was_home' => true,
            ];
            $byTeam[$away][] = [
                'event' => $event,
                'label' => $homeShort . ' (A)',
                'fdr' => (int) ($fx['team_a_difficulty'] ?? 3),
                'was_home' => false,
            ];
        }
        foreach ($byTeam as $teamId => $rows) {
            usort($rows, static fn ($a, $b) => $a['event'] <=> $b['event']);
            $byTeam[$teamId] = array_slice($rows, 0, 5);
        }
        return $byTeam;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function planningEvent(array $events): array
    {
        $current = null;
        $next = null;
        foreach ($events as $event) {
            if (!empty($event['is_next'])) {
                $next = $event;
            }
            if (!empty($event['is_current']) && empty($event['finished'])) {
                $current = $event;
            }
        }
        return $next ?: ($current ?: ($events[0] ?? []));
    }
}
