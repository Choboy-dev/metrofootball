<?php

declare(strict_types=1);

namespace App;

/**
 * Personal Premium AI tips pack. Numbers from scout sheet + budget rules.
 * OpenAI narrates once per user per gameweek. Cached until the next GW.
 */
final class AiTipsService
{
    /** Keep until next GW key changes; long TTL avoids re-spend. */
    private const CACHE_SECONDS = 1209600; // 14 days

    public static function cacheKey(int $userId, int $eventId): string
    {
        return 'ai-tips-gw-v1-' . $userId . '-' . $eventId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCached(int $userId, int $eventId): ?array
    {
        $cached = FplCache::get(self::cacheKey($userId, $eventId), self::CACHE_SECONDS);
        if (!is_array($cached) || !isset($cached['sections'])) {
            return null;
        }
        $cached['cached'] = true;
        return $cached;
    }

    /**
     * @param array<string, mixed> $pick
     * @param list<array<string, mixed>> $squad enriched
     * @param list<array<string, mixed>> $catalogue enriched
     * @param array{gw:int,five_gw:int} $strength
     * @param array{gw:float,five_gw:float} $predicted
     * @return array<string, mixed>
     */
    public static function build(
        int $userId,
        array $pick,
        array $squad,
        array $catalogue,
        array $strength,
        array $predicted
    ): array {
        $entryId = (int) ($pick['entry']['id'] ?? 0);
        $eventId = (int) ($pick['event']['id'] ?? 0);
        $bank = (float) ($pick['bank'] ?? 0);
        $ft = (int) ($pick['free_transfers'] ?? 1);
        $cacheKey = self::cacheKey($userId, $eventId);

        $cached = self::getCached($userId, $eventId);
        if ($cached !== null) {
            return $cached;
        }

        $transfers = self::buildTransfers($squad, $catalogue, $bank, $ft);
        $captain = self::buildCaptain($squad);
        $dropWatch = self::buildDropWatch($squad);
        $chip = self::buildChip($squad, $pick, $strength, $predicted, $ft);
        $snipers = self::buildSnipers($squad, $catalogue);

        $narration = self::narrate([
            'team' => (string) ($pick['entry']['team_name'] ?? 'Team'),
            'event' => (string) ($pick['event']['name'] ?? ''),
            'strength_gw' => $strength['gw'] ?? 0,
            'predicted_gw' => $predicted['gw'] ?? 0,
            'transfers' => $transfers,
            'captain' => $captain,
            'drop_watch' => $dropWatch,
            'chip' => $chip,
            'snipers' => $snipers,
        ]);

        // Plain per-card sentences for transfers, captain, drop watch, and differentials.
        if (!empty($narration['chip_why']) && is_array($chip)) {
            $chip['why'] = $narration['chip_why'];
        }

        $payload = [
            'event' => $pick['event'] ?? null,
            'entry' => [
                'id' => $entryId,
                'team_name' => $pick['entry']['team_name'] ?? null,
                'manager_name' => $pick['entry']['manager_name'] ?? null,
            ],
            'bank' => $bank,
            'free_transfers' => $ft,
            'strength' => $strength,
            'predicted_points' => $predicted,
            'sections' => [
                'transfers' => $transfers,
                'captain' => $captain,
                'drop_watch' => $dropWatch,
                'chip' => $chip,
                'snipers' => $snipers,
            ],
            'model' => OpenAiClient::configured() ? OpenAiClient::model() : 'scout-only',
            'generated_at' => gmdate('c'),
            'cache_key' => $cacheKey,
            'cached' => false,
        ];

        FplCache::put($cacheKey, $payload);
        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>
     */
    private static function buildTransfers(array $squad, array $catalogue, float $bank, int $ft): array
    {
        $raw = TransferSuggestService::suggest($squad, $catalogue, $bank, $ft);
        $byId = self::playerIndex(array_merge($squad, $catalogue));
        $high = [];
        $usedOut = [];
        $usedIn = [];
        foreach ($raw as $row) {
            $delta = (float) ($row['proj_delta'] ?? 0);
            // High certainty: clear projection gap and no hit preferred.
            if ($delta < 0.75) {
                continue;
            }
            $outId = (int) $row['out_id'];
            $inId = (int) $row['in_id'];
            if (isset($usedOut[$outId]) || isset($usedIn[$inId])) {
                continue;
            }

            $certainty = min(99, (int) round(70 + ($delta * 12) + ((int) ($row['hit_points'] ?? 0) === 0 ? 8 : 0)));
            $out = self::playerVisual($byId, $outId);
            $in = self::playerVisual($byId, $inId);
            $outName = (string) ($row['out_name'] ?: $out['name']);
            $inName = (string) ($row['in_name'] ?: $in['name']);
            $priceChange = (float) $row['price_change'];
            $usedOut[$outId] = true;
            $usedIn[$inId] = true;
            $high[] = [
                'out_id' => $outId,
                'out_name' => $outName,
                'out_photo' => $out['photo'],
                'out_shirt' => $out['shirt'],
                'out_team_short' => $out['team_short'],
                'in_id' => $inId,
                'in_name' => $inName,
                'in_photo' => $in['photo'],
                'in_shirt' => $in['shirt'],
                'in_team_short' => $in['team_short'],
                'pos' => (string) $row['pos'],
                'price_change' => $priceChange,
                'bank_after' => (float) $row['bank_after'],
                'proj_delta' => $delta,
                'hit_points' => (int) $row['hit_points'],
                'certainty' => $certainty,
                'reason' => self::transferReasonSentence($outName, $inName, $priceChange, $delta),
            ];
            if (count($high) >= 2) {
                break;
            }
        }

        return [
            'title' => 'AI Transfers',
            'items' => $high,
            'empty' => $high === []
                ? 'Your squad already looks solid for the budget you have. No high certainty swaps this week.'
                : null,
            'note' => null,
        ];
    }

    /**
     * Rebuild transfer swaps on cached tip packs so uniqueness and copy stay current
     * without calling OpenAI again.
     *
     * @param array<string, mixed> $tips
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>
     */
    public static function refreshTransferSection(
        array $tips,
        array $squad,
        array $catalogue,
        float $bank,
        int $ft
    ): array {
        $tips['sections']['transfers'] = self::buildTransfers($squad, $catalogue, $bank, $ft);
        return $tips;
    }

    private static function transferReasonSentence(
        string $outName,
        string $inName,
        float $priceChange,
        float $projDelta
    ): string {
        $outName = trim($outName) !== '' ? trim($outName) : 'this player';
        $inName = trim($inName) !== '' ? trim($inName) : 'the replacement';
        $absPrice = number_format(abs($priceChange), 1);

        if ($priceChange < -0.05) {
            $costBit = $inName . ' costs £' . $absPrice . ' less than ' . $outName;
        } elseif ($priceChange > 0.05) {
            $costBit = $inName . ' costs £' . $absPrice . ' more than ' . $outName;
        } else {
            $costBit = $inName . ' costs about the same as ' . $outName;
        }

        $pts = number_format(abs($projDelta), 1);
        if ($projDelta >= 0) {
            $projBit = 'is predicted to score +' . $pts . ' more points than him in the upcoming gameweek';
        } else {
            $projBit = 'is predicted to score ' . $pts . ' fewer points than him in the upcoming gameweek';
        }

        return $costBit . ' and ' . $projBit . '.';
    }

    /**
     * Fill transfer photos on cached tips without regenerating narration.
     *
     * @param array<string, mixed> $tips
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $players
     * @return array<string, mixed>
     */
    public static function hydrateTransferVisuals(array $tips, array $squad, array $players): array
    {
        $items = $tips['sections']['transfers']['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return $tips;
        }

        $byId = self::playerIndex(array_merge($squad, $players));
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $out = self::playerVisual($byId, (int) ($item['out_id'] ?? 0));
            $in = self::playerVisual($byId, (int) ($item['in_id'] ?? 0));
            $items[$i]['out_photo'] = $item['out_photo'] ?? $out['photo'];
            $items[$i]['out_shirt'] = $item['out_shirt'] ?? $out['shirt'];
            $items[$i]['out_team_short'] = $item['out_team_short'] ?? $out['team_short'];
            $items[$i]['in_photo'] = $item['in_photo'] ?? $in['photo'];
            $items[$i]['in_shirt'] = $item['in_shirt'] ?? $in['shirt'];
            $items[$i]['in_team_short'] = $item['in_team_short'] ?? $in['team_short'];
        }
        $tips['sections']['transfers']['items'] = $items;
        return $tips;
    }

    /**
     * @param list<array<string, mixed>> $players
     * @return array<int, array<string, mixed>>
     */
    private static function playerIndex(array $players): array
    {
        $map = [];
        foreach ($players as $player) {
            $id = (int) ($player['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $player;
            }
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     * @return array{photo:?string,shirt:?string,team_short:string,name:string}
     */
    private static function playerVisual(array $byId, int $id): array
    {
        $player = $byId[$id] ?? [];
        $headshot = $player['headshot'] ?? null;
        if (!$headshot) {
            $photo = (string) ($player['photo'] ?? '');
            $photoId = $photo !== '' ? preg_replace('/\.jpg$/i', '', $photo) : '';
            $headshot = $photoId !== ''
                ? 'https://resources.premierleague.com/premierleague/photos/players/250x250/p' . $photoId . '.png'
                : null;
        }

        return [
            'photo' => is_string($headshot) && $headshot !== '' ? $headshot : null,
            'shirt' => isset($player['shirt']) && is_string($player['shirt']) ? $player['shirt'] : null,
            'team_short' => (string) ($player['team_short'] ?? ''),
            'name' => (string) ($player['web_name'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>
     */
    private static function buildCaptain(array $squad): array
    {
        $best = null;
        $vice = null;
        foreach ($squad as $player) {
            if ((int) ($player['position'] ?? 99) > 11) {
                continue;
            }
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? $player['ep_next'] ?? 0);
            $visual = self::playerVisual([(int) ($player['id'] ?? 0) => $player], (int) ($player['id'] ?? 0));
            $nextFx = is_array($player['fixtures'][0] ?? null) ? $player['fixtures'][0] : [];
            $row = [
                'id' => (int) ($player['id'] ?? 0),
                'name' => (string) ($player['web_name'] ?? 'Player'),
                'pos' => (string) ($player['pos_label'] ?? ''),
                'team_short' => (string) ($player['team_short'] ?? ''),
                'fixture' => (string) (($nextFx['label'] ?? '') ?: 'TBC'),
                'fdr' => (int) ($nextFx['fdr'] ?? 3),
                'proj' => round($proj, 1),
                'doubled' => round($proj * 2, 1),
                'photo' => $visual['photo'],
                'shirt' => $visual['shirt'] ?? ($player['shirt'] ?? null),
                'is_current_captain' => !empty($player['is_captain']),
            ];
            if ($best === null || $row['proj'] > $best['proj']) {
                $vice = $best;
                $best = $row;
            } elseif ($vice === null || $row['proj'] > $vice['proj']) {
                $vice = $row;
            }
        }

        if ($best === null) {
            return [
                'title' => 'Who to captain',
                'pick' => null,
                'vice' => null,
                'why' => null,
            ];
        }

        $best['reason'] = self::captainReasonSentence($best, true);
        if (is_array($vice)) {
            $vice['reason'] = self::captainReasonSentence($vice, false);
        }

        return [
            'title' => 'Who to captain',
            'pick' => $best,
            'vice' => $vice,
            'why' => null,
        ];
    }

    /**
     * @param array<string, mixed> $tips
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>
     */
    public static function refreshCaptainSection(array $tips, array $squad): array
    {
        $tips['sections']['captain'] = self::buildCaptain($squad);
        return $tips;
    }

    /**
     * @param array<string, mixed> $player
     */
    private static function captainReasonSentence(array $player, bool $isCaptain): string
    {
        $name = trim((string) ($player['name'] ?? ''));
        if ($name === '') {
            $name = 'This player';
        }
        $proj = number_format((float) ($player['proj'] ?? 0), 1);
        $doubled = number_format((float) ($player['doubled'] ?? 0), 1);

        if ($isCaptain) {
            return $name . ' looks best to captain. He is predicted to score '
                . $proj . ' points this gameweek, or ' . $doubled . ' with the armband.';
        }

        return $name . ' is a strong vice tip. He is predicted to score '
            . $proj . ' points this gameweek.';
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>
     */
    private static function buildDropWatch(array $squad): array
    {
        $watched = [];
        foreach ($squad as $player) {
            $fixtures = is_array($player['fixtures'] ?? null) ? $player['fixtures'] : [];
            if (count($fixtures) < 2) {
                continue;
            }
            $near = array_slice($fixtures, 0, 2);
            $far = array_slice($fixtures, 2, 3);
            if ($far === []) {
                continue;
            }
            $nearAvg = self::avgFdr($near);
            $farAvg = self::avgFdr($far);
            $nextFdr = (int) ($fixtures[0]['fdr'] ?? 3);
            $rise = $farAvg - $nearAvg;
            $tag = (string) (($player['ai']['tip_tag'] ?? '') ?: '');
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? 0);

            if ($rise < 0.75 && $farAvg < 3.6 && !in_array($tag, ['avoid', 'injured', 'rotation'], true)) {
                continue;
            }
            if ($proj >= 6.5 && $farAvg < 4.0 && !in_array($tag, ['avoid', 'injured'], true)) {
                // Keep strong assets unless run is clearly brutal.
                continue;
            }

            $run = [];
            foreach (array_slice($fixtures, 0, 5) as $fx) {
                $run[] = [
                    'label' => (string) (($fx['label'] ?? '') ?: 'TBC'),
                    'fdr' => (int) ($fx['fdr'] ?? 3),
                ];
            }

            $visual = self::playerVisual([(int) ($player['id'] ?? 0) => $player], (int) ($player['id'] ?? 0));
            $name = (string) ($player['web_name'] ?? 'Player');
            $watched[] = [
                'id' => (int) ($player['id'] ?? 0),
                'name' => $name,
                'pos' => (string) ($player['pos_label'] ?? ''),
                'team_short' => (string) ($player['team_short'] ?? ''),
                'photo' => $visual['photo'],
                'shirt' => $visual['shirt'] ?? ($player['shirt'] ?? null),
                'near_fdr' => round($nearAvg, 1),
                'far_fdr' => round($farAvg, 1),
                'next_fdr' => $nextFdr,
                'fixtures' => $run,
                'proj' => round($proj, 1),
                'tag' => $tag !== '' ? $tag : null,
                'reason' => self::dropWatchReasonSentence($name, $tag, $rise, $farAvg),
            ];
        }

        usort($watched, static function ($a, $b) {
            $scoreA = ($a['far_fdr'] - $a['near_fdr']) * 2 + ($a['far_fdr'] * 0.5) - ($a['proj'] * 0.15);
            $scoreB = ($b['far_fdr'] - $b['near_fdr']) * 2 + ($b['far_fdr'] * 0.5) - ($b['proj'] * 0.15);
            return $scoreB <=> $scoreA;
        });
        $watched = array_slice($watched, 0, 4);

        return [
            'title' => 'Player drop watch',
            'items' => $watched,
            'empty' => $watched === []
                ? 'No clear drop candidates from your fixture run right now.'
                : null,
            'note' => null,
        ];
    }

    /**
     * @param array<string, mixed> $tips
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>
     */
    public static function refreshDropWatchSection(array $tips, array $squad): array
    {
        $tips['sections']['drop_watch'] = self::buildDropWatch($squad);
        return $tips;
    }

    private static function dropWatchReasonSentence(
        string $name,
        string $tag,
        float $rise,
        float $farAvg
    ): string {
        $name = trim($name) !== '' ? trim($name) : 'This player';

        if (in_array($tag, ['avoid', 'injured', 'rotation'], true)) {
            return $name . ' is flagged as ' . $tag . ', so keep him on drop watch.';
        }

        if ($rise >= 1.0) {
            return $name . ' has easier games now, but his next fixtures get much harder.';
        }

        if ($farAvg >= 4.0) {
            return $name . ' faces a very hard run of fixtures ahead.';
        }

        return $name . ' has a tougher fixture stretch coming up.';
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $pick
     * @param array{gw:int,five_gw:int} $strength
     * @param array{gw:float,five_gw:float} $predicted
     * @return array<string, mixed>
     */
    private static function buildChip(
        array $squad,
        array $pick,
        array $strength,
        array $predicted,
        int $ft
    ): array {
        $active = $pick['active_chip'] ?? null;
        if (is_string($active) && $active !== '') {
            return [
                'title' => 'Chip advice',
                'action' => 'hold',
                'chip' => null,
                'label' => 'Chip already active',
                'why' => 'You already have ' . self::chipLabel($active) . ' active this gameweek. Hold and ride it out.',
            ];
        }

        $risk = 0;
        $benchProj = 0.0;
        $bestProj = 0.0;
        $blankish = 0;
        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? 0);
            $tag = (string) (($player['ai']['tip_tag'] ?? '') ?: '');
            $fixtures = is_array($player['fixtures'] ?? null) ? $player['fixtures'] : [];
            $next = $fixtures[0] ?? null;
            if ($pos <= 11) {
                $bestProj = max($bestProj, $proj);
                if (in_array($tag, ['avoid', 'injured', 'rotation'], true)) {
                    $risk++;
                }
                if ($next === null) {
                    $blankish++;
                }
            } else {
                $benchProj += $proj;
            }
        }

        $gwStrength = (int) ($strength['gw'] ?? 0);
        $predGw = (float) ($predicted['gw'] ?? 0);

        $suggestion = [
            'action' => 'hold',
            'chip' => null,
            'label' => 'No chip needed right now',
            'why' => 'Your squad looks fine for a normal week. Save your chips for a clearer moment.',
        ];

        if ($blankish >= 3) {
            $suggestion = [
                'action' => 'play',
                'chip' => 'freehit',
                'label' => 'Play Free Hit',
                'why' => 'Several starters look blank this week. Free Hit can rebuild the team for one gameweek only.',
            ];
        } elseif ($risk >= 4 || ($gwStrength > 0 && $gwStrength < 78 && $ft <= 1)) {
            $suggestion = [
                'action' => 'play',
                'chip' => 'wildcard',
                'label' => 'Play Wildcard',
                'why' => 'There are too many weak spots for one free transfer. Wildcard can reset the team properly.',
            ];
        } elseif ($benchProj >= 12.0 && $predGw >= 55) {
            $suggestion = [
                'action' => 'play',
                'chip' => 'bboost',
                'label' => 'Play Bench Boost',
                'why' => 'Your bench looks strong this week. Bench Boost can turn those points into a big total.',
            ];
        } elseif ($bestProj >= 7.5 && $predGw >= 58) {
            $suggestion = [
                'action' => 'play',
                'chip' => '3xc',
                'label' => 'Play Triple Captain',
                'why' => 'Your top captain pick is strong enough that Triple Captain can turn a good score into a huge one.',
            ];
        }

        return array_merge(['title' => 'Chip advice'], $suggestion);
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>
     */
    private static function buildSnipers(array $squad, array $catalogue): array
    {
        $owned = [];
        foreach ($squad as $p) {
            $owned[(int) ($p['id'] ?? 0)] = true;
        }

        $byPos = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($catalogue as $player) {
            $id = (int) ($player['id'] ?? 0);
            if ($id < 1 || isset($owned[$id])) {
                continue;
            }
            $type = (int) ($player['element_type'] ?? 0);
            if (!isset($byPos[$type])) {
                continue;
            }
            $status = (string) ($player['status'] ?? 'a');
            if ($status !== 'a') {
                continue;
            }
            $price = (float) ($player['now_cost'] ?? 99);
            // Value hunters: not premium priced.
            $cap = match ($type) {
                1 => 5.0,
                2 => 5.5,
                3 => 7.0,
                default => 7.5,
            };
            if ($price > $cap) {
                continue;
            }
            $own = (float) ($player['selected_by_percent'] ?? 100);
            $form = (float) ($player['form'] ?? 0);
            $ppg = (float) ($player['points_per_game'] ?? 0);
            $proj = (float) (($player['ai']['projections'][0] ?? null) ?? $player['ep_next'] ?? 0);
            $points = (int) ($player['total_points'] ?? 0);
            if ($proj < 2.5 && $form < 3.0) {
                continue;
            }
            $value = ($proj * 1.6) + ($form * 1.1) + ($ppg * 0.8) + min(8, $points / 12);
            $value += max(0, (18 - $own) * 0.08); // low ownership boost
            $value -= max(0, $price - 4.5) * 0.35;

            $byPos[$type][] = [
                'id' => $id,
                'name' => (string) ($player['web_name'] ?? 'Player'),
                'pos' => (string) ($player['pos_label'] ?? ''),
                'team_short' => (string) ($player['team_short'] ?? ''),
                'price' => round($price, 1),
                'ownership' => round($own, 1),
                'form' => round($form, 1),
                'proj' => round($proj, 1),
                'fixture' => (string) (($player['fixtures'][0]['label'] ?? '') ?: 'TBC'),
                'score' => round($value, 2),
                '_player' => $player,
            ];
        }

        $picks = [];
        foreach ([1 => 'GK', 2 => 'DEF', 3 => 'MID', 4 => 'FWD'] as $type => $label) {
            $list = $byPos[$type];
            usort($list, static fn ($a, $b) => $b['score'] <=> $a['score']);
            if ($list !== []) {
                $top = $list[0];
                $player = is_array($top['_player'] ?? null) ? $top['_player'] : [];
                unset($top['score'], $top['_player']);

                $run = [];
                $fixtures = is_array($player['fixtures'] ?? null) ? $player['fixtures'] : [];
                foreach (array_slice($fixtures, 0, 5) as $fx) {
                    $run[] = [
                        'label' => (string) (($fx['label'] ?? '') ?: 'TBC'),
                        'fdr' => (int) ($fx['fdr'] ?? 3),
                    ];
                }

                $visual = self::playerVisual([(int) ($top['id'] ?? 0) => $player], (int) ($top['id'] ?? 0));
                $top['pos'] = $label;
                $top['photo'] = $visual['photo'];
                $top['shirt'] = $visual['shirt'] ?? ($player['shirt'] ?? null);
                $top['fixtures'] = $run;
                $top['reason'] = self::differentialReasonSentence(
                    (string) $top['name'],
                    (float) $top['ownership'],
                    (float) $top['proj'],
                    (float) $top['price']
                );
                $picks[] = $top;
            }
        }

        return [
            'title' => 'Differential finder',
            'items' => $picks,
            'empty' => $picks === []
                ? 'No clear differentials stood out outside your squad this week.'
                : null,
            'note' => null,
        ];
    }

    /**
     * @param array<string, mixed> $tips
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>
     */
    public static function refreshSnipersSection(array $tips, array $squad, array $catalogue): array
    {
        $tips['sections']['snipers'] = self::buildSnipers($squad, $catalogue);
        return $tips;
    }

    private static function differentialReasonSentence(
        string $name,
        float $ownership,
        float $proj,
        float $price
    ): string {
        $name = trim($name) !== '' ? trim($name) : 'This player';
        $own = number_format($ownership, 1);
        $points = number_format($proj, 1);
        $cost = number_format($price, 1);

        if ($ownership <= 5.0) {
            return $name . ' is owned by just ' . $own
                . '%. He is predicted to score ' . $points
                . ' points this gameweek and costs £' . $cost . '.';
        }

        if ($ownership <= 12.0) {
            return $name . ' is still low owned at ' . $own
                . '%. He is predicted to score ' . $points
                . ' points this gameweek for £' . $cost . '.';
        }

        return $name . ' looks like strong value at £' . $cost
            . '. He is predicted to score ' . $points
            . ' points this gameweek with ' . $own . '% ownership.';
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, string>
     */
    private static function narrate(array $facts): array
    {
        if (!OpenAiClient::configured()) {
            return [];
        }

        try {
            $result = OpenAiClient::chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You write short Metro Football tip copy for one FPL manager.
Only use the JSON facts given. Do not invent players or numbers.
Voice: We or Metro Football. Simple words. No exclamation marks. No dashes.
Return JSON with five short strings.
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'metro_ai_tips_pack_v1',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'transfers_note' => ['type' => 'string'],
                        'captain_why' => ['type' => 'string'],
                        'drop_note' => ['type' => 'string'],
                        'chip_why' => ['type' => 'string'],
                        'snipers_note' => ['type' => 'string'],
                    ],
                    'required' => [
                        'transfers_note',
                        'captain_why',
                        'drop_note',
                        'chip_why',
                        'snipers_note',
                    ],
                ]
            );

            $out = [];
            foreach (['transfers_note', 'captain_why', 'drop_note', 'chip_why', 'snipers_note'] as $key) {
                $text = trim((string) ($result[$key] ?? ''));
                if ($text === '') {
                    continue;
                }
                if (strlen($text) > 220) {
                    $text = substr($text, 0, 217) . '...';
                }
                $out[$key] = $text;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @param list<array<string, mixed>> $fixtures */
    private static function avgFdr(array $fixtures): float
    {
        if ($fixtures === []) {
            return 3.0;
        }
        $sum = 0;
        foreach ($fixtures as $fx) {
            $sum += (int) ($fx['fdr'] ?? 3);
        }
        return $sum / count($fixtures);
    }

    private static function chipLabel(string $chip): string
    {
        return match (strtolower($chip)) {
            'wildcard', 'wildcard1', 'wildcard2' => 'Wildcard',
            'freehit', 'freehit1', 'freehit2' => 'Free Hit',
            'bboost', 'benchboost' => 'Bench Boost',
            '3xc', 'triplecaptain' => 'Triple Captain',
            default => $chip,
        };
    }
}
