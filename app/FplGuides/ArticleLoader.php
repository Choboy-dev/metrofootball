<?php

declare(strict_types=1);

namespace App\FplGuides;

/**
 * Loads guide article HTML and estimates read time from real word count.
 */
final class ArticleLoader
{
    public static function html(string $slug): string
    {
        $path = __DIR__ . '/articles/' . $slug . '.html';
        if (!is_file($path)) {
            return '';
        }

        $html = trim((string) file_get_contents($path));

        return self::versionGuideImages($html);
    }

    private static function versionGuideImages(string $html): string
    {
        $version = '20260902';

        return preg_replace(
            '#(src="/images/(?:guides|hero)/[^"?]+)(?=")#',
            '$1?v=' . $version,
            $html
        ) ?? $html;
    }

    public static function readMins(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_match_all('/[\p{L}\']+/u', $text, $matches) ? count($matches[0]) : 0;

        return max(4, (int) ceil($words / 210));
    }
}
