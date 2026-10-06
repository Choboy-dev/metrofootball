<?php

declare(strict_types=1);

namespace App;

/**
 * Extra AI Tips sections: bench order, roadmap, chip preview, set pieces, hits.
 */
final class AiTipsExtrasService
{
    /**
     * @param array<string, mixed> $pick
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @param array{gw:int,five_gw:int} $strength
     * @param array{gw:float,five_gw:float} $predicted
     * @param array<string, mixed> $sections
     * @return array<string, mixed>
     */
    public static function buildAll(
        int $userId,
        int $entryId,
        array $pick,
        array $squad,
        array $catalogue,
        array $strength,
        array $predicted,
        array $sections
    ): array {
        return [
            'bench_order' => self::buildBenchOrder($squad, $pick),
            'transfer_roadmap' => self::buildTransferRoadmap($squad, $catalogue, $pick),
            'chip_preview' => self::buildChipPreview($squad, $catalogue, $pick, $predicted),
            'set_pieces' => self::buildSetPieces($squad),
            'hit_calculator' => self::buildHitCalculator($squad, $catalogue, $pick),
            'share_card' => self::buildShareMeta($pick, $sections),
        ];
    }

    /**
     * @param array<string, mixed> $tips
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>
     */
    public static function refreshAll(
        array $tips,
        int $userId,
        int $entryId,
        array $pick,
        array $squad,
        array $catalogue,
        array $strength,
        array $predicted
    ): array {
        $sections = is_array($tips['sections'] ?? null) ? $tips['sections'] : [];
        $extras = self::buildAll($userId, $entryId, $pick, $squad, $catalogue, $strength, $predicted, $sections);
        $tips['sections'] = array_merge($sections, $extras);
        return $tips;
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>
     */
    private static function buildBenchOrder(array $squad, array $pick): array
    {
        $bench = [];
        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            if ($pos < 12 || $pos > 15) {
                continue;
            }
            $proj = self::playerProj($player, 0);
            $tag = (string) (($player['ai']['tip_tag'] ?? '') ?: '');
            $fx = is_array($player['fixtures'][0] ?? null) ? $player['fixtures'][0] : [];
            $visual = self::playerVisual($player);
            $bench[] = [
                'id' => (int) ($player['id'] ?? 0),
                'name' => (string) ($player['web_name'] ?? 'Player'),
                'pos' => (string) ($player['pos_label'] ?? ''),
                'team_short' => (string) ($player['team_short'] ?? ''),
                'fpl_position' => $pos,
                'proj' => round($proj, 1),
                'fixture' => (string) (($fx['label'] ?? '') ?: 'TBC'),
                'fdr' => (int) ($fx['fdr'] ?? 3),
                'photo' => $visual['photo'],
                'shirt' => $visual['shirt'],
                'tag' => $tag,
                'reason' => self::benchReason($player, $proj, $tag),
            ];
        }

        usort($bench, static function (array $a, array $b): int {
            $proj = $b['proj'] <=> $a['proj'];
            if ($proj !== 0) {
                return $proj;
            }
            return ($a['fpl_position'] ?? 99) <=> ($b['fpl_position'] ?? 99);
        });

        $rank = 1;
        foreach ($bench as $i => $row) {
            $bench[$i]['order'] = $rank;
            $rank++;
        }

        return [
            'title' => self::benchOrderTitle($pick),
            'items' => $bench,
            'empty' => $bench === [] ? 'No bench players found.' : null,
        ];
    }

    /**
     * @param array<string, mixed> $pick
     */
    private static function benchOrderTitle(array $pick): string
    {
        $gw = self::currentGameweekFromPick($pick);

        return $gw > 0
            ? 'Gameweek ' . $gw . ' advised bench order'
            : 'Advised bench order';
    }

    /**
     * @param array<string, mixed> $pick
     */
    private static function currentGameweekFromPick(array $pick): int
    {
        $currentGw = (int) ($pick['event']['id'] ?? 0);
        if ($currentGw < 1) {
            $eventName = trim((string) ($pick['event']['name'] ?? ''));
            if ($eventName !== '' && preg_match('/(\d+)/', $eventName, $m)) {
                $currentGw = (int) $m[1];
            }
        }

        return max(0, $currentGw);
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @param array<string, mixed> $pick
     * @return array<string, mixed>
     */
    private static function buildTransferRoadmap(array $squad, array $catalogue, array $pick): array
    {
        $bank = (float) ($pick['bank'] ?? 0);
        $ft = (int) ($pick['free_transfers'] ?? 1);
        $eventName = trim((string) ($pick['event']['name'] ?? ''));
        $steps = [];
        $simSquad = $squad;
        $simBank = $bank;
        $simFt = $ft;

        for ($gw = 0; $gw < 3; $gw++) {
            $label = self::roadmapLabel($gw, $pick);
            $suggestions = TransferSuggestService::suggest($simSquad, $catalogue, $simBank, max(1, $simFt));
            $best = null;
            foreach ($suggestions as $row) {
                if ((float) ($row['proj_delta'] ?? 0) >= 0.5) {
                    $best = $row;
                    break;
                }
            }

            if ($best === null) {
                $steps[] = [
                    'label' => $label,
                    'action' => 'hold',
                    'out_name' => null,
                    'in_name' => null,
                    'proj_delta' => 0.0,
                    'note' => 'No clear move yet. We would save the transfer and wait.',
                ];
                $simFt = min(5, $simFt + 1);
                continue;
            }

            $hit = (int) ($best['hit_points'] ?? 0);
            $outPlayer = self::squadPlayerById($simSquad, (int) ($best['out_id'] ?? 0));
            $inPlayer = self::cataloguePlayerById($catalogue, (int) ($best['in_id'] ?? 0));
            $outVisual = $outPlayer !== null ? self::playerVisual($outPlayer) : ['photo' => null, 'shirt' => null];
            $inVisual = $inPlayer !== null ? self::playerVisual($inPlayer) : ['photo' => null, 'shirt' => null];
            $steps[] = [
                'label' => $label,
                'action' => 'transfer',
                'out_name' => (string) ($best['out_name'] ?? ''),
                'in_name' => (string) ($best['in_name'] ?? ''),
                'out_photo' => $outVisual['photo'],
                'out_shirt' => $outVisual['shirt'],
                'in_photo' => $inVisual['photo'],
                'in_shirt' => $inVisual['shirt'],
                'out_team_short' => (string) ($outPlayer['team_short'] ?? ''),
                'in_team_short' => (string) ($inPlayer['team_short'] ?? ''),
                'pos' => (string) ($best['pos'] ?? ''),
                'proj_delta' => round((float) ($best['proj_delta'] ?? 0), 1),
                'hit_points' => $hit,
                'note' => self::roadmapNote($hit, $gw),
            ];

            $simSquad = self::applyVirtualTransfer($simSquad, $catalogue, $best);
            $simBank = (float) ($best['bank_after'] ?? $simBank);
            $simFt = $hit < 0 ? 0 : max(0, $simFt - 1);
        }

        return [
            'title' => '3 gameweek transfer roadmap',
            'event' => $eventName !== '' ? $eventName : null,
            'steps' => $steps,
        ];
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @param array<string, mixed> $pick
     * @param array{gw:float,five_gw:float} $predicted
     * @return array<string, mixed>
     */
    private static function buildChipPreview(
        array $squad,
        array $catalogue,
        array $pick,
        array $predicted
    ): array {
        $current = round((float) ($predicted['gw'] ?? self::xiProjection($squad, true)), 1);
        $bench = 0.0;
        foreach ($squad as $player) {
            if ((int) ($player['position'] ?? 99) > 11) {
                $bench += self::playerProj($player, 0);
            }
        }
        $bench = round($bench, 1);
        $freeHit = round(self::optimalXiProjection($catalogue), 1);
        $bestCaptain = 0.0;
        foreach ($squad as $player) {
            if ((int) ($player['position'] ?? 99) > 11) {
                continue;
            }
            $bestCaptain = max($bestCaptain, self::playerProj($player, 0));
        }
        $tripleCaptain = round($current + ($bestCaptain * 2), 1);
        $benchBoost = round($current + $bench, 1);
        $active = (string) ($pick['active_chip'] ?? '');

        $rows = [
            [
                'chip' => 'freehit',
                'label' => 'Free Hit',
                'projected' => $freeHit,
                'delta' => round($freeHit - $current, 1),
                'note' => 'One week rebuild from the full player pool.',
            ],
            [
                'chip' => 'bboost',
                'label' => 'Bench Boost',
                'projected' => $benchBoost,
                'delta' => round($bench, 1),
                'note' => 'Adds bench points to your XI total.',
            ],
            [
                'chip' => '3xc',
                'label' => 'Triple Captain',
                'projected' => $tripleCaptain,
                'delta' => round($bestCaptain * 2, 1),
                'note' => 'Extra captain points if he starts.',
            ],
            [
                'chip' => 'wildcard',
                'label' => 'Wildcard',
                'projected' => $freeHit,
                'delta' => round($freeHit - $current, 1),
                'note' => 'Long term reset. Preview uses optimal XI this week.',
            ],
        ];

        return [
            'title' => 'Chip preview',
            'current_xi' => $current,
            'active_chip' => $active !== '' ? $active : null,
            'rows' => $rows,
        ];
    }

    private static function setPieceRoleLabel(string $kind, int $order): string
    {
        $ordinals = [
            1 => '1st choice',
            2 => '2nd choice',
            3 => '3rd choice',
            4 => '4th choice',
            5 => '5th choice',
        ];
        $ordinal = $ordinals[$order] ?? ($order . 'th choice');

        return match ($kind) {
            'pen' => $ordinal . ' penalty taker',
            'corner' => $ordinal . ' corner kick taker',
            'fk' => $ordinal . ' free kick taker',
            default => $ordinal . ' set piece taker',
        };
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>
     */
    private static function buildSetPieces(array $squad): array
    {
        $items = [];
        foreach ($squad as $player) {
            $pos = (int) ($player['position'] ?? 99);
            if ($pos > 11) {
                continue;
            }
            $pens = (int) ($player['penalties_order'] ?? 0);
            $corners = (int) ($player['corners_and_indirect_freekicks_order'] ?? 0);
            $fk = (int) ($player['direct_freekicks_order'] ?? 0);
            if ($pens < 1 && $corners < 1 && $fk < 1) {
                continue;
            }
            $roles = [];
            if ($pens >= 1) {
                $roles[] = self::setPieceRoleLabel('pen', $pens);
            }
            if ($corners >= 1) {
                $roles[] = self::setPieceRoleLabel('corner', $corners);
            }
            if ($fk >= 1) {
                $roles[] = self::setPieceRoleLabel('fk', $fk);
            }
            $visual = self::playerVisual($player);
            $items[] = [
                'id' => (int) ($player['id'] ?? 0),
                'name' => (string) ($player['web_name'] ?? 'Player'),
                'team_short' => (string) ($player['team_short'] ?? ''),
                'pos' => (string) ($player['pos_label'] ?? ''),
                'roles' => $roles,
                'photo' => $visual['photo'],
                'shirt' => $visual['shirt'],
            ];
        }

        usort($items, static fn ($a, $b) => count($b['roles']) <=> count($a['roles']));

        return [
            'title' => 'Your players on setpiece duties',
            'items' => $items,
            'empty' => $items === [] ? 'No set piece roles in your starting XI.' : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @param array<string, mixed> $pick
     * @return array<string, mixed>
     */
    private static function buildHitCalculator(array $squad, array $catalogue, array $pick): array
    {
        $bank = (float) ($pick['bank'] ?? 0);
        $ft = (int) ($pick['free_transfers'] ?? 1);
        $raw = TransferSuggestService::suggest($squad, $catalogue, $bank, $ft);
        $items = [];

        foreach (array_slice($raw, 0, 3) as $row) {
            $hit = (int) ($row['hit_points'] ?? 0);
            if ($hit >= 0) {
                continue;
            }
            $deltaGw1 = (float) ($row['proj_delta'] ?? 0);
            $deltaGw2 = self::catalogueProj($catalogue, (int) ($row['in_id'] ?? 0))
                - self::squadProj($squad, (int) ($row['out_id'] ?? 0), 1);
            $twoGwGain = $deltaGw1 + max(0, $deltaGw2);
            $hitCost = abs($hit);
            $worthIt = $twoGwGain >= ($hitCost + 2);
            $outPlayer = self::squadPlayerById($squad, (int) ($row['out_id'] ?? 0));
            $inPlayer = self::cataloguePlayerById($catalogue, (int) ($row['in_id'] ?? 0));
            $outVisual = $outPlayer !== null ? self::playerVisual($outPlayer) : ['photo' => null, 'shirt' => null];
            $inVisual = $inPlayer !== null ? self::playerVisual($inPlayer) : ['photo' => null, 'shirt' => null];
            $items[] = [
                'out_name' => (string) ($row['out_name'] ?? ''),
                'in_name' => (string) ($row['in_name'] ?? ''),
                'out_photo' => $outVisual['photo'],
                'out_shirt' => $outVisual['shirt'],
                'in_photo' => $inVisual['photo'],
                'in_shirt' => $inVisual['shirt'],
                'out_team_short' => (string) ($outPlayer['team_short'] ?? ''),
                'in_team_short' => (string) ($inPlayer['team_short'] ?? ''),
                'pos' => (string) ($row['pos'] ?? ''),
                'hit_cost' => $hitCost,
                'gain_gw1' => round($deltaGw1, 1),
                'gain_gw2' => round(max(0, $deltaGw2), 1),
                'gain_two_gw' => round($twoGwGain, 1),
                'worth_it' => $worthIt,
                'verdict' => $worthIt
                    ? 'Worth the -' . $hitCost . ' points over two gameweeks.'
                    : 'Wait unless you need the points this week.',
            ];
        }

        return [
            'title' => 'Hit calculator',
            'rule' => 'We flag a hit when two gameweek gain is at least the hit cost plus 2 points.',
            'items' => $items,
            'empty' => $items === [] ? 'No transfer this week needs a hit in our model.' : null,
        ];
    }

    /**
     * @param array<string, mixed> $pick
     * @param array<string, mixed> $sections
     * @return array<string, mixed>
     */
    private static function buildShareMeta(array $pick, array $sections): array
    {
        $captain = is_array($sections['captain']['pick'] ?? null) ? $sections['captain']['pick'] : [];
        $transfer = is_array($sections['transfers']['items'][0] ?? null) ? $sections['transfers']['items'][0] : [];
        $chip = is_array($sections['chip'] ?? null) ? $sections['chip'] : [];

        return [
            'title' => 'My ' . (string) ($pick['event']['name'] ?? 'Gameweek') . ' AI plan',
            'team' => (string) ($pick['entry']['team_name'] ?? 'My team'),
            'captain' => (string) ($captain['name'] ?? ''),
            'transfer' => isset($transfer['in_name'], $transfer['out_name'])
                ? (string) $transfer['out_name'] . ' → ' . (string) $transfer['in_name']
                : '',
            'chip' => (string) ($chip['label'] ?? 'Hold chips'),
        ];
    }

    /**
     * @param array<string, mixed> $player
     */
    private static function playerProj(array $player, int $gwIndex): float
    {
        $proj = $player['ai']['projections'][$gwIndex] ?? null;
        if ($proj !== null) {
            return (float) $proj;
        }
        return (float) ($player['ep_next'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $catalogue
     */
    private static function catalogueProj(array $catalogue, int $id): float
    {
        foreach ($catalogue as $player) {
            if ((int) ($player['id'] ?? 0) === $id) {
                return self::playerProj($player, 0);
            }
        }
        return 0.0;
    }

    /**
     * @param list<array<string, mixed>> $squad
     */
    private static function squadProj(array $squad, int $id, int $gwIndex): float
    {
        foreach ($squad as $player) {
            if ((int) ($player['id'] ?? 0) === $id) {
                return self::playerProj($player, $gwIndex);
            }
        }
        return 0.0;
    }

    /**
     * @param list<array<string, mixed>> $squad
     */
    private static function xiProjection(array $squad, bool $captainDouble): float
    {
        $total = 0.0;
        $best = 0.0;
        $hasCaptain = false;
        foreach ($squad as $player) {
            if ((int) ($player['position'] ?? 99) > 11) {
                continue;
            }
            $proj = self::playerProj($player, 0);
            if (!empty($player['is_captain'])) {
                $total += $proj * 2;
                $hasCaptain = true;
            } else {
                $total += $proj;
            }
            $best = max($best, $proj);
        }
        if (!$hasCaptain && $captainDouble) {
            $total += $best;
        }
        return $total;
    }

    /**
     * @param list<array<string, mixed>> $catalogue
     */
    private static function optimalXiProjection(array $catalogue): float
    {
        $byType = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($catalogue as $player) {
            if ((string) ($player['status'] ?? 'a') !== 'a') {
                continue;
            }
            $type = (int) ($player['element_type'] ?? 0);
            if (!isset($byType[$type])) {
                continue;
            }
            $byType[$type][] = self::playerProj($player, 0);
        }
        foreach ($byType as $type => $list) {
            rsort($list);
            $byType[$type] = $list;
        }

        $gk = array_slice($byType[1], 0, 1);
        $def = array_slice($byType[2], 0, 3);
        $mid = array_slice($byType[3], 0, 5);
        $fwd = array_slice($byType[4], 0, 2);
        $pool = array_merge($gk, $def, $mid, $fwd);
        $needed = 11 - count($pool);
        if ($needed > 0) {
            $rest = array_merge(
                array_slice($byType[2], 3),
                array_slice($byType[3], 5),
                array_slice($byType[4], 2)
            );
            rsort($rest);
            $pool = array_merge($pool, array_slice($rest, 0, $needed));
        }

        return array_sum($pool);
    }

    /**
     * @param array<string, mixed> $player
     * @return array{photo:?string,shirt:?string}
     */
    private static function roadmapLabel(int $offset, array $pick): string
    {
        $currentGw = self::currentGameweekFromPick($pick);
        if ($currentGw < 1) {
            return $offset === 0 ? 'This gameweek' : 'Gameweek';
        }

        return 'Gameweek ' . ($currentGw + $offset);
    }

    private static function roadmapNote(int $hit, int $offset): string
    {
        if ($hit < 0) {
            return 'This costs ' . abs($hit) . ' points. Only take it if the gain lasts beyond this week.';
        }
        if ($offset === 0) {
            return 'We would use a free transfer for this move now.';
        }

        return 'We would use a free transfer when that gameweek arrives.';
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array<string, mixed>|null
     */
    private static function squadPlayerById(array $squad, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        foreach ($squad as $player) {
            if ((int) ($player['id'] ?? 0) === $id) {
                return $player;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>|null
     */
    private static function cataloguePlayerById(array $catalogue, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        foreach ($catalogue as $player) {
            if ((int) ($player['id'] ?? 0) === $id) {
                return $player;
            }
        }

        return null;
    }

    private static function playerVisual(array $player): array
    {
        $headshot = $player['headshot'] ?? null;
        if (!$headshot) {
            $photo = (string) ($player['photo'] ?? '');
            $photoId = $photo !== '' ? preg_replace('/\.jpg$/i', '', $photo) : '';
            $headshot = $photoId !== ''
                ? 'https://resources.premierleague.com/premierleague/photos/players/110x140/p' . $photoId . '.png'
                : null;
        }

        return [
            'photo' => is_string($headshot) && $headshot !== '' ? $headshot : null,
            'shirt' => isset($player['shirt']) && is_string($player['shirt']) ? $player['shirt'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $player
     */
    private static function benchReason(array $player, float $proj, string $tag): string
    {
        if (in_array($tag, ['injured', 'avoid'], true)) {
            return 'Do not rely on him this week.';
        }
        if ($tag === 'rotation') {
            return 'Rotation risk. Keep him behind safer subs.';
        }
        if ($proj >= 4) {
            return 'Strong bench option if a starter misses out.';
        }
        if ($proj >= 2.5) {
            return 'Useful cover if you need a late swap.';
        }
        return 'Last resort sub this gameweek.';
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @param array<string, mixed> $transfer
     * @return list<array<string, mixed>>
     */
    private static function applyVirtualTransfer(array $squad, array $catalogue, array $transfer): array
    {
        $outId = (int) ($transfer['out_id'] ?? 0);
        $inId = (int) ($transfer['in_id'] ?? 0);
        $inPlayer = null;
        foreach ($catalogue as $player) {
            if ((int) ($player['id'] ?? 0) === $inId) {
                $inPlayer = $player;
                break;
            }
        }
        if ($inPlayer === null) {
            return $squad;
        }

        $outPos = 99;
        $next = [];
        foreach ($squad as $player) {
            if ((int) ($player['id'] ?? 0) === $outId) {
                $outPos = (int) ($player['position'] ?? 99);
                continue;
            }
            $next[] = $player;
        }
        $inPlayer['position'] = $outPos;
        $next[] = $inPlayer;
        return $next;
    }
}
