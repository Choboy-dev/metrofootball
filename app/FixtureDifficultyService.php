<?php

declare(strict_types=1);

namespace App;

/**
 * Premier League fixture difficulty ticker for every club.
 */
final class FixtureDifficultyService
{
    private const ALLOWED_HORIZONS = [3, 5, 8];

    /**
     * @return array<string, mixed>
     */
    public static function build(int $horizon = 5): array
    {
        $horizon = in_array($horizon, self::ALLOWED_HORIZONS, true) ? $horizon : 5;

        $bootstrap = FplClient::fetchBootstrapIndex();
        $fixtures = FplClient::fetchFixtures();
        $teamsById = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

        $fromEvent = self::startEventId($events);
        $eventWindow = self::eventWindow($events, $fromEvent, $horizon);
        $byTeamEvent = self::indexFixturesByTeamEvent($fixtures, $teamsById);

        $teams = [];
        foreach ($teamsById as $team) {
            $teamId = (int) ($team['id'] ?? 0);
            if ($teamId < 1) {
                continue;
            }

            $run = [];
            $fdrSum = 0;
            $fdrCount = 0;
            foreach ($eventWindow as $event) {
                $eventId = (int) $event['id'];
                $fx = $byTeamEvent[$teamId][$eventId] ?? null;
                if ($fx === null) {
                    $run[] = [
                        'event' => $eventId,
                        'event_name' => (string) ($event['name'] ?? ('GW' . $eventId)),
                        'blank' => true,
                        'label' => 'Blank',
                        'opponent' => null,
                        'was_home' => null,
                        'fdr' => null,
                    ];
                    continue;
                }

                $fdr = (int) ($fx['fdr'] ?? 3);
                $fdr = max(1, min(5, $fdr));
                $fdrSum += $fdr;
                $fdrCount++;
                $run[] = [
                    'event' => $eventId,
                    'event_name' => (string) ($event['name'] ?? ('GW' . $eventId)),
                    'blank' => false,
                    'label' => (string) $fx['label'],
                    'opponent' => (string) $fx['opponent'],
                    'was_home' => (bool) $fx['was_home'],
                    'fdr' => $fdr,
                ];
            }

            $avg = $fdrCount > 0 ? round($fdrSum / $fdrCount, 2) : 3.0;
            $code = (int) ($team['code'] ?? 0);
            $teams[] = [
                'id' => $teamId,
                'name' => (string) ($team['name'] ?? 'Club'),
                'short_name' => (string) ($team['short_name'] ?? ''),
                'code' => $code,
                'badge' => $code > 0
                    ? 'https://resources.premierleague.com/premierleague/badges/70/t' . $code . '.png'
                    : null,
                'avg_fdr' => $avg,
                'fixtures' => $run,
            ];
        }

        usort($teams, static function ($a, $b) {
            $avg = $a['avg_fdr'] <=> $b['avg_fdr'];
            if ($avg !== 0) {
                return $avg;
            }
            return strcmp((string) $a['short_name'], (string) $b['short_name']);
        });

        $currentName = 'Gameweek';
        foreach ($events as $event) {
            if ((int) ($event['id'] ?? 0) === $fromEvent) {
                $currentName = (string) ($event['name'] ?? $currentName);
                break;
            }
        }

        return [
            'event' => [
                'id' => $fromEvent,
                'name' => $currentName,
            ],
            'horizon' => $horizon,
            'horizons' => self::ALLOWED_HORIZONS,
            'events' => array_map(static fn ($e) => [
                'id' => (int) $e['id'],
                'name' => (string) ($e['name'] ?? ('GW' . $e['id'])),
            ], $eventWindow),
            'teams' => $teams,
            'legend' => [
                ['fdr' => 1, 'label' => 'Easiest'],
                ['fdr' => 2, 'label' => 'Easy'],
                ['fdr' => 3, 'label' => 'Medium'],
                ['fdr' => 4, 'label' => 'Hard'],
                ['fdr' => 5, 'label' => 'Hardest'],
            ],
            'updated_at' => gmdate('c'),
        ];
    }

    /**
     * First upcoming gameweek for planning (next fixtures, not the finished current GW).
     *
     * @param list<array<string, mixed>> $events
     */
    private static function startEventId(array $events): int
    {
        foreach ($events as $event) {
            if (!empty($event['is_next'])) {
                return (int) $event['id'];
            }
        }
        foreach ($events as $event) {
            if (!empty($event['is_current']) && empty($event['finished'])) {
                return (int) $event['id'];
            }
        }
        foreach ($events as $event) {
            if (empty($event['finished'])) {
                return (int) $event['id'];
            }
        }
        $last = end($events);
        return is_array($last) ? (int) ($last['id'] ?? 1) : 1;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function eventWindow(array $events, int $fromEvent, int $horizon): array
    {
        $window = [];
        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            if ($id < $fromEvent) {
                continue;
            }
            $window[] = $event;
            if (count($window) >= $horizon) {
                break;
            }
        }
        return $window;
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function indexFixturesByTeamEvent(array $fixtures, array $teamsById): array
    {
        $index = [];
        foreach ($fixtures as $fx) {
            $event = isset($fx['event']) ? (int) $fx['event'] : 0;
            if ($event < 1) {
                continue;
            }

            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home < 1 || $away < 1) {
                continue;
            }

            $homeShort = (string) ($teamsById[$home]['short_name'] ?? '');
            $awayShort = (string) ($teamsById[$away]['short_name'] ?? '');

            $index[$home][$event] = [
                'opponent' => $awayShort,
                'was_home' => true,
                'fdr' => (int) ($fx['team_h_difficulty'] ?? 3),
                'label' => $awayShort . ' (H)',
            ];
            $index[$away][$event] = [
                'opponent' => $homeShort,
                'was_home' => false,
                'fdr' => (int) ($fx['team_a_difficulty'] ?? 3),
                'label' => $homeShort . ' (A)',
            ];
        }

        return $index;
    }
}
