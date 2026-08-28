<?php

declare(strict_types=1);

namespace App;

/**
 * Weekly public FPL Gameweek Tips report.
 * FPL compute + Tavily research stored once per GW. OpenAI writes from stored pack only.
 */
final class GameweekTipsService
{
    private const MODEL = 'gameweek-tips-v1';
    private const RESEARCH_MODEL = 'gameweek-tips-research-v1';
    private const CACHE_PREFIX = 'gameweek-tips-v1-';
    private const RESEARCH_PREFIX = 'gameweek-tips-research-v1-';
    private const STORE_SECONDS = 60 * 60 * 24 * 400;
    private const MIN_TIPS = 8;
    private const MAX_TIPS = 8;
    private const TZ = 'Europe/London';

    /** @var array<int, string> */
    private const POS_LABEL = [1 => 'GK', 2 => 'DEF', 3 => 'MID', 4 => 'FWD'];

    /** @var array<string, string> */
    private const TIP_LABELS = [
        'captain' => 'Captain pick',
        'vice_captain' => 'Vice captain',
        'premium_pick' => 'Premium pick',
        'differential' => 'Differential',
        'player_to_avoid' => 'Player to avoid',
        'defender_pick' => 'Defender pick',
        'budget_pick' => 'Budget pick',
        'form_pick' => 'Form pick',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function getMeta(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $target = self::resolveTargetEvent($events);
        $gw = (int) $target['id'];

        return [
            'ok' => true,
            'upcoming_gw' => $gw,
            'upcoming_name' => (string) ($target['name'] ?? ('Gameweek ' . $gw)),
            'upcoming_path' => self::pathForGameweek($gw),
            'upcoming_url' => self::canonicalForGameweek($gw),
            'has_research' => self::getStoredResearch($gw) !== null,
            'is_published' => self::getPostForGameweek($gw) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getForRequest(?int $requestedGw = null): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $upcoming = self::resolveTargetEvent($events);
        $upcomingGw = (int) $upcoming['id'];

        if ($requestedGw === null || $requestedGw < 1) {
            $requestedGw = $upcomingGw;
        }

        $eventMeta = self::eventById($events, $requestedGw);
        $target = $eventMeta ?? [
            'id' => $requestedGw,
            'name' => 'Gameweek ' . $requestedGw,
            'deadline_time' => '',
        ];

        $post = self::getPostForGameweek($requestedGw);
        $needsGenerate = $post === null;

        return [
            'ok' => true,
            'needs_generate' => $needsGenerate,
            'post' => $post !== null ? self::enrichPostForDisplay($post) : null,
            'gameweek' => $requestedGw,
            'gameweek_name' => (string) ($target['name'] ?? ('Gameweek ' . $requestedGw)),
            'upcoming_gw' => $upcomingGw,
            'upcoming_path' => self::pathForGameweek($upcomingGw),
            'is_upcoming' => $requestedGw === $upcomingGw,
            'has_research' => self::getStoredResearch($requestedGw) !== null,
            'archive_paths' => self::listPublishedPaths(),
        ];
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
        $fixtures = FplClient::fetchFixtures();
        $target = self::resolveTargetEvent($events);
        $gw = (int) $target['id'];

        if (!$forceResearch) {
            $existing = self::getStoredResearch($gw);
            if ($existing !== null) {
                return $existing;
            }
        }

        $teamsById = self::teamsById($teams);
        $fixtureIndex = self::indexFixturesByTeamEvent($fixtures, $teamsById);
        $roster = self::buildTipRoster($elements, $teamsById, $fixtureIndex, $gw, $fixtures);

        if (count($roster) < self::MIN_TIPS) {
            throw new \RuntimeException('Not enough tip candidates for this gameweek yet.');
        }

        $tipPacks = [];
        $tavilyCalls = 0;
        foreach ($roster as $slot) {
            $pack = self::researchTipDeep($slot, $gw);
            $tavilyCalls += (int) ($pack['tavily_calls'] ?? 0);
            $tipPacks[] = $pack;
        }

        $context = self::researchGameweekContext($gw, $fixtures, $teamsById, $target);
        $tavilyCalls += (int) ($context['tavily_calls'] ?? 0);

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $research = [
            'model' => self::RESEARCH_MODEL,
            'gameweek' => $gw,
            'gameweek_name' => (string) ($target['name'] ?? ('Gameweek ' . $gw)),
            'deadline_time' => (string) ($target['deadline_time'] ?? ''),
            'researched_at' => $now->format(\DateTimeInterface::ATOM),
            'tip_count' => count($tipPacks),
            'tavily_calls' => $tavilyCalls,
            'search_depth' => 'advanced',
            'fixtures' => self::fixturesSummary($fixtures, $teamsById, $gw),
            'gw_context' => $context,
            'tips' => $tipPacks,
        ];

        FplCache::put(self::RESEARCH_PREFIX . $gw, $research);
        return $research;
    }

    /**
     * @return array<string, mixed>
     */
    public static function publishFromResearch(bool $forceWrite = false, ?int $gameweek = null): array
    {
        $gw = $gameweek ?? self::targetGameweekId();
        if (!$forceWrite) {
            $existing = self::getPostForGameweek($gw);
            if ($existing !== null) {
                return $existing;
            }
        }

        if (!OpenAiClient::configured()) {
            throw new \RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY in config/.env');
        }

        $research = self::getStoredResearch($gw);
        if ($research === null) {
            throw new \RuntimeException(
                'No stored research for this gameweek. Run: php scripts/publish-gameweek-tips.php --research'
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
            'tips' => count($factPacks),
            'tavily_calls' => 0,
            'openai_only' => true,
            'used_stored_research' => true,
            'researched_at' => $research['researched_at'] ?? null,
            'once_per_gameweek' => true,
        ];

        FplCache::put(self::CACHE_PREFIX . $gw, $post);
        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    public static function generate(bool $force = false, bool $forceResearch = false): array
    {
        $gw = self::targetGameweekId();
        if (!$force) {
            $existing = self::getPostForGameweek($gw);
            if ($existing !== null) {
                return $existing;
            }
        }

        $research = self::research($forceResearch);
        $post = self::publishFromResearch(true, $gw);
        $post['cost_controls']['tavily_calls'] = (int) ($research['tavily_calls'] ?? 0);
        FplCache::put(self::CACHE_PREFIX . $gw, $post);
        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    public static function edit(string $instructions, ?int $elementId = null, ?int $gameweek = null): array
    {
        $instructions = trim($instructions);
        if ($instructions === '') {
            throw new \InvalidArgumentException('Edit instructions cannot be empty.');
        }

        if (!OpenAiClient::configured()) {
            throw new \RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY in config/.env');
        }

        $gw = $gameweek ?? self::targetGameweekId();
        $research = self::getStoredResearch($gw);
        if ($research === null) {
            throw new \RuntimeException('No stored research for this gameweek.');
        }

        $current = self::getPostForGameweek($gw);
        if ($current === null) {
            throw new \RuntimeException('No published tips post to edit for this gameweek yet.');
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
            'tips' => count($factPacks),
            'tavily_calls' => 0,
            'openai_only' => true,
            'used_stored_research' => true,
            'edit' => true,
            'researched_at' => $research['researched_at'] ?? null,
        ];

        FplCache::put(self::CACHE_PREFIX . $gw, $post);
        return $post;
    }

    public static function pathForGameweek(int $gw): string
    {
        return './fpl-gameweek-' . max(1, $gw) . '-tips';
    }

    public static function canonicalForGameweek(int $gw): string
    {
        return 'https://metrofootball.co.uk/fpl-gameweek-' . max(1, $gw) . '-tips';
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function enrichPostForDisplay(array $post): array
    {
        if (isset($post['tips']) && is_array($post['tips'])) {
            foreach ($post['tips'] as $i => $tip) {
                if (!is_array($tip)) {
                    continue;
                }
                if (isset($tip['fixture_line'])) {
                    $post['tips'][$i]['fixture_line'] = self::formatFixtureLine((string) $tip['fixture_line']);
                }
            }
        }

        $post = self::hydratePostPlayerMedia($post);

        $quick = $post['quick_picks'] ?? [];
        if ($quick !== [] && is_string($quick[0] ?? null)) {
            $post['quick_picks'] = self::buildQuickPickCards($quick, $post['tips'] ?? []);
        }

        $post['toc'] = self::buildTipsToc($post);

        return $post;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{id: string, label: string}>
     */
    private static function buildTipsToc(array $post): array
    {
        $toc = [];
        $quick = $post['quick_picks'] ?? [];
        if (is_array($quick) && $quick !== []) {
            $toc[] = ['id' => 'quick-picks', 'label' => 'Quick picks'];
        }

        foreach ($post['tips'] ?? [] as $tip) {
            if (!is_array($tip)) {
                continue;
            }
            $slug = (string) ($tip['slug'] ?? '');
            $heading = (string) ($tip['heading'] ?? '');
            if ($slug === '' || $heading === '') {
                continue;
            }
            $toc[] = ['id' => $slug, 'label' => $heading];
        }

        return $toc;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function hydratePostPlayerMedia(array $post): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        if ($elements === [] || $teams === []) {
            return $post;
        }

        $apply = static function (array $row) use ($elements, $teams): array {
            $eid = (int) ($row['element_id'] ?? 0);
            if ($eid < 1) {
                return $row;
            }
            $el = $elements[$eid] ?? null;
            if ($el === null) {
                return $row;
            }
            $team = $teams[(int) ($el['team'] ?? 0)] ?? null;
            if ($team === null) {
                return $row;
            }

            $photo = self::playerPhotoUrl($el);
            if ($photo !== null) {
                $row['photo'] = $photo;
            }
            $shirt = self::playerShirtUrl($el, $team);
            if ($shirt !== null) {
                $row['shirt'] = $shirt;
            }

            if (empty($row['fixture_fdr']) && preg_match('/(\d+)/', (string) ($row['opponent_rank'] ?? ''), $fdrMatch) === 1) {
                $row['fixture_fdr'] = max(1, min(5, (int) $fdrMatch[1]));
            }

            return $row;
        };

        if (isset($post['tips']) && is_array($post['tips'])) {
            foreach ($post['tips'] as $i => $tip) {
                if (is_array($tip)) {
                    $post['tips'][$i] = $apply($tip);
                }
            }
        }

        if (isset($post['quick_picks']) && is_array($post['quick_picks'])) {
            foreach ($post['quick_picks'] as $i => $pick) {
                if (is_array($pick)) {
                    $post['quick_picks'][$i] = $apply($pick);
                }
            }
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function playerPhotoUrl(array $el): ?string
    {
        $photoRaw = trim((string) ($el['photo'] ?? ''));
        $photoCode = preg_replace('/\.(jpg|jpeg|png)$/i', '', $photoRaw) ?? '';
        if ($photoCode === '') {
            return null;
        }

        return 'https://resources.premierleague.com/premierleague/photos/players/250x250/p' . $photoCode . '.png';
    }

    /**
     * @param array<string, mixed> $el
     * @param array<string, mixed> $team
     */
    private static function playerShirtUrl(array $el, array $team): ?string
    {
        $teamCode = (int) ($team['code'] ?? 0);
        if ($teamCode < 1) {
            return null;
        }

        $pos = (int) ($el['element_type'] ?? 0);

        return $pos === 1
            ? 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode . '_1-110.webp'
            : 'https://fantasy.premierleague.com/dist/img/shirts/standard/shirt_' . $teamCode . '-110.webp';
    }

    private static function targetGameweekId(): int
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        return (int) self::resolveTargetEvent($events)['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getPostForGameweek(int $gw): ?array
    {
        $cached = FplCache::get(self::CACHE_PREFIX . $gw, self::STORE_SECONDS);
        if (!is_array($cached) || ($cached['model'] ?? '') !== self::MODEL) {
            return null;
        }
        return $cached;
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
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>|null
     */
    private static function eventById(array $events, int $id): ?array
    {
        foreach ($events as $event) {
            if (is_array($event) && (int) ($event['id'] ?? 0) === $id) {
                return $event;
            }
        }
        return null;
    }

    /**
     * @return list<array{gw:int,path:string,label:string}>
     */
    private static function listPublishedPaths(): array
    {
        $dir = dirname(__DIR__) . '/storage/cache';
        $paths = [];
        if (!is_dir($dir)) {
            return $paths;
        }
        foreach (glob($dir . '/gameweek-tips-v1-*.json') ?: [] as $file) {
            if (!preg_match('/gameweek-tips-v1-(\d+)\.json$/', $file, $m)) {
                continue;
            }
            $gw = (int) $m[1];
            $paths[] = [
                'gw' => $gw,
                'path' => self::pathForGameweek($gw),
                'label' => 'Gameweek ' . $gw . ' tips',
            ];
        }
        usort($paths, static fn (array $a, array $b): int => $b['gw'] <=> $a['gw']);
        return $paths;
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
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function indexFixturesByTeamEvent(array $fixtures, array $teamsById): array
    {
        $index = [];
        foreach ($fixtures as $fx) {
            if (!is_array($fx)) {
                continue;
            }
            $event = (int) ($fx['event'] ?? 0);
            if ($event < 1) {
                continue;
            }
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home < 1 || $away < 1) {
                continue;
            }
            $homeName = (string) ($teamsById[$home]['name'] ?? '');
            $awayName = (string) ($teamsById[$away]['name'] ?? '');
            $kickoff = (string) ($fx['kickoff_time'] ?? '');

            $index[$home][$event] = [
                'opponent' => $awayName,
                'opponent_short' => (string) ($teamsById[$away]['short_name'] ?? ''),
                'was_home' => true,
                'fdr' => max(1, min(5, (int) ($fx['team_h_difficulty'] ?? 3))),
                'kickoff' => $kickoff,
                'kickoff_label' => self::formatKickoffLabel($kickoff),
            ];
            $index[$away][$event] = [
                'opponent' => $homeName,
                'opponent_short' => (string) ($teamsById[$home]['short_name'] ?? ''),
                'was_home' => false,
                'fdr' => max(1, min(5, (int) ($fx['team_a_difficulty'] ?? 3))),
                'kickoff' => $kickoff,
                'kickoff_label' => self::formatKickoffLabel($kickoff),
            ];
        }
        return $index;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, array<int, array<string, mixed>>> $fixtureIndex
     * @param list<array<string, mixed>> $fixtures
     * @return list<array<string, mixed>>
     */
    private static function buildTipRoster(
        array $elements,
        array $teamsById,
        array $fixtureIndex,
        int $gw,
        array $fixtures
    ): array {
        $pool = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $mapped = self::mapPlayer($el, $teamsById, $fixtureIndex, $gw);
            if ($mapped !== null) {
                $pool[] = $mapped;
            }
        }

        if ($pool === []) {
            return [];
        }

        $used = [];
        $roster = [];

        $captain = self::pickBest($pool, static function (array $p): bool {
            return in_array($p['position_code'], [3, 4], true) && $p['status'] === 'a';
        }, static fn (array $p): float => $p['captain_score'], $used);
        if ($captain) {
            $roster[] = self::slot($captain, 'captain');
            $used[(int) $captain['element_id']] = true;
        }

        $vice = self::pickBest($pool, static function (array $p): bool {
            return in_array($p['position_code'], [3, 4], true) && $p['status'] === 'a';
        }, static fn (array $p): float => $p['captain_score'] * 0.92, $used);
        if ($vice) {
            $roster[] = self::slot($vice, 'vice_captain');
            $used[(int) $vice['element_id']] = true;
        }

        $premium = self::pickBest($pool, static fn (array $p): bool => $p['ownership'] >= 20.0 && $p['status'] === 'a', static fn (array $p): float => $p['pick_score'], $used);
        if ($premium) {
            $roster[] = self::slot($premium, 'premium_pick');
            $used[(int) $premium['element_id']] = true;
        }

        $diff = self::pickBest($pool, static fn (array $p): bool => $p['ownership'] < 8.0 && $p['status'] === 'a', static fn (array $p): float => $p['pick_score'], $used);
        if ($diff) {
            $roster[] = self::slot($diff, 'differential');
            $used[(int) $diff['element_id']] = true;
        }

        $avoid = self::pickBest($pool, static function (array $p): bool {
            return in_array($p['status'], ['i', 'd', 's'], true) || ($p['fixture_fdr'] >= 4 && (float) $p['stats']['form'] < 3.0);
        }, static fn (array $p): float => $p['avoid_score'], $used);
        if ($avoid) {
            $roster[] = self::slot($avoid, 'player_to_avoid');
            $used[(int) $avoid['element_id']] = true;
        }

        $def = self::pickBest($pool, static fn (array $p): bool => in_array($p['position_code'], [1, 2], true) && $p['status'] === 'a', static fn (array $p): float => $p['def_score'], $used);
        if ($def) {
            $roster[] = self::slot($def, 'defender_pick');
            $used[(int) $def['element_id']] = true;
        }

        $budget = self::pickBest($pool, static fn (array $p): bool => $p['price'] <= 5.5 && $p['status'] === 'a', static fn (array $p): float => $p['pick_score'], $used);
        if ($budget) {
            $roster[] = self::slot($budget, 'budget_pick');
            $used[(int) $budget['element_id']] = true;
        }

        $form = self::pickBest($pool, static fn (array $p): bool => $p['status'] === 'a', static fn (array $p): float => $p['form_score'], $used);
        if ($form) {
            $roster[] = self::slot($form, 'form_pick');
            $used[(int) $form['element_id']] = true;
        }

        return array_slice($roster, 0, self::MAX_TIPS);
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @param callable(array<string,mixed>):bool $filter
     * @param callable(array<string,mixed>):float $scoreFn
     * @param array<int,bool> $used
     * @return array<string, mixed>|null
     */
    private static function pickBest(array $pool, callable $filter, callable $scoreFn, array $used): ?array
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($pool as $row) {
            $eid = (int) ($row['element_id'] ?? 0);
            if ($eid < 1 || isset($used[$eid]) || !$filter($row)) {
                continue;
            }
            $score = (float) $scoreFn($row);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $player
     * @return array<string, mixed>
     */
    private static function slot(array $player, string $tipType): array
    {
        return [
            'tip_type' => $tipType,
            'tip_label' => self::TIP_LABELS[$tipType] ?? ucfirst(str_replace('_', ' ', $tipType)),
            'player' => $player,
        ];
    }

    /**
     * @param array<string, mixed> $el
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, array<int, array<string, mixed>>> $fixtureIndex
     * @return array<string, mixed>|null
     */
    private static function mapPlayer(
        array $el,
        array $teamsById,
        array $fixtureIndex,
        int $gw
    ): ?array {
        $eid = (int) ($el['id'] ?? 0);
        $teamId = (int) ($el['team'] ?? 0);
        if ($eid < 1 || $teamId < 1) {
            return null;
        }

        $minutes = (int) ($el['minutes'] ?? 0);
        if ($minutes < 45) {
            return null;
        }

        $team = $teamsById[$teamId] ?? null;
        if ($team === null) {
            return null;
        }

        $pos = (int) ($el['element_type'] ?? 0);
        $status = (string) ($el['status'] ?? 'a');
        $fx = $fixtureIndex[$teamId][$gw] ?? null;
        $fdr = (int) ($fx['fdr'] ?? 3);
        $form = (float) ($el['form'] ?? 0);
        $xg = (float) ($el['expected_goals'] ?? 0);
        $xa = (float) ($el['expected_assists'] ?? 0);
        $own = round((float) ($el['selected_by_percent'] ?? 0), 1);
        $price = round(((int) ($el['now_cost'] ?? 0)) / 10, 1);
        $points = (int) ($el['total_points'] ?? 0);

        $fixtureEase = 6 - $fdr;
        $pickScore = $fixtureEase * 2.2 + $form + $xg * 3.5 + $xa * 2.5 + min($own, 30) * 0.05;
        $captainScore = $fixtureEase * 2.5 + $form * 1.4 + $xg * 5 + $xa * 3 + $own * 0.03;
        $defScore = $fixtureEase * 2.8 + $form + ($pos === 1 ? 1.5 : 0);
        $avoidScore = ($status === 'i' ? 80 : 0) + ($status === 'd' ? 50 : 0) + ($status === 's' ? 70 : 0)
            + max(0, $fdr - 3) * 12 + max(0, 4 - $form) * 4;
        $formScore = $form * 2.2 + $xg * 2 + $xa * 1.5 + $fixtureEase;

        $photoUrl = self::playerPhotoUrl($el) ?? '';
        $shirt = self::playerShirtUrl($el, $team) ?? '';

        $opponent = (string) ($fx['opponent'] ?? 'TBC');
        $kickoffLabel = (string) ($fx['kickoff_label'] ?? '');
        $homeAway = !empty($fx['was_home']) ? 'at home' : 'away';
        $fixtureLine = 'vs ' . $opponent . ' ' . $homeAway . ($kickoffLabel !== '' ? ' on ' . $kickoffLabel : '');

        return [
            'element_id' => $eid,
            'web_name' => (string) ($el['web_name'] ?? ''),
            'full_name' => trim((string) ($el['first_name'] ?? '') . ' ' . (string) ($el['second_name'] ?? '')),
            'team_name' => (string) ($team['name'] ?? ''),
            'team_short' => (string) ($team['short_name'] ?? ''),
            'position' => self::POS_LABEL[$pos] ?? 'MID',
            'position_code' => $pos,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'news' => (string) ($el['news'] ?? ''),
            'chance' => isset($el['chance_of_playing_next_round']) ? (int) $el['chance_of_playing_next_round'] : null,
            'ownership' => $own,
            'price' => $price,
            'photo' => $photoUrl,
            'shirt' => $shirt,
            'fixture_fdr' => $fdr,
            'fixture_line' => $fixtureLine,
            'opponent_rank' => 'FDR ' . $fdr,
            'form_line' => 'Form ' . number_format($form, 1),
            'pick_score' => round($pickScore, 2),
            'captain_score' => round($captainScore, 2),
            'def_score' => round($defScore, 2),
            'avoid_score' => round($avoidScore, 2),
            'form_score' => round($formScore, 2),
            'stats' => [
                'goals' => (int) ($el['goals_scored'] ?? 0),
                'assists' => (int) ($el['assists'] ?? 0),
                'xg' => round($xg, 2),
                'xa' => round($xa, 2),
                'total_points' => $points,
                'form' => (string) ($el['form'] ?? '0.0'),
                'ownership' => $own,
                'price' => $price,
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
            default => 'Available',
        };
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private static function researchTipDeep(array $slot, int $gw): array
    {
        $player = $slot['player'];
        $tipType = (string) ($slot['tip_type'] ?? 'form_pick');
        $name = (string) ($player['full_name'] !== '' ? $player['full_name'] : $player['web_name']);
        $team = (string) ($player['team_name'] ?? '');
        $short = (string) ($player['team_short'] ?? '');
        $clubDomain = TavilyClient::clubDomainForShort($short);

        $merged = [];
        $searches = [];
        $tavilyCalls = 0;
        $errors = [];

        $queries = [
            [
                'label' => 'club_official',
                'query' => trim("{$name} {$team} Gameweek {$gw} team news press conference minutes start"),
                'domains' => array_values(array_filter(['premierleague.com', $clubDomain])),
                'max' => 5,
            ],
            [
                'label' => 'trusted_desk',
                'query' => trim("{$name} {$team} FPL Gameweek {$gw} preview BBC Sky Sports Guardian"),
                'domains' => TavilyClient::TIER2_DOMAINS,
                'max' => 5,
            ],
        ];

        foreach ($queries as $q) {
            try {
                $rows = TavilyClient::search($q['query'], (int) $q['max'], 'advanced', $q['domains']);
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
                $searches[] = ['label' => $q['label'], 'query' => $q['query'], 'sources' => $sources];
            } catch (\Throwable $e) {
                $errors[] = $q['label'] . ': ' . $e->getMessage();
                $searches[] = ['label' => $q['label'], 'query' => $q['query'], 'sources' => [], 'error' => $e->getMessage()];
            }
        }

        $sourcesList = array_values($merged);
        usort($sourcesList, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return [
            'tip_type' => $tipType,
            'tip_label' => (string) ($slot['tip_label'] ?? ''),
            'element_id' => (int) $player['element_id'],
            'player' => $player,
            'computed' => [
                'fixture_line' => $player['fixture_line'] ?? '',
                'opponent_rank' => $player['opponent_rank'] ?? '',
                'form_line' => $player['form_line'] ?? '',
            ],
            'searches' => $searches,
            'sources' => array_slice($sourcesList, 0, 8),
            'tavily_calls' => $tavilyCalls,
            'research_errors' => $errors,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private static function researchGameweekContext(
        int $gw,
        array $fixtures,
        array $teamsById,
        array $target
    ): array {
        $gwFixtures = [];
        foreach ($fixtures as $fx) {
            if (is_array($fx) && (int) ($fx['event'] ?? 0) === $gw) {
                $gwFixtures[] = $fx;
            }
        }

        usort($gwFixtures, static fn (array $a, array $b): int => strcmp(
            (string) ($a['kickoff_time'] ?? ''),
            (string) ($b['kickoff_time'] ?? '')
        ));

        $merged = [];
        $searches = [];
        $tavilyCalls = 0;
        $picked = array_slice($gwFixtures, 0, 4);

        foreach ($picked as $fx) {
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            $homeName = (string) ($teamsById[$home]['name'] ?? 'Home');
            $awayName = (string) ($teamsById[$away]['name'] ?? 'Away');
            $query = trim("{$homeName} vs {$awayName} Gameweek {$gw} preview team news FPL");
            try {
                $rows = TavilyClient::search($query, 4, 'advanced', TavilyClient::TIER2_DOMAINS);
                $tavilyCalls++;
                $sources = [];
                foreach ($rows as $row) {
                    $source = [
                        'title' => $row['title'],
                        'url' => $row['url'],
                        'snippet' => self::clip($row['content'], 360),
                        'outlet' => $row['outlet'],
                        'tier' => $row['tier'],
                    ];
                    $sources[] = $source;
                    $merged[$row['url']] = $source;
                }
                $searches[] = ['label' => 'fixture_preview', 'query' => $query, 'sources' => $sources];
            } catch (\Throwable $e) {
                $searches[] = ['label' => 'fixture_preview', 'query' => $query, 'sources' => [], 'error' => $e->getMessage()];
            }
        }

        return [
            'gameweek_name' => (string) ($target['name'] ?? ('Gameweek ' . $gw)),
            'searches' => $searches,
            'sources' => array_values($merged),
            'tavily_calls' => $tavilyCalls,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @param array<int, array<string, mixed>> $teamsById
     * @return list<array<string, mixed>>
     */
    private static function fixturesSummary(array $fixtures, array $teamsById, int $gw): array
    {
        $out = [];
        foreach ($fixtures as $fx) {
            if (!is_array($fx) || (int) ($fx['event'] ?? 0) !== $gw) {
                continue;
            }
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            $out[] = [
                'home' => (string) ($teamsById[$home]['name'] ?? ''),
                'away' => (string) ($teamsById[$away]['name'] ?? ''),
                'kickoff' => (string) ($fx['kickoff_time'] ?? ''),
                'kickoff_label' => self::formatKickoffLabel((string) ($fx['kickoff_time'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $research
     * @return list<array<string, mixed>>
     */
    private static function factPacksFromResearch(array $research): array
    {
        $packs = [];
        foreach (($research['tips'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $packs[] = [
                'tip_type' => (string) ($row['tip_type'] ?? ''),
                'tip_label' => (string) ($row['tip_label'] ?? ''),
                'player' => is_array($row['player'] ?? null) ? $row['player'] : [],
                'sources' => is_array($row['sources'] ?? null) ? $row['sources'] : [],
                'computed' => is_array($row['computed'] ?? null) ? $row['computed'] : [],
            ];
        }
        if (count($packs) < self::MIN_TIPS) {
            throw new \RuntimeException('Stored research has too few tips.');
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
        $isEdit = ($editContext['mode'] ?? '') === 'edit';

        $packsForAi = [];
        foreach ($factPacks as $pack) {
            $p = $pack['player'];
            $packsForAi[] = [
                'element_id' => (int) $p['element_id'],
                'tip_type' => $pack['tip_type'],
                'tip_label' => $pack['tip_label'],
                'web_name' => $p['web_name'],
                'full_name' => $p['full_name'],
                'team' => $p['team_name'],
                'position' => $p['position'],
                'ownership' => $p['ownership'],
                'price' => $p['price'],
                'fixture_line' => $p['fixture_line'] ?? '',
                'opponent_rank' => $p['opponent_rank'] ?? '',
                'form_line' => $p['form_line'] ?? '',
                'season_stats' => [
                    'goals' => (int) ($p['stats']['goals'] ?? 0),
                    'assists' => (int) ($p['stats']['assists'] ?? 0),
                    'total_points' => (int) ($p['stats']['total_points'] ?? 0),
                    'xg' => (float) ($p['stats']['xg'] ?? 0),
                    'xa' => (float) ($p['stats']['xa'] ?? 0),
                    'form' => (string) ($p['stats']['form'] ?? '0.0'),
                ],
                'fpl_status' => $p['status_label'] ?? '',
                'fpl_note' => $p['news'] ?? '',
                'sources' => array_map(static fn (array $s): array => [
                    'title' => $s['title'] ?? '',
                    'url' => $s['url'] ?? '',
                    'snippet' => $s['snippet'] ?? '',
                    'outlet' => $s['outlet'] ?? '',
                ], array_slice($pack['sources'], 0, 8)),
            ];
        }

        $user = [
            'gameweek' => $gw,
            'gameweek_name' => $gwName,
            'required_tips' => count($packsForAi),
            'tips' => $packsForAi,
            'seo_title_pattern' => 'FPL Gameweek ' . $gw . ' tips: …',
            'h1_hint' => 'Must include FPL Gameweek ' . $gw . ' tips',
            'length_targets' => [
                'article_minimum_words' => 1500,
                'per_tip_minimum_words' => 150,
                'intro_minimum_words' => 120,
                'closing_minimum_words' => 80,
            ],
            'writing_brief' => [
                'Write for a smart 10 year old. Plain words only.',
                'Never use delve, examining, navigating, landscape, or in a recent.',
                'Each tip why_pick opening sentence must name the player, fixture, opponent, and kickoff date when known.',
                'Include exactly one markdown link per tip in the article body.',
                'what_we_suggest must be 2 to 3 sentences with a clear action: captain, buy, sell, bench, hold, or avoid.',
                'season_stats on each tip come from the live FPL API and are always correct for the current season.',
                'Never claim a player blanked, struggled for returns, or failed to score if season_stats show goals, assists, or strong total_points.',
                'If a source snippet disagrees with season_stats, trust season_stats and ignore the conflicting snippet.',
            ],
        ];

        if ($isEdit) {
            $user['edit_instructions'] = (string) ($editContext['instructions'] ?? '');
            $user['focus_element_id'] = $editContext['element_id'] ?? null;
            $user['current_published_post'] = $editContext['current_post'] ?? null;
        }

        return OpenAiClient::chatJson(
            [
                ['role' => 'system', 'content' => self::writerSystemPrompt($isEdit)],
                ['role' => 'user', 'content' => json_encode($user, JSON_THROW_ON_ERROR)],
            ],
            $isEdit ? 'metro_fpl_gw_tips_edit' : 'metro_fpl_gw_tips',
            self::articleSchema(count($packsForAi)),
            180
        );
    }

    private static function writerSystemPrompt(bool $isEdit): string
    {
        $base = <<<'TXT'
You write Metro Football public FPL Gameweek tips for managers before the deadline. Long form journalism, not bullet notes.

Voice: We or Metro Football. Never lone first person I.
Copy: clear enough for a 10 year old. Short paragraphs of 2 to 4 sentences. No dashes. No exclamation marks.
Banned: delve, examining, navigating, landscape, in a recent, it remains to be seen, in conclusion.

Length: whole article at least 1500 words. Each tip at least 150 words. Intro at least 120 words. Closing at least 80 words.

Every tip must include:
- why_pick: 4 to 6 paragraphs. For player_to_avoid use wary tone. Others use positive tone.
- fpl_impact: 3 to 4 paragraphs on price, ownership, template risk, captaincy, transfers.
- what_we_suggest: 2 to 3 sentences telling managers what Metro Football advises.

Facts: fixture_line, opponent_rank, form_line, and season_stats come from supplied data. season_stats are authoritative. Do not invent stats.
Never say a player blanked or had no returns if season_stats show goals, assists, or strong total_points this season.
Source snippets are background only. If a snippet conflicts with season_stats, ignore the snippet for that fact.
Links: exactly one markdown link [phrase](url) per tip inside why_pick or fpl_impact.

SEO: phrase "FPL Gameweek X tips" (use the real gameweek number) in seo_title at front, first intro paragraph, and about 1.5 to 2 percent density.

quick_picks: 4 short labels for captain, differential, avoid, and budget with element_id and teaser under 12 words.

Return JSON only.
TXT;

        return $isEdit
            ? $base . "\nEdit mode: use stored research only. Follow edit_instructions."
            : $base;
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

    private static function articleSchema(int $tipCount): array
    {
        $tipCount = max(self::MIN_TIPS, min(self::MAX_TIPS, $tipCount));

        $tipBlock = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'element_id' => ['type' => 'integer'],
                'slug' => ['type' => 'string'],
                'heading' => ['type' => 'string'],
                'alt_text' => ['type' => 'string'],
                'tip_type' => ['type' => 'string'],
                'story_lead' => ['type' => 'string'],
                'fixture_line' => ['type' => 'string'],
                'opponent_rank' => ['type' => 'string'],
                'form_line' => ['type' => 'string'],
                'why_pick' => self::narrativeParagraphs(4, 6),
                'fpl_impact' => self::narrativeParagraphs(3, 5),
                'what_we_suggest' => self::narrativeParagraphs(2, 3),
            ],
            'required' => [
                'element_id', 'slug', 'heading', 'alt_text', 'tip_type', 'story_lead',
                'fixture_line', 'opponent_rank', 'form_line',
                'why_pick', 'fpl_impact', 'what_we_suggest',
            ],
        ];

        $quickPick = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'element_id' => ['type' => 'integer'],
                'label' => ['type' => 'string'],
                'teaser' => ['type' => 'string'],
            ],
            'required' => ['element_id', 'label', 'teaser'],
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
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'label'],
                    ],
                    'minItems' => $tipCount,
                    'maxItems' => $tipCount + 2,
                ],
                'quick_picks' => [
                    'type' => 'array',
                    'items' => $quickPick,
                    'minItems' => 4,
                    'maxItems' => 4,
                ],
                'tips' => [
                    'type' => 'array',
                    'items' => $tipBlock,
                    'minItems' => $tipCount,
                    'maxItems' => $tipCount,
                ],
                'closing' => self::narrativeParagraphs(2, 4),
            ],
            'required' => ['seo_title', 'h1', 'intro_paragraphs', 'toc', 'quick_picks', 'tips', 'closing'],
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

        $packsByType = [];
        foreach ($factPacks as $pack) {
            $packsByType[(string) ($pack['tip_type'] ?? '')] = $pack;
        }

        $tipsOut = [];
        foreach (($article['tips'] ?? []) as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $eid = (int) ($block['element_id'] ?? 0);
            $pack = $byId[$eid] ?? null;
            if ($pack === null) {
                $type = (string) ($block['tip_type'] ?? '');
                $pack = $packsByType[$type] ?? ($factPacks[$index] ?? null);
            }
            if ($pack === null) {
                continue;
            }
            $eid = (int) $pack['player']['element_id'];
            $p = $pack['player'];
            $slug = self::slug((string) ($block['slug'] ?? $p['web_name']));
            $tipsOut[] = [
                'element_id' => $eid,
                'slug' => $slug,
                'heading' => self::cleanCopy((string) ($block['heading'] ?? $p['web_name'])),
                'alt_text' => self::cleanCopy((string) ($block['alt_text'] ?? ($p['web_name'] . ' FPL Gameweek ' . $gw . ' tips'))),
                'tip_type' => (string) ($block['tip_type'] ?? $pack['tip_type']),
                'tip_label' => self::TIP_LABELS[$pack['tip_type']] ?? $pack['tip_label'],
                'web_name' => $p['web_name'],
                'team_name' => $p['team_name'],
                'team_short' => $p['team_short'],
                'position' => $p['position'],
                'status' => $p['status'],
                'status_label' => $p['status_label'],
                'ownership' => $p['ownership'],
                'price' => $p['price'],
                'photo' => $p['photo'],
                'shirt' => $p['shirt'],
                'story_lead' => self::cleanCopy((string) ($block['story_lead'] ?? '')),
                'fixture_line' => self::formatFixtureLine((string) ($block['fixture_line'] ?? $p['fixture_line'] ?? '')),
                'fixture_fdr' => (int) ($p['fixture_fdr'] ?? 0),
                'opponent_rank' => self::cleanCopy((string) ($block['opponent_rank'] ?? $p['opponent_rank'] ?? '')),
                'form_line' => self::cleanCopy((string) ($block['form_line'] ?? $p['form_line'] ?? '')),
                'why_pick' => self::cleanParagraphs($block['why_pick'] ?? []),
                'fpl_impact' => self::cleanParagraphs($block['fpl_impact'] ?? []),
                'what_we_suggest' => self::cleanParagraphs($block['what_we_suggest'] ?? []),
                'stats' => $p['stats'],
            ];
        }

        if (count($tipsOut) < self::MIN_TIPS) {
            throw new \RuntimeException('The writer returned too few tips.');
        }

        $quickPicks = [];
        foreach (($article['quick_picks'] ?? []) as $qp) {
            if (!is_array($qp)) {
                continue;
            }
            $eid = (int) ($qp['element_id'] ?? 0);
            $pack = $byId[$eid] ?? null;
            if ($pack === null) {
                continue;
            }
            $quickPicks[] = [
                'element_id' => $eid,
                'label' => self::cleanCopy((string) ($qp['label'] ?? '')),
                'teaser' => self::cleanCopy((string) ($qp['teaser'] ?? '')),
                'web_name' => $pack['player']['web_name'],
                'team_short' => $pack['player']['team_short'],
                'position' => $pack['player']['position'],
                'photo' => $pack['player']['photo'],
                'shirt' => $pack['player']['shirt'],
            ];
        }

        $toc = self::buildTipsToc([
            'quick_picks' => $quickPicks,
            'tips' => $tipsOut,
        ]);

        $meta = sprintf(
            'FPL Gameweek %d tips. See who to captain, who to bench, and what Metro Football thinks managers should do before the deadline.',
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
            'seo_title' => self::cleanCopy((string) ($article['seo_title'] ?? ('FPL Gameweek ' . $gw . ' tips'))),
            'meta_description' => $meta,
            'h1' => self::cleanCopy((string) ($article['h1'] ?? ('FPL Gameweek ' . $gw . ' tips'))),
            'intro_paragraphs' => self::cleanParagraphs($article['intro_paragraphs'] ?? []),
            'toc' => $toc,
            'quick_picks' => $quickPicks,
            'tips' => $tipsOut,
            'closing' => self::cleanParagraphs($article['closing'] ?? []),
            'canonical' => self::canonicalForGameweek($gw),
            'path' => self::pathForGameweek($gw),
            'focus_keyphrase' => 'FPL Gameweek ' . $gw . ' tips',
        ];
    }

    /**
     * @param list<string|array<string,mixed>> $names
     * @param list<array<string,mixed>> $tips
     * @return list<array<string,mixed>>
     */
    private static function buildQuickPickCards(array $names, array $tips): array
    {
        if ($names !== [] && is_array($names[0] ?? null)) {
            return $names;
        }
        $byId = [];
        foreach ($tips as $tip) {
            $byId[(int) ($tip['element_id'] ?? 0)] = $tip;
        }
        $cards = [];
        foreach ($names as $name) {
            if (is_array($name)) {
                $cards[] = $name;
            }
        }
        return $cards !== [] ? $cards : [];
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

    private static function scrubBannedWords(string $text): string
    {
        $replacements = [
            '/\bdelve\b/i' => 'discuss',
            '/\bexamining\b/i' => 'looking at',
            '/\bin a recent\b/i' => 'in the',
            '/\bnavigating\b/i' => 'handling',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        return $text;
    }

    private static function formatFixtureLine(string $text): string
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

    private static function formatKickoffLabel(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
            $dt = $dt->setTimezone(new \DateTimeZone(self::TZ));
            return $dt->format('jS F, Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'player';
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (strlen($text) <= $max) {
            return $text;
        }
        return rtrim(substr($text, 0, $max - 1)) . '…';
    }
}
