<?php

declare(strict_types=1);

namespace App;

/**
 * Shared pick page load: fast squad shell + deferred enrichment.
 */
final class PickPageService
{
    /**
     * @return array{user_id: int, user: array<string, mixed>, entry_id: int, premium: bool}
     */
    public static function authenticate(): array
    {
        Session::start();
        $userId = Session::userId();
        if (!$userId) {
            Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
        }

        $user = AuthService::findUserById($userId);
        if (!$user) {
            Session::logout();
            Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
        }

        if (empty($user['fpl_team_id'])) {
            Response::json(['ok' => false, 'error' => 'Link your FPL team first.', 'redirect' => '/link-team'], 422);
        }

        Session::release();

        $premium = ($user['plan'] ?? '') === 'premium'
            && ($user['subscription_status'] ?? '') === 'active';

        return [
            'user_id' => $userId,
            'user' => $user,
            'entry_id' => (int) $user['fpl_team_id'],
            'premium' => $premium,
        ];
    }

    /**
     * Fast path: squad, catalogue, event meta, draft overlay. No AI enrichment.
     *
     * @return array{pick: array<string, mixed>, cache: string, premium: bool, event_id: int}
     */
    public static function buildShell(int $userId, int $entryId, bool $premium): array
    {
        $result = PickTeamService::buildForEntry($entryId);
        $pick = $result['pick'];
        $eventId = (int) ($pick['event']['id'] ?? 0);

        $pick = self::applyDraftOverlay($userId, $entryId, $eventId, $pick);

        $editable = !empty($pick['editable']);
        if ($premium) {
            $pick['locks'] = [
                'editing' => !$editable,
                'predicted' => false,
                'suggested_transfers' => false,
                'optimise' => false,
                'chips' => false,
                'premium_card_stats' => false,
            ];
            $pick['suggested_transfers'] = [];
        } else {
            $pick['locks']['editing'] = !$editable;
            $pick['predicted_points'] = null;
            $pick['suggested_transfers'] = [];
        }

        $pick['ai'] = [
            'model' => 'shared-scout',
            'source' => 'pending',
            'cached' => true,
            'notes' => '',
            'tips_remaining' => TeamAiService::tipsRemaining($userId),
            'enriched' => false,
        ];

        return [
            'pick' => $pick,
            'cache' => $result['cache'],
            'premium' => $premium,
            'event_id' => $eventId,
        ];
    }

    /**
     * Slow path: player scores, strength, predicted points, transfer suggestions.
     *
     * @return array{pick: array<string, mixed>, ai_source: string}
     */
    public static function enrich(int $userId, int $entryId, bool $premium): array
    {
        $result = PickTeamService::buildForEntry($entryId);
        $pick = $result['pick'];
        $eventId = (int) ($pick['event']['id'] ?? 0);
        $pick = self::applyDraftOverlay($userId, $entryId, $eventId, $pick);

        $context = PickTeamService::lastFetchContext();
        $bootstrap = $context['bootstrap'] ?? null;
        $fixtures = $context['fixtures'] ?? null;

        try {
            $scoreMap = PlayerAiScout::ensureScores($eventId, $bootstrap, $fixtures);
        } catch (\Throwable $e) {
            $scoreMap = PlayerAiScoreStore::loadMap($eventId);
            if (!is_array($scoreMap)) {
                $scoreMap = [];
            }
        }

        $pick['players'] = TeamScoreComposer::enrichPlayers($pick['players'], $scoreMap);
        $pick['squad'] = TeamScoreComposer::enrichPlayers($pick['squad'], $scoreMap);
        $pick['squad'] = PickTeamService::syncSquadFixturesFromCatalogue($pick['squad'], $pick['players']);
        if (is_array($pick['fpl_squad'] ?? null)) {
            $pick['fpl_squad'] = TeamScoreComposer::enrichPlayers($pick['fpl_squad'], $scoreMap);
            $pick['fpl_squad'] = PickTeamService::syncSquadFixturesFromCatalogue($pick['fpl_squad'], $pick['players']);
        }

        $composed = TeamScoreComposer::compose($pick['squad'], $scoreMap);
        if (empty($pick['strength'])) {
            $pick['strength'] = $composed['strength'];
        }

        $editable = !empty($pick['editable']);
        $pick['ai'] = [
            'model' => 'shared-scout',
            'source' => $composed['source'],
            'cached' => true,
            'notes' => '',
            'tips_remaining' => TeamAiService::tipsRemaining($userId),
            'enriched' => true,
        ];

        if ($premium) {
            $pick['locks'] = [
                'editing' => !$editable,
                'predicted' => false,
                'suggested_transfers' => false,
                'optimise' => false,
                'chips' => false,
                'premium_card_stats' => false,
            ];
            if (!is_array($pick['predicted_points'] ?? null)) {
                $pick['predicted_points'] = $composed['predicted_points'];
            }
            if ($editable) {
                try {
                    $pick['suggested_transfers'] = TransferSuggestService::suggest(
                        $pick['squad'],
                        $pick['players'],
                        (float) ($pick['bank'] ?? 0),
                        (int) ($pick['free_transfers'] ?? 1)
                    );
                } catch (\Throwable $e) {
                    $pick['suggested_transfers'] = [];
                }
            } else {
                $pick['suggested_transfers'] = [];
            }
        } else {
            $pick['locks']['editing'] = !$editable;
            $pick['predicted_points'] = null;
            $pick['suggested_transfers'] = [];
        }

        return [
            'pick' => $pick,
            'ai_source' => $composed['source'],
            'cache' => $result['cache'],
        ];
    }

    /**
     * @param array<string, mixed> $pick
     * @return array<string, mixed>
     */
    private static function applyDraftOverlay(int $userId, int $entryId, int $eventId, array $pick): array
    {
        $fplSquad = $pick['squad'];
        $fplBank = (float) ($pick['bank'] ?? 0);
        $fplPicksEvent = $pick['picks_event'] ?? null;

        $editable = !empty($pick['editable']);
        $draft = $editable ? PickDraftService::get($userId, $entryId, $eventId) : null;
        if ($draft !== null) {
            $pick['fpl_squad'] = $fplSquad;
            $pick['fpl_bank'] = $fplBank;
            $pick['fpl_picks_event'] = $fplPicksEvent;
            $pick['squad'] = $draft['squad'];
            $pick['bank'] = (float) $draft['bank'];
            $pick['has_saved_draft'] = true;
            $pick['draft_saved_at'] = $draft['saved_at'];
            if (is_array($draft['strength'])) {
                $pick['strength'] = $draft['strength'];
            }
            if (is_array($draft['predicted_points'])) {
                $pick['predicted_points'] = $draft['predicted_points'];
            }
        } else {
            $pick['fpl_squad'] = $fplSquad;
            $pick['fpl_bank'] = $fplBank;
            $pick['fpl_picks_event'] = $fplPicksEvent;
            $pick['has_saved_draft'] = false;
            $pick['draft_saved_at'] = null;
            if (!$editable) {
                $pick['strength'] = null;
                $pick['predicted_points'] = null;
            }
        }

        return $pick;
    }
}
