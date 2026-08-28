<?php

declare(strict_types=1);

namespace App;

/**
 * Position-aware expected FPL points (xp-engine-v12).
 *
 * Scorecards follow hub-style signal emphasis (free FPL fields only):
 * - GK: CS, GC/xGC, saves (+ pen saves), bonus; club defence separates premiums
 * - DEF: CS, GC/xGC, aerial/attack threat, set pieces, defensive contribution
 * - MID: creativity/xA, xG/threat, set-piece duties, mild def contribution
 * - FWD: xG, threat, pens, xA; no clean-sheet path (own FDR bands vs MID)
 *
 * Secure projections are then aligned to FDR/opponent bands ×
 * position-specific quality (per-player FPL rates + team strength).
 * No player-name overrides.
 */
final class PlayerProjectionEngine
{
    public const MODEL = 'xp-engine-v12';
    public const HORIZON = 6;

    /** @var array<int, float> */
    private const CS_BY_FDR = [
        1 => 0.52,
        2 => 0.42,
        3 => 0.32,
        4 => 0.22,
        5 => 0.13,
    ];

    /** @var array<int, float> */
    private const POS_BENCHMARK = [
        1 => 4.0,
        2 => 4.2,
        3 => 5.0,
        4 => 4.8,
    ];

    /**
     * @param array<string, mixed> $player
     * @return array{
     *   element_id:int,
     *   projections:list<float>,
     *   strength:float,
     *   tip_tag:string,
     *   model:string,
     *   source:string
     * }
     */
    public static function score(array $player): array
    {
        $pos = (int) ($player['pos'] ?? $player['element_type'] ?? 0);
        $avail = self::availability($player);
        $minsCertainty = max(0.05, min(1.0, (float) ($player['mins_certainty'] ?? 0.7)));
        $playProb = max(0.05, min(1.0, $minsCertainty));
        $minutes = max(0, (int) ($player['minutes'] ?? 0));

        $role = self::ictRoleBoost($player, $pos);
        $xg90 = self::rateXg90($player);
        $xa90 = self::rateXa90($player);
        // ICT fills gaps; barely stacks when underlying rates are already strong.
        $xg90 += self::fillBoost($xg90, $role['xg'], 0.28);
        $xa90 += self::fillBoost($xa90, $role['xa'], 0.22);
        $xg90 = self::shrinkAttackRate($xg90, $minutes, $pos, 'xg');
        $xa90 = self::shrinkAttackRate($xa90, $minutes, $pos, 'xa');
        $xgForQuality = $xg90;
        $xaForQuality = $xa90;
        // Set-piece EV is a role constant: apply after shrink so early-season caps
        // do not erase pens / corners.
        $xg90 += self::setPieceXgBoost($player);
        $xa90 += self::setPieceXaBoost($player);
        $xg90 = max(0.0, min(1.15, $xg90));
        $xa90 = max(0.0, min(1.0, $xa90));

        $xgc90 = self::rateXgc90($player);
        $saves90 = max(0.0, (float) ($player['saves90'] ?? 0));
        $epNext = max(0.0, (float) ($player['ep_next'] ?? 0));
        $form = max(0.0, (float) ($player['form'] ?? 0));
        $ppg = max(0.0, (float) ($player['ppg'] ?? $player['points_per_game'] ?? 0));
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $recent = is_array($player['recent'] ?? null) ? $player['recent'] : [];

        $fixtures = array_values($player['fixtures'] ?? []);
        $h2h = is_array($player['h2h'] ?? null) ? $player['h2h'] : [];
        $projections = [];

        for ($i = 0; $i < self::HORIZON; $i++) {
            $fx = $fixtures[$i] ?? null;
            if (!$fx || !empty($fx['blank']) || (int) ($fx['legs'] ?? 1) === 0) {
                $projections[] = 0.0;
                continue;
            }

            $slot = 0.0;
            foreach (self::fixtureLegs($fx) as $leg) {
                $slot += self::scoreLeg(
                    $player,
                    $pos,
                    $leg,
                    $playProb,
                    $xg90,
                    $xa90,
                    $xgc90,
                    $saves90,
                    $h2h,
                    $form,
                    $ppg,
                    $pastPpg,
                    $recent,
                    $role['bonus'],
                    $minutes
                );
            }

            // Position-aware hub-band align (MID/FWD keep calibrated anchors).
            $slot = self::hubAlignXp($slot, $player, $pos, $playProb, $xgForQuality, $xaForQuality, $fx);

            $cong = (float) ($fx['congestion_hours'] ?? 120);
            if ($cong > 0 && $cong < 72) {
                $slot *= 0.94;
            }

            $projections[] = round(max(0.0, min(14.0, $slot)), 1);
        }

        $strength = self::playerStrength($pos, $projections, $playProb, $fixtures, $form, $recent);
        $tag = self::tipTag($player, $strength, $playProb, $avail);

        return [
            'element_id' => (int) ($player['id'] ?? $player['element_id'] ?? 0),
            'projections' => $projections,
            'strength' => (float) $strength,
            'tip_tag' => $tag,
            'model' => self::MODEL,
            'source' => 'deterministic',
        ];
    }

    public static function squadStrengthFromPredicted(float $predictedGw, float $predicted5gw): array
    {
        return [
            'gw' => self::pointsToStrengthPercent($predictedGw),
            'five_gw' => self::pointsToStrengthPercent($predicted5gw / 5.0 * 1.03),
        ];
    }

    private static function pointsToStrengthPercent(float $xiPoints): int
    {
        // Hub-style bands on captain-adjusted XI expected points:
        // 62–65 → 89%, then +1% per 4 pts (66–69 → 90%, …, 100 → 99%).
        // Below 62: same 4-pt step downward.
        if ($xiPoints <= 0) {
            return 5;
        }
        if ($xiPoints >= 100) {
            return 99;
        }
        $pct = (int) floor(($xiPoints - 62.0) / 4.0) + 89;
        return max(5, min(98, $pct));
    }

    /** @param array<string, mixed> $player */
    private static function availability(array $player): float
    {
        $avail = (float) ($player['avail'] ?? 1.0);
        if (isset($player['chance_next']) && !isset($player['avail'])) {
            $chance = $player['chance_next'];
            $avail = $chance === null ? 1.0 : max(0.0, ((float) $chance) / 100.0);
        }
        $status = (string) ($player['status'] ?? 'a');
        if ($status === 'i' || $status === 's' || $status === 'u') {
            $avail = min($avail, 0.2);
        } elseif ($status === 'd') {
            $avail = min($avail, max(0.35, $avail));
        }
        return max(0.0, min(1.0, $avail));
    }

    /**
     * ICT / threat as a role prior. Applied via fillBoost so it does not double-count strong xG.
     *
     * @return array{xg:float,xa:float,bonus:float}
     */
    private static function ictRoleBoost(array $player, int $pos): array
    {
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        $gamesProxy = max(1.0, $minutes / 90.0);
        $threatPg = max(0.0, (float) ($player['threat'] ?? 0)) / $gamesProxy;
        $creativityPg = max(0.0, (float) ($player['creativity'] ?? 0)) / $gamesProxy;
        $influencePg = max(0.0, (float) ($player['influence'] ?? 0)) / $gamesProxy;
        $ictPg = max(0.0, (float) ($player['ict'] ?? 0)) / $gamesProxy;

        $xg = 0.0;
        if ($threatPg >= 55) {
            $xg = 0.22;
        } elseif ($threatPg >= 35) {
            $xg = 0.15;
        } elseif ($threatPg >= 22) {
            $xg = 0.09;
        } elseif ($threatPg >= 12) {
            $xg = 0.05;
        }

        $xa = 0.0;
        if ($creativityPg >= 50) {
            $xa = 0.14;
        } elseif ($creativityPg >= 32) {
            $xa = 0.09;
        } elseif ($creativityPg >= 20) {
            $xa = 0.05;
        } elseif ($creativityPg >= 10) {
            $xa = 0.025;
        }

        if ($ictPg >= 12) {
            $xg += $pos >= 3 ? 0.03 : 0.015;
            $xa += $pos === 3 ? 0.025 : 0.01;
        } elseif ($ictPg >= 7) {
            $xg += $pos >= 3 ? 0.015 : 0.008;
        }

        $bonus = 0.0;
        if ($influencePg >= 40) {
            $bonus = 0.12;
        } elseif ($influencePg >= 25) {
            $bonus = 0.07;
        } elseif ($influencePg >= 14) {
            $bonus = 0.04;
        }

        return [
            'xg' => max(0.0, min(0.28, $xg)),
            'xa' => max(0.0, min(0.22, $xa)),
            'bonus' => max(0.0, min(0.2, $bonus)),
        ];
    }

    /** Only add role boost when the base rate is thin. */
    private static function fillBoost(float $base, float $boost, float $strongAt): float
    {
        if ($boost <= 0) {
            return 0.0;
        }
        if ($base >= $strongAt) {
            return $boost * 0.12;
        }
        if ($base >= $strongAt * 0.65) {
            return $boost * 0.4;
        }
        return $boost * 0.85;
    }

    /**
     * Shrink only the excess above an early-season ceiling.
     * Keeps solid underlying rates; stops one-haul xG90 ≈ 1.0 rockets.
     */
    private static function shrinkAttackRate(float $rate, int $minutes, int $pos, string $kind): float
    {
        $ceiling = match ($pos) {
            4 => $kind === 'xg' ? 0.52 : 0.24,
            3 => $kind === 'xg' ? 0.44 : 0.3,
            2 => $kind === 'xg' ? 0.14 : 0.12,
            default => 0.04,
        };

        $hardCap = match ($pos) {
            4 => 0.72,
            3 => 0.58,
            2 => 0.24,
            default => 0.06,
        };

        if ($minutes >= 540) {
            return max(0.0, min($hardCap, $rate));
        }

        if ($rate <= $ceiling) {
            return max(0.0, min($hardCap, $rate));
        }

        // Keep a growing share of the excess as minutes accrue.
        $progress = min(1.0, max(0.0, $minutes / 540.0));
        $keepExcess = 0.35 + (0.65 * $progress);
        $shrunk = $ceiling + (($rate - $ceiling) * $keepExcess);
        if ($minutes < 180) {
            $shrunk = min($shrunk, $ceiling + 0.16);
        }

        return max(0.0, min($hardCap, $shrunk));
    }

    /** Soft ceiling so premiums do not sit at 10–12 on ordinary fixtures. */
    private static function compressHigh(float $xp, int $pos): float
    {
        $soft = match ($pos) {
            1 => 6.4,
            2 => 6.6,
            3 => 7.4,
            4 => 7.6,
            default => 6.8,
        };
        if ($xp <= $soft) {
            return $xp;
        }
        return $soft + (($xp - $soft) * 0.4);
    }

    /**
     * Position-aware hub-band alignment.
     * MID/FWD: FDR/opponent bands × role quality (keeps hub anchors).
     * GK/DEF: CS-led bands blended with raw scorecard.
     *
     * @param array<string, mixed> $player
     * @param array<string, mixed> $fx
     */
    private static function hubAlignXp(
        float $raw,
        array $player,
        int $pos,
        float $playProb,
        float $xg90,
        float $xa90,
        array $fx
    ): float {
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $price = max(0.0, (float) ($player['price'] ?? 0));
        // Premiums / proven past still get band align even if early minutes are thin.
        $alignFloor = ($pastPpg >= 4.0 || $price >= 7.0) ? 0.34 : 0.55;
        if ($playProb < $alignFloor) {
            return round($raw, 1);
        }

        $leg = self::fixtureLegs($fx)[0] ?? $fx;
        $fdr = (int) max(1, min(5, (int) ($leg['fdr'] ?? 3)));
        $home = !empty($leg['home']);
        $oppDefN = self::normalizeStrength((float) ($leg['opp_def'] ?? 3));
        $oppAttN = self::normalizeStrength((float) ($leg['opp_att'] ?? 3));
        $teamDefN = self::normalizeStrength((float) ($leg['team_def'] ?? 3));

        // Minutes risk: only discount cameos / unused players, not 60+ min starters.
        $minutesPlayed = max(0, (int) ($player['minutes'] ?? 0));
        $startScale = 1.0;
        if ($playProb < 0.8 && $minutesPlayed < 50) {
            $startScale = max(0.72, min(1.0, 0.55 + ($playProb * 0.5)));
        }

        if ($pos === 1) {
            // GK bands sit lower than DEF; club defence is the main premium separator.
            $band = match ($fdr) {
                1 => $home ? 4.85 : 4.45,
                2 => $home ? 3.75 : 3.75,
                3 => $home ? 3.7 : 3.6,
                4 => $home ? 3.55 : 3.3,
                5 => $home ? 2.65 : 2.35,
                default => 3.6,
            };
            if ($teamDefN >= 0.5) {
                $band += ($teamDefN - 0.5) * 3.6;
            } else {
                $band += ($teamDefN - 0.5) * 1.7;
            }
            $band += (0.5 - $oppAttN) * 1.35;
            $q = self::gkQualityIndex($player, $playProb);
            $hubish = max(2.2, min(7.0, $band * $q));
            // Prefer calibrated CS band over early save/form noise.
            return round((($raw * 0.16) + ($hubish * 0.84)) * $startScale, 1);
        }

        if ($pos === 2) {
            // Easy fixtures are not automatic 6s. Club defence + past ppg separate Shaw vs Gabriel.
            // GW2 Hub anchors: Virgil FDR3 H ≈ 4.9, Gabriel FDR4 A ≈ 4.8.
            $band = match ($fdr) {
                1 => $home ? 4.25 : 3.9,
                2 => $home ? 3.95 : 3.65,
                3 => $home ? 3.45 : 3.25,
                4 => $home ? 3.15 : 2.88,
                5 => $home ? 2.6 : 2.35,
                default => 3.4,
            };
            if ($teamDefN >= 0.5) {
                $band += ($teamDefN - 0.5) * ($fdr >= 4 ? 1.8 : 2.4);
            } else {
                $band += ($teamDefN - 0.5) * 0.9;
            }
            $band += (0.5 - $oppAttN) * 0.35;
            $q = self::defQualityIndex($player, $xg90, $xa90, $playProb);
            $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
            $hist = $pastPpg > 0
                ? max(0.72, min(1.1, 0.52 + ($pastPpg / 8.0)))
                : 0.88;
            $hubish = max(2.0, min(6.4, $band * $q * $hist));
            return round((($raw * 0.12) + ($hubish * 0.88)) * $startScale, 1);
        }

        if ($pos < 3) {
            return round($raw, 1);
        }

        $q = $pos === 4
            ? self::fwdQualityIndex($player, $xg90, $xa90)
            : self::midQualityIndex($player, $xg90, $xa90);

        // Soften haul + minutes risk (Palmer), not ordinary starters with thin certainty.
        $rawForm = max(0.0, (float) ($player['form'] ?? 0));
        if ($playProb < 0.8 && $rawForm >= 6.0) {
            $q *= max(0.85, 0.5 + (0.52 * $playProb));
        }

        if ($fdr >= 5) {
            return round(3.4 * $startScale, 1);
        }

        if ($pos === 4) {
            // FWD bands fitted to Hub GW2: Haaland 6.1 (FDR3 A), JP 5.1 (FDR2 H), Isak 4.8 (FDR3 H).
            $band = match ($fdr) {
                1 => $home ? 5.6 : 5.3,
                2 => $home ? 5.55 : 5.05,
                3 => $home ? 5.1 : 5.3,
                4 => $home ? 4.2 : 3.95,
                default => 4.6,
            };
            if ($fdr <= 2) {
                $band += (0.5 - $oppDefN) * 1.6;
            } elseif ($fdr === 3) {
                $band += (0.5 - $oppDefN) * 0.55;
            }
            $band = max(3.0, min(7.2, $band));
            return round($band * $q * $startScale, 1);
        }

        // Cameo / unused MIDs: softer FDR curve.
        $minutesPlayed = max(0, (int) ($player['minutes'] ?? 0));
        if ($minutesPlayed < 50) {
            $soft = match ($fdr) {
                1 => $home ? 5.55 : 5.15,
                2 => $home ? 5.35 : 4.95,
                3 => $home ? 5.05 : 4.85,
                4 => $home ? 4.55 : 4.2,
                default => 4.55,
            };
            if ($fdr <= 2) {
                $soft += (0.5 - $oppDefN) * 1.4;
            }
            $soft = max(3.2, min(6.6, $soft));
            return round($soft * $q * $startScale, 1);
        }

        // MID bands fitted to Hub GW2:
        // Fernandes 6.8 / Mbeumo 5.4 (FDR2 H), Palmer 5.1 (FDR2 H), Szoboszlai 5.1 (FDR3 H), Le Fée 4.8.
        $band = match ($fdr) {
            1 => $home ? 5.9 : 5.5,
            2 => $home ? 5.25 : 4.9,
            3 => $home ? 4.62 : 4.85,
            4 => $home ? 4.25 : 4.55,
            default => 4.4,
        };
        if ($fdr <= 2) {
            $band += (0.5 - $oppDefN) * 1.18;
        }
        $band = max(3.3, min(7.3, $band));

        return round($band * $q * $startScale, 1);
    }

    /** @param array<string, mixed> $player */
    private static function gkQualityIndex(array $player, float $playProb): float
    {
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        $saves90 = max(0.0, (float) ($player['saves90'] ?? 0));
        $form = max(0.0, min(12.0, (float) ($player['form'] ?? 0)));
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $penSaves = (int) ($player['pen_saves'] ?? 0);
        // One-game form hauls inflate cheap GKs early; prefer past + saves.
        $formW = $minutes < 270 ? 0.025 : 0.07;

        $teamCtx = is_array($player['team_ctx'] ?? null) ? $player['team_ctx'] : [];
        $defH = (float) (($teamCtx['def_h'] ?? 0) ?: ($teamCtx['overall_h'] ?? 3));
        $defA = (float) (($teamCtx['def_a'] ?? 0) ?: ($teamCtx['overall_a'] ?? 3));
        $teamDefN = self::normalizeStrength(($defH + $defA) / 2.0);

        $q = 0.88
            + min(0.1, $saves90 / 45.0)
            + min(0.06, $form * $formW)
            + min(0.05, $penSaves * 0.025)
            + (($teamDefN - 0.5) * 0.18)
            + (($playProb - 0.7) * 0.12);
        if ($minutes < 360 && $pastPpg > 0) {
            $q += min(0.08, ($pastPpg - 3.0) / 40.0);
        }
        return max(0.82, min(1.16, $q));
    }

    /**
     * DEF quality from that player's FPL card: aerial/threat, xG, def_con,
     * past-season ppg (early sample), and club defence strength. No name overrides.
     *
     * @param array<string, mixed> $player
     */
    private static function defQualityIndex(array $player, float $xg90, float $xa90, float $playProb): float
    {
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        $gamesProxy = max(0.5, max(1, $minutes) / 90.0);
        $threatPg = max(0.0, (float) ($player['threat'] ?? 0)) / $gamesProxy;
        $influencePg = max(0.0, (float) ($player['influence'] ?? 0)) / $gamesProxy;
        if ($minutes < 180) {
            $threatPg = min($threatPg, 9.0);
            $influencePg = min($influencePg, 36.0);
        }
        $defCon = max(0.0, (float) ($player['def_con'] ?? 0));
        $form = max(0.0, min(12.0, (float) ($player['form'] ?? 0)));
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $pens = (($player['set_pieces']['pens'] ?? null) === 1) ? 1.0 : 0.0;

        // Club defence strength (FPL team strength; fall back to overall when axis is zeroed).
        $teamCtx = is_array($player['team_ctx'] ?? null) ? $player['team_ctx'] : [];
        $defH = (float) ($teamCtx['def_h'] ?? 0);
        $defA = (float) ($teamCtx['def_a'] ?? 0);
        if ($defH <= 0) {
            $defH = (float) ($teamCtx['overall_h'] ?? 3);
        }
        if ($defA <= 0) {
            $defA = (float) ($teamCtx['overall_a'] ?? 3);
        }
        $teamDefN = self::normalizeStrength(($defH + $defA) / 2.0);

        $q = 0.8
            + min(0.14, $xg90 * 0.7)
            + min(0.05, $xa90 * 0.25)
            + min(0.1, $threatPg / 100.0)
            + min(0.06, $influencePg / 260.0)
            + min(0.06, $defCon / 60.0)
            + min(0.03, $form / 180.0)
            + (0.03 * $pens)
            + (($teamDefN - 0.5) * 0.16)
            + (($playProb - 0.7) * 0.1);

        // Past ppg is the main early-season separator (Gabriel vs quieter full-backs).
        if ($minutes < 360 && $pastPpg > 0) {
            if ($pastPpg >= 4.0) {
                $q += min(0.16, ($pastPpg - 3.8) / 16.0);
            } else {
                $q -= min(0.24, (4.2 - $pastPpg) / 5.0);
            }
        }

        return max(0.72, min(1.18, $q));
    }

    /**
     * MID quality: creativity / xA, threat, pens/corners, stable form, past ppg.
     * Fitted to Hub GW2 anchors (Fernandes 6.8, Mbeumo 5.4, Palmer 5.1, Szoboszlai 5.1, Le Fée 4.8).
     *
     * @param array<string, mixed> $player
     */
    private static function midQualityIndex(array $player, float $xg90, float $xa90): float
    {
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        $gameProxy = max(0.5, max(1, $minutes) / 90.0);
        $threatPg = max(0.0, (float) ($player['threat'] ?? 0)) / $gameProxy;
        $creativityPg = max(0.0, (float) ($player['creativity'] ?? 0)) / $gameProxy;
        if ($minutes < 180) {
            $threatPg = min($threatPg, 45.0);
            $creativityPg = min($creativityPg, 55.0);
        }
        $form = self::stableForm((float) ($player['form'] ?? 0), $minutes);
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $pens = 0.0;
        $pOrder = $player['set_pieces']['pens'] ?? null;
        if ($pOrder === 1) {
            $pens = 1.0;
        } elseif ($pOrder === 2) {
            $pens = 0.1;
        }
        $corners = 0.0;
        $cOrder = $player['set_pieces']['corners'] ?? null;
        if ($cOrder === 1) {
            $corners = 1.0;
        } elseif ($cOrder === 2) {
            $corners = 0.28;
        }
        // Primary pen takers already price set-piece upside; do not stack corners fully.
        if ($pens >= 1.0) {
            $corners *= 0.45;
        }

        // One-game xG spikes should not dominate mid quality.
        $xgSig = min($xg90, $minutes < 180 ? 0.38 : 0.65);

        $q = 0.735
            + (0.02 * $xgSig)
            + (0.195 * ($creativityPg / 50.0))
            + (0.085 * $xa90)
            + (0.01 * ($threatPg / 50.0))
            + (0.01 * ($form / 10.0))
            + (0.1 * $pens)
            + (0.155 * $corners);

        // Elite creators (Fernandes-class) get a Hub-sized lift without naming players.
        if ($creativityPg >= 52.0 && $pens >= 1.0) {
            $q += 0.058;
        }

        if ($pastPpg > 0) {
            $q += min(0.1, max(-0.08, ($pastPpg - 5.0) / 18.0));
        }

        return max(0.78, min(1.22, $q));
    }

    /**
     * FWD quality: xG, aerial threat, pens, xA secondary.
     * Fitted to Hub GW2 anchors (Haaland 6.1, João Pedro 5.1, Isak 4.8).
     *
     * @param array<string, mixed> $player
     */
    private static function fwdQualityIndex(array $player, float $xg90, float $xa90): float
    {
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        $gameProxy = max(0.5, max(1, $minutes) / 90.0);
        $threatPg = max(0.0, (float) ($player['threat'] ?? 0)) / $gameProxy;
        if ($minutes < 180) {
            $threatPg = min($threatPg, 40.0);
        }
        $form = self::stableForm((float) ($player['form'] ?? 0), $minutes);
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $pens = (($player['set_pieces']['pens'] ?? null) === 1) ? 1.0 : 0.0;

        if ($minutes < 90) {
            $q = 0.78
                + min(0.12, $pastPpg / 50.0)
                + (0.05 * $pens)
                + min(0.08, $xg90 * 0.15);
            return max(0.78, min(1.12, $q));
        }

        $xgSig = min($xg90, $minutes < 180 ? 0.55 : 0.85);

        $q = 0.78
            + (0.14 * $xgSig)
            + (0.07 * ($threatPg / 50.0))
            + (0.03 * ($form / 10.0))
            + (0.08 * $pens)
            + (0.04 * $xa90);

        // Primary pen elite finishers (Haaland) get a Hub-sized lift; backups do not.
        if ($pens >= 1.0 && $pastPpg >= 6.5 && $xg90 >= 0.55) {
            $q += 0.16;
        } elseif ($pastPpg >= 6.5 && $xg90 >= 0.55) {
            $q += 0.02;
        }

        return max(0.78, min(1.24, $q));
    }

    /** Early-season haul damper so one gameweek form does not rewrite Hub bands. */
    private static function stableForm(float $form, int $minutes): float
    {
        $form = max(0.0, min(12.0, $form));
        if ($minutes >= 360) {
            return $form;
        }

        return (min($form, 3.8) * 0.55) + (min($form, 6.0) * 0.2);
    }
    /** @deprecated kept for any old callers; prefer mid/fwd indexes */
    private static function attackerQualityIndex(array $player, int $pos, float $xg90, float $xa90): float
    {
        return $pos === 4
            ? self::fwdQualityIndex($player, $xg90, $xa90)
            : self::midQualityIndex($player, $xg90, $xa90);
    }

    /** @param array<string, mixed> $player */
    private static function rateXg90(array $player): float
    {
        $xg90 = (float) ($player['xg90'] ?? 0);
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        if ($xg90 <= 0 && $minutes >= 60) {
            $xg = (float) ($player['xg'] ?? 0);
            $xg90 = $xg / max(1.0, $minutes / 90.0);
        }
        $recent = is_array($player['recent'] ?? null) ? $player['recent'] : [];
        $games = (int) ($recent['games'] ?? 0);
        if ($games > 0 && $minutes >= 45) {
            $recentGoals90 = ((float) ($recent['goals'] ?? 0)) * (90.0 / max(45.0, (float) ($recent['avg_mins'] ?? 90)));
            $w = min(0.4, 0.12 + (0.07 * $games));
            if ($games <= 2 && $recentGoals90 > $xg90) {
                // Haul regression: do not let one goal rewrite the rate.
                $w *= 0.35;
            } elseif ($games <= 2 && $recentGoals90 < $xg90) {
                $w *= 0.3;
            }
            $xg90 = ($xg90 * (1 - $w)) + ($recentGoals90 * $w);
        }
        if ($xg90 <= 0.01) {
            $pastPpg = (float) (($player['past']['ppg'] ?? 0));
            $pos = (int) ($player['pos'] ?? 0);
            if ($pastPpg >= 4 && $pos >= 3) {
                $xg90 = $pos === 4 ? 0.28 : 0.18;
            } elseif ($pastPpg >= 3 && $pos === 2) {
                $xg90 = 0.06;
            }
        }

        return max(0.0, min(1.2, $xg90));
    }

    /** @param array<string, mixed> $player */
    private static function setPieceXgBoost(array $player): float
    {
        $boost = 0.0;
        $pens = $player['set_pieces']['pens'] ?? null;
        if ($pens === 1) {
            $boost += 0.2;
        } elseif ($pens === 2) {
            $boost += 0.08;
        } elseif ($pens === 3) {
            $boost += 0.03;
        }
        $fk = $player['set_pieces']['fk'] ?? null;
        if ($fk === 1) {
            $boost += 0.04;
        } elseif ($fk === 2) {
            $boost += 0.02;
        }
        return $boost;
    }

    /** @param array<string, mixed> $player */
    private static function setPieceXaBoost(array $player): float
    {
        $boost = 0.0;
        $corners = $player['set_pieces']['corners'] ?? null;
        if ($corners === 1) {
            $boost += 0.12;
        } elseif ($corners === 2) {
            $boost += 0.05;
        } elseif ($corners === 3) {
            $boost += 0.02;
        }
        $fk = $player['set_pieces']['fk'] ?? null;
        if ($fk === 1) {
            $boost += 0.03;
        }
        return $boost;
    }

    /** @param array<string, mixed> $player */
    private static function rateXa90(array $player): float
    {
        $xa90 = (float) ($player['xa90'] ?? 0);
        $minutes = max(0, (int) ($player['minutes'] ?? 0));
        if ($xa90 <= 0 && $minutes >= 60) {
            $xa = (float) ($player['xa'] ?? 0);
            $xa90 = $xa / max(1.0, $minutes / 90.0);
        }
        $recent = is_array($player['recent'] ?? null) ? $player['recent'] : [];
        $games = (int) ($recent['games'] ?? 0);
        if ($games > 0 && $minutes >= 45) {
            $recentAst90 = ((float) ($recent['assists'] ?? 0)) * (90.0 / max(45.0, (float) ($recent['avg_mins'] ?? 90)));
            $w = min(0.4, 0.12 + (0.07 * $games));
            if ($games <= 2 && $recentAst90 > $xa90) {
                $w *= 0.35;
            } elseif ($games <= 2 && $recentAst90 < $xa90) {
                $w *= 0.3;
            }
            $xa90 = ($xa90 * (1 - $w)) + ($recentAst90 * $w);
        }
        return max(0.0, min(1.0, $xa90));
    }

    /** @param array<string, mixed> $player */
    private static function rateXgc90(array $player): float
    {
        $xgc90 = (float) ($player['xgc90'] ?? 0);
        $recent = is_array($player['recent'] ?? null) ? $player['recent'] : [];
        $games = (int) ($recent['games'] ?? 0);
        if ($games > 0) {
            $recentGc = (float) ($recent['avg_gc'] ?? 0);
            $w = min(0.45, 0.15 + (0.07 * $games));
            if ($games <= 2) {
                $w *= 0.45;
            }
            if ($xgc90 > 0) {
                $xgc90 = ($xgc90 * (1 - $w)) + ($recentGc * $w);
            } else {
                $xgc90 = $recentGc > 0 ? $recentGc : 1.35;
            }
        }
        if ($xgc90 > 0) {
            return max(0.15, min(3.5, $xgc90));
        }
        return 1.35;
    }

    /**
     * @param array<string, mixed> $fx
     * @return list<array<string, mixed>>
     */
    private static function fixtureLegs(array $fx): array
    {
        $legs = $fx['leg_rows'] ?? null;
        if (is_array($legs) && $legs) {
            return array_values($legs);
        }
        return [$fx];
    }

    /**
     * @return array{att:float,def:float,cs:float,gc:float,quality:float,fdr:int}
     */
    private static function contextVotes(array $leg): array
    {
        $fdr = (int) max(1, min(5, (int) ($leg['fdr'] ?? 3)));
        $home = !empty($leg['home']);
        $oppAtt = self::normalizeStrength((float) ($leg['opp_att'] ?? 3));
        $oppDef = self::normalizeStrength((float) ($leg['opp_def'] ?? 3));
        $teamAtt = self::normalizeStrength((float) ($leg['team_att'] ?? 3));
        $teamDef = self::normalizeStrength((float) ($leg['team_def'] ?? 3));
        $oppForm = self::normalizeForm((float) ($leg['opp_form'] ?? 1.0));
        $teamForm = self::normalizeForm((float) ($leg['team_form'] ?? 1.0));

        // Wider FDR spread so hard games actually hurt attack EV (hub-like).
        $fdrAtt = match ($fdr) {
            1 => 0.9,
            2 => 0.74,
            3 => 0.5,
            4 => 0.28,
            5 => 0.1,
            default => 0.5,
        };
        $fdrDef = match ($fdr) {
            1 => 0.86,
            2 => 0.7,
            3 => 0.5,
            4 => 0.3,
            5 => 0.14,
            default => 0.5,
        };

        $homeAtt = $home ? 0.62 : 0.34;
        $homeDef = $home ? 0.58 : 0.36;

        $attContext = (
            ($fdrAtt * 0.42)
            + ((1.0 - $oppDef) * 0.24)
            + ($teamAtt * 0.12)
            + ($teamForm * 0.08)
            + ((1.0 - $oppForm) * 0.06)
            + ($homeAtt * 0.08)
        );
        $defContext = (
            ($fdrDef * 0.32)
            + ((1.0 - $oppAtt) * 0.22)
            + ($teamDef * 0.2)
            + ($teamForm * 0.08)
            + ((1.0 - $oppForm) * 0.08)
            + ($homeDef * 0.1)
        );

        $csP = (
            ((self::CS_BY_FDR[$fdr] ?? 0.28) * 0.24)
            + ($teamDef * 0.30)
            + ((1.0 - $oppAtt) * 0.20)
            + ($teamForm * 0.10)
            + ((1.0 - $oppForm) * 0.10)
            + (($home ? 0.55 : 0.38) * 0.06)
        );
        $csP = max(0.05, min(0.78, $csP));

        $gcContext = (
            ((1.0 - $fdrDef) * 0.26)
            + ($oppAtt * 0.24)
            + ((1.0 - $teamDef) * 0.2)
            + ((1.0 - $teamForm) * 0.12)
            + ($oppForm * 0.1)
            + (($home ? 0.3 : 0.55) * 0.08)
        );

        // Tighter multipliers: easy fixtures lift, hard fixtures cut, no 1.6x rockets.
        $att = max(0.48, min(1.35, 0.52 + $attContext));
        $def = max(0.5, min(1.3, 0.55 + ($defContext * 0.92)));

        return [
            'att' => $att,
            'def' => $def,
            'cs' => $csP,
            'gc' => max(0.35, min(2.8, 0.55 + ($gcContext * 1.55))),
            'quality' => (0.55 * $att) + (0.45 * $def),
            'fdr' => $fdr,
        ];
    }

    /**
     * @param array<string, mixed> $player
     * @param array<string, mixed> $leg
     * @param array<int|string, array<string, mixed>> $h2h
     * @param array<string, mixed> $recent
     */
    private static function scoreLeg(
        array $player,
        int $pos,
        array $leg,
        float $playProb,
        float $xg90,
        float $xa90,
        float $xgc90,
        float $saves90,
        array $h2h,
        float $form,
        float $ppg,
        float $pastPpg,
        array $recent,
        float $roleBonus = 0.0,
        int $minutes = 0
    ): float {
        return match ($pos) {
            1 => self::scoreGkLeg($player, $leg, $playProb, $xgc90, $saves90, $form, $ppg, $pastPpg, $recent, $minutes),
            2 => self::scoreDefLeg($player, $leg, $playProb, $xg90, $xa90, $xgc90, $form, $ppg, $pastPpg, $recent, $roleBonus, $minutes),
            4 => self::scoreFwdLeg($player, $leg, $playProb, $xg90, $xa90, $form, $ppg, $pastPpg, $recent, $roleBonus, $minutes),
            default => self::scoreMidLeg($player, $leg, $playProb, $xg90, $xa90, $form, $ppg, $pastPpg, $recent, $roleBonus, $minutes),
        };
    }

    /** @param array<string, mixed> $player @param array<string, mixed> $leg @param array<string, mixed> $recent */
    private static function scoreGkLeg(
        array $player,
        array $leg,
        float $playProb,
        float $xgc90,
        float $saves90,
        float $form,
        float $ppg,
        float $pastPpg,
        array $recent,
        int $minutes
    ): float {
        $ctx = self::contextVotes($leg);
        $p60 = min(1.0, $playProb * 0.98);
        $appearance = (2.0 * $p60) + (1.0 * max(0.0, $playProb - $p60));
        $heat = self::playerHeat($form, $ppg, $pastPpg, $recent, $playProb);

        // Hub GK: CS first, then GC frailty, saves (incl. pens), bonus.
        $csP = self::csProbability($ctx, $recent, true);
        $cleanPts = 4.0 * $csP * $p60;
        $eGc = max(0.25, min(3.2, (($ctx['gc'] * 0.55) + ($xgc90 * 0.45)) / max(0.55, $ctx['def'])));
        $gcDeduction = ($eGc / 2.0) * $p60 * 0.45;
        $expSaves = max(2.2, $saves90) * (0.8 + ($ctx['gc'] * 0.4));
        $savePts = ($expSaves / 3.0) * $p60 * $heat;
        $penSavePts = min(0.25, ((int) ($player['pen_saves'] ?? 0)) * 0.08) * $playProb;
        $bonus = (0.22 + ($csP * 0.45) + min(0.2, $expSaves / 25.0)) * $playProb;

        $xp = $appearance + $cleanPts + $savePts + $penSavePts + $bonus - $gcDeduction;
        $xp = self::blendRecentAndFloor($xp, 1, $ctx, $playProb, $form, $recent, $minutes, $leg, []);
        return max(0.0, $xp);
    }

    /** @param array<string, mixed> $player @param array<string, mixed> $leg @param array<string, mixed> $recent */
    private static function scoreDefLeg(
        array $player,
        array $leg,
        float $playProb,
        float $xg90,
        float $xa90,
        float $xgc90,
        float $form,
        float $ppg,
        float $pastPpg,
        array $recent,
        float $roleBonus,
        int $minutes
    ): float {
        $ctx = self::contextVotes($leg);
        $p60 = min(1.0, $playProb * 0.98);
        $appearance = (2.0 * $p60) + (1.0 * max(0.0, $playProb - $p60));
        $heat = self::playerHeat($form, $ppg, $pastPpg, $recent, $playProb);

        // Hub DEF: CS + GC, aerial/attack threat, set pieces, clearances/tackles proxy.
        $csP = self::csProbability($ctx, $recent, true);
        $cleanPts = 4.0 * $csP * $p60 * max(0.92, min(1.18, (float) ($ctx['def'] ?? 1)));
        $eGc = max(0.25, min(3.2, (($ctx['gc'] * 0.55) + ($xgc90 * 0.45)) / max(0.55, $ctx['def'])));
        $gcDeduction = ($eGc / 2.0) * $p60 * 0.38;

        $minutesPlayed = max(1, (int) ($player['minutes'] ?? 90));
        $threatPg = max(0.0, (float) ($player['threat'] ?? 0)) / max(0.5, $minutesPlayed / 90.0);
        $influencePg = max(0.0, (float) ($player['influence'] ?? 0)) / max(0.5, $minutesPlayed / 90.0);
        // One-game ICT hauls are noisy early; keep aerial signal but cap the spike.
        if ($minutes < 180) {
            $threatPg = min($threatPg, 9.0);
            $influencePg = min($influencePg, 36.0);
        }
        // Aerial / box threat from FPL threat + influence + xG (set-piece CBs).
        $aerialPts = (
            ($xg90 * 5.2)
            + min(0.55, $threatPg / 120.0)
            + min(0.28, $influencePg / 400.0)
            + ($xa90 * 2.4)
        ) * $ctx['att'] * $p60 * $heat;
        if ($ctx['fdr'] >= 5) {
            $aerialPts *= 0.55;
        } elseif ($ctx['fdr'] <= 2) {
            $aerialPts *= 1.14;
        }

        $defCon = max(0.0, (float) ($player['def_con'] ?? 0));
        $defConPts = min(0.95, ($defCon / 10.0) * 0.38) * $p60;
        $pastPpg = max(0.0, (float) (($player['past']['ppg'] ?? 0)));
        $earlyPastLift = ($minutes < 270 && $pastPpg >= 3.8)
            ? min(0.45, ($pastPpg - 3.5) * 0.08) * $playProb
            : 0.0;
        $bonus = (0.2 + ($csP * 0.45) + ($xg90 * 0.45) + ($roleBonus * 0.7) + $earlyPastLift) * $playProb;

        $xp = $appearance + $aerialPts + $cleanPts + $defConPts + $bonus - $gcDeduction;
        $xp = self::blendRecentAndFloor($xp, 2, $ctx, $playProb, $form, $recent, $minutes, $leg, []);
        return max(0.0, $xp);
    }

    /** @param array<string, mixed> $player @param array<string, mixed> $leg @param array<string, mixed> $recent */
    private static function scoreMidLeg(
        array $player,
        array $leg,
        float $playProb,
        float $xg90,
        float $xa90,
        float $form,
        float $ppg,
        float $pastPpg,
        array $recent,
        float $roleBonus,
        int $minutes
    ): float {
        $ctx = self::contextVotes($leg);
        $p60 = min(1.0, $playProb * 0.98);
        $appearance = (2.0 * $p60) + (1.0 * max(0.0, $playProb - $p60));
        $heat = self::playerHeat($form, $ppg, $pastPpg, $recent, $playProb);

        // Hub MID: creativity/assists, goals/threat, set-piece duties, mild def work.
        $minutesPlayed = max(1, (int) ($player['minutes'] ?? 90));
        $creativityPg = max(0.0, (float) ($player['creativity'] ?? 0)) / max(0.5, $minutesPlayed / 90.0);
        $xaEff = $xa90 + min(0.12, $creativityPg / 350.0);

        $attackPts = (($xg90 * 5.0) + ($xaEff * 3.0)) * $ctx['att'] * $p60 * $heat;
        if ($ctx['fdr'] >= 5) {
            $attackPts *= 0.55;
        } elseif ($ctx['fdr'] <= 2) {
            $attackPts *= 1.18;
        }

        $csP = self::csProbability($ctx, $recent, false);
        $cleanPts = 1.0 * $csP * $p60 * 0.85;
        $defCon = max(0.0, (float) ($player['def_con'] ?? 0));
        $defConPts = min(0.55, ($defCon / 14.0) * 0.28) * $p60;
        $bonus = (0.22 + (($xg90 + $xaEff) * 0.5) + ($roleBonus * 0.75)) * $playProb;

        $xp = $appearance + $attackPts + $cleanPts + $defConPts + $bonus;
        $xp = self::blendRecentAndFloor($xp, 3, $ctx, $playProb, $form, $recent, $minutes, $leg, []);
        return max(0.0, $xp);
    }

    /** @param array<string, mixed> $player @param array<string, mixed> $leg @param array<string, mixed> $recent */
    private static function scoreFwdLeg(
        array $player,
        array $leg,
        float $playProb,
        float $xg90,
        float $xa90,
        float $form,
        float $ppg,
        float $pastPpg,
        array $recent,
        float $roleBonus,
        int $minutes
    ): float {
        $ctx = self::contextVotes($leg);
        $p60 = min(1.0, $playProb * 0.98);
        $appearance = (2.0 * $p60) + (1.0 * max(0.0, $playProb - $p60));
        $heat = self::playerHeat($form, $ppg, $pastPpg, $recent, $playProb);

        // Hub FWD: goals/xG, aerial threat, pens, xA; no CS path.
        $minutesPlayed = max(1, (int) ($player['minutes'] ?? 90));
        $threatPg = max(0.0, (float) ($player['threat'] ?? 0)) / max(0.5, $minutesPlayed / 90.0);
        $xgEff = $xg90 + min(0.08, $threatPg / 500.0);

        $attackPts = (($xgEff * 4.0) + ($xa90 * 3.0)) * $ctx['att'] * $p60 * $heat;
        if ($ctx['fdr'] >= 5) {
            $attackPts *= 0.55;
        } elseif ($ctx['fdr'] <= 2) {
            $attackPts *= 1.2;
        }

        $bonus = (0.25 + (($xgEff + $xa90) * 0.55) + ($roleBonus * 0.8)) * $playProb;
        $xp = $appearance + $attackPts + $bonus;
        $xp = self::blendRecentAndFloor($xp, 4, $ctx, $playProb, $form, $recent, $minutes, $leg, []);
        return max(0.0, $xp);
    }

    /** @param array<string, mixed> $recent */
    private static function playerHeat(float $form, float $ppg, float $pastPpg, array $recent, float $playProb): float
    {
        $formForHeat = min(9.0, $form);
        $ppgForHeat = min(7.0, $ppg);
        $playerHeat = min(1.18, 0.95 + ($formForHeat / 40) + ($ppgForHeat / 80) + (min(6.0, $pastPpg) / 160));
        $recentGames = (int) ($recent['games'] ?? 0);
        $avgPts = max(0.0, (float) ($recent['avg_pts'] ?? 0));
        if ($playProb >= 0.72 && $recentGames > 0 && $recentGames <= 2 && $avgPts < 3.5) {
            $playerHeat = ($playerHeat * 0.55) + (1.0 * 0.45);
        }
        if ($recentGames > 0 && $recentGames <= 2 && $avgPts >= 8.0) {
            $playerHeat = ($playerHeat * 0.6) + (1.0 * 0.4);
        }
        return max(0.92, min(1.18, $playerHeat));
    }

    /** @param array<string, mixed> $ctx @param array<string, mixed> $recent */
    private static function csProbability(array $ctx, array $recent, bool $defensivePos): float
    {
        $csP = (float) ($ctx['cs'] ?? 0.28);
        $recentGames = (int) ($recent['games'] ?? 0);
        if ($recentGames > 0 && $defensivePos) {
            $recentCs = max(0.0, min(1.0, (float) ($recent['cs_rate'] ?? 0)));
            $recentW = min(0.32, 0.12 + (0.05 * $recentGames));
            if ($recentGames <= 2 && $recentCs < $csP && (($ctx['def'] ?? 1) >= 1.05)) {
                $recentW *= 0.4;
            }
            $csP = ($csP * (1 - $recentW)) + ($recentCs * $recentW);
        }
        return max(0.05, min(0.78, $csP));
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $recent
     * @param array<string, mixed> $leg
     * @param array<int|string, array<string, mixed>> $h2h
     */
    private static function blendRecentAndFloor(
        float $xp,
        int $pos,
        array $ctx,
        float $playProb,
        float $form,
        array $recent,
        int $minutes,
        array $leg,
        array $h2h
    ): float {
        $recentGames = (int) ($recent['games'] ?? 0);
        $avgPts = max(0.0, (float) ($recent['avg_pts'] ?? 0));
        $formForHeat = min(9.0, $form);

        if ($recentGames > 0 && $avgPts > 0) {
            $w = min(0.28, 0.1 + (0.05 * $recentGames));
            $recentForBlend = $avgPts;
            if ($recentGames <= 2 && $avgPts >= 7.0) {
                $recentForBlend = 4.8 + (($avgPts - 4.8) * 0.28);
                $w *= 0.45;
            } elseif ($playProb >= 0.72 && $recentGames <= 2 && $avgPts < 3.5 && ($ctx['quality'] ?? 1) >= 1.05) {
                $w *= 0.4;
            }
            if ($minutes < 270) {
                $w *= 0.75;
            }
            $matchupScale = max(0.7, min(1.15, (float) ($ctx['quality'] ?? 1)));
            $xp = ($xp * (1 - $w)) + ($recentForBlend * $playProb * $matchupScale * $w);
        }

        if ($playProb >= 0.72) {
            $baseFloor = match ($pos) {
                1 => 3.05,
                2 => 2.95,
                3 => 3.15,
                4 => 3.1,
                default => 2.6,
            };
            $contextFloor = (0.48 * (float) ($ctx['def'] ?? 1)) + (0.52 * (float) ($ctx['att'] ?? 1));
            $formFloor = min(1.1, 0.94 + ($formForHeat / 55));
            $matchupLift = max(0.0, (((float) ($ctx['quality'] ?? 1)) - 1.0) * 0.7);
            $floor = ($baseFloor * $contextFloor * $playProb * $formFloor) + ($matchupLift * $playProb);
            $fdr = (int) ($ctx['fdr'] ?? 3);
            if ($fdr >= 5) {
                $floor *= 0.7;
            } elseif ($fdr >= 4) {
                $floor *= 0.8;
            }
            $xp = max($xp, $floor);
        }

        return $xp;
    }

    private static function bonusExpectation(
        int $pos,
        float $xg90,
        float $xa90,
        float $csP,
        float $playProb,
        float $form,
        array $recent
    ): float {
        $base = match ($pos) {
            1 => 0.18 + ($csP * 0.35),
            2 => 0.16 + ($csP * 0.32) + ($xg90 * 0.35),
            3 => 0.2 + (($xg90 + $xa90) * 0.45),
            4 => 0.22 + (($xg90 + $xa90) * 0.4),
            default => 0.12,
        };
        $recentBonus = (float) ($recent['avg_bonus'] ?? 0);
        if ($recentBonus > 0) {
            $games = (int) ($recent['games'] ?? 0);
            $w = $games <= 2 ? 0.15 : 0.25;
            $base = ($base * (1 - $w)) + ($recentBonus * $w);
        }
        return max(0.05, min(1.1, $base * $playProb * (0.9 + min(0.2, $form / 50))));
    }

    private static function normalizeStrength(float $value): float
    {
        if ($value <= 0) {
            return 0.5;
        }
        if ($value >= 50) {
            return max(0.0, min(1.0, ($value - 1000.0) / 400.0));
        }
        return max(0.0, min(1.0, ($value - 1.0) / 4.0));
    }

    private static function normalizeForm(float $ppg): float
    {
        return max(0.0, min(1.0, $ppg / 3.0));
    }

    /**
     * @param list<float> $projections
     * @param list<array<string, mixed>> $fixtures
     * @param array<string, mixed> $recent
     */
    private static function playerStrength(
        int $pos,
        array $projections,
        float $playProb,
        array $fixtures,
        float $form,
        array $recent
    ): int {
        $next = (float) ($projections[0] ?? 0);
        $bench = self::POS_BENCHMARK[$pos] ?? 5.0;
        $ratio = $next / max(0.5, $bench);
        $formLift = min(8.0, min(8.0, $form) * 0.7);
        $recentLift = min(5.0, ((float) ($recent['avg_pts'] ?? 0)) * 0.45);

        $score = ($ratio * 68.0) + $formLift + $recentLift + ($playProb * 10.0);
        return (int) max(0, min(100, round($score)));
    }

    /** @param array<string, mixed> $player */
    private static function tipTag(array $player, int $strength, float $playProb, float $avail): string
    {
        $status = (string) ($player['status'] ?? 'a');
        if ($avail < 0.6 || $status === 'i' || $status === 'd') {
            return 'injured';
        }
        if ($playProb < 0.55) {
            return 'rotation';
        }
        $own = (float) ($player['own'] ?? 0);
        $price = (float) ($player['price'] ?? 0);
        if ($strength >= 78 && $own >= 20) {
            return 'premium';
        }
        if ($strength >= 70 && $own < 8) {
            return 'differential';
        }
        if ($price <= 5.5 && $strength >= 60) {
            return 'budget';
        }
        if ($strength < 38) {
            return 'avoid';
        }
        return 'steady';
    }
}
