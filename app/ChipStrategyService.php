<?php

declare(strict_types=1);

namespace App;

/**
 * Personal chip strategy planner from the user's current squad and scout projections.
 */
final class ChipStrategyService
{
    private const HORIZON = 14;
    private const MAX_EVENT = 38;
    /** Wait at least this many gameweeks before BB, TC, or WC suggestions. */
    private const PATIENCE_WEEKS = 2;

    /** @var array<string, float> Minimum peak strength per chip. */
    private const CHIP_FLOOR = [
        'bboost' => 16.0,
        '3xc' => 7.5,
        'freehit' => 2.0,
        'wildcard' => 40.0,
    ];

    /** @var array<string, float> Best week must stand out this much above the weakest week in view. */
    private const CHIP_SPREAD = [
        'bboost' => 1.5,
        '3xc' => 0.35,
        'freehit' => 1.0,
        'wildcard' => 10.0,
    ];

    /** @var list<array{id:string,label:string,short:string,max:int}> */
    private const CHIP_META = [
        ['id' => 'bboost', 'label' => 'Bench Boost', 'short' => 'BB', 'max' => 1],
        ['id' => '3xc', 'label' => 'Triple Captain', 'short' => 'TC', 'max' => 1],
        ['id' => 'freehit', 'label' => 'Free Hit', 'short' => 'FH', 'max' => 1],
        ['id' => 'wildcard', 'label' => 'Wildcard', 'short' => 'WC', 'max' => 2],
    ];

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, mixed> $pick
     * @param array{strength:array{gw:int},predicted_points:array{gw:float}} $composed
     * @return array<string, mixed>
     */
    public static function build(int $entryId, array $pick, array $squad, array $composed): array
    {
        $eventId = (int) ($pick['event']['id'] ?? 0);
        $history = FplClient::fetchEntryHistory($entryId);
        $inventory = self::chipInventory($history);
        $timeline = self::buildTimeline($squad, $eventId, $inventory);
        $chips = self::buildChipCards($timeline, $inventory);
        $summary = self::buildSummary($chips, $pick, $composed, $eventId);

        return [
            'entry' => $pick['entry'] ?? null,
            'event' => $pick['event'] ?? null,
            'team_value' => (float) ($pick['team_value'] ?? 0),
            'bank' => (float) ($pick['bank'] ?? 0),
            'strength' => (int) ($composed['strength']['gw'] ?? 0),
            'predicted_points' => (float) ($composed['predicted_points']['gw'] ?? 0),
            'summary' => $summary,
            'chips' => $chips,
            'timeline' => $timeline,
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $history
     * @return array<string, array{available:int,used:int,events:list<int>}>
     */
    private static function chipInventory(array $history): array
    {
        $used = [
            'bboost' => [],
            '3xc' => [],
            'freehit' => [],
            'wildcard' => [],
        ];

        foreach ($history['chips'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::normaliseChipCode((string) ($row['name'] ?? ''));
            $event = isset($row['event']) ? (int) $row['event'] : 0;
            if ($code !== '' && $event > 0 && isset($used[$code])) {
                $used[$code][] = $event;
            }
        }

        $inventory = [];
        foreach (self::CHIP_META as $meta) {
            $id = $meta['id'];
            $count = count($used[$id] ?? []);
            $inventory[$id] = [
                'available' => max(0, $meta['max'] - $count),
                'used' => $count,
                'events' => array_values($used[$id] ?? []),
            ];
        }

        return $inventory;
    }

    private static function normaliseChipCode(string $name): string
    {
        $key = strtolower(trim($name));
        return match ($key) {
            'bboost', 'bench boost', 'benchboost' => 'bboost',
            '3xc', 'triple captain', 'triplecaptain' => '3xc',
            'freehit', 'free hit', 'freehit1', 'freehit2' => 'freehit',
            'wildcard', 'wildcard1', 'wildcard2' => 'wildcard',
            default => '',
        };
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @param array<string, array{available:int,used:int,events:list<int>}> $inventory
     * @return list<array<string, mixed>>
     */
    private static function buildTimeline(array $squad, int $fromEvent, array $inventory): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $eventsById = [];
        foreach ($bootstrap['events'] as $event) {
            if (is_array($event)) {
                $eventsById[(int) ($event['id'] ?? 0)] = $event;
            }
        }

        $rows = [];
        $lastEvent = min(self::MAX_EVENT, $fromEvent + self::HORIZON - 1);

        for ($eventId = $fromEvent; $eventId <= $lastEvent; $eventId++) {
            $offset = $eventId - $fromEvent;
            $rawScores = self::scoreGameweek($squad, $offset);
            $ev = $eventsById[$eventId] ?? [];
            $scores = [
                'bboost' => self::heatScore($rawScores['bboost'], 30.0),
                '3xc' => self::heatScore($rawScores['3xc'], 12.0),
                'freehit' => self::heatScore($rawScores['freehit'], 16.0),
                'wildcard' => self::heatScore($rawScores['wildcard'], 100.0),
            ];

            $rows[] = [
                'event_id' => $eventId,
                'name' => (string) ($ev['name'] ?? ('Gameweek ' . $eventId)),
                'is_current' => !empty($ev['is_current']),
                'is_next' => !empty($ev['is_next']),
                'scores' => $scores,
                'raw' => $rawScores,
                'pick' => null,
            ];
        }

        self::applyTimelinePicks($rows, $inventory, $fromEvent);

        return $rows;
    }

    /**
     * Each chip gets at most one week in the timeline, only on a clear peak.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{available:int,used:int,events:list<int>}> $inventory
     */
    private static function applyTimelinePicks(array &$rows, array $inventory, int $fromEvent): void
    {
        $candidates = [];

        foreach (self::CHIP_META as $meta) {
            $id = $meta['id'];
            if ((int) ($inventory[$id]['available'] ?? 0) <= 0) {
                continue;
            }

            $week = self::bestWeekForChip($id, $rows, $fromEvent, $meta);
            if ($week !== null) {
                $candidates[] = $week;
            }
        }

        usort($candidates, static fn ($a, $b) => $b['priority'] <=> $a['priority'] ?: $b['raw'] <=> $a['raw']);

        $claimed = [];
        foreach ($candidates as $candidate) {
            $eventId = (int) $candidate['event_id'];
            if (isset($claimed[$eventId])) {
                continue;
            }
            $claimed[$eventId] = true;

            foreach ($rows as &$row) {
                if ((int) ($row['event_id'] ?? 0) !== $eventId) {
                    continue;
                }
                $row['pick'] = [
                    'chip' => (string) $candidate['chip'],
                    'label' => (string) $candidate['label'],
                    'short' => (string) $candidate['short'],
                    'heat' => (int) $candidate['heat'],
                ];
                break;
            }
            unset($row);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{id:string,label:string,short:string,max:int} $meta
     * @return array{event_id:int,chip:string,label:string,short:string,heat:int,raw:float,priority:int}|null
     */
    private static function bestWeekForChip(string $id, array $rows, int $fromEvent, array $meta): ?array
    {
        $values = [];

        foreach ($rows as $row) {
            $eventId = (int) ($row['event_id'] ?? 0);
            $offset = $eventId - $fromEvent;
            if ($id !== 'freehit' && $offset < self::PATIENCE_WEEKS) {
                continue;
            }

            $rawRow = is_array($row['raw'] ?? null) ? $row['raw'] : [];
            $values[] = [
                'event_id' => $eventId,
                'row' => $row,
                'value' => self::chipRawValue($id, $rawRow),
            ];
        }

        if ($values === []) {
            return null;
        }

        usort($values, static fn ($a, $b) => $b['value'] <=> $a['value']);

        $best = $values[0];
        $weakest = $values[count($values) - 1]['value'] ?? 0.0;
        $floor = (float) (self::CHIP_FLOOR[$id] ?? 999.0);
        $spreadMin = (float) (self::CHIP_SPREAD[$id] ?? 0.0);
        $spread = $best['value'] - $weakest;

        if ($best['value'] < $floor) {
            return null;
        }

        if ($spread < $spreadMin) {
            return null;
        }

        if ($id === 'freehit' && $best['value'] < 3.0) {
            // Two blanks is rarely worth a Free Hit. Wait for a heavier blank week.
            return null;
        }

        $heat = self::relativeHeat($id, $best['value'], $rows);

        return [
            'event_id' => (int) $best['event_id'],
            'chip' => $id,
            'label' => (string) $meta['label'],
            'short' => (string) $meta['short'],
            'heat' => $heat,
            'raw' => (float) $best['value'],
            'priority' => self::chipPriority($id),
        ];
    }

    /** @param array<string, float|string> $raw */
    private static function chipRawValue(string $id, array $raw): float
    {
        return match ($id) {
            'bboost' => (float) ($raw['bboost'] ?? 0),
            '3xc' => (float) ($raw['3xc'] ?? 0),
            'freehit' => (float) round(((float) ($raw['freehit'] ?? 0)) / 5),
            'wildcard' => (float) ($raw['wildcard'] ?? 0),
            default => 0.0,
        };
    }

    private static function chipPriority(string $id): int
    {
        return match ($id) {
            'freehit' => 100,
            'wildcard' => 80,
            'bboost' => 60,
            '3xc' => 40,
            default => 0,
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function relativeHeat(string $id, float $value, array $rows): int
    {
        $values = [];
        foreach ($rows as $row) {
            $rawRow = is_array($row['raw'] ?? null) ? $row['raw'] : [];
            $values[] = self::chipRawValue($id, $rawRow);
        }

        $max = max($values);
        $min = min($values);
        if ($max <= $min) {
            return 72;
        }

        $ratio = ($value - $min) / ($max - $min);

        return (int) max(55, min(100, round(55 + ($ratio * 45))));
    }

    /**
     * @param list<array<string, mixed>> $squad
     * @return array{bboost:float,3xc:float,freehit:float,wildcard:float,xi:float,captain:string}
     */
    private static function scoreGameweek(array $squad, int $offset): array
    {
        $bench = 0.0;
        $captainProj = 0.0;
        $captainName = '';
        $xi = 0.0;
        $blanks = 0;
        $risk = 0;
        $avgFdr = 0.0;
        $fdrCount = 0;

        foreach ($squad as $player) {
            if (!is_array($player)) {
                continue;
            }
            $pos = (int) ($player['position'] ?? 99);
            $projs = is_array($player['ai']['projections'] ?? null) ? $player['ai']['projections'] : [];
            $proj = (float) ($projs[min($offset, count($projs) - 1)] ?? ($player['proj'] ?? 0));
            $fixtures = is_array($player['fixtures'] ?? null) ? $player['fixtures'] : [];
            $fx = $fixtures[min($offset, max(0, count($fixtures) - 1))] ?? null;
            $tag = (string) (($player['ai']['tip_tag'] ?? '') ?: '');

            if ($pos <= 11) {
                $xi += $proj;
                if ($proj > $captainProj) {
                    $captainProj = $proj;
                    $captainName = (string) ($player['web_name'] ?? 'Captain');
                }
                if ($fx === null) {
                    $blanks++;
                }
                if (in_array($tag, ['avoid', 'injured', 'rotation'], true)) {
                    $risk++;
                }
                if ($fx !== null) {
                    $avgFdr += (int) ($fx['fdr'] ?? 3);
                    $fdrCount++;
                }
            } else {
                $bench += $proj;
            }
        }

        $avgFdr = $fdrCount > 0 ? $avgFdr / $fdrCount : 3.0;
        $wildcard = ($risk * 18) + max(0, ($avgFdr - 3.2) * 12) + max(0, 70 - $xi) * 0.6;

        return [
            'bboost' => round($bench, 1),
            '3xc' => round($captainProj, 1),
            'freehit' => round($blanks * 5.0, 1),
            'wildcard' => round($wildcard, 1),
            'xi' => round($xi, 1),
            'captain' => $captainName,
        ];
    }

    private static function heatScore(float $raw, float $cap): int
    {
        if ($cap <= 0) {
            return 0;
        }
        return (int) max(0, min(100, round(($raw / $cap) * 100)));
    }

    /**
     * @param list<array<string, mixed>> $timeline
     * @param array<string, array{available:int,used:int,events:list<int>}> $inventory
     * @return list<array<string, mixed>>
     */
    private static function buildChipCards(array $timeline, array $inventory): array
    {
        $cards = [];
        foreach (self::CHIP_META as $meta) {
            $id = $meta['id'];
            $inv = $inventory[$id] ?? ['available' => 0, 'used' => 0, 'events' => []];
            $available = (int) ($inv['available'] ?? 0) > 0;
            $usedEvents = is_array($inv['events'] ?? null) ? $inv['events'] : [];

            // Target week must match the coloured bar on the timeline. Never guess from rankings alone.
            $target = $available ? self::targetFromTimeline($id, $timeline) : null;
            $status = $available ? 'available' : 'used';
            $confidence = self::confidence($target['heat'] ?? 0);

            $cards[] = [
                'id' => $id,
                'label' => $meta['label'],
                'short' => $meta['short'],
                'status' => $status,
                'available' => $available,
                'used_events' => $usedEvents,
                'target' => $target,
                'confidence' => $confidence,
                'insights' => self::chipInsights($id, $timeline, $target, $available, $usedEvents),
                'reason' => self::chipReason($id, $target, $available, $usedEvents, $confidence),
            ];
        }

        return $cards;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     * @return array<string, mixed>|null
     */
    private static function targetFromTimeline(string $id, array $timeline): ?array
    {
        foreach ($timeline as $row) {
            $pick = $row['pick'] ?? null;
            if (!is_array($pick) || (string) ($pick['chip'] ?? '') !== $id) {
                continue;
            }

            $rawRow = is_array($row['raw'] ?? null) ? $row['raw'] : [];

            return [
                'event_id' => (int) ($row['event_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'heat' => (int) ($pick['heat'] ?? 0),
                'raw' => self::chipRawValue($id, $rawRow),
                'captain' => (string) ($rawRow['captain'] ?? ''),
                'detail' => self::weekDetail($id, $rawRow),
            ];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     * @param list<int> $usedEvents
     * @return list<array{label:string,value:string}>
     */
    private static function chipInsights(
        string $id,
        array $timeline,
        ?array $target,
        bool $available,
        array $usedEvents
    ): array {
        if (!$available) {
            if ($usedEvents !== []) {
                $when = implode(', ', array_map(static fn ($eventId) => 'GW' . $eventId, $usedEvents));

                return [
                    ['label' => 'Played', 'value' => $when],
                ];
            }

            return [
                ['label' => 'Status', 'value' => 'Already used this season'],
            ];
        }

        if ($target !== null) {
            return self::targetInsights($id, $target);
        }

        return self::watchInsights($id, $timeline);
    }

    /**
     * @param list<array<string, mixed>> $timeline
     * @return array{value:float,name:string,event_id:int,captain:string}|null
     */
    private static function peakInTimeline(string $id, array $timeline): ?array
    {
        $best = null;

        foreach ($timeline as $row) {
            $rawRow = is_array($row['raw'] ?? null) ? $row['raw'] : [];
            $value = self::chipRawValue($id, $rawRow);
            if ($best === null || $value > $best['value']) {
                $best = [
                    'value' => $value,
                    'name' => (string) ($row['name'] ?? ''),
                    'event_id' => (int) ($row['event_id'] ?? 0),
                    'captain' => (string) ($rawRow['captain'] ?? ''),
                ];
            }
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     * @return list<array{label:string,value:string}>
     */
    private static function watchInsights(string $id, array $timeline): array
    {
        $peak = self::peakInTimeline($id, $timeline);
        if ($peak === null) {
            return [];
        }

        $gw = self::gwLabel($peak);

        return match ($id) {
            'bboost' => [
                [
                    'label' => 'Highest bench in the next 14 weeks',
                    'value' => number_format($peak['value'], 1) . ' predicted points in ' . $gw,
                ],
                [
                    'label' => 'What we want',
                    'value' => '16+ predicted bench points on one clear peak week',
                ],
            ],
            '3xc' => [
                [
                    'label' => 'Best captain week',
                    'value' => ($peak['captain'] !== '' ? $peak['captain'] : 'Your captain')
                        . ' with ' . number_format($peak['value'], 1) . ' predicted points in ' . $gw,
                ],
                [
                    'label' => 'What we want',
                    'value' => '7.5+ predicted points on a week that clearly beats the rest',
                ],
            ],
            'freehit' => [
                [
                    'label' => 'Worst blank week',
                    'value' => (int) $peak['value'] . ' starters may not play in ' . $gw,
                ],
                [
                    'label' => 'What we want',
                    'value' => '3+ starters may not play in the same gameweek',
                ],
            ],
            'wildcard' => [
                [
                    'label' => 'Toughest week for your squad',
                    'value' => $gw . ' has the most risk right now',
                ],
                [
                    'label' => 'What we want',
                    'value' => 'Several risky players and one clear tough week before a reset',
                ],
            ],
            default => [],
        };
    }

    /** @param array<string, mixed> $target */
    private static function targetInsights(string $id, array $target): array
    {
        $gw = self::gwLabel($target);
        $insights = [
            ['label' => 'Planned week', 'value' => $gw],
        ];

        match ($id) {
            'bboost' => $insights[] = [
                'label' => 'Bench that week',
                'value' => number_format((float) ($target['raw'] ?? 0), 1) . ' predicted points',
            ],
            '3xc' => $insights[] = [
                'label' => 'Captain that week',
                'value' => ((string) ($target['captain'] ?? 'Your captain'))
                    . ' with ' . number_format((float) ($target['raw'] ?? 0), 1) . ' predicted points',
            ],
            'freehit' => $insights[] = [
                'label' => 'Blank risk',
                'value' => (int) ($target['raw'] ?? 0) . ' starters may not play',
            ],
            'wildcard' => $insights[] = [
                'label' => 'Squad stress',
                'value' => 'Several players look risky before this week',
            ],
            default => null,
        };

        return $insights;
    }

    /**
     * @param array<string, float|string>|null $target
     * @param list<int> $usedEvents
     */
    private static function chipReason(
        string $chipId,
        ?array $target,
        bool $available,
        array $usedEvents,
        string $confidence
    ): string {
        if (!$available) {
            if ($usedEvents !== []) {
                $when = implode(', ', array_map(static fn ($id) => 'GW' . $id, $usedEvents));

                return 'You already played this chip in ' . $when . '.';
            }

            return 'You have used this chip already this season.';
        }
        if ($target === null) {
            return 'Nothing stands out yet for this chip. Keep waiting.';
        }

        $gw = self::gwLabel($target);
        $captain = (string) ($target['captain'] ?? 'Your captain');
        $blanks = (int) round((float) ($target['raw'] ?? 0));

        return match ($chipId) {
            'bboost' => 'Bench Boost looks best in ' . $gw
                . ' because your bench has '
                . number_format((float) ($target['raw'] ?? 0), 1)
                . ' predicted points that week.',
            '3xc' => $captain . ' on your team is the best pick for Triple Captain in ' . $gw
                . ' because his predicted points are '
                . number_format((float) ($target['raw'] ?? 0), 1) . '.',
            'freehit' => 'Free Hit looks best in ' . $gw
                . ' because ' . $blanks . ' of your starters may not play that week.',
            'wildcard' => 'Wildcard looks best before ' . $gw
                . ' because several players in your squad look risky.',
            default => 'This chip looks best in ' . $gw . '.',
        };
    }

    /** @param array<string, float|string> $raw */
    private static function weekDetail(string $chipId, array $raw): string
    {
        $pts = number_format((float) match ($chipId) {
            'bboost' => (float) ($raw['bboost'] ?? 0),
            '3xc' => (float) ($raw['3xc'] ?? 0),
            default => 0.0,
        }, 1);

        return match ($chipId) {
            'bboost' => $pts . ' predicted bench points',
            '3xc' => $pts . ' predicted points',
            'freehit' => (int) round(((float) ($raw['freehit'] ?? 0)) / 5) . ' starters may not play',
            'wildcard' => 'Several players look risky',
            default => '',
        };
    }

    /** @param array<string, mixed> $target */
    private static function gwLabel(array $target): string
    {
        $eventId = (int) ($target['event_id'] ?? 0);
        if ($eventId > 0) {
            return 'GW' . $eventId;
        }

        $name = (string) ($target['name'] ?? '');
        if (preg_match('/(\d+)/', $name, $matches)) {
            return 'GW' . $matches[1];
        }

        return $name !== '' ? $name : 'this week';
    }

    private static function confidence(int $heat): string
    {
        if ($heat >= 78) {
            return 'high';
        }
        if ($heat >= 55) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param list<array<string, mixed>> $chips
     * @param array<string, mixed> $pick
     * @param array{strength:array{gw:int},predicted_points:array{gw:float}} $composed
     * @return array<string, mixed>
     */
    private static function buildSummary(array $chips, array $pick, array $composed, int $eventId): array
    {
        $active = $pick['active_chip'] ?? null;
        if (is_string($active) && $active !== '') {
            return [
                'headline' => 'You have a chip active this gameweek.',
                'lead' => 'Finish this gameweek first, then come back to plan the rest.',
                'focus_chip' => null,
            ];
        }

        $best = null;
        foreach ($chips as $card) {
            if (empty($card['available']) || !is_array($card['target'] ?? null)) {
                continue;
            }
            $heat = (int) ($card['target']['heat'] ?? 0);
            if ($heat < 55) {
                continue;
            }
            if ($best === null || $heat > (int) ($best['target']['heat'] ?? 0)) {
                $best = $card;
            }
        }

        if ($best === null) {
            return [
                'headline' => 'Hold your chips for now.',
                'lead' => 'No week stands out in the next few gameweeks.',
                'focus_chip' => null,
            ];
        }

        $targetEvent = (int) ($best['target']['event_id'] ?? 0);
        $soon = $targetEvent <= $eventId + 2;
        $gw = self::gwLabel(is_array($best['target'] ?? null) ? $best['target'] : []);
        $headline = $soon
            ? ($best['label'] ?? 'A chip') . ' may be worth playing soon.'
            : ($best['label'] ?? 'This chip') . ' looks best in ' . $gw . '.';

        return [
            'headline' => $headline,
            'lead' => (string) ($best['reason'] ?? ''),
            'focus_chip' => $best['id'] ?? null,
        ];
    }
}
