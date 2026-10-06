<?php

declare(strict_types=1);

namespace App\FplGuides;

/**
 * Adds internal guide links and external sources to article HTML at render time.
 */
final class ArticleLinker
{
    /**
     * @return array{internal:list<array{from:string,to:string,label?:string}>,external:list<array{href:string,label:string}>}
     */
    private static function rules(string $slug): array
    {
        $g = static fn (string $s): string => '/fpl-guides/' . $s;

        $rules = [
            'how-to-play-fpl' => [
                'internal' => [
                    ['from' => 'our separate guide on picking the armband', 'to' => $g('how-to-pick-captain'), 'label' => 'guide on picking the armband'],
                    ['from' => 'our dedicated scoring guide', 'to' => $g('how-fpl-scoring-works'), 'label' => 'dedicated scoring guide'],
                    ['from' => 'called taking a hit', 'to' => $g('when-to-take-a-hit'), 'label' => 'taking a hit'],
                    ['from' => 'blank gameweeks when many clubs do not play', 'to' => $g('blank-double-gameweek-guide'), 'label' => 'blank gameweeks'],
                    ['from' => 'double gameweeks when some teams play twice', 'to' => $g('blank-double-gameweek-guide'), 'label' => 'double gameweeks'],
                    ['from' => 'check fixture difficulty for the next two or three rounds', 'to' => '/fixture-difficulty', 'label' => 'fixture difficulty'],
                    ['from' => '<strong>Rate My Team</strong>', 'to' => '/pick', 'label' => '<strong>Rate My Team</strong>'],
                    ['from' => '<strong>Gameweek tips</strong>', 'to' => '/fpl-gameweek-tips', 'label' => '<strong>Gameweek tips</strong>'],
                    ['from' => '<strong>AI Tips</strong>', 'to' => '/ai-tips', 'label' => '<strong>AI Tips</strong>'],
                    ['from' => 'that is a Wildcard conversation', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard conversation'],
                    ['from' => 'valid formation', 'to' => $g('fpl-formations-guide'), 'label' => 'valid formation'],
                    ['from' => 'Your <strong>team value</strong> is the current market price', 'to' => $g('price-rises-and-falls'), 'label' => '<strong>team value</strong>'],
                    ['from' => 'Explore our guides', 'to' => '/fpl-guides', 'label' => 'Explore our guides'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official Fantasy Premier League rules and help'],
                    ['href' => 'https://www.premierleague.com/fixtures', 'label' => 'Premier League fixture list'],
                    ['href' => 'https://fantasy.premierleague.com/', 'label' => 'Fantasy Premier League game site'],
                ],
            ],
            'wildcard-vs-free-hit' => [
                'internal' => [
                    ['from' => 'blank gameweek: many of your players have no fixture', 'to' => $g('blank-double-gameweek-guide'), 'label' => 'blank gameweek'],
                    ['from' => 'double gameweek prep favour Wildcard', 'to' => $g('blank-double-gameweek-guide'), 'label' => 'double gameweek prep'],
                    ['from' => '<strong>Chip Strategy</strong> tool maps', 'to' => '/chip-strategy', 'label' => '<strong>Chip Strategy</strong> tool'],
                    ['from' => '<strong>Fixture Difficulty</strong> shows', 'to' => '/fixture-difficulty', 'label' => '<strong>Fixture Difficulty</strong>'],
                    ['from' => '<strong>AI Tips chip preview</strong> estimates', 'to' => '/ai-tips#chip-preview', 'label' => '<strong>AI Tips chip preview</strong>'],
                    ['from' => 'Bench Boost or Triple Captain', 'to' => $g('chip-guide'), 'label' => 'Bench Boost or Triple Captain'],
                    ['from' => 'Price changes trapped you with unsellable assets', 'to' => $g('price-rises-and-falls'), 'label' => 'Price changes'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL chip rules'],
                    ['href' => 'https://www.premierleague.com/fixtures', 'label' => 'Premier League fixtures and reschedules'],
                    ['href' => 'https://www.premierleague.com/news', 'label' => 'Premier League news and postponements'],
                ],
            ],
            'when-to-take-a-hit' => [
                'internal' => [
                    ['from' => 'Every gameweek you get one free transfer', 'to' => $g('how-to-play-fpl'), 'label' => 'one free transfer'],
                    ['from' => 'Price Change Predictions', 'to' => '/price-changes', 'label' => 'Price Change Predictions'],
                    ['from' => 'Drop Watch on AI Tips', 'to' => '/ai-tips#drop-watch', 'label' => 'Drop Watch on AI Tips'],
                    ['from' => 'protect team value', 'to' => $g('price-rises-and-falls'), 'label' => 'team value'],
                    ['from' => 'hit calculator flags', 'to' => '/ai-tips#hit-calculator', 'label' => 'hit calculator'],
                    ['from' => 'differential who also blanks', 'to' => $g('template-vs-differential'), 'label' => 'differential'],
                    ['from' => 'Wildcard or Free Hit is close', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard or Free Hit'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL transfer rules'],
                    ['href' => 'https://fantasy.premierleague.com/statistics', 'label' => 'FPL player statistics'],
                    ['href' => 'https://www.premierleague.com/players', 'label' => 'Premier League player stats'],
                ],
            ],
            'how-fpl-scoring-works' => [
                'internal' => [
                    ['from' => 'judge captain options', 'to' => $g('how-to-pick-captain'), 'label' => 'captain options'],
                    ['from' => '(unless you play Bench Boost)', 'to' => $g('chip-guide'), 'label' => 'Bench Boost'],
                    ['from' => 'Triple Captain chip', 'to' => $g('chip-guide'), 'label' => 'Triple Captain chip'],
                    ['from' => 'Bench Boost chip', 'to' => $g('chip-guide'), 'label' => 'Bench Boost chip'],
                    ['from' => 'Captain doubles goals', 'to' => $g('how-to-pick-captain'), 'label' => 'Captain doubles'],
                    ['from' => 'Metro Football <strong>Live</strong> page', 'to' => '/live', 'label' => 'Metro Football <strong>Live</strong> page'],
                    ['from' => '<strong>Player profiles</strong> show', 'to' => '/players', 'label' => '<strong>Player profiles</strong>'],
                    ['from' => 'captaincy and differentials', 'to' => $g('template-vs-differential'), 'label' => 'differentials'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL scoring rules'],
                    ['href' => 'https://www.premierleague.com/stats', 'label' => 'Premier League match statistics'],
                    ['href' => 'https://www.premierleague.com/matchweek', 'label' => 'Premier League matchweek hub'],
                ],
            ],
            'how-to-pick-captain' => [
                'internal' => [
                    ['from' => 'template vs differential strategy', 'to' => $g('template-vs-differential'), 'label' => 'template vs differential strategy'],
                    ['from' => 'Template captain vs differential captain', 'to' => $g('template-vs-differential'), 'label' => 'Template captain vs differential captain'],
                    ['from' => 'Metro Football <strong>Fixture Difficulty</strong> tool', 'to' => '/fixture-difficulty', 'label' => '<strong>Fixture Difficulty</strong> tool'],
                    ['from' => '<strong>Rate My Team</strong> confirms', 'to' => '/pick', 'label' => '<strong>Rate My Team</strong>'],
                    ['from' => '<strong>AI Tips</strong> suggests captain', 'to' => '/ai-tips#captain', 'label' => '<strong>AI Tips</strong>'],
                    ['from' => '<strong>Gameweek tips</strong> shows', 'to' => '/fpl-gameweek-tips', 'label' => '<strong>Gameweek tips</strong>'],
                    ['from' => 'Triple Captain makes your captain', 'to' => $g('chip-guide'), 'label' => 'Triple Captain'],
                    ['from' => 'double gameweeks or home games', 'to' => $g('blank-double-gameweek-guide'), 'label' => 'double gameweeks'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL captain rules'],
                    ['href' => 'https://www.premierleague.com/stats', 'label' => 'Premier League player stats'],
                    ['href' => 'https://www.premierleague.com/fixtures', 'label' => 'Premier League fixtures'],
                ],
            ],
            'price-rises-and-falls' => [
                'internal' => [
                    ['from' => 'Price Change Predictions estimates', 'to' => '/price-changes', 'label' => 'Price Change Predictions'],
                    ['from' => 'Drop Watch on AI Tips', 'to' => '/ai-tips#drop-watch', 'label' => 'Drop Watch on AI Tips'],
                    ['from' => 'Chip Strategy on Metro Football helps', 'to' => '/chip-strategy', 'label' => 'Chip Strategy on Metro Football'],
                    ['from' => 'Wildcard is the great reset button', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard'],
                    ['from' => 'Free Hit and Wildcard reprice', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Free Hit and Wildcard'],
                    ['from' => 'worth more than four points', 'to' => $g('when-to-take-a-hit'), 'label' => 'minus 4 hit'],
                    ['from' => 'Why team value matters', 'to' => $g('how-to-play-fpl'), 'label' => 'team value basics'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL rules on transfers and pricing'],
                    ['href' => 'https://fantasy.premierleague.com/statistics', 'label' => 'FPL player prices and ownership'],
                    ['href' => 'https://www.premierleague.com/news', 'label' => 'Premier League news affecting availability'],
                ],
            ],
            'blank-double-gameweek-guide' => [
                'internal' => [
                    ['from' => 'Fixture Difficulty on Metro Football colours', 'to' => '/fixture-difficulty', 'label' => 'Fixture Difficulty on Metro Football'],
                    ['from' => 'Chip Strategy layers chip windows', 'to' => '/chip-strategy', 'label' => 'Chip Strategy'],
                    ['from' => 'Free Hit is the classic large-blank tool', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Free Hit'],
                    ['from' => 'Wildcard is stronger', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard'],
                    ['from' => 'Bench Boost on big doubles', 'to' => $g('chip-guide'), 'label' => 'Bench Boost'],
                    ['from' => 'Triple Captain on doubles', 'to' => $g('chip-guide'), 'label' => 'Triple Captain'],
                    ['from' => 'rotation risk for the second match', 'to' => $g('how-to-pick-captain'), 'label' => 'rotation risk'],
                    ['from' => 'That costs points if you take hits', 'to' => $g('when-to-take-a-hit'), 'label' => 'take hits'],
                ],
                'external' => [
                    ['href' => 'https://www.premierleague.com/fixtures', 'label' => 'Premier League fixtures and reschedules'],
                    ['href' => 'https://www.premierleague.com/news', 'label' => 'Premier League postponement news'],
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL gameweek rules'],
                ],
            ],
            'fpl-formations-guide' => [
                'internal' => [
                    ['from' => 'test shapes on Rate My Team', 'to' => '/pick', 'label' => 'Rate My Team'],
                    ['from' => 'explore alternatives on AI Teams', 'to' => '/ai-teams', 'label' => 'AI Teams'],
                    ['from' => 'Wildcard rebuild: pick formation after players', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard rebuild'],
                    ['from' => 'FPL auto-sub replaces', 'to' => $g('how-to-play-fpl'), 'label' => 'auto-sub rules'],
                    ['from' => 'Captain lives inside formation choice', 'to' => $g('how-to-pick-captain'), 'label' => 'Captain choice'],
                    ['from' => 'if you Wildcard or pivot lines', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL squad and formation rules'],
                    ['href' => 'https://www.premierleague.com/clubs', 'label' => 'Premier League clubs and squads'],
                    ['href' => 'https://www.premierleague.com/stats', 'label' => 'Premier League positional stats'],
                ],
            ],
            'template-vs-differential' => [
                'internal' => [
                    ['from' => 'AI Tips differential finder', 'to' => '/ai-tips#snipers', 'label' => 'AI Tips differential finder'],
                    ['from' => 'Mini Leagues rival tracking', 'to' => '/mini-leagues', 'label' => 'Mini Leagues rival tracking'],
                    ['from' => 'Differential Finder on AI Tips surfaces', 'to' => '/ai-tips#snipers', 'label' => 'Differential Finder on AI Tips'],
                    ['from' => 'Mini Leagues on Metro Football shows', 'to' => '/mini-leagues', 'label' => 'Mini Leagues on Metro Football'],
                    ['from' => 'Early ownership on a breakout player funds team value', 'to' => $g('price-rises-and-falls'), 'label' => 'team value'],
                    ['from' => 'Captain is the highest leverage differential decision', 'to' => $g('how-to-pick-captain'), 'label' => 'Captain'],
                    ['from' => 'Wildcard is a good moment', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Wildcard'],
                    ['from' => 'Free Hit can load differentials', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Free Hit'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/statistics', 'label' => 'FPL ownership statistics'],
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL rules'],
                    ['href' => 'https://www.premierleague.com/stats', 'label' => 'Premier League player stats'],
                ],
            ],
            'chip-guide' => [
                'internal' => [
                    ['from' => 'Metro Football maps chip windows on Chip Strategy and estimates upside on AI Tips chip preview', 'to' => '/chip-strategy', 'label' => 'Chip Strategy'],
                    ['from' => 'AI Tips chip preview estimates', 'to' => '/ai-tips#chip-preview', 'label' => 'AI Tips chip preview'],
                    ['from' => 'blank and double gameweeks', 'to' => $g('blank-double-gameweek-guide'), 'label' => 'blank and double gameweeks'],
                    ['from' => 'Free Hit works like Wildcard', 'to' => $g('wildcard-vs-free-hit'), 'label' => 'Free Hit vs Wildcard'],
                    ['from' => 'Triple Captain makes captain points', 'to' => $g('how-to-pick-captain'), 'label' => 'Triple Captain captaincy'],
                    ['from' => 'crystallises team value gains', 'to' => $g('price-rises-and-falls'), 'label' => 'team value'],
                    ['from' => 'one or two transfers fixes the XI', 'to' => $g('when-to-take-a-hit'), 'label' => 'transfer hits'],
                ],
                'external' => [
                    ['href' => 'https://fantasy.premierleague.com/help', 'label' => 'Official FPL chip rules'],
                    ['href' => 'https://www.premierleague.com/fixtures', 'label' => 'Premier League fixture calendar'],
                    ['href' => 'https://www.premierleague.com/news', 'label' => 'Premier League news'],
                ],
            ],
        ];

        return $rules[$slug] ?? ['internal' => [], 'external' => []];
    }

    /**
     * @return list<string>
     */
    private static function relatedSlugs(string $slug): array
    {
        $map = [
            'how-to-play-fpl' => ['how-fpl-scoring-works', 'how-to-pick-captain', 'when-to-take-a-hit', 'chip-guide', 'fpl-formations-guide'],
            'wildcard-vs-free-hit' => ['chip-guide', 'blank-double-gameweek-guide', 'when-to-take-a-hit', 'price-rises-and-falls'],
            'when-to-take-a-hit' => ['how-to-play-fpl', 'price-rises-and-falls', 'wildcard-vs-free-hit', 'template-vs-differential'],
            'how-fpl-scoring-works' => ['how-to-play-fpl', 'how-to-pick-captain', 'chip-guide', 'template-vs-differential'],
            'how-to-pick-captain' => ['how-fpl-scoring-works', 'template-vs-differential', 'blank-double-gameweek-guide', 'chip-guide'],
            'price-rises-and-falls' => ['when-to-take-a-hit', 'wildcard-vs-free-hit', 'how-to-play-fpl', 'template-vs-differential'],
            'blank-double-gameweek-guide' => ['chip-guide', 'wildcard-vs-free-hit', 'how-to-pick-captain', 'fixture-difficulty'],
            'fpl-formations-guide' => ['how-to-play-fpl', 'how-to-pick-captain', 'wildcard-vs-free-hit', 'chip-guide'],
            'template-vs-differential' => ['how-to-pick-captain', 'price-rises-and-falls', 'mini-leagues', 'how-to-play-fpl'],
            'chip-guide' => ['wildcard-vs-free-hit', 'blank-double-gameweek-guide', 'how-to-pick-captain', 'price-rises-and-falls'],
        ];

        return $map[$slug] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private static function guideTitles(): array
    {
        return [
            'how-to-play-fpl' => 'How to play Fantasy Premier League',
            'wildcard-vs-free-hit' => 'Wildcard vs Free Hit',
            'when-to-take-a-hit' => 'When should you take a -4 hit?',
            'how-fpl-scoring-works' => 'How FPL scoring works',
            'how-to-pick-captain' => 'How to pick your FPL captain',
            'price-rises-and-falls' => 'FPL price rises and falls',
            'blank-double-gameweek-guide' => 'Blank and double gameweek guide',
            'fpl-formations-guide' => 'FPL formations guide',
            'template-vs-differential' => 'Template vs differential picks',
            'chip-guide' => 'FPL chip guide',
        ];
    }

    public static function apply(string $slug, string $html): string
    {
        $config = self::rules($slug);
        $selfPath = '/fpl-guides/' . $slug;

        usort($config['internal'], static fn (array $a, array $b): int => strlen($b['from']) <=> strlen($a['from']));

        foreach ($config['internal'] as $rule) {
            if (($rule['to'] ?? '') === $selfPath) {
                continue;
            }

            $label = $rule['label'] ?? $rule['from'];
            $link = self::anchor((string) $rule['to'], (string) $label, str_starts_with((string) $rule['to'], 'http'));
            $html = self::replaceFirst((string) $rule['from'], $link, $html);
        }

        $html .= self::relatedBlock($slug);

        if ($config['external'] !== []) {
            $html .= self::externalBlock(array_slice($config['external'], 0, 3));
        }

        return $html;
    }

    private static function relatedBlock(string $slug): string
    {
        $titles = self::guideTitles();
        $items = [];

        foreach (self::relatedSlugs($slug) as $related) {
            if ($related === $slug) {
                continue;
            }

            if ($related === 'fixture-difficulty') {
                $items[] = '<li>' . self::anchor('/fixture-difficulty', 'Fixture Difficulty tool') . '</li>';
                continue;
            }

            if ($related === 'mini-leagues') {
                $items[] = '<li>' . self::anchor('/mini-leagues', 'Mini Leagues') . '</li>';
                continue;
            }

            $title = $titles[$related] ?? $related;
            $items[] = '<li>' . self::anchor('/fpl-guides/' . $related, $title) . '</li>';
        }

        if ($items === []) {
            return '';
        }

        return '<nav class="fg-related" aria-label="Related guides">'
            . '<h2 class="fg-related__title">Related guides</h2>'
            . '<ul class="fg-related__list">' . implode('', $items) . '</ul>'
            . '</nav>';
    }

    private static function anchor(string $href, string $label, bool $external = false): string
    {
        $attrs = $external
            ? ' class="fg-link fg-link--external" rel="noopener noreferrer" target="_blank"'
            : ' class="fg-link fg-link--internal"';

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $attrs . '>' . $label . '</a>';
    }

  /**
     * @param list<array{href:string,label:string}> $links
     */
    private static function externalBlock(array $links): string
    {
        if ($links === []) {
            return '';
        }

        $items = [];
        foreach ($links as $link) {
            $items[] = '<li>' . self::anchor($link['href'], $link['label'], true) . '</li>';
        }

        return '<aside class="fg-sources" aria-label="Official sources">'
            . '<h2 class="fg-sources__title">Official sources</h2>'
            . '<ul class="fg-sources__list">' . implode('', $items) . '</ul>'
            . '</aside>';
    }

    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '' || !str_contains($subject, $search)) {
            return $subject;
        }

        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }

        return substr_replace($subject, $replace, $pos, strlen($search));
    }
}
