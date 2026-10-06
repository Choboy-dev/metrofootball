<?php

declare(strict_types=1);

namespace App;

/**
 * Written FPL verdicts for player and club pages, built only from public FPL data
 * (no premium projections).
 */
final class PlayerScoutReport
{
    private const POS_SINGULAR = [1 => 'goalkeeper', 2 => 'defender', 3 => 'midfielder', 4 => 'forward'];
    private const POS_PLURAL = [1 => 'goalkeepers', 2 => 'defenders', 3 => 'midfielders', 4 => 'forwards'];

    public const VERDICT_LABELS = [
        'buy' => 'Buy',
        'hold' => 'Hold',
        'sell' => 'Sell',
        'watch' => 'Watch',
        'avoid' => 'Avoid',
    ];

    /**
     * @param array<string, mixed> $profile Payload from PlayerProfileService::build()
     * @return array{verdict: string, verdict_label: string, verdict_line: string, paragraphs: list<string>, updated: string}|null
     */
    public static function forPlayer(array $profile): ?array
    {
        $playerId = (int) ($profile['player']['id'] ?? 0);
        if ($playerId < 1) {
            return null;
        }

        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $el = $elements[$playerId] ?? null;
        if (!is_array($el)) {
            return null;
        }

        $teamId = (int) ($el['team'] ?? 0);
        $team = $teams[$teamId] ?? [];
        $pos = (int) ($el['element_type'] ?? 0);
        $seed = $playerId;

        $name = (string) ($el['web_name'] ?? 'He');
        $full = trim((string) ($el['first_name'] ?? '') . ' ' . (string) ($el['second_name'] ?? ''));
        if ($full === '') {
            $full = $name;
        }
        $teamName = (string) ($team['name'] ?? 'his club');
        $played = (int) (self::standings($teams)[$teamId]['played'] ?? 0);
        $starts = (int) ($el['starts'] ?? 0);
        $minutes = (int) ($el['minutes'] ?? 0);
        $mps = $starts > 0 ? min(90, (int) round($minutes / $starts)) : 0;
        $price = ((int) ($el['now_cost'] ?? 0)) / 10;
        $own = (float) ($el['selected_by_percent'] ?? 0);
        $form = (float) ($el['form'] ?? 0);
        $startRatio = $played > 0 ? $starts / $played : 0.0;

        $peers = self::peers($elements, $pos);
        $formRank = self::rankOf($peers, $playerId, static fn (array $p): float => (float) ($p['form'] ?? 0));
        $formPct = $formRank !== null ? 1 - (($formRank - 1) / max(1, count($peers))) : 0.0;

        $fixtures = self::teamFixtures($teamId, $teams, $events, 5);
        $avgFdr = self::averageDifficulty($fixtures);

        $risk = self::availability($el);
        $paragraphs = [];

        if ((string) ($el['status'] ?? 'a') === 'u') {
            $news = trim(str_replace([' - ', ' – ', ' — '], ', ', (string) ($el['news'] ?? '')));
            $paragraphs[] = sprintf(
                '%s is listed as a %s for %s at £%.1fm, but FPL has him marked as unavailable.%s Players in this position score nothing, however good the fixtures look.',
                $full,
                self::POS_SINGULAR[$pos] ?? 'player',
                $teamName,
                $price,
                $news !== '' ? ' The reason given: ' . rtrim($news, '.') . '.' : ''
            );

            return [
                'verdict' => 'avoid',
                'verdict_label' => self::VERDICT_LABELS['avoid'],
                'verdict_line' => sprintf('Leave %s out of your team. If you still own him, he should be the first player you sell.', $name),
                'paragraphs' => $paragraphs,
                'updated' => gmdate('j F Y'),
            ];
        }

        // Role and minutes.
        if ($played < 1) {
            $paragraphs[] = self::pick($seed, 1, [
                sprintf('%s is a %s for %s, priced at £%.1fm. The new season has not produced enough football yet to judge him properly, so this summary will fill out once games are played.', $full, self::POS_SINGULAR[$pos] ?? 'player', $teamName, $price),
                sprintf('%s costs £%.1fm and plays as a %s for %s. There is no league football to go on yet, so we will build a fuller picture after the opening games.', $full, $price, self::POS_SINGULAR[$pos] ?? 'player', $teamName),
            ]);
        } else {
            $role = $starts > 0
                ? self::pick($seed, 2, [
                    sprintf('%s has started %d of %s\'s %d league games this season and averages %d minutes when he does.', $full, $starts, $teamName, $played, $mps),
                    sprintf('%s has been in the %s starting eleven for %d of their %d league matches, staying on for %d minutes on average.', $full, $teamName, $starts, $played, $mps),
                    sprintf('Of the %d Premier League games %s have played, %s has started %d, lasting %d minutes a time on average.', $played, $teamName, $full, $starts, $mps),
                ])
                : sprintf('%s has not started a league game for %s yet this season.', $full, $teamName);

            if ($starts === 0) {
                $security = $minutes > 0
                    ? 'His minutes have come off the bench, which makes him very hard to rely on in Fantasy Premier League.'
                    : 'Until he gets on the pitch, there is nothing for an FPL manager to work with.';
            } elseif ($startRatio >= 0.85 && $mps >= 75) {
                $security = self::pick($seed, 3, [
                    'That is about as secure as a starting place gets.',
                    'His spot in the side looks safe.',
                    'Minutes are not a worry here.',
                ]);
            } elseif ($startRatio >= 0.6) {
                $security = self::pick($seed, 3, [
                    'He starts most weeks, though not every week.',
                    'He is a regular, but rotation does creep in now and then.',
                ]);
            } else {
                $security = self::pick($seed, 3, [
                    'That makes him a rotation risk, and that matters more than any other number on this page.',
                    'He is in and out of the side, which makes him difficult to trust from week to week.',
                ]);
            }

            if ($own >= 20) {
                $ownLine = sprintf('At £%.1fm he is owned by %.1f%% of managers, so he is a core pick for a big part of the game.', $price, $own);
            } elseif ($own < 0.1) {
                $ownLine = sprintf('He costs £%.1fm and hardly anyone owns him.', $price);
            } elseif ($own < 2 && $startRatio >= 0.6) {
                $ownLine = sprintf('At £%.1fm and owned by just %.1f%% of managers, he is a genuine differential.', $price, $own);
            } elseif ($own < 2) {
                $ownLine = sprintf('He costs £%.1fm and is owned by just %.1f%% of managers.', $price, $own);
            } else {
                $ownLine = sprintf('He costs £%.1fm and sits in %.1f%% of teams.', $price, $own);
            }

            $paragraphs[] = $role . ' ' . $security . ' ' . $ownLine;
        }

        // Form against his position.
        if ($played > 0 && $formRank !== null && $minutes > 0) {
            $posPlural = self::POS_PLURAL[$pos] ?? 'players';
            $formLine = self::pick($seed, 4, [
                sprintf('His FPL form, the average points per game over the last 30 days, stands at %.1f. That ranks %s of %d %s who are playing regularly.', $form, self::ordinal($formRank), count($peers), $posPlural),
                sprintf('Over the last 30 days he has averaged %.1f points a game, which puts him %s out of %d regular %s.', $form, self::ordinal($formRank), count($peers), $posPlural),
            ]);
            if ($formRank === 1) {
                $formLine .= ' Nobody at his position is scoring more right now.';
            } elseif ($formRank === 2) {
                $formLine .= sprintf(' Only one %s is scoring more right now.', self::POS_SINGULAR[$pos] ?? 'player');
            } elseif ($formPct >= 0.85) {
                $formLine .= ' ' . self::pick($seed, 5, ['Very few at his position are scoring more right now.', 'Only a handful at his position are doing better.']);
            } elseif ($formPct >= 0.6) {
                $formLine .= ' ' . self::pick($seed, 5, ['Solid, if not spectacular.', 'He is comfortably in the top half.']);
            } elseif ($formPct >= 0.35) {
                $formLine .= ' ' . self::pick($seed, 5, ['Middle of the pack, in other words.', 'He is ticking over rather than flying.']);
            } else {
                $formLine .= ' ' . self::pick($seed, 5, ['He is struggling for points at the moment.', 'Managers want a lot more than that from the position.']);
            }

            $formLine .= self::underlyingLine($el, $pos, $peers, $playerId, $profile);
            $formLine .= self::setPieceLine($el);
            $paragraphs[] = $formLine;
        }

        // Price and transfers.
        $paragraphs[] = self::priceLine($el, $seed);

        // Fixtures.
        if ($fixtures !== []) {
            $paragraphs[] = self::fixtureLine($fixtures, $avgFdr, $teamName, $seed);
        }

        // Verdict.
        [$verdict, $verdictLine] = self::verdict($el, $risk, $played, $starts, $startRatio, $formPct, $avgFdr, $fixtures, $name, $seed);

        return [
            'verdict' => $verdict,
            'verdict_label' => self::VERDICT_LABELS[$verdict],
            'verdict_line' => $verdictLine,
            'paragraphs' => array_values(array_filter($paragraphs, static fn (string $p): bool => trim($p) !== '')),
            'updated' => gmdate('j F Y'),
        ];
    }

    /**
     * @param array<string, mixed> $hub Payload from PlayerProfileService::resolveClubHub()
     * @return array{paragraphs: list<string>, updated: string, title: string}|null
     */
    public static function forClub(array $hub): ?array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];
        $teams = is_array($bootstrap['teams'] ?? null) ? $bootstrap['teams'] : [];
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];

        $teamId = 0;
        foreach ($teams as $row) {
            if ((string) ($row['short_name'] ?? '') === (string) ($hub['short_name'] ?? '')) {
                $teamId = (int) ($row['id'] ?? 0);
                break;
            }
        }
        $team = $teams[$teamId] ?? null;
        if (!is_array($team)) {
            return null;
        }

        $club = (string) ($team['name'] ?? 'This club');
        $seed = $teamId * 7;
        $team = array_merge($team, self::standings($teams)[$teamId] ?? []);
        $played = (int) ($team['played'] ?? 0);
        $squad = array_values(array_filter(
            $elements,
            static fn ($el): bool => is_array($el) && (int) ($el['team'] ?? 0) === $teamId && (string) ($el['status'] ?? 'a') !== 'u'
        ));
        $paragraphs = [];

        if ($played > 0) {
            $position = (int) ($team['position'] ?? 0);
            $standing = $position > 0
                ? sprintf('%s sit %s in the Premier League with %d points from %d games: %d won, %d drawn and %d lost.', $club, self::ordinal($position), (int) ($team['points'] ?? 0), $played, (int) ($team['win'] ?? 0), (int) ($team['draw'] ?? 0), (int) ($team['loss'] ?? 0))
                : sprintf('%s have played %d league games so far, winning %d, drawing %d and losing %d.', $club, $played, (int) ($team['win'] ?? 0), (int) ($team['draw'] ?? 0), (int) ($team['loss'] ?? 0));

            $gf = (int) ($team['gf'] ?? 0);
            $ga = (int) ($team['ga'] ?? 0);
            $goalsLine = sprintf('They have scored %d and conceded %d.', $gf, $ga);
            $gfPer = $gf / $played;
            $gaPer = $ga / $played;
            if ($gaPer <= 0.8 && $gfPer >= 1.8) {
                $goalsLine .= ' That is strong at both ends, so there are points on offer from defenders and attackers alike.';
            } elseif ($gaPer <= 0.8) {
                $goalsLine .= ' The defence is the standout, which makes their goalkeeper and defenders worth a look for clean sheets.';
            } elseif ($gfPer >= 1.8) {
                $goalsLine .= ' Goals are the selling point here, so the attackers are where the FPL value sits.';
            } elseif ($gaPer >= 1.8) {
                $goalsLine .= ' Leaking that many goals makes their defenders hard to trust for clean sheets.';
            } else {
                $goalsLine .= ' Neither end stands out yet, so pick players on their own numbers rather than the team.';
            }
            $paragraphs[] = $standing . ' ' . $goalsLine;
        } else {
            $paragraphs[] = sprintf('%s have not played a league game yet this season, so this page leans on prices and ownership until the football starts.', $club);
        }

        $regulars = array_values(array_filter($squad, static fn (array $p): bool => (int) ($p['minutes'] ?? 0) >= max(90, $played * 45)));
        $topScorer = self::maxBy($squad, static fn (array $p): float => (float) ($p['total_points'] ?? 0));
        $mostOwned = self::maxBy($squad, static fn (array $p): float => (float) ($p['selected_by_percent'] ?? 0));
        $inForm = self::maxBy($regulars, static fn (array $p): float => (float) ($p['form'] ?? 0));
        $value = self::maxBy($regulars, static fn (array $p): float => ((int) ($p['now_cost'] ?? 0)) > 0 ? ((int) ($p['total_points'] ?? 0)) / (((int) $p['now_cost']) / 10) : 0);

        $assetBits = [];
        if ($topScorer !== null && (int) ($topScorer['total_points'] ?? 0) > 0) {
            $assetBits[] = sprintf('%s leads the way with %d FPL points', (string) $topScorer['web_name'], (int) $topScorer['total_points']);
        }
        if ($mostOwned !== null) {
            $assetBits[] = sprintf('%s is the most popular pick at %.1f%% ownership', (string) $mostOwned['web_name'], (float) $mostOwned['selected_by_percent']);
        }
        if ($topScorer !== null && $mostOwned !== null && (int) $topScorer['id'] === (int) $mostOwned['id'] && $assetBits !== []) {
            $assetBits = [sprintf('%s leads the way with %d FPL points and is also the most popular pick at %.1f%% ownership', (string) $topScorer['web_name'], (int) $topScorer['total_points'], (float) $topScorer['selected_by_percent'])];
        }
        if ($assetBits !== []) {
            $line = ucfirst(implode(', while ', $assetBits)) . '.';
            if ($inForm !== null && (float) ($inForm['form'] ?? 0) > 0) {
                $line .= ' ' . self::pick($seed, 2, [
                    sprintf('On current form, %s is the man to watch, averaging %.1f points a game over the last month.', (string) $inForm['web_name'], (float) $inForm['form']),
                    sprintf('Right now the player in the best form is %s, with %.1f points a game over the last 30 days.', (string) $inForm['web_name'], (float) $inForm['form']),
                ]);
            }
            $valueIsNew = $value !== null && !in_array((int) $value['id'], array_map(static fn ($p): int => (int) ($p['id'] ?? 0), array_filter([$topScorer, $mostOwned, $inForm])), true);
            if ($valueIsNew && (int) ($value['total_points'] ?? 0) > 0) {
                $line .= ' ' . sprintf('For value, %s stands out, returning %.1f points for every £1m of his £%.1fm price.', (string) $value['web_name'], ((int) $value['total_points']) / (((int) $value['now_cost']) / 10), ((int) $value['now_cost']) / 10);
            }
            $paragraphs[] = $line;
        }

        $fixtures = self::teamFixtures($teamId, $teams, $events, 5);
        if ($fixtures !== []) {
            $avg = self::averageDifficulty($fixtures);
            $fixtureText = self::fixtureLine($fixtures, $avg, $club, $seed, true);
            $pick = $inForm ?? $topScorer;
            if ($avg !== null && $avg <= 2.8) {
                $fixtureText .= ' ' . sprintf('That makes this a good stretch to own %s players%s.', $club, $pick !== null ? ', and ' . (string) $pick['web_name'] . ' is the obvious place to start' : '');
            } elseif ($avg !== null && $avg >= 3.5) {
                $fixtureText .= ' ' . sprintf('We would be wary of adding more %s players until the run eases. If you already own the best of them, holding is fine, but this is not the moment to load up.', $club);
            } else {
                $fixtureText .= ' Pick the strongest one or two players here rather than spreading your money across the squad.';
            }
            $paragraphs[] = $fixtureText;
        }

        return [
            'title' => sprintf('%s for FPL: our view', $club),
            'paragraphs' => $paragraphs,
            'updated' => gmdate('j F Y'),
        ];
    }

    /**
     * League table from finished fixtures; the bootstrap team record fields are always zero.
     *
     * @param array<int, array<string, mixed>> $teams
     * @return array<int, array{played: int, win: int, draw: int, loss: int, points: int, gd: int, gf: int, ga: int, position: int}>
     */
    private static function standings(array $teams): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $fixtures = FplClient::fetchFixtures();
        } catch (\Throwable) {
            $fixtures = [];
        }

        $rows = [];
        foreach ($teams as $team) {
            $rows[(int) $team['id']] = ['played' => 0, 'win' => 0, 'draw' => 0, 'loss' => 0, 'points' => 0, 'gd' => 0, 'gf' => 0, 'ga' => 0, 'position' => 0];
        }
        foreach ($fixtures as $fx) {
            if (!is_array($fx) || (empty($fx['finished']) && empty($fx['finished_provisional']))) {
                continue;
            }
            if (($fx['team_h_score'] ?? null) === null || ($fx['team_a_score'] ?? null) === null) {
                continue;
            }
            $h = (int) $fx['team_h'];
            $a = (int) $fx['team_a'];
            if (!isset($rows[$h], $rows[$a])) {
                continue;
            }
            $hs = (int) $fx['team_h_score'];
            $as = (int) $fx['team_a_score'];
            foreach ([[$h, $hs, $as], [$a, $as, $hs]] as [$id, $for, $against]) {
                $rows[$id]['played']++;
                $rows[$id]['gf'] += $for;
                $rows[$id]['ga'] += $against;
                $rows[$id]['gd'] += $for - $against;
                if ($for > $against) {
                    $rows[$id]['win']++;
                    $rows[$id]['points'] += 3;
                } elseif ($for === $against) {
                    $rows[$id]['draw']++;
                    $rows[$id]['points']++;
                } else {
                    $rows[$id]['loss']++;
                }
            }
        }

        $order = array_keys($rows);
        usort($order, static fn (int $x, int $y): int => [$rows[$y]['points'], $rows[$y]['gd'], $rows[$y]['gf']] <=> [$rows[$x]['points'], $rows[$x]['gd'], $rows[$x]['gf']]);
        foreach ($order as $i => $id) {
            $rows[$id]['position'] = $i + 1;
        }

        return $cache = $rows;
    }

    /**
     * @param array<int|string, array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    private static function peers(array $elements, int $pos): array
    {
        $out = [];
        foreach ($elements as $el) {
            if (!is_array($el) || (int) ($el['element_type'] ?? 0) !== $pos) {
                continue;
            }
            if ((string) ($el['status'] ?? 'a') === 'u' || (int) ($el['starts'] ?? 0) < 1) {
                continue;
            }
            $out[] = $el;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function rankOf(array $rows, int $id, callable $metric): ?int
    {
        $target = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $target = $metric($row);
                break;
            }
        }
        if ($target === null) {
            return null;
        }
        $rank = 1;
        foreach ($rows as $row) {
            if ($metric($row) > $target) {
                $rank++;
            }
        }

        return $rank;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private static function maxBy(array $rows, callable $metric): ?array
    {
        $best = null;
        $bestVal = -INF;
        foreach ($rows as $row) {
            $v = $metric($row);
            if ($v > $bestVal) {
                $bestVal = $v;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function availability(array $el): ?string
    {
        $status = strtolower((string) ($el['status'] ?? 'a'));
        $chance = $el['chance_of_playing_next_round'] ?? null;
        if (in_array($status, ['i', 's', 'u', 'n'], true) || $chance === 0 || $chance === '0') {
            return 'out';
        }
        if ($status === 'd' || ($chance !== null && $chance !== '' && (int) $chance < 100)) {
            return 'doubt';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $el
     * @param list<array<string, mixed>> $peers
     * @param array<string, mixed> $profile
     */
    private static function underlyingLine(array $el, int $pos, array $peers, int $playerId, array $profile): string
    {
        $text = '';
        if ($pos === 3 || $pos === 4) {
            $xgi = (float) ($el['expected_goal_involvements_per_90'] ?? 0);
            $rank = self::rankOf($peers, $playerId, static fn (array $p): float => (float) ($p['expected_goal_involvements_per_90'] ?? 0));
            if ($rank !== null && $xgi > 0) {
                $text .= sprintf(' Underneath the points, his expected goal involvement of %.2f per 90 minutes ranks %s in the same group.', $xgi, self::ordinal($rank));
            }
        } elseif ($pos === 2) {
            $cs = (int) ($el['clean_sheets'] ?? 0);
            $bonus = (int) ($el['bonus'] ?? 0);
            $text .= sprintf(' He has %d clean sheet%s and %d bonus point%s so far.', $cs, $cs === 1 ? '' : 's', $bonus, $bonus === 1 ? '' : 's');
        } elseif ($pos === 1) {
            $cs = (int) ($el['clean_sheets'] ?? 0);
            $saves = (float) ($el['saves_per_90'] ?? 0);
            $text .= sprintf(' He has kept %d clean sheet%s and makes %.1f saves per 90 minutes, and every three saves is worth a point.', $cs, $cs === 1 ? '' : 's', $saves);
        }

        $chart = is_array($profile['points_vs_expected'] ?? null) ? $profile['points_vs_expected'] : [];
        if (count($chart) >= 3) {
            $pts = array_sum(array_map(static fn (array $r): float => (float) ($r['points'] ?? 0), $chart));
            $exp = array_sum(array_map(static fn (array $r): float => (float) ($r['expected_points'] ?? 0), $chart));
            if ($pts > $exp + 4) {
                $text .= ' His returns are running ahead of what the chances suggest, so a quieter spell would not be a surprise.';
            } elseif ($pts + 4 < $exp) {
                $text .= ' His returns trail what his chances suggest, which often means better weeks are on the way.';
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function setPieceLine(array $el): string
    {
        $pens = $el['penalties_order'] ?? null;
        $corners = $el['corners_and_indirect_freekicks_order'] ?? null;
        if ((int) $pens === 1 && (int) $corners === 1) {
            return ' He is first choice for penalties and corners, which gives him a higher ceiling than most at his price.';
        }
        if ((int) $pens === 1) {
            return ' He is first in line for penalties, and that alone lifts his ceiling.';
        }
        if ((int) $corners === 1) {
            return ' He also takes corners and indirect free kicks, which adds assist potential.';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function priceLine(array $el, int $seed): string
    {
        $start = (int) ($el['cost_change_start'] ?? 0);
        $event = (int) ($el['cost_change_event'] ?? 0);
        $net = (int) ($el['transfers_in_event'] ?? 0) - (int) ($el['transfers_out_event'] ?? 0);
        $pct = (float) ($el['price_change_percent'] ?? 0);

        if ($start > 0) {
            $line = sprintf('His price has gone up £%.1fm since the start of the season', $start / 10);
        } elseif ($start < 0) {
            $line = sprintf('His price has dropped £%.1fm since the start of the season', abs($start) / 10);
        } else {
            $line = 'He costs exactly what he did at the start of the season';
        }
        if ($event > 0) {
            $line .= ', including a rise this gameweek.';
        } elseif ($event < 0) {
            $line .= ', including a fall this gameweek.';
        } else {
            $line .= '.';
        }

        if ($net >= 50000) {
            $line .= ' ' . self::pick($seed, 6, [
                sprintf('Managers are piling in, with %s more transfers in than out this gameweek, so further rises are likely.', number_format($net)),
                sprintf('Demand is strong: %s more managers have bought him than sold him this gameweek.', number_format($net)),
            ]);
        } elseif ($net <= -50000) {
            $line .= ' ' . self::pick($seed, 6, [
                sprintf('The selling has started, with %s more transfers out than in this gameweek, so a fall could follow.', number_format(abs($net))),
                sprintf('Managers are cashing out: %s more have sold him than bought him this gameweek.', number_format(abs($net))),
            ]);
        } elseif ($net >= 5000) {
            $line .= sprintf(' He has picked up %s net transfers in this gameweek.', number_format($net));
        } elseif ($net <= -5000) {
            $line .= sprintf(' He has lost %s net transfers this gameweek.', number_format(abs($net)));
        }

        if ($pct >= 70) {
            $line .= sprintf(' Our price predictor has him %d%% of the way to his next rise.', (int) round($pct));
        } elseif ($pct <= -70) {
            $line .= sprintf(' Our price predictor has him %d%% of the way to a fall.', (int) round(abs($pct)));
        }

        return $line;
    }

    /**
     * @param list<array{gw: int, games: list<array{opp: string, venue: string, fdr: int}>}> $fixtures
     */
    private static function fixtureLine(array $fixtures, ?float $avg, string $teamName, int $seed, bool $isClub = false): string
    {
        $parts = [];
        $blanks = [];
        $doubles = [];
        foreach ($fixtures as $gw) {
            if ($gw['games'] === []) {
                $blanks[] = $gw['gw'];
                continue;
            }
            if (count($gw['games']) > 1) {
                $doubles[] = $gw['gw'];
            }
            foreach ($gw['games'] as $g) {
                $parts[] = $g['opp'] . ' (' . $g['venue'] . ')';
            }
        }

        $subject = $isClub ? $teamName . ' face' : 'Over the next five gameweeks he faces';
        $list = self::joinList($parts);
        $line = $isClub
            ? sprintf('Across the next five gameweeks, %s %s.', $subject, $list)
            : sprintf('%s %s.', $subject, $list);

        if ($avg !== null) {
            if ($avg <= 2.4) {
                $line .= ' ' . self::pick($seed, 7, ['On paper that is a kind run.', 'It is about as friendly a schedule as you will find.']);
            } elseif ($avg <= 3.0) {
                $line .= ' ' . self::pick($seed, 7, ['That is a fair run of games.', 'There is nothing there to be afraid of.']);
            } elseif ($avg <= 3.5) {
                $line .= ' ' . self::pick($seed, 7, ['It is a mixed spell, with good and awkward games side by side.', 'That run has a bit of everything.']);
            } else {
                $line .= ' ' . self::pick($seed, 7, ['That is a tough run, and points will be harder to come by.', 'It is a demanding stretch against strong opposition.']);
            }
        }
        if ($doubles !== []) {
            $line .= sprintf(' There is a double gameweek in Gameweek %s, which is worth planning around.', self::joinList(array_map('strval', $doubles)));
        }
        if ($blanks !== []) {
            $line .= sprintf(' %s have no game in Gameweek %s, so bench cover will be needed.', $teamName, self::joinList(array_map('strval', $blanks)));
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $el
     * @param list<array{gw: int, games: list<array<string, mixed>>}> $fixtures
     * @return array{0: string, 1: string}
     */
    private static function verdict(
        array $el,
        ?string $risk,
        int $played,
        int $starts,
        float $startRatio,
        float $formPct,
        ?float $avgFdr,
        array $fixtures,
        string $name,
        int $seed
    ): array {
        $news = trim(str_replace([' - ', ' – ', ' — '], ', ', (string) ($el['news'] ?? '')));
        $newsLine = $news !== '' ? 'The latest news: ' . rtrim($news, '.') . '. ' : '';
        $fdr = $avgFdr ?? 3.0;
        $blanks = count(array_filter($fixtures, static fn (array $g): bool => $g['games'] === []));

        if ($risk === 'out') {
            return ['sell', $newsLine . sprintf('Until %s is back in contention, owners should look to move him on and everyone else should leave him alone. A player who is not playing cannot score.', $name)];
        }
        if ($played < 1) {
            return ['watch', sprintf('We would wait for a couple of games before making a call on %s. Early season minutes tell you more than any preseason hype.', $name)];
        }
        if ($starts === 0 || $startRatio < 0.4) {
            return ['watch', sprintf('Without regular starts, %s is a player to keep an eye on rather than buy. If he forces his way into the side, this changes quickly.', $name)];
        }
        if ($risk === 'doubt') {
            return ['watch', $newsLine . sprintf('With a fitness question over %s, wait for the press conference before doing anything. If you own him, have a replacement lined up.', $name)];
        }

        $score = ($formPct * 2) + ((3.0 - $fdr) * 0.6) + ($startRatio >= 0.85 ? 0.3 : 0) - ($blanks * 0.3);

        if ($score >= 1.75 && $formPct >= 0.6) {
            return ['buy', self::pick($seed, 8, [
                sprintf('For managers without %s, now looks like a good time to buy. His form and his fixtures point the same way, and that is the combination we look for.', $name),
                sprintf('%s is a buy for us. He is playing well, he is playing every week, and the games ahead give him every chance to keep it going.', $name),
            ])];
        }
        if ($formPct <= 0.3 && $fdr >= 3.2) {
            return ['sell', self::pick($seed, 8, [
                sprintf('If you own %s, we would look to move him on. Poor form and a hard run is the worst mix in FPL, and that transfer is better spent elsewhere.', $name),
                sprintf('We would sell %s. He is not scoring, the fixtures do not help, and holding on usually costs more points than it saves.', $name),
            ])];
        }
        if ($formPct <= 0.25) {
            return ['sell', sprintf('%s is struggling badly for points. Unless he is a long term pick you believe in, there are better uses for the money.', $name)];
        }

        if ($formPct < 0.35) {
            return ['hold', sprintf('There is no need to panic about %s yet. He is starting and the games ahead give him a chance, but if the points do not come soon, he is one to replace.', $name)];
        }

        return ['hold', self::pick($seed, 8, [
            sprintf('If %s is already in your team, keep him. If he is not, there are probably better places to spend this week\'s transfer.', $name),
            sprintf('We would hold %s. He is doing enough to keep his place in your team, but not enough to be worth a transfer on his own.', $name),
        ])];
    }

    /**
     * @param array<int, array<string, mixed>> $teams
     * @param list<array<string, mixed>> $events
     * @return list<array{gw: int, games: list<array{opp: string, venue: string, fdr: int}>}>
     */
    private static function teamFixtures(int $teamId, array $teams, array $events, int $count): array
    {
        $nextId = 0;
        foreach ($events as $ev) {
            if (!empty($ev['is_next'])) {
                $nextId = (int) $ev['id'];
                break;
            }
        }
        if ($nextId < 1) {
            return [];
        }
        $lastEvent = 0;
        foreach ($events as $ev) {
            $lastEvent = max($lastEvent, (int) ($ev['id'] ?? 0));
        }

        try {
            $all = FplClient::fetchFixtures();
        } catch (\Throwable) {
            return [];
        }

        $byGw = [];
        for ($gw = $nextId; $gw < $nextId + $count && $gw <= $lastEvent; $gw++) {
            $byGw[$gw] = [];
        }
        foreach ($all as $fx) {
            if (!is_array($fx) || !empty($fx['finished'])) {
                continue;
            }
            $gw = (int) ($fx['event'] ?? 0);
            if (!array_key_exists($gw, $byGw)) {
                continue;
            }
            $home = (int) ($fx['team_h'] ?? 0);
            $away = (int) ($fx['team_a'] ?? 0);
            if ($home === $teamId) {
                $byGw[$gw][] = ['opp' => (string) ($teams[$away]['short_name'] ?? '?'), 'venue' => 'H', 'fdr' => (int) ($fx['team_h_difficulty'] ?? 3)];
            } elseif ($away === $teamId) {
                $byGw[$gw][] = ['opp' => (string) ($teams[$home]['short_name'] ?? '?'), 'venue' => 'A', 'fdr' => (int) ($fx['team_a_difficulty'] ?? 3)];
            }
        }

        $out = [];
        foreach ($byGw as $gw => $games) {
            $out[] = ['gw' => $gw, 'games' => $games];
        }

        return $out;
    }

    /**
     * @param list<array{gw: int, games: list<array{fdr: int}>}> $fixtures
     */
    private static function averageDifficulty(array $fixtures): ?float
    {
        $vals = [];
        foreach ($fixtures as $gw) {
            foreach ($gw['games'] as $g) {
                $vals[] = (int) $g['fdr'];
            }
        }

        return $vals === [] ? null : array_sum($vals) / count($vals);
    }

    /**
     * @param list<string> $options
     */
    private static function pick(int $seed, int $slot, array $options): string
    {
        $i = abs(crc32($seed . ':' . $slot)) % count($options);

        return $options[$i];
    }

    private static function ordinal(int $n): string
    {
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return $n . 'th';
        }

        return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    }

    /**
     * @param list<string> $items
     */
    private static function joinList(array $items): string
    {
        $items = array_values($items);
        $n = count($items);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[$n - 1];
    }
}
