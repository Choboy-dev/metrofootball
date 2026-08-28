<?php

declare(strict_types=1);

namespace App;

/**
 * Suggested transfers from the shared scout sheet + budget rules. No OpenAI.
 */
final class TransferSuggestService
{
    /**
     * @param list<array<string, mixed>> $squad
     * @param list<array<string, mixed>> $catalogue
     * @return list<array<string, mixed>>
     */
    public static function suggest(array $squad, array $catalogue, float $bank, int $freeTransfers = 1): array
    {
        $owned = [];
        foreach ($squad as $player) {
            $owned[(int) $player['id']] = $player;
        }

        $teamCounts = [];
        foreach ($squad as $player) {
            $team = (int) ($player['team'] ?? 0);
            $teamCounts[$team] = ($teamCounts[$team] ?? 0) + 1;
        }

        $candidates = [];
        foreach ($squad as $out) {
            if ((int) ($out['position'] ?? 0) > 11) {
                // Prefer upgrading starters first.
                continue;
            }
            $outId = (int) $out['id'];
            $outProj = (float) (($out['ai']['projections'][0] ?? null) ?? $out['ep_next'] ?? 0);
            $sell = (float) ($out['selling_price'] ?? $out['now_cost'] ?? 0);
            $budget = $bank + $sell;

            foreach ($catalogue as $in) {
                if ((int) $in['id'] === $outId) {
                    continue;
                }
                if (isset($owned[(int) $in['id']])) {
                    continue;
                }
                if ((int) $in['element_type'] !== (int) $out['element_type']) {
                    continue;
                }
                $price = (float) ($in['now_cost'] ?? 0);
                if ($price > $budget + 0.05) {
                    continue;
                }
                $team = (int) ($in['team'] ?? 0);
                $sameClub = $teamCounts[$team] ?? 0;
                if ($team === (int) ($out['team'] ?? 0)) {
                    $sameClub -= 1;
                }
                if ($sameClub >= 3) {
                    continue;
                }

                $inProj = (float) (($in['ai']['projections'][0] ?? null) ?? $in['ep_next'] ?? 0);
                $delta = $inProj - $outProj;
                $outTag = (string) (($out['ai']['tip_tag'] ?? '') ?: '');
                $inTag = (string) (($in['ai']['tip_tag'] ?? '') ?: '');
                $riskOut = in_array($outTag, ['avoid', 'injured', 'rotation'], true);
                if ($delta < 0.4 && !$riskOut) {
                    continue;
                }

                $tagBoost = 0.0;
                if ($riskOut) {
                    $tagBoost += 0.35;
                }
                if ($inTag === 'differential') {
                    $tagBoost += 0.1;
                } elseif ($inTag === 'premium') {
                    $tagBoost += 0.05;
                } elseif ($inTag === 'avoid' || $inTag === 'injured') {
                    continue;
                }

                $hit = $freeTransfers >= 1 ? 0 : 4;
                $net = $delta + $tagBoost - $hit;
                if ($net < 0.25) {
                    continue;
                }

                $reason = 'Higher next GW projection within budget';
                if (in_array($outTag, ['avoid', 'injured', 'rotation'], true)) {
                    $reason = 'Replace flagged ' . $outTag . ' asset';
                } elseif ($inTag === 'differential') {
                    $reason = 'Differential upgrade within budget';
                }

                $candidates[] = [
                    'out_id' => $outId,
                    'out_name' => (string) $out['web_name'],
                    'in_id' => (int) $in['id'],
                    'in_name' => (string) $in['web_name'],
                    'pos' => (string) $out['pos_label'],
                    'price_change' => round($price - $sell, 1),
                    'bank_after' => round($budget - $price, 1),
                    'proj_delta' => round($delta, 2),
                    'hit_points' => $hit,
                    'net_delta' => round($net, 2),
                    'reason' => $reason,
                ];
            }
        }

        usort($candidates, static fn ($a, $b) => $b['net_delta'] <=> $a['net_delta']);

        // One best swap per outgoing player so tips are different ideas, not two options for the same out.
        $bestByOut = [];
        foreach ($candidates as $candidate) {
            $outId = (int) $candidate['out_id'];
            if (!isset($bestByOut[$outId])) {
                $bestByOut[$outId] = $candidate;
            }
        }

        $diverse = array_values($bestByOut);
        usort($diverse, static fn ($a, $b) => $b['net_delta'] <=> $a['net_delta']);
        return array_slice($diverse, 0, 5);
    }
}
