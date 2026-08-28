<?php

declare(strict_types=1);

namespace App;

/**
 * Weekly FPL Injury Risk Digest.
 * Research (Tavily) runs once per gameweek and is stored.
 * Rewrites and edits use stored research only (OpenAI, no new Tavily calls).
 */
final class InjuryNewsService
{
    private const MODEL = 'injury-news-v1';
    private const RESEARCH_MODEL = 'injury-research-v1';
    private const CACHE_PREFIX = 'injury-news-v1-';
    private const RESEARCH_PREFIX = 'injury-news-research-v1-';
    private const LIVE_KEY = 'injury-news-live-v1';
    private const STORE_SECONDS = 60 * 60 * 24 * 120;
    private const MIN_PLAYERS = 6;
    private const MAX_PLAYERS = 8;
    private const TZ = 'Europe/London';

    /** @var array<int, string> */
    private const POS_LABEL = [
        1 => 'GK',
        2 => 'DEF',
        3 => 'MID',
        4 => 'FWD',
    ];

    /** @var array<string, int> */
    private const STATUS_WEIGHT = [
        'i' => 100,
        'd' => 55,
        's' => 45,
        'u' => 20,
    ];

    /**
     * @return array{ok:bool,needs_generate:bool,post:?array<string,mixed>,target_gw:int,target_name:string}
     */
    public static function getLive(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $target = self::resolveTargetEvent($events);
        $gw = (int) $target['id'];

        $live = FplCache::get(self::LIVE_KEY, self::STORE_SECONDS);
        if (
            is_array($live)
            && ($live['model'] ?? '') === self::MODEL
            && (int) ($live['gameweek'] ?? 0) === $gw
        ) {
            return self::liveResponse($target, $live, false);
        }

        $cached = FplCache::get(self::CACHE_PREFIX . $gw, self::STORE_SECONDS);
        if (is_array($cached) && ($cached['model'] ?? '') === self::MODEL) {
            FplCache::put(self::LIVE_KEY, $cached);
            return self::liveResponse($target, $cached, false);
        }

        return self::liveResponse($target, null, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getStoredResearch(?int $gameweek = null): ?array
    {
        $gw = $gameweek ?? self::targetGameweekId();
        $pack = FplCache::get(self::RESEARCH_PREFIX . $gw, self::STORE_SECONDS);
        if (!is_array($pack) || ($pack['model'] ?? '') !== self::RESEARCH_MODEL) {
            return null;
        }
        return $pack;
    }

    /**
     * Research only. Stored per gameweek. Skips Tavily if research already exists unless $forceResearch.
     *
     * @return array<string, mixed>
     */
    public static function research(bool $forceResearch = false): array
    {
        if (!TavilyClient::configured()) {
            throw new \RuntimeException('Tavily is not configured. Add TAVILY_API_KEY in config/.env');
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $target = self::resolveTargetEvent($events);
        $gw = (int) $target['id'];

        if (!$forceResearch) {
            $existing = self::getStoredResearch($gw);
            if ($existing !== null) {
                return $existing;
            }
        }

        $teamsById = self::teamsById($teams);
        $candidates = self::rankCandidates($elements, $teamsById);
        if (count($candidates) < self::MIN_PLAYERS) {
            throw new \RuntimeException(
                'Not enough injury candidates yet. We need at least '
                . self::MIN_PLAYERS
                . ' flagged players before we can research.'
            );
        }

        $take = min(self::MAX_PLAYERS, count($candidates));
        $selected = array_slice($candidates, 0, $take);
        $playerPacks = [];
        $tavilyCalls = 0;

        foreach ($selected as $player) {
            $pack = self::researchPlayerDeep($player, $gw);
            $tavilyCalls += (int) ($pack['tavily_calls'] ?? 0);
            $playerPacks[] = $pack;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $research = [
            'model' => self::RESEARCH_MODEL,
            'gameweek' => $gw,
            'gameweek_name' => (string) ($target['name'] ?? ('Gameweek ' . $gw)),
            'deadline_time' => (string) ($target['deadline_time'] ?? ''),
            'researched_at' => $now->format(\DateTimeInterface::ATOM),
            'player_count' => count($playerPacks),
            'tavily_calls' => $tavilyCalls,
            'search_depth' => 'advanced',
            'players' => $playerPacks,
        ];

        FplCache::put(self::RESEARCH_PREFIX . $gw, $research);
        return $research;
    }

    /**
     * Write and publish from stored research. No Tavily.
     *
     * @return array<string, mixed>
     */
    public static function publishFromResearch(bool $forceWrite = false): array
    {
        $gw = self::targetGameweekId();
        if (!$forceWrite) {
            $existingPost = FplCache::get(self::CACHE_PREFIX . $gw, self::STORE_SECONDS);
            if (is_array($existingPost) && ($existingPost['model'] ?? '') === self::MODEL) {
                FplCache::put(self::LIVE_KEY, $existingPost);
                return $existingPost;
            }
        }

        if (!OpenAiClient::configured()) {
            throw new \RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY in config/.env');
        }

        $research = self::getStoredResearch($gw);
        if ($research === null) {
            throw new \RuntimeException(
                'No stored research for this gameweek. Run research first: php scripts/publish-injury-news.php --research'
            );
        }

        $target = [
            'id' => $gw,
            'name' => $research['gameweek_name'] ?? ('Gameweek ' . $gw),
            'deadline_time' => $research['deadline_time'] ?? '',
        ];

        $factPacks = self::factPacksFromResearch($research);
        $article = self::writeArticle($factPacks, $target, null);
        $post = self::assemblePost($article, $factPacks, $target, $research);
        $post['cost_controls'] = [
            'players' => count($factPacks),
            'tavily_calls' => 0,
            'openai_only' => true,
            'used_stored_research' => true,
            'researched_at' => $research['researched_at'] ?? null,
            'once_per_gameweek' => true,
        ];

        FplCache::put(self::CACHE_PREFIX . $gw, $post);
        FplCache::put(self::LIVE_KEY, $post);
        return $post;
    }

    /**
     * Full first-time publish: research (if needed) then write. Page visits never call this.
     *
     * @return array<string, mixed>
     */
    public static function generate(bool $force = false, bool $forceResearch = false): array
    {
        $gw = self::targetGameweekId();
        if (!$force) {
            $existingPost = FplCache::get(self::CACHE_PREFIX . $gw, self::STORE_SECONDS);
            if (is_array($existingPost) && ($existingPost['model'] ?? '') === self::MODEL) {
                FplCache::put(self::LIVE_KEY, $existingPost);
                return $existingPost;
            }
        }

        $research = self::research($forceResearch);
        $post = self::publishFromResearch(true);
        $post['cost_controls']['tavily_calls'] = (int) ($research['tavily_calls'] ?? 0);
        $post['cost_controls']['used_stored_research'] = !$forceResearch && isset($research['researched_at']);
        FplCache::put(self::CACHE_PREFIX . $gw, $post);
        FplCache::put(self::LIVE_KEY, $post);
        return $post;
    }

    /**
     * Edit tone or copy using stored research only. No Tavily.
     *
     * @return array<string, mixed>
     */
    public static function edit(string $instructions, ?int $elementId = null): array
    {
        $instructions = trim($instructions);
        if ($instructions === '') {
            throw new \InvalidArgumentException('Edit instructions cannot be empty.');
        }

        if (!OpenAiClient::configured()) {
            throw new \RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY in config/.env');
        }

        $gw = self::targetGameweekId();
        $research = self::getStoredResearch($gw);
        if ($research === null) {
            throw new \RuntimeException(
                'No stored research for this gameweek. Edits must use the saved research pack, not new web searches.'
            );
        }

        $current = FplCache::get(self::CACHE_PREFIX . $gw, self::STORE_SECONDS);
        if (!is_array($current) || ($current['model'] ?? '') !== self::MODEL) {
            throw new \RuntimeException('No published post to edit for this gameweek yet.');
        }

        $target = [
            'id' => $gw,
            'name' => $research['gameweek_name'] ?? ('Gameweek ' . $gw),
            'deadline_time' => $research['deadline_time'] ?? '',
        ];

        $factPacks = self::factPacksFromResearch($research);
        $article = self::writeArticle($factPacks, $target, [
            'mode' => 'edit',
            'instructions' => $instructions,
            'element_id' => $elementId,
            'current_post' => $current,
        ]);

        $post = self::assemblePost($article, $factPacks, $target, $research);
        $post['published_at'] = $current['published_at'] ?? $post['published_at'];
        $post['cost_controls'] = [
            'players' => count($factPacks),
            'tavily_calls' => 0,
            'openai_only' => true,
            'used_stored_research' => true,
            'edit' => true,
            'researched_at' => $research['researched_at'] ?? null,
        ];

        FplCache::put(self::CACHE_PREFIX . $gw, $post);
        FplCache::put(self::LIVE_KEY, $post);
        return $post;
    }

    private static function targetGameweekId(): int
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        return (int) self::resolveTargetEvent($events)['id'];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed>|null $post
     * @return array{ok:bool,needs_generate:bool,post:?array<string,mixed>,target_gw:int,target_name:string}
     */
    private static function liveResponse(array $target, ?array $post, bool $needsGenerate): array
    {
        return [
            'ok' => true,
            'needs_generate' => $needsGenerate,
            'post' => $post !== null ? self::enrichPostForDisplay($post) : null,
            'target_gw' => (int) $target['id'],
            'target_name' => (string) ($target['name'] ?? ('Gameweek ' . $target['id'])),
            'has_research' => self::getStoredResearch((int) $target['id']) !== null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $teams
     * @return array<int, array<string, mixed>>
     */
    private static function teamsById(array $teams): array
    {
        $teamsById = [];
        foreach ($teams as $team) {
            $tid = (int) ($team['id'] ?? 0);
            if ($tid > 0) {
                $teamsById[$tid] = $team;
            }
        }
        return $teamsById;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function resolveTargetEvent(array $events): array
    {
        $next = null;
        $current = null;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!empty($event['is_next'])) {
                $next = $event;
            }
            if (!empty($event['is_current'])) {
                $current = $event;
            }
        }
        $pick = $next ?? $current;
        if ($pick === null) {
            throw new \RuntimeException('No upcoming gameweek is available yet.');
        }
        return $pick;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, mixed>>
     */
    private static function rankCandidates(array $elements, array $teamsById): array
    {
        $rows = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $mapped = self::mapCandidate($el, $teamsById);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['importance'] <=> $a['importance']);
        return $rows;
    }

    /**
     * @param array<string, mixed> $el
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<string, mixed>|null
     */
    private static function mapCandidate(array $el, array $teamsById): ?array
    {
        $id = (int) ($el['id'] ?? 0);
        if ($id < 1) {
            return null;
        }

        $status = strtolower((string) ($el['status'] ?? 'a'));
        $news = trim((string) ($el['news'] ?? ''));
        if ($status === 'a' && $news === '') {
            return null;
        }

        $statusWeight = ($status === 'a' && $news !== '')
            ? 25
            : (self::STATUS_WEIGHT[$status] ?? 15);

        $teamId = (int) ($el['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $own = (float) ($el['selected_by_percent'] ?? 0);
        $price = ((int) ($el['now_cost'] ?? 0)) / 10;
        $points = (int) ($el['total_points'] ?? 0);
        $form = (float) ($el['form'] ?? 0);
        $chance = $el['chance_of_playing_next_round'] ?? $el['chance_of_playing_this_round'] ?? null;
        $chanceVal = is_numeric($chance) ? (float) $chance : null;

        $importance = ($own * 12.0) + ($price * 5.0) + ($points * 1.5) + ($form * 2.5) + $statusWeight;
        if ($chanceVal !== null) {
            $importance += max(0, 100 - $chanceVal) * 0.25;
        }
        if ($news !== '') {
            $importance += 10;
        }

        $pos = (int) ($el['element_type'] ?? 0);
        $photoRaw = trim((string) ($el['photo'] ?? ''));
        $photoCode = preg_replace('/\.(jpg|jpeg|png)$/i', '', $photoRaw) ?? '';
        $teamCode = (int) ($team['code'] ?? 0);
        $shirt = null;
        if ($teamCode > 0) {
            $shirt = $pos === 1
                ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode . '_1-110.webp'
                : 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode . '-110.webp';
        }
        $photoUrl = $photoCode !== ''
            ? 'https://resources.premierleague.com/premierleague/photos/players/250x250/p' . $photoCode . '.png'
            : null;

        return [
            'element_id' => $id,
            'web_name' => (string) ($el['web_name'] ?? ''),
            'full_name' => trim(
                trim((string) ($el['first_name'] ?? '')) . ' ' . trim((string) ($el['second_name'] ?? ''))
            ),
            'team_id' => $teamId,
            'team_name' => (string) ($team['name'] ?? ''),
            'team_short' => (string) ($team['short_name'] ?? ''),
            'position' => self::POS_LABEL[$pos] ?? 'UNK',
            'position_id' => $pos,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'news' => $news,
            'chance' => $chanceVal,
            'ownership' => $own,
            'price' => $price,
            'photo' => $photoUrl,
            'shirt' => $shirt,
            'importance' => round($importance, 2),
            'stats' => [
                'goals' => (int) ($el['goals_scored'] ?? 0),
                'assists' => (int) ($el['assists'] ?? 0),
                'xg' => round((float) ($el['expected_goals'] ?? 0), 2),
                'xa' => round((float) ($el['expected_assists'] ?? 0), 2),
                'total_points' => $points,
                'minutes' => (int) ($el['minutes'] ?? 0),
                'form' => (string) ($el['form'] ?? '0.0'),
                'ppg' => (string) ($el['points_per_game'] ?? '0.0'),
                'ownership' => $own,
                'price' => $price,
                'ict' => (string) ($el['ict_index'] ?? '0.0'),
            ],
        ];
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'i' => 'Injured',
            'd' => 'Doubtful',
            's' => 'Suspended',
            'u' => 'Unavailable',
            default => 'Watch',
        };
    }

    /**
     * Deep research for one player: club/PL, trusted desks, club social snippets.
     *
     * @param array<string, mixed> $player
     * @return array<string, mixed>
     */
    private static function researchPlayerDeep(array $player, int $gw): array
    {
        $name = (string) ($player['full_name'] !== '' ? $player['full_name'] : $player['web_name']);
        $team = (string) ($player['team_name'] ?? '');
        $short = (string) ($player['team_short'] ?? '');
        $clubDomain = TavilyClient::clubDomainForShort($short);

        $searches = [];
        $merged = [];
        $tavilyCalls = 0;
        $errors = [];

        $queries = [
            [
                'label' => 'club_official',
                'tier' => 'tier1_official',
                'query' => trim("{$name} {$team} injury fitness update return timeline squad news"),
                'domains' => array_values(array_filter([
                    'premierleague.com',
                    $clubDomain,
                ])),
                'max' => 6,
            ],
            [
                'label' => 'trusted_desk',
                'tier' => 'tier2_desk',
                'query' => trim("{$name} {$team} injury BBC Sky Sports Guardian Reuters Premier League Gameweek {$gw}"),
                'domains' => TavilyClient::TIER2_DOMAINS,
                'max' => 6,
            ],
            [
                'label' => 'club_social',
                'tier' => 'social_official',
                'query' => trim("{$team} official injury update {$name} press conference return date"),
                'domains' => TavilyClient::SOCIAL_DOMAINS,
                'max' => 3,
            ],
        ];

        foreach ($queries as $q) {
            try {
                $rows = TavilyClient::search(
                    $q['query'],
                    (int) $q['max'],
                    'advanced',
                    $q['domains']
                );
                $tavilyCalls++;
                $sources = [];
                foreach ($rows as $row) {
                    $source = [
                        'title' => $row['title'],
                        'url' => $row['url'],
                        'snippet' => self::clip($row['content'], 480),
                        'score' => $row['score'],
                        'tier' => $row['tier'],
                        'outlet' => $row['outlet'],
                        'search_label' => $q['label'],
                    ];
                    $sources[] = $source;
                    $merged[$row['url']] = $source;
                }
                $searches[] = [
                    'label' => $q['label'],
                    'query' => $q['query'],
                    'source_count' => count($sources),
                    'sources' => $sources,
                ];
            } catch (\Throwable $e) {
                $errors[] = $q['label'] . ': ' . $e->getMessage();
                $searches[] = [
                    'label' => $q['label'],
                    'query' => $q['query'],
                    'source_count' => 0,
                    'sources' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        $sourcesList = array_values($merged);
        usort($sourcesList, static function (array $a, array $b): int {
            $tierRank = ['tier1_official' => 0, 'tier2_desk' => 1, 'social_official' => 2, 'other' => 3];
            $ta = $tierRank[$a['tier'] ?? 'other'] ?? 9;
            $tb = $tierRank[$b['tier'] ?? 'other'] ?? 9;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        return [
            'element_id' => (int) $player['element_id'],
            'player' => $player,
            'fpl' => [
                'status' => $player['status_label'] ?? '',
                'status_code' => $player['status'] ?? '',
                'news' => (string) ($player['news'] ?? ''),
                'chance' => $player['chance'] ?? null,
                'ownership' => $player['ownership'] ?? 0,
                'price' => $player['price'] ?? 0,
            ],
            'searches' => $searches,
            'sources' => array_slice($sourcesList, 0, 10),
            'tavily_calls' => $tavilyCalls,
            'research_errors' => $errors,
            'researched_at' => (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $research
     * @return list<array<string, mixed>>
     */
    private static function factPacksFromResearch(array $research): array
    {
        $packs = [];
        foreach (($research['players'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $player = $row['player'] ?? [];
            $fpl = $row['fpl'] ?? [];
            $packs[] = [
                'player' => $player,
                'sources' => is_array($row['sources'] ?? null) ? $row['sources'] : [],
                'searches' => is_array($row['searches'] ?? null) ? $row['searches'] : [],
                'fpl_note' => (string) ($fpl['news'] ?? $player['news'] ?? ''),
                'fpl_status' => (string) ($fpl['status'] ?? $player['status_label'] ?? ''),
                'fpl_chance' => $fpl['chance'] ?? $player['chance'] ?? null,
                'web_researched' => true,
            ];
        }
        if (count($packs) < self::MIN_PLAYERS) {
            throw new \RuntimeException('Stored research has too few players.');
        }
        return $packs;
    }

    /**
     * @param list<array<string, mixed>> $factPacks
     * @param array<string, mixed> $target
     * @param array<string, mixed>|null $editContext
     * @return array<string, mixed>
     */
    private static function writeArticle(array $factPacks, array $target, ?array $editContext): array
    {
        $gw = (int) ($target['id'] ?? 0);
        $gwName = (string) ($target['name'] ?? ('Gameweek ' . $gw));
        $deadline = (string) ($target['deadline_time'] ?? '');

        $packsForAi = [];
        foreach ($factPacks as $pack) {
            $p = $pack['player'];
            $packsForAi[] = [
                'element_id' => (int) $p['element_id'],
                'web_name' => $p['web_name'],
                'full_name' => $p['full_name'],
                'team' => $p['team_name'],
                'position' => $p['position'],
                'fpl_status' => $pack['fpl_status'],
                'fpl_note' => $pack['fpl_note'],
                'fpl_chance' => $pack['fpl_chance'],
                'ownership' => $p['ownership'],
                'price' => $p['price'],
                'sources' => array_map(static function (array $s): array {
                    return [
                        'title' => $s['title'] ?? '',
                        'url' => $s['url'] ?? '',
                        'snippet' => $s['snippet'] ?? '',
                        'tier' => $s['tier'] ?? '',
                        'outlet' => $s['outlet'] ?? '',
                    ];
                }, array_slice($pack['sources'], 0, 10)),
            ];
        }

        $isEdit = ($editContext['mode'] ?? '') === 'edit';
        $system = self::writerSystemPrompt($isEdit);

        $user = [
            'gameweek' => $gw,
            'gameweek_name' => $gwName,
            'deadline_utc' => $deadline,
            'required_players' => count($packsForAi),
            'players' => $packsForAi,
            'seo_title_pattern' => 'FPL injury news for Gameweek ' . $gw . ': …',
            'h1_hint' => 'Must include FPL injury news and Gameweek ' . $gw,
            'length_targets' => [
                'article_minimum_words' => 1500,
                'per_player_minimum_words' => 175,
                'intro_minimum_words' => 120,
                'closing_minimum_words' => 80,
            ],
            'writing_brief' => [
                'Write for a smart 10 year old. Use plain words only. Never use delve, examining, scrutinising, navigating, landscape, or in a recent.',
                'The first sentence of what_happened must name the player, injury, competition, opponent, and full date, then say how many fixtures he may miss. Example: Morgan Gibbs-White suffered a knee injury in the Premier League clash against Chelsea on the 21st of August, 2026, that has left him doubtful for the next two fixtures.',
                'Include exactly one markdown link per player in the article body using [anchor phrase](url). Pick the best official or trusted desk source. No separate sources list.',
                'what_we_suggest must be 2 to 3 full sentences telling managers what Metro Football advises for this player.',
                'Estimate fixtures missed and return timing when sources allow. Do not write one line sections.',
            ],
        ];

        if ($isEdit) {
            $user['edit_instructions'] = (string) ($editContext['instructions'] ?? '');
            $user['focus_element_id'] = $editContext['element_id'] ?? null;
            $user['current_published_post'] = $editContext['current_post'] ?? null;
            $user['rules'] = [
                'Use only the supplied stored research and FPL notes. Do not invent new medical facts.',
                'Apply the edit instructions to tone and wording. Remove AI filler if asked.',
                'Keep the same player list and structure unless the edit explicitly asks to remove a player.',
            ];
        }

        $schema = self::articleSchema(count($packsForAi));

        return OpenAiClient::chatJson(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($user, JSON_THROW_ON_ERROR)],
            ],
            $isEdit ? 'metro_fpl_injury_news_edit' : 'metro_fpl_injury_news',
            $schema,
            180
        );
    }

    private static function writerSystemPrompt(bool $isEdit): string
    {
        $base = <<<'TXT'
You write Metro Football FPL injury news for managers. This is long form journalism, not bullet notes.

Voice: We or Metro Football. Never lone first person I.
Copy: clear enough for a 10 year old. Use short common words. Full sentences. Short paragraphs of 2 to 4 sentences each. No dashes. No exclamation marks.
Banned words and phrases: delve, examining, scrutinising, navigating, landscape, leverage, robust, pivotal, in a recent, it remains to be seen, in conclusion, managers should keep an eye on, other players may benefit. Use discuss instead of delve.

Length (mandatory):
- Whole article at least 1500 words across intro, all player sections, and closing.
- Each player section at least 175 words.
- Intro at least 120 words across multiple paragraphs.
- Closing at least 80 words.

Story rules for every player:
- What happened: the first sentence must follow this shape when match details are known: "{Player} suffered a {injury} in the {competition} clash against {opponent} on the {ordinal day} of {month}, {year}, that has left him {status} for the next {number} fixtures." Then add 3 to 5 more short paragraphs with how it happened and what the club said.
- When is he expected back: say how long he is likely out, how many Premier League or European fixtures he could miss, and the gameweek or month he could return. If exact timing is unknown, say what scans or club comments show and give a realistic range.
- FPL impact: explain price, ownership, template risk, captaincy, and transfer pain in full paragraphs.
- Who benefits: name real teammates or rivals who gain minutes, pens, or FPL points where the research supports it.
- What do we suggest: 2 to 3 sentences in what_we_suggest telling managers what Metro Football advises for this player (hold, sell, bench, wait, or captain elsewhere).

Links: include exactly one markdown link [anchor phrase](url) per player inside what_happened, return_outlook, fpl_impact, or who_benefits. Use the best official or tier 2 source from research. Never add a sources list field.

SEO: The phrase "FPL injury news" must appear in seo_title at the front, in the first intro paragraph, and naturally about 1.5 to 2 percent density across the article.

Sources: Tier 1 official and club sources are highest trust. Tier 2 desks next. FPL API notes are for status and ownership framing only. Never invent medical facts, match details, or return dates not supported by the supplied research. If a detail is missing, say it is not confirmed yet rather than guessing.

Every player must fill injury_match, fixtures_likely_missed, and expected_return_gw with the best estimate from research. injury_match must read vs {opponent} on {date}, for example vs Chelsea on 21st August, 2026. expected_return_gw should read like Gameweek 3 or mid September 2026.

Return JSON only matching the schema.
TXT;

        if (!$isEdit) {
            return $base;
        }

        return $base . "\nEdit mode: Revise using ONLY the stored research pack and current post. Do not assume new web facts. Follow edit_instructions. Keep long form depth unless asked to shorten.";
    }

    private static function narrativeParagraphs(int $min, int $max = 6): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'minItems' => $min,
            'maxItems' => $max,
        ];
    }

    private static function articleSchema(int $playerCount): array
    {
        $playerCount = max(self::MIN_PLAYERS, min(self::MAX_PLAYERS, $playerCount));

        $tocItem = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'string'],
                'label' => ['type' => 'string'],
            ],
            'required' => ['id', 'label'],
        ];

        $playerBlock = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'element_id' => ['type' => 'integer'],
                'slug' => ['type' => 'string'],
                'heading' => ['type' => 'string'],
                'alt_text' => ['type' => 'string'],
                'story_lead' => ['type' => 'string'],
                'injury_match' => ['type' => 'string'],
                'fixtures_likely_missed' => ['type' => 'string'],
                'expected_return_gw' => ['type' => 'string'],
                'what_happened' => self::narrativeParagraphs(4, 6),
                'return_outlook' => self::narrativeParagraphs(3, 5),
                'fpl_impact' => self::narrativeParagraphs(3, 5),
                'who_benefits' => self::narrativeParagraphs(2, 4),
                'what_we_suggest' => self::narrativeParagraphs(2, 3),
            ],
            'required' => [
                'element_id', 'slug', 'heading', 'alt_text', 'story_lead',
                'injury_match', 'fixtures_likely_missed', 'expected_return_gw',
                'what_happened', 'return_outlook', 'fpl_impact', 'who_benefits', 'what_we_suggest',
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'seo_title' => ['type' => 'string'],
                'h1' => ['type' => 'string'],
                'intro_paragraphs' => self::narrativeParagraphs(3, 5),
                'toc' => [
                    'type' => 'array',
                    'items' => $tocItem,
                    'minItems' => $playerCount,
                    'maxItems' => $playerCount + 2,
                ],
                'players' => [
                    'type' => 'array',
                    'items' => $playerBlock,
                    'minItems' => $playerCount,
                    'maxItems' => $playerCount,
                ],
                'watch_list' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 2,
                    'maxItems' => 6,
                ],
                'closing' => self::narrativeParagraphs(2, 4),
            ],
            'required' => [
                'seo_title', 'h1', 'intro_paragraphs', 'toc', 'players', 'watch_list', 'closing',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $article
     * @param list<array<string, mixed>> $factPacks
     * @param array<string, mixed> $target
     * @param array<string, mixed>|null $research
     * @return array<string, mixed>
     */
    private static function assemblePost(
        array $article,
        array $factPacks,
        array $target,
        ?array $research = null
    ): array {
        $gw = (int) ($target['id'] ?? 0);
        $byId = [];
        foreach ($factPacks as $pack) {
            $byId[(int) $pack['player']['element_id']] = $pack;
        }

        $playersOut = [];
        foreach (($article['players'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $eid = (int) ($block['element_id'] ?? 0);
            $pack = $byId[$eid] ?? null;
            if ($pack === null) {
                continue;
            }
            $p = $pack['player'];
            $slug = self::slug((string) ($block['slug'] ?? $p['web_name']));
            $playersOut[] = [
                'element_id' => $eid,
                'slug' => $slug,
                'heading' => self::cleanCopy((string) ($block['heading'] ?? $p['web_name'])),
                'alt_text' => self::cleanCopy((string) ($block['alt_text'] ?? ($p['web_name'] . ' FPL injury news'))),
                'web_name' => $p['web_name'],
                'full_name' => $p['full_name'],
                'team_name' => $p['team_name'],
                'team_short' => $p['team_short'],
                'position' => $p['position'],
                'status' => $p['status'],
                'status_label' => $p['status_label'],
                'news' => $p['news'],
                'chance' => $p['chance'],
                'ownership' => $p['ownership'],
                'price' => $p['price'],
                'photo' => $p['photo'],
                'shirt' => $p['shirt'],
                'story_lead' => self::cleanCopy((string) ($block['story_lead'] ?? '')),
                'injury_match' => self::formatInjuryMatch((string) ($block['injury_match'] ?? '')),
                'fixtures_likely_missed' => self::cleanCopy((string) ($block['fixtures_likely_missed'] ?? '')),
                'expected_return_gw' => self::cleanCopy((string) ($block['expected_return_gw'] ?? '')),
                'what_happened' => self::cleanParagraphs($block['what_happened'] ?? []),
                'return_outlook' => self::cleanParagraphs($block['return_outlook'] ?? []),
                'fpl_impact' => self::cleanParagraphs($block['fpl_impact'] ?? []),
                'who_benefits' => self::cleanParagraphs($block['who_benefits'] ?? []),
                'what_we_suggest' => self::cleanParagraphs(
                    $block['what_we_suggest'] ?? (
                        ($block['action'] ?? '') !== ''
                            ? [(string) ($block['action'] ?? '')]
                            : []
                    )
                ),
                'stats' => $p['stats'],
            ];
        }

        if (count($playersOut) < self::MIN_PLAYERS) {
            throw new \RuntimeException('The writer returned too few player stories.');
        }

        $toc = [];
        foreach (($article['toc'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = self::slug((string) ($item['id'] ?? ''));
            $label = self::cleanCopy((string) ($item['label'] ?? ''));
            if ($id !== '' && $label !== '') {
                $toc[] = ['id' => $id, 'label' => $label];
            }
        }
        $tocIds = array_column($toc, 'id');
        foreach ($playersOut as $pl) {
            if (!in_array($pl['slug'], $tocIds, true)) {
                $toc[] = ['id' => $pl['slug'], 'label' => $pl['heading']];
            }
        }
        if (!in_array('watch-list', $tocIds, true)) {
            $toc[] = ['id' => 'watch-list', 'label' => 'Injury watch list'];
        }

        $meta = sprintf(
            'FPL injury news for Gameweek %d. See who is out, who may return, and what Metro Football thinks managers should do before the deadline.',
            $gw
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));

        return [
            'model' => self::MODEL,
            'gameweek' => $gw,
            'gameweek_name' => (string) ($target['name'] ?? ('Gameweek ' . $gw)),
            'deadline_time' => (string) ($target['deadline_time'] ?? ''),
            'published_at' => $now->format(\DateTimeInterface::ATOM),
            'updated_at' => $now->format(\DateTimeInterface::ATOM),
            'researched_at' => $research['researched_at'] ?? null,
            'seo_title' => self::cleanCopy((string) ($article['seo_title'] ?? ('FPL injury news for Gameweek ' . $gw))),
            'meta_description' => $meta,
            'h1' => self::cleanCopy((string) ($article['h1'] ?? ('FPL injury news for Gameweek ' . $gw))),
            'intro_paragraphs' => self::cleanParagraphs($article['intro_paragraphs'] ?? []),
            'toc' => $toc,
            'players' => $playersOut,
            'watch_list' => self::buildWatchCards(self::cleanParagraphs($article['watch_list'] ?? [])),
            'closing' => self::cleanParagraphs($article['closing'] ?? []),
            'canonical' => 'https://metrofootball.co.uk/fpl-injury-news',
            'focus_keyphrase' => 'FPL injury news',
        ];
    }

    /** @param mixed $paras @return list<string> */
    private static function cleanParagraphs(mixed $paras): array
    {
        if (!is_array($paras)) {
            return [];
        }
        $out = [];
        foreach ($paras as $p) {
            $t = self::cleanCopy((string) $p);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function cleanCopy(string $text): string
    {
        $text = trim($text);
        $text = str_replace(["\u{2014}", "\u{2013}", '—', '–'], ', ', $text);
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;
        $text = str_replace(' ,', ',', $text);
        $text = self::scrubBannedWords($text);
        return trim($text);
    }

    private static function formatInjuryMatch(string $text): string
    {
        $text = trim(self::cleanCopy($text));
        if ($text === '') {
            return '';
        }
        if (preg_match('/^vs\s/i', $text)) {
            return $text;
        }

        return 'vs ' . $text;
    }

    private static function scrubBannedWords(string $text): string
    {
        $replacements = [
            '/\bdelve\b/i' => 'discuss',
            '/\bdelves\b/i' => 'discusses',
            '/\bdelving\b/i' => 'discussing',
            '/\bdelved\b/i' => 'discussed',
            '/\bexamining\b/i' => 'looking at',
            '/\bexamines\b/i' => 'looks at',
            '/\bin a recent\b/i' => 'in the',
            '/\bscrutinising\b/i' => 'checking',
            '/\bnavigating\b/i' => 'handling',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        return $text;
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'player';
    }

    private static function outletFromUrl(string $url): string
    {
        return TavilyClient::outletFromUrl($url);
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (strlen($text) <= $max) {
            return $text;
        }
        return rtrim(substr($text, 0, $max - 1)) . '…';
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function enrichPostForDisplay(array $post): array
    {
        if (isset($post['players']) && is_array($post['players'])) {
            foreach ($post['players'] as $index => $player) {
                if (!is_array($player)) {
                    continue;
                }
                if (isset($player['injury_match'])) {
                    $post['players'][$index]['injury_match'] = self::formatInjuryMatch(
                        (string) $player['injury_match']
                    );
                }
            }
        }

        $watch = $post['watch_list'] ?? [];
        if ($watch === [] || !is_string($watch[0] ?? null)) {
            return $post;
        }
        $post['watch_list'] = self::buildWatchCards($watch);
        return $post;
    }

    /**
     * @param list<string|array<string, mixed>> $names
     * @return list<array<string, mixed>>
     */
    public static function buildWatchCards(array $names): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $teamsById = self::teamsById($teams);

        $cards = [];
        foreach ($names as $name) {
            if (is_array($name)) {
                $cards[] = $name;
                continue;
            }
            $label = self::cleanCopy((string) $name);
            if ($label === '') {
                continue;
            }
            $card = self::findWatchCard($label, $elements, $teamsById);
            $cards[] = $card ?? [
                'label' => $label,
                'web_name' => $label,
                'team_short' => '',
                'position' => '',
                'status_label' => 'Watch',
                'status' => 'a',
                'news' => '',
                'photo' => null,
                'shirt' => null,
            ];
        }

        return array_slice($cards, 0, 5);
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<string, mixed>|null
     */
    private static function findWatchCard(string $label, array $elements, array $teamsById): ?array
    {
        $needle = self::normalizeName($label);
        $best = null;
        $bestScore = 0;

        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $web = self::normalizeName((string) ($el['web_name'] ?? ''));
            $full = self::normalizeName(
                trim((string) ($el['first_name'] ?? '') . ' ' . (string) ($el['second_name'] ?? ''))
            );
            $score = 0;
            if ($needle === $web || $needle === $full) {
                $score = 100;
            } elseif (str_contains($full, $needle) || str_contains($needle, $full)) {
                $score = 85;
            } elseif (str_contains($web, $needle) || str_contains($needle, $web)) {
                $score = 75;
            } else {
                $parts = explode(' ', $needle);
                $last = $parts[count($parts) - 1] ?? '';
                if ($last !== '' && (str_contains($full, $last) || str_contains($web, $last))) {
                    $score = 55;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $el;
            }
        }

        if ($best === null || $bestScore < 55) {
            return null;
        }

        return self::mapWatchCard($best, $teamsById, $label);
    }

    /**
     * @param array<string, mixed> $el
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<string, mixed>
     */
    private static function mapWatchCard(array $el, array $teamsById, string $label): array
    {
        $teamId = (int) ($el['team'] ?? 0);
        $team = $teamsById[$teamId] ?? [];
        $pos = (int) ($el['element_type'] ?? 0);
        $status = strtolower((string) ($el['status'] ?? 'a'));
        $photoRaw = trim((string) ($el['photo'] ?? ''));
        $photoCode = preg_replace('/\.(jpg|jpeg|png)$/i', '', $photoRaw) ?? '';
        $teamCode = (int) ($team['code'] ?? 0);
        $shirt = null;
        if ($teamCode > 0) {
            $shirt = $pos === 1
                ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode . '_1-110.webp'
                : 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode . '-110.webp';
        }
        $photoUrl = $photoCode !== ''
            ? 'https://resources.premierleague.com/premierleague/photos/players/250x250/p' . $photoCode . '.png'
            : null;

        return [
            'element_id' => (int) ($el['id'] ?? 0),
            'label' => $label,
            'web_name' => (string) ($el['web_name'] ?? $label),
            'team_short' => (string) ($team['short_name'] ?? ''),
            'position' => self::POS_LABEL[$pos] ?? '',
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'news' => trim((string) ($el['news'] ?? '')),
            'photo' => $photoUrl,
            'shirt' => $shirt,
        ];
    }

    private static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9\s]/', '', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
