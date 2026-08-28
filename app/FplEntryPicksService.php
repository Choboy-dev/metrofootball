<?php

declare(strict_types=1);

namespace App;

/**
 * Resolves the squad FPL shows on "My Team" for a planning gameweek.
 * Public event picks may 404 before publish — we rebuild from transfers or a saved draft.
 */
final class FplEntryPicksService
{
    /**
     * @param list<array<string, mixed>> $events
     * @return array{
     *   current: ?array<string, mixed>,
     *   next: ?array<string, mixed>,
     *   previous: ?array<string, mixed>,
     *   planning_event: array<string, mixed>,
     *   editable: bool,
     *   is_live_locked: bool,
     *   scores_event: ?array<string, mixed>
     * }
     */
    public static function resolveContext(array $events): array
    {
        $current = null;
        $next = null;
        $previous = null;
        foreach ($events as $event) {
            if (!empty($event['is_current'])) {
                $current = $event;
            }
            if (!empty($event['is_next'])) {
                $next = $event;
            }
            if (!empty($event['is_previous'])) {
                $previous = $event;
            }
        }

        $editable = $next !== null;
        $isLiveLocked = $current !== null
            && empty($current['finished'])
            && $next === null;
        $planningEvent = $next ?? $current;
        if (!$planningEvent) {
            throw new \RuntimeException('No gameweek is available for picks right now.');
        }

        $scoresEvent = null;
        if ($current !== null && !empty($current['finished'])) {
            $scoresEvent = $current;
        } elseif ($current !== null && empty($current['finished'])) {
            $scoresEvent = $current;
        }

        return [
            'current' => $current,
            'next' => $next,
            'previous' => $previous,
            'planning_event' => $planningEvent,
            'editable' => $editable,
            'is_live_locked' => $isLiveLocked,
            'scores_event' => $scoresEvent,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<int>
     */
    public static function baseGameweekCandidates(array $events, array $context): array
    {
        $current = $context['current'];
        $next = $context['next'];
        $previous = $context['previous'];

        if (!empty($context['is_live_locked']) && $current) {
            return [(int) $current['id']];
        }

        if (!empty($context['editable']) && $next) {
            $candidateGws = [(int) $next['id']];
            if ($current) {
                $candidateGws[] = (int) $current['id'];
            }
            if ($previous) {
                $candidateGws[] = (int) $previous['id'];
            }
            return array_values(array_unique($candidateGws));
        }

        $candidateGws = [];
        if ($current) {
            $candidateGws[] = (int) $current['id'];
        }
        if ($previous) {
            $candidateGws[] = (int) $previous['id'];
        }
        if ($next) {
            $candidateGws[] = (int) $next['id'];
        }
        if (!$candidateGws) {
            $candidateGws[] = 1;
        }

        return array_values(array_unique($candidateGws));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{
     *   payload: array<string, mixed>,
     *   event_id: int,
     *   source: string,
     *   fallback: bool
     * }
     */
    public static function resolveForGameweek(
        int $entryId,
        array $events,
        int $targetGameweekId,
        ?int $userId = null
    ): array {
        $context = self::resolveContext($events);
        $targetGameweekId = max(1, $targetGameweekId);

        $direct = FplClient::tryFetchEventPicks($entryId, $targetGameweekId);
        if ($direct !== null && !empty($direct['picks'])) {
            return [
                'payload' => $direct,
                'event_id' => $targetGameweekId,
                'source' => 'event_picks',
                'fallback' => false,
            ];
        }

        $basePayload = null;
        $baseGameweekId = 0;
        $sortedEvents = $events;
        usort($sortedEvents, static fn (array $a, array $b): int => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));
        foreach ($sortedEvents as $event) {
            $gw = (int) ($event['id'] ?? 0);
            if ($gw < 1 || $gw >= $targetGameweekId) {
                continue;
            }
            $candidate = FplClient::tryFetchEventPicks($entryId, $gw);
            if ($candidate !== null && !empty($candidate['picks'])) {
                $basePayload = $candidate;
                $baseGameweekId = $gw;
                break;
            }
        }

        if ($basePayload !== null) {
            $rebuilt = self::rebuildPicksPayload($entryId, $basePayload, $targetGameweekId, $baseGameweekId);
            if ($rebuilt !== null) {
                return [
                    'payload' => $rebuilt,
                    'event_id' => $targetGameweekId,
                    'source' => 'transfers_rebuild',
                    'fallback' => true,
                ];
            }
        }

        if ($userId !== null) {
            $draft = PickDraftService::get($userId, $entryId, $targetGameweekId);
            $fromDraft = self::picksFromDraft($draft);
            if ($fromDraft !== null) {
                return [
                    'payload' => $fromDraft,
                    'event_id' => $targetGameweekId,
                    'source' => 'saved_draft',
                    'fallback' => true,
                ];
            }
        }

        if ($basePayload !== null) {
            return [
                'payload' => $basePayload,
                'event_id' => $baseGameweekId,
                'source' => 'latest_event_picks',
                'fallback' => true,
            ];
        }

        throw new \RuntimeException(
            'Your FPL squad is not available yet. Try again closer to the gameweek deadline.'
        );
    }

    /**
     * Active squad shown on FPL "My Team" while planning the next gameweek.
     *
     * @param list<array<string, mixed>> $events
     * @return array{
     *   payload: array<string, mixed>,
     *   event_id: int,
     *   source: string,
     *   fallback: bool,
     *   planning_event_id: int,
     *   scores_event_id: ?int
     * }
     */
    public static function resolveActiveSquad(int $entryId, array $events, ?int $userId = null): array
    {
        $context = self::resolveContext($events);
        $planningEventId = (int) ($context['planning_event']['id'] ?? 0);
        $resolved = self::resolveForGameweek($entryId, $events, $planningEventId, $userId);

        return [
            'payload' => $resolved['payload'],
            'event_id' => $resolved['event_id'],
            'source' => $resolved['source'],
            'fallback' => $resolved['fallback'],
            'planning_event_id' => $planningEventId,
            'scores_event_id' => isset($context['scores_event']['id'])
                ? (int) $context['scores_event']['id']
                : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $draft
     * @return array<string, mixed>|null
     */
    public static function picksFromDraft(?array $draft): ?array
    {
        if ($draft === null) {
            return null;
        }
        $squad = $draft['squad'] ?? null;
        if (!is_array($squad) || count($squad) !== 15) {
            return null;
        }

        $picks = [];
        foreach ($squad as $player) {
            if (!is_array($player)) {
                return null;
            }
            $position = (int) ($player['position'] ?? 0);
            if ($position < 1 || $position > 15) {
                return null;
            }
            $picks[] = [
                'element' => (int) ($player['id'] ?? 0),
                'position' => $position,
                'is_captain' => !empty($player['is_captain']),
                'is_vice_captain' => !empty($player['is_vice_captain']),
                'multiplier' => (int) ($player['multiplier'] ?? ($position <= 11 ? 1 : 0)),
                'element_type' => (int) ($player['element_type'] ?? 0),
            ];
        }

        usort($picks, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            'picks' => $picks,
            'active_chip' => null,
            'entry_history' => [
                'bank' => (int) round(((float) ($draft['bank'] ?? 0)) * 10),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $basePayload
     */
    public static function rebuildPicksPayload(
        int $entryId,
        array $basePayload,
        int $targetGameweekId,
        int $baseGameweekId
    ): ?array {
        $transfers = FplClient::fetchEntryTransfers($entryId);
        if (!is_array($transfers) || $transfers === []) {
            return null;
        }

        $picks = $basePayload['picks'] ?? null;
        if (!is_array($picks) || $picks === []) {
            return null;
        }

        $applicable = array_values(array_filter($transfers, static function (array $row) use ($baseGameweekId, $targetGameweekId): bool {
            $event = (int) ($row['event'] ?? 0);
            return $event > $baseGameweekId && $event <= $targetGameweekId;
        }));
        if ($applicable === []) {
            return null;
        }

        usort($applicable, static fn (array $a, array $b): int => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));

        foreach ($applicable as $transfer) {
            $outId = (int) ($transfer['element_out'] ?? 0);
            $inId = (int) ($transfer['element_in'] ?? 0);
            if ($outId < 1 || $inId < 1) {
                continue;
            }

            $outIndex = null;
            foreach ($picks as $index => $pick) {
                if ((int) ($pick['element'] ?? 0) === $outId) {
                    $outIndex = $index;
                    break;
                }
            }
            if ($outIndex === null) {
                continue;
            }

            $outPick = $picks[$outIndex];
            $position = (int) ($outPick['position'] ?? 0);
            $picks[$outIndex] = [
                'element' => $inId,
                'position' => $position,
                'multiplier' => (int) ($outPick['multiplier'] ?? ($position <= 11 ? 1 : 0)),
                'is_captain' => !empty($outPick['is_captain']),
                'is_vice_captain' => !empty($outPick['is_vice_captain']),
                'element_type' => (int) ($outPick['element_type'] ?? 0),
            ];
        }

        usort($picks, static fn (array $a, array $b): int => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));

        $payload = $basePayload;
        $payload['picks'] = $picks;
        if (isset($payload['entry_history']) && is_array($payload['entry_history'])) {
            $payload['entry_history']['event'] = $targetGameweekId;
        }

        return $payload;
    }
}
