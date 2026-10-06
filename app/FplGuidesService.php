<?php

declare(strict_types=1);

namespace App;

use App\FplGuides\ArticleLinker;
use App\FplGuides\ArticleLoader;
use App\FplGuides\ArticleToc;
use App\FplGuides\GuideBlockRenderer;

/**
 * Evergreen FPL education articles for SEO and onboarding.
 */
final class FplGuidesService
{
/**
     * Guides attributed to the default author (no live FPL API cards).
     *
     * @return list<array{slug:string,title:string,description:string,category:string,read_mins:int,featured_image:string,href:string,source:string}>
     */
    public static function listAuthorGuides(): array
    {
        $out = [];
        foreach (self::guides() as $slug => $guide) {
            $html = ArticleLoader::html($slug);
            $out[] = [
                'slug' => $slug,
                'title' => (string) $guide['title'],
                'description' => (string) $guide['description'],
                'category' => (string) $guide['category'],
                'read_mins' => ArticleLoader::readMins($html),
                'featured_image' => self::staticFeaturedImage($slug),
                'href' => '/fpl-guides/' . $slug,
                'source' => 'static',
            ];
        }

        foreach (GuidePostService::listPublished() as $post) {
            $out[] = [
                'slug' => (string) $post['slug'],
                'title' => (string) $post['title'],
                'description' => (string) $post['description'],
                'category' => (string) $post['category'],
                'read_mins' => (int) $post['read_mins'],
                'featured_image' => trim((string) ($post['featured_image'] ?? '')),
                'href' => '/fpl-guides/' . (string) $post['slug'],
                'source' => 'database',
            ];
        }

        return $out;
    }

    /**
     * @return list<array{slug:string,title:string,description:string,category:string,read_mins:int,featured_image:string,source:string}>
     */
    public static function listGuides(): array
    {
        $out = self::listAuthorGuides();

        foreach (GameweekTipsService::listGuideCards() as $card) {
            $out[] = $card;
        }

        foreach (GameweekRecapService::listGuideCards() as $card) {
            $out[] = $card;
        }

        return $out;
    }

    /**
     * Previous / next article guides for the single-guide template (static + CMS posts).
     *
     * @return array{prev: ?array<string, mixed>, next: ?array<string, mixed>, index: int, total: int}
     */
    public static function adjacentAuthorGuides(string $slug): array
    {
        $guides = self::listAuthorGuides();
        $total = count($guides);
        $index = -1;
        foreach ($guides as $i => $guide) {
            if ((string) ($guide['slug'] ?? '') === $slug) {
                $index = $i;
                break;
            }
        }

        if ($index < 0) {
            return ['prev' => null, 'next' => null, 'index' => -1, 'total' => $total];
        }

        return [
            'prev' => $index > 0 ? $guides[$index - 1] : null,
            'next' => $index < ($total - 1) ? $guides[$index + 1] : null,
            'index' => $index,
            'total' => $total,
        ];
    }

    /**
     * All guides for admin list: static file guides plus database posts.
     *
     * @return list<array<string, mixed>>
     */
    public static function adminListAll(): array
    {
        $out = [];

        foreach (GuidePostService::adminList() as $post) {
            $post['source'] = 'database';
            $post['editable'] = true;
            $out[] = $post;
        }

        usort($out, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['updated_at'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['updated_at'] ?? '')) ?: 0;
            if ($aTime !== $bTime) {
                return $bTime <=> $aTime;
            }

            return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $out;
    }

    /**
     * @return list<array{loc:string,lastmod:string}>
     */
    public static function sitemapEntries(): array
    {
        $entries = [];
        foreach (GuidePostService::listPublished() as $post) {
            $slug = trim((string) ($post['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $updated = (string) ($post['updated_at'] ?? $post['published_at'] ?? '');
            $lastmod = $updated !== '' ? date('Y-m-d', strtotime($updated) ?: time()) : date('Y-m-d');
            $entries[] = [
                'loc' => 'https://metrofootball.co.uk/fpl-guides/' . $slug,
                'lastmod' => $lastmod,
            ];
        }

        foreach (GameweekTipsService::listPublishedPaths() as $row) {
            $gw = (int) ($row['gw'] ?? 0);
            if ($gw < 1) {
                continue;
            }
            $entries[] = [
                'loc' => 'https://metrofootball.co.uk/fpl-gameweek-' . $gw . '-tips',
                'lastmod' => date('Y-m-d'),
            ];
        }

        foreach (GameweekRecapService::listFinishedPaths() as $row) {
            $gw = (int) ($row['gw'] ?? 0);
            if ($gw < 1) {
                continue;
            }
            $entries[] = [
                'loc' => 'https://metrofootball.co.uk/gameweek-recap?gw=' . $gw,
                'lastmod' => date('Y-m-d'),
            ];
        }

        return $entries;
    }

    public static function staticSlugExists(string $slug): bool
    {
        return isset(self::guides()[$slug]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getGuide(string $slug): ?array
    {
        $dbPost = GuidePostService::getPublishedBySlug($slug);
        if ($dbPost !== null) {
            return self::buildGuideFromPost($dbPost);
        }

        $guides = self::guides();
        if (!isset($guides[$slug])) {
            return null;
        }

        $guide = $guides[$slug];
        $html = ArticleLoader::html($slug);
        if ($html === '') {
            return null;
        }

        $enriched = ArticleToc::enrich($html, '/fpl-guides/' . $slug);
        $linked = ArticleLinker::apply($slug, $enriched['html']);

        return [
            'slug' => $slug,
            'title' => (string) $guide['title'],
            'description' => (string) $guide['description'],
            'category' => (string) $guide['category'],
            'read_mins' => ArticleLoader::readMins($html),
            'html' => $linked,
            'toc' => $enriched['toc'],
            'headings' => $enriched['headings'],
            'tool_links' => is_array($guide['tool_links'] ?? null) ? $guide['tool_links'] : [],
            'schema_type' => 'Article',
            'featured_image' => self::staticFeaturedImage($slug),
            'source' => 'static',
        ];
    }

    public static function staticFeaturedImage(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?? '';
        if ($slug === '') {
            return '';
        }

        $relative = '/images/guides/featured/' . $slug . '.webp';
        $full = dirname(__DIR__) . '/public' . $relative;

        return is_file($full) ? $relative : '';
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function buildGuideFromPost(array $post): array
    {
        $html = (string) ($post['body_html'] ?? '');
        $html = GuideBlockRenderer::promoteInlineImageCaptions($html);
        $featured = trim((string) ($post['featured_image'] ?? ''));
        if ($featured !== '') {
            $figure = GuideBlockRenderer::featuredFigure($featured, (string) $post['title']);
            $html = $figure . "\n\n" . $html;
        }

        $slug = (string) $post['slug'];
        $enriched = ArticleToc::enrich($html, '/fpl-guides/' . $slug);

        return [
            'slug' => $slug,
            'title' => (string) $post['title'],
            'description' => (string) $post['description'],
            'category' => (string) $post['category'],
            'read_mins' => (int) ($post['read_mins'] ?? ArticleLoader::readMins($html)),
            'html' => $enriched['html'],
            'toc' => $enriched['toc'],
            'headings' => $enriched['headings'],
            'tool_links' => [],
            'schema_type' => (string) ($post['schema_type'] ?? 'Article'),
            'featured_image' => $featured,
            'source' => 'database',
        ];
    }

    /**
     * @return array{
     *   gw_label: string,
     *   gw_short: string,
     *   title: string,
     *   lead: string,
     *   bullets: list<string>,
     *   tiles: list<array{title:string,desc:string,href:string,icon:string}>,
     *   cta_label: string,
     *   cta_href: string
     * }
     */
    public static function publicWeeklyTeaser(): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $events = is_array($bootstrap['events'] ?? null) ? $bootstrap['events'] : [];
        $gwName = 'This gameweek';
        $gwShort = 'GW';
        foreach ($events as $event) {
            if (is_array($event) && !empty($event['is_next'])) {
                $gwName = (string) ($event['name'] ?? $gwName);
                $gwShort = preg_match('/\d+/', $gwName, $match) === 1
                    ? 'GW' . $match[0]
                    : 'GW';
                break;
            }
        }

        $injuryDesc = 'Check who is out before you lock your team.';
        try {
            $live = InjuryNewsService::getLive();
            $post = is_array($live['post'] ?? null) ? $live['post'] : null;
            if ($post !== null && is_string($post['title'] ?? null) && $post['title'] !== '') {
                $injuryDesc = (string) $post['title'];
            }
        } catch (\Throwable) {
            // Optional.
        }

        $tiles = [
            [
                'title' => 'Injury digest',
                'desc' => $injuryDesc,
                'href' => '/fpl-injury-news',
                'icon' => 'injury',
            ],
            [
                'title' => 'Gameweek tips',
                'desc' => 'Template players and captain ideas for this round.',
                'href' => '/fpl-gameweek-tips',
                'icon' => 'tips',
            ],
            [
                'title' => 'AI deadline brief',
                'desc' => 'Personal transfer and captain picks on AI Tips.',
                'href' => '/ai-tips',
                'icon' => 'ai',
            ],
        ];

        $bullets = array_map(static fn (array $tile): string => $tile['desc'], $tiles);

        return [
            'gw_label' => $gwName,
            'gw_short' => $gwShort,
            'title' => $gwName . ' briefing',
            'lead' => 'Metro Football pulls injury news, fixture swings, and squad tools into one place before the deadline.',
            'bullets' => $bullets,
            'tiles' => $tiles,
            'cta_label' => 'Open AI Tips',
            'cta_href' => '/ai-tips',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function guides(): array
    {
        return [];
    }
}
