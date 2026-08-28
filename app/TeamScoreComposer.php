<?php

declare(strict_types=1);

namespace App;

/**
 * Compose team strength / predicted points from the shared scout sheet.
 * No OpenAI calls here.
 */
final class TeamScoreComposer
{
    /**
     * @param list<array<string, mixed>> $squad
     * @param array<int, array<string, mixed>> $scoreMap
     * @return array{
     *   strength: array{gw:int,five_gw:int},
     *   predicted_points: array{gw:float,five_gw:float},
     *   source: string
     * }
     */
    public static function compose(array $squad, array $scoreMap): array
    {
        $xi = array_values(array_filter(
            $squad,
            static fn ($p) => is_array($p) && (int) ($p['position'] ?? 99) <= 11
        ));

        if (!$xi) {
            return [
                'strength' => ['gw' => 0, 'five_gw' => 0],
                'predicted_points' => ['gw' => 0.0, 'five_gw' => 0.0],
                'source' => 'empty',
            ];
        }

        $predGw = 0.0;
        $pred5 = 0.0;
        $source = 'scout';

        foreach ($xi as $player) {
            $id = (int) ($player['id'] ?? 0);
            $score = $scoreMap[$id] ?? null;
            if (!$score) {
                $score = PlayerAiScout::deterministicScore($player);
                $source = 'mixed';
            }

            $projs = array_values(array_map(
                'floatval',
                array_slice(array_pad($score['projections'] ?? [0, 0, 0, 0, 0], 5, 0), 0, 5)
            ));
            $captainMul = !empty($player['is_captain']) ? 2.0 : 1.0;

            $predGw += $projs[0] * $captainMul;
            // Captain double applies to next GW only; later GWs use single projections.
            $pred5 += ($projs[0] * $captainMul) + $projs[1] + $projs[2] + $projs[3] + $projs[4];
        }

        // Squad strength is derived from XI expected points (hub-style), not a flat average of tip scores.
        $strength = PlayerProjectionEngine::squadStrengthFromPredicted($predGw, $pred5);

        return [
            'strength' => $strength,
            'predicted_points' => [
                'gw' => round($predGw, 1),
                'five_gw' => round($pred5, 1),
            ],
            'source' => $source,
        ];
    }

    /**
     * @param list<array<string, mixed>> $players
     * @param array<int, array<string, mixed>> $scoreMap
     * @return list<array<string, mixed>>
     */
    public static function enrichPlayers(array $players, array $scoreMap): array
    {
        foreach ($players as &$player) {
            if (!is_array($player)) {
                continue;
            }
            $id = (int) ($player['id'] ?? 0);
            $score = $scoreMap[$id] ?? null;
            if (!$score || count($score['projections'] ?? []) < PlayerProjectionEngine::HORIZON) {
                $score = PlayerAiScout::deterministicScore($player);
            }
            $projs = array_values(array_map(
                'floatval',
                array_slice(
                    array_pad($score['projections'] ?? [0, 0, 0, 0, 0, 0], PlayerProjectionEngine::HORIZON, 0),
                    0,
                    PlayerProjectionEngine::HORIZON
                )
            ));
            $player['ai'] = [
                'projections' => $projs,
                'strength' => (float) ($score['strength'] ?? 0),
                'tip_tag' => $score['tip_tag'] ?? null,
                'source' => $score['source'] ?? 'deterministic',
            ];
        }
        unset($player);
        return $players;
    }
}
