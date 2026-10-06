<?php

declare(strict_types=1);

namespace App;

/**
 * Season long AI Teams: four philosophies locked from GW2, nurtured with strict FPL rules.
 */
final class AiSeasonService
{
    private const START_GW = 2;
    private const BUDGET = 100.0;
    private const MAX_FT = 5;
    private const HIT_COST = 4;
    private const STORAGE_KEY = 'ai-season-v2';
    private const CHIP_PATIENCE_GW = 5;
    private const CHIP_LATE_GW = 36;

    /**
     * Minimum spectacular score before a chip is even considered.
     *
     * @var array<string, array<string, float>>
     */
    private const CHIP_SPECTACULAR = [
        'value' => ['bboost' => 26.0, '3xc' => 99.0, 'freehit' => 99.0],
        'attackers' => ['bboost' => 99.0, '3xc' => 8.5, 'freehit' => 99.0],
        'midfielders' => ['bboost' => 28.0, '3xc' => 9.0, 'freehit' => 99.0],
        'defenders' => ['bboost' => 27.0, '3xc' => 99.0, 'freehit' => 99.0],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function getPayload(?int $viewGw = null): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $planning = self::planningGameweek($bootstrap['events'] ?? []);
        $planningId = (int) ($planning['id'] ?? 0);
        if ($planningId < self::START_GW) {
            throw new \RuntimeException('AI Teams season tracking starts from Gameweek ' . self::START_GW . '.');
        }

        $state = self::loadState();
        if ($state === null) {
            $state = self::initSeason($bootstrap, $planningId);
        }

        self::syncToGameweek($state, $bootstrap, $planningId);
        self::refreshActualGameweekScores($state, $bootstrap, $planningId);

        $available = self::availableGameweeks($state, $planningId);
        $view = $viewGw ?? $planningId;
        if ($view < self::START_GW) {
            $view = self::START_GW;
        }
        if ($view > $planningId) {
            $view = $planningId;
        }
        if (!in_array($view, $available, true)) {
            $view = max(self::START_GW, end($available) ?: self::START_GW);
        }

        self::saveState($state);

        return self::buildViewPayload($state, $bootstrap, $view, $planning, $available);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function planningGameweek(array $events): array
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

    /** @return array<string, mixed>|null */
    private static function loadState(): ?array
    {
        $path = self::storagePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $state */
    private static function saveState(array $state): void
    {
        $dir = dirname(self::storagePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $state['updated_at'] = gmdate('c');
        file_put_contents(
            self::storagePath(),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private static function storagePath(): string
    {
        return dirname(__DIR__) . '/storage/data/' . self::STORAGE_KEY . '.json';
    }

    /**
     * @param array<string, mixed> $bootstrap
     * @return array<string, mixed>
     */
    private static function initSeason(array $bootstrap, int $planningId): array
    {
        $pool = AiTeamsService::buildPlayerPool($bootstrap, self::START_GW);
        $teams = [];

        foreach (AiTeamsService::philosophies() as $meta) {
            $squad = AiTeamsService::buildInitialSquad($pool, $meta['id']);
            $purchase = [];
            $cost = 0.0;
            foreach ($squad as $player) {
                $id = (int) ($player['id'] ?? 0);
                $price = (float) ($player['now_cost'] ?? 0);
                $purchase[$id] = $price;
                $cost += $price;
            }

            $teams[$meta['id']] = [
                'id' => $meta['id'],
                'title' => $meta['title'],
                'lead' => $meta['lead'],
                'free_transfers' => 1,
                'bank' => round(max(0, self::BUDGET - $cost), 1),
                'total_points' => 0,
                'chips' => [
                    'bboost' => true,
                    '3xc' => true,
                    'freehit' => true,
                ],
                'chip_peaks' => [
                    'bboost' => 0.0,
                    '3xc' => 0.0,
                    'freehit' => 0.0,
                ],
                'player_ids' => array_map(static fn ($p) => (int) $p['id'], $squad),
                'purchase_prices' => $purchase,
                'gameweeks' => [],
                'synced_through' => self::START_GW - 1,
            ];
        }

        return [
            'version' => 2,
            'start_gw' => self::START_GW,
            'teams' => $teams,
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $bootstrap
     */
    private static function syncToGameweek(array &$state, array $bootstrap, int $targetGw): void
    {
        for ($gw = self::START_GW; $gw <= $targetGw; $gw++) {
            foreach ($state['teams'] as $id => &$team) {
                if ((int) ($team['synced_through'] ?? 0) >= $gw) {
                    continue;
                }
                if ($gw === self::START_GW && empty($team['gameweeks'])) {
                    self::processGameweek($team, $gw, $bootstrap, true, $targetGw);
                } elseif ($gw > (int) ($team['synced_through'] ?? 0)) {
                    self::processGameweek($team, $gw, $bootstrap, false, $targetGw);
                }
                $team['synced_through'] = $gw;
            }
            unset($team);
        }
    }

    /**
     * @param array<string, mixed> $team
     * @param array<string, mixed> $bootstrap
     */
    private static function processGameweek(array &$team, int $gw, array $bootstrap, bool $isFirst, int $planningGw): void
    {
        $philosophyId = (string) ($team['id'] ?? 'value');
        $purchasePrices = is_array($team['purchase_prices'] ?? null) ? $team['purchase_prices'] : [];
        $ids = is_array($team['player_ids'] ?? null) ? $team['player_ids'] : [];

        $squad = self::squadFromIds($ids, $purchasePrices, $bootstrap, $gw);
        $ftBefore = (int) ($team['free_transfers'] ?? 1);
        $bank = (float) ($team['bank'] ?? 0);
        $transfers = [];

        if (!$isFirst) {
            $transfers = self::pickTransfers($squad, $bootstrap, $gw, $bank, $ftBefore, $philosophyId);
            foreach ($transfers as $transfer) {
                $squad = self::applyTransfer($squad, $transfer, $bootstrap, $gw, $purchasePrices, $bank, $team);
            }
        }

        $ordered = AiTeamsService::optimiseXi($squad);
        $scores = TeamScoreComposer::compose($ordered, self::scoreMapFromSquad($ordered));
        $chip = self::pickChip($team, $ordered, $scores, $philosophyId, $gw);
        self::recordChipPeaks($team, $ordered);
        $ordered = self::applyChipLineup($ordered, $chip);
        $gwPoints = self::scoreGameweek($ordered, $chip, $gw, $bootstrap, $planningGw);
        $hitPoints = array_sum(array_column($transfers, 'hit_points'));
        $gwPoints['points'] = max(0, (float) $gwPoints['points'] - $hitPoints);

        $ftUsed = count(array_filter($transfers, static fn ($t) => (int) ($t['hit_points'] ?? 0) === 0));
        $ftAfter = max(0, $ftBefore - $ftUsed);
        $ftAfter = min(self::MAX_FT, $ftAfter + 1);

        $team['free_transfers'] = $ftAfter;
        $team['bank'] = round($bank, 1);
        $team['player_ids'] = array_map(static fn ($p) => (int) $p['id'], $ordered);
        $team['purchase_prices'] = $purchasePrices;
        $team['total_points'] = round(((float) ($team['total_points'] ?? 0)) + $gwPoints['points'], 1);

        if ($chip !== null && !empty($team['chips'][$chip])) {
            $team['chips'][$chip] = false;
        }

        $cost = 0.0;
        foreach ($ordered as $player) {
            $cost += (float) ($player['now_cost'] ?? 0);
        }

        $team['gameweeks'][(string) $gw] = [
            'event_id' => $gw,
            'transfers' => $transfers,
            'chip' => $chip,
            'chip_label' => $chip !== null ? self::chipLabel($chip) : null,
            'hit_points' => $hitPoints,
            'gw_points' => round($gwPoints['points'], 1),
            'predicted_points' => round((float) ($gwPoints['predicted'] ?? 0), 1),
            'points_source' => $gwPoints['source'],
            'free_transfers_before' => $ftBefore,
            'free_transfers_after' => $ftAfter,
            'bank' => round($bank, 1),
            'team_value' => round($cost, 1),
            'strength' => (int) ($scores['strength']['gw'] ?? 0),
            'formation' => AiTeamsService::formationLabel(AiTeamsService::splitPitchBench($ordered)['pitch']),
            'player_ids' => array_map(static fn ($p) => (int) $p['id'], $ordered),
        ];
    }

    /**
     * @param list<int> $ids
     * @param array<int, float> $purchasePrices
     * @return list<array<string, mixed>>
     */
    private static function squadFromIds(
        array $ids,
        array &$purchasePrices,
        array $bootstrap,
        int $gw
    ): array {
        $elementsById = [];
        foreach ($bootstrap['elements'] as $element) {
            if (is_array($element)) {
                $elementsById[(int) ($element['id'] ?? 0)] = $element;
            }
        }

        $stub = [];
        foreach ($ids as $id) {
            $stub[] = ['id' => $id, 'purchase_price' => (float) ($purchasePrices[$id] ?? 0)];
        }

        $hydrated = AiTeamsService::hydrateSquadForGameweek($stub, $bootstrap, $gw, $purchasePrices);
        foreach ($hydrated as $player) {
            $pid = (int) ($player['id'] ?? 0);
            if (!isset($purchasePrices[$pid])) {
                $purchasePrices[$pid] = (float) ($player['now_cost'] ?? 0);
            }
        }

        return $hydrated;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return list<array<string, mixed>>
     */
    private static function pickTransfers(
        array $squad,
        array $bootstrap,
        int $gw,
        float $bank,
        int $freeTransfers,
        string $philosophyId
    ): array {
        $pool = AiTeamsService::buildPlayerPool($bootstrap, $gw);
        $owned = [];
        foreach ($squad as $player) {
            $owned[(int) $player['id']] = true;
        }
        $catalogue = array_values(array_filter(
            $pool,
            static fn ($p) => !isset($owned[(int) ($p['id'] ?? 0)])
        ));

        $enriched = [];
        foreach ($squad as $player) {
            $copy = $player;
            $copy['selling_price'] = AiTeamsService::sellingPrice(
                (float) ($player['purchase_price'] ?? $player['now_cost'] ?? 0),
                (float) ($player['now_cost'] ?? 0)
            );
            $enriched[] = $copy;
        }

        $raw = TransferSuggestService::suggest($enriched, $catalogue, $bank, $freeTransfers);
        if ($raw === []) {
            return [];
        }

        $best = $raw[0];
        if ((float) ($best['net_delta'] ?? 0) < 0.35) {
            return [];
        }

        return [[
            'out_id' => (int) $best['out_id'],
            'out_name' => (string) $best['out_name'],
            'in_id' => (int) $best['in_id'],
            'in_name' => (string) $best['in_name'],
            'pos' => (string) $best['pos'],
            'hit_points' => (int) $best['hit_points'],
            'proj_delta' => (float) $best['proj_delta'],
            'bank_after' => (float) $best['bank_after'],
            'price_change' => (float) $best['price_change'],
            'reason' => self::formatTransferReason($best),
            'uses_free_transfer' => (int) ($best['hit_points'] ?? 0) === 0,
        ]];
    }

    /**
     * @param array<string, mixed> $transfer
     */
    private static function formatTransferReason(array $transfer): string
    {
        $reason = trim((string) ($transfer['reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $delta = (float) ($transfer['proj_delta'] ?? 0);
        if ($delta > 0) {
            return sprintf('Expected +%.1f more projected points this gameweek', $delta);
        }

        return 'Upgrade within budget and squad rules';
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $transfer
     * @param array<int, float> $purchasePrices
     * @param array<string, mixed> $team
     * @return list<array<string, mixed>>
     */
    private static function applyTransfer(
        array $squad,
        array $transfer,
        array $bootstrap,
        int $gw,
        array &$purchasePrices,
        float &$bank,
        array &$team
    ): array {
        $outId = (int) ($transfer['out_id'] ?? 0);
        $inId = (int) ($transfer['in_id'] ?? 0);
        $pool = AiTeamsService::buildPlayerPool($bootstrap, $gw);
        $incoming = null;
        foreach ($pool as $player) {
            if ((int) ($player['id'] ?? 0) === $inId) {
                $incoming = $player;
                break;
            }
        }
        if ($incoming === null) {
            return $squad;
        }

        $sellPrice = 0.0;
        $next = [];
        foreach ($squad as $player) {
            if ((int) ($player['id'] ?? 0) === $outId) {
                $sellPrice = AiTeamsService::sellingPrice(
                    (float) ($purchasePrices[$outId] ?? $player['now_cost'] ?? 0),
                    (float) ($player['now_cost'] ?? 0)
                );
                unset($purchasePrices[$outId]);
                continue;
            }
            $next[] = $player;
        }

        $buyPrice = (float) ($incoming['now_cost'] ?? 0);
        $bank = round($bank + $sellPrice - $buyPrice, 1);
        $purchasePrices[$inId] = $buyPrice;
        $incoming['purchase_price'] = $buyPrice;
        $incoming['selling_price'] = $buyPrice;
        $next[] = $incoming;

        return $next;
    }

    /**
     * @param array<string, mixed> $team
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $scores
     */
    private static function pickChip(
        array $team,
        array $squad,
        array $scores,
        string $philosophyId,
        int $gw
    ): ?string {
        $available = is_array($team['chips'] ?? null) ? $team['chips'] : [];
        $spectacular = self::CHIP_SPECTACULAR[$philosophyId] ?? self::CHIP_SPECTACULAR['value'];
        $peaks = is_array($team['chip_peaks'] ?? null) ? $team['chip_peaks'] : [
            'bboost' => 0.0,
            '3xc' => 0.0,
            'freehit' => 0.0,
        ];

        $benchProj = 0.0;
        $captainProj = 0.0;
        $blankish = 0;
        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? ($player['proj'] ?? 0));
            $fixtures = is_array($player['fixtures'] ?? null) ? $player['fixtures'] : [];
            if ($pos <= 11) {
                if (!empty($player['is_captain'])) {
                    $captainProj = $proj;
                }
                if (($fixtures[0] ?? null) === null) {
                    $blankish++;
                }
            } else {
                $benchProj += $proj;
            }
        }

        // Free Hit only when the XI is broken by blanks.
        if (!empty($available['freehit']) && $blankish >= 4) {
            return 'freehit';
        }

        // Save Bench Boost and Triple Captain for a standout week, not the opening gameweeks.
        if ($gw < self::CHIP_PATIENCE_GW) {
            return null;
        }

        $predGw = (float) ($scores['predicted_points']['gw'] ?? 0);
        $lateSeason = $gw >= self::CHIP_LATE_GW;
        $candidates = [];

        if (!empty($available['bboost'])) {
            $min = (float) ($spectacular['bboost'] ?? 99.0);
            $peak = (float) ($peaks['bboost'] ?? 0.0);
            $isSeasonBest = $benchProj >= $peak;
            $isStrongWeek = $benchProj >= $min && ($predGw <= 0 || $benchProj >= $predGw * 0.2);
            if ($isStrongWeek && ($isSeasonBest || ($lateSeason && $benchProj >= $min * 0.92))) {
                $candidates['bboost'] = $benchProj;
            }
        }

        if (!empty($available['3xc'])) {
            $min = (float) ($spectacular['3xc'] ?? 99.0);
            $peak = (float) ($peaks['3xc'] ?? 0.0);
            $isSeasonBest = $captainProj >= $peak;
            $isStrongWeek = $captainProj >= $min;
            if ($isStrongWeek && ($isSeasonBest || ($lateSeason && $captainProj >= $min * 0.92))) {
                $candidates['3xc'] = $captainProj;
            }
        }

        if ($candidates === []) {
            return null;
        }

        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    /**
     * Track the best chip windows seen so far this season.
     *
     * @param array<string, mixed> $team
     * @param list<array<string, mixed>> $squad
     */
    private static function recordChipPeaks(array &$team, array $squad): void
    {
        $benchProj = 0.0;
        $captainProj = 0.0;
        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? ($player['proj'] ?? 0));
            if ($pos <= 11) {
                if (!empty($player['is_captain'])) {
                    $captainProj = $proj;
                }
            } else {
                $benchProj += $proj;
            }
        }

        if (!is_array($team['chip_peaks'] ?? null)) {
            $team['chip_peaks'] = ['bboost' => 0.0, '3xc' => 0.0, 'freehit' => 0.0];
        }

        $team['chip_peaks']['bboost'] = max((float) $team['chip_peaks']['bboost'], $benchProj);
        $team['chip_peaks']['3xc'] = max((float) $team['chip_peaks']['3xc'], $captainProj);
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return list<array<string, mixed>>
     */
    private static function applyChipLineup(array $squad, ?string $chip): array
    {
        if ($chip === null) {
            return $squad;
        }

        foreach ($squad as &$player) {
            $pos = (int) ($player['position'] ?? 99);
            if ($chip === '3xc' && !empty($player['is_captain']) && $pos <= 11) {
                $player['multiplier'] = 3;
            } elseif ($chip === 'bboost' && $pos > 11) {
                $player['multiplier'] = 1;
                $player['bench_boost'] = true;
            }
        }
        unset($player);

        return $squad;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array{points: float, predicted: float, source: string}
     */
    private static function scoreGameweek(array $squad, ?string $chip, int $gw, array $bootstrap, int $planningGw): array
    {
        $liveMap = self::actualPointsMap($gw, $bootstrap, $planningGw);
        $hasLive = $liveMap !== [];
        $predicted = 0.0;
        $actual = 0.0;

        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            $id = (int) ($player['id'] ?? 0);
            $baseProj = (float) (($player['ai']['projections'][0] ?? null) ?? ($player['proj'] ?? 0));
            $pts = $hasLive && isset($liveMap[$id]) ? (float) $liveMap[$id] : $baseProj;

            if ($pos <= 11) {
                if (!empty($player['is_captain'])) {
                    $mul = $chip === '3xc' ? 3 : 2;
                    $predicted += $baseProj * $mul;
                    $actual += $pts * $mul;
                } else {
                    $predicted += $baseProj;
                    $actual += $pts;
                }
                continue;
            }

            if ($chip === 'bboost') {
                $predicted += $baseProj;
                $actual += $pts;
            }
        }

        if ($hasLive) {
            return [
                'points' => round($actual, 1),
                'predicted' => round($predicted, 1),
                'source' => 'live',
            ];
        }

        return [
            'points' => round($predicted, 1),
            'predicted' => round($predicted, 1),
            'source' => 'projected',
        ];
    }

    /**
     * Use official FPL live points once a gameweek is no longer the upcoming planning GW.
     */
    private static function gameweekUsesActualPoints(int $gw, array $bootstrap, int $planningGw): bool
    {
        foreach ($bootstrap['events'] as $event) {
            if (!is_array($event) || (int) ($event['id'] ?? 0) !== $gw) {
                continue;
            }
            if (!empty($event['finished']) || !empty($event['data_checked'])) {
                return true;
            }

            return $gw < $planningGw;
        }

        return $gw < $planningGw;
    }

    /**
     * @return array<int, float>
     */
    private static function actualPointsMap(int $gw, array $bootstrap, int $planningGw): array
    {
        if (!self::gameweekUsesActualPoints($gw, $bootstrap, $planningGw)) {
            return [];
        }

        try {
            $live = FplClient::fetchEventLive($gw);
            $map = [];
            foreach ($live['elements'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];
                $map[$id] = (float) ($stats['total_points'] ?? 0);
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Backfill stored gameweek rows with official FPL points after the deadline passes.
     *
     * @param array<string, mixed> $state
     */
    private static function refreshActualGameweekScores(array &$state, array $bootstrap, int $planningGw): void
    {
        foreach ($state['teams'] as &$team) {
            $purchasePrices = is_array($team['purchase_prices'] ?? null) ? $team['purchase_prices'] : [];
            $seasonTotal = 0.0;

            foreach ($team['gameweeks'] as $gwKey => &$snap) {
                if (!is_array($snap)) {
                    continue;
                }
                $gw = (int) $gwKey;
                if (!self::gameweekUsesActualPoints($gw, $bootstrap, $planningGw)) {
                    $seasonTotal += (float) ($snap['gw_points'] ?? 0);
                    continue;
                }

                $ids = is_array($snap['player_ids'] ?? null) ? $snap['player_ids'] : [];
                $squad = self::squadFromIds($ids, $purchasePrices, $bootstrap, $gw);
                $ordered = AiTeamsService::optimiseXi($squad);
                $chip = is_string($snap['chip'] ?? null) ? $snap['chip'] : null;
                $ordered = self::applyChipLineup($ordered, $chip);
                $scored = self::scoreGameweek($ordered, $chip, $gw, $bootstrap, $planningGw);
                if (($scored['source'] ?? '') !== 'live') {
                    $seasonTotal += (float) ($snap['gw_points'] ?? 0);
                    continue;
                }

                $hitPoints = (int) ($snap['hit_points'] ?? 0);
                $snap['gw_points'] = max(0, round($scored['points'] - $hitPoints, 1));
                $snap['predicted_points'] = round((float) ($scored['predicted'] ?? 0), 1);
                $snap['points_source'] = 'live';
                $seasonTotal += (float) $snap['gw_points'];
            }
            unset($snap);

            $team['total_points'] = round($seasonTotal, 1);
            $team['purchase_prices'] = $purchasePrices;
        }
        unset($team);
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    private static function availableGameweeks(array $state, int $planningId): array
    {
        $max = self::START_GW;
        foreach ($state['teams'] as $team) {
            $max = max($max, (int) ($team['synced_through'] ?? self::START_GW));
        }
        $max = min($max, $planningId);
        $out = [];
        for ($i = self::START_GW; $i <= $max; $i++) {
            $out[] = $i;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<int> $available
     * @return array<string, mixed>
     */
    private static function buildViewPayload(
        array $state,
        array $bootstrap,
        int $viewGw,
        array $planning,
        array $available
    ): array {
        $eventsById = [];
        foreach ($bootstrap['events'] as $event) {
            if (is_array($event)) {
                $eventsById[(int) ($event['id'] ?? 0)] = $event;
            }
        }

        $planningId = (int) ($planning['id'] ?? $viewGw);
        $gameweeks = [];
        foreach ($available as $id) {
            $ev = $eventsById[$id] ?? [];
            $usesActual = self::gameweekUsesActualPoints($id, $bootstrap, $planningId);
            $gameweeks[] = [
                'id' => $id,
                'name' => (string) ($ev['name'] ?? ('Gameweek ' . $id)),
                'finished' => $usesActual || !empty($ev['finished']),
                'uses_actual_points' => $usesActual,
            ];
        }

        $teams = [];
        foreach ($state['teams'] as $teamState) {
            $teams[] = self::buildTeamView($teamState, $bootstrap, $viewGw, $planningId);
        }

        return [
            'mode' => 'season',
            'start_gw' => self::START_GW,
            'view_gw' => $viewGw,
            'planning_gw' => [
                'id' => (int) ($planning['id'] ?? $viewGw),
                'name' => (string) ($planning['name'] ?? ('Gameweek ' . $viewGw)),
            ],
            'gameweeks' => $gameweeks,
            'budget' => self::BUDGET,
            'teams' => $teams,
            'updated_at' => $state['updated_at'] ?? gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $teamState
     * @return array<string, mixed>
     */
    private static function buildTeamView(array $teamState, array $bootstrap, int $viewGw, int $planningGw): array
    {
        $gwKey = (string) $viewGw;
        $snap = is_array($teamState['gameweeks'][$gwKey] ?? null) ? $teamState['gameweeks'][$gwKey] : null;
        if ($snap === null) {
            $viewGw = (int) ($teamState['synced_through'] ?? self::START_GW);
            $snap = $teamState['gameweeks'][(string) $viewGw] ?? [];
        }

        $purchasePrices = is_array($teamState['purchase_prices'] ?? null) ? $teamState['purchase_prices'] : [];
        $ids = is_array($snap['player_ids'] ?? null) ? $snap['player_ids'] : ($teamState['player_ids'] ?? []);
        $squad = self::squadFromIds($ids, $purchasePrices, $bootstrap, $viewGw);
        $ordered = AiTeamsService::optimiseXi($squad);

        $chip = $snap['chip'] ?? null;
        $ordered = self::applyChipLineup($ordered, is_string($chip) ? $chip : null);
        $scores = TeamScoreComposer::compose($ordered, self::scoreMapFromSquad($ordered));
        $split = AiTeamsService::splitPitchBench($ordered);

        $usesActual = self::gameweekUsesActualPoints($viewGw, $bootstrap, $planningGw);
        $isPlanningView = $viewGw === $planningGw && !$usesActual;
        $displayGwPoints = (float) ($snap['gw_points'] ?? 0);
        $pointsSource = (string) ($snap['points_source'] ?? 'projected');

        if ($usesActual) {
            $liveMap = self::actualPointsMap($viewGw, $bootstrap, $planningGw);
            if ($liveMap !== []) {
                foreach ($ordered as &$player) {
                    $player['gw_points'] = (float) ($liveMap[(int) ($player['id'] ?? 0)] ?? 0);
                }
                unset($player);

                $scored = self::scoreGameweek($ordered, is_string($chip) ? $chip : null, $viewGw, $bootstrap, $planningGw);
                $hitPoints = (int) ($snap['hit_points'] ?? 0);
                $displayGwPoints = max(0, round($scored['points'] - $hitPoints, 1));
                $pointsSource = (string) ($scored['source'] ?? 'live');
                $split = AiTeamsService::splitPitchBench($ordered);
            }
        }

        $totalToGw = 0.0;
        foreach ($teamState['gameweeks'] as $gw => $row) {
            if ((int) $gw <= $viewGw && is_array($row)) {
                $totalToGw += (float) ($row['gw_points'] ?? 0);
            }
        }

        $transfers = is_array($snap['transfers'] ?? null) ? $snap['transfers'] : [];
        foreach ($transfers as $i => $transfer) {
            if (!is_array($transfer)) {
                continue;
            }
            if (empty($transfer['reason'])) {
                $transfers[$i]['reason'] = self::formatTransferReason($transfer);
            }
            if (!isset($transfer['uses_free_transfer'])) {
                $transfers[$i]['uses_free_transfer'] = (int) ($transfer['hit_points'] ?? 0) === 0;
            }
            $transfers[$i] = self::enrichTransferVisuals($transfers[$i], $bootstrap);
        }

        $transferPlan = null;
        if ($isPlanningView) {
            $ftBefore = (int) ($snap['free_transfers_before'] ?? ($teamState['free_transfers'] ?? 1));
            $transferPlan = [
                'gameweek' => $viewGw,
                'free_transfers_before' => $ftBefore,
                'transfers' => $transfers,
                'note' => $transfers === []
                    ? sprintf(
                        'No transfers planned. This squad keeps its %d free transfer%s.',
                        max(1, $ftBefore),
                        max(1, $ftBefore) === 1 ? '' : 's'
                    )
                    : null,
            ];
        }

        return [
            'id' => $teamState['id'],
            'title' => $teamState['title'],
            'lead' => $teamState['lead'],
            'total_points' => round($totalToGw, 1),
            'season_total' => round((float) ($teamState['total_points'] ?? 0), 1),
            'gw_points' => $displayGwPoints,
            'predicted_points' => (float) ($snap['predicted_points'] ?? ($scores['predicted_points']['gw'] ?? 0)),
            'uses_actual_points' => $usesActual && $pointsSource === 'live',
            'is_planning_view' => $isPlanningView,
            'transfer_plan' => $transferPlan,
            'strength' => (int) ($snap['strength'] ?? ($scores['strength']['gw'] ?? 0)),
            'team_value' => (float) ($snap['team_value'] ?? 0),
            'bank' => (float) ($snap['bank'] ?? ($teamState['bank'] ?? 0)),
            'free_transfers' => (int) ($snap['free_transfers_after'] ?? ($teamState['free_transfers'] ?? 0)),
            'formation' => (string) ($snap['formation'] ?? ''),
            'transfers' => $transfers,
            'hit_points' => (int) ($snap['hit_points'] ?? 0),
            'chip' => $chip !== null ? [
                'code' => $chip,
                'label' => self::chipLabel((string) $chip),
                'played' => true,
            ] : null,
            'chips_remaining' => $teamState['chips'] ?? [],
            'pitch' => $split['pitch'],
            'bench' => $split['bench'],
            'squad' => $ordered,
            'points_source' => $snap['points_source'] ?? 'projected',
        ];
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
     * @param array<string, mixed> $transfer
     * @param array<string, mixed> $bootstrap
     * @return array<string, mixed>
     */
    private static function enrichTransferVisuals(array $transfer, array $bootstrap): array
    {
        $elementsById = [];
        foreach ($bootstrap['elements'] ?? [] as $element) {
            if (!is_array($element)) {
                continue;
            }
            $elementsById[(int) ($element['id'] ?? 0)] = $element;
        }

        $teamsById = [];
        foreach ($bootstrap['teams'] ?? [] as $team) {
            if (!is_array($team)) {
                continue;
            }
            $teamsById[(int) ($team['id'] ?? 0)] = $team;
        }

        foreach (['out' => 'out_id', 'in' => 'in_id'] as $side => $idKey) {
            $id = (int) ($transfer[$idKey] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $element = $elementsById[$id] ?? null;
            if ($element === null) {
                continue;
            }

            $teamId = (int) ($element['team'] ?? 0);
            $team = $teamsById[$teamId] ?? [];
            $teamCode = (int) ($team['code'] ?? 0);
            $elementType = (int) ($element['element_type'] ?? 0);
            $isGk = $elementType === 1;
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

            $transfer[$side . '_photo'] = $headshot;
            $transfer[$side . '_shirt'] = $shirt;
            $transfer[$side . '_team_short'] = (string) ($team['short_name'] ?? '');
            $transfer[$side . '_price'] = ((int) ($element['now_cost'] ?? 0)) / 10;
        }

        return $transfer;
    }

    private static function chipLabel(string $chip): string
    {
        return match (strtolower($chip)) {
            'bboost', 'benchboost' => 'Bench Boost',
            '3xc', 'triplecaptain' => 'Triple Captain',
            'freehit', 'freehit1', 'freehit2' => 'Free Hit',
            default => ucfirst($chip),
        };
    }
}
