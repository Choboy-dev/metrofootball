<?php

declare(strict_types=1);

namespace App\FplGuides;

/**
 * Builds a table of contents and injects heading ids for guide articles.
 */
final class ArticleToc
{
    /**
     * @return array{html:string,toc:string,headings:list<array{level:int,id:string,text:string}>}
     */
    public static function enrich(string $html, string $pagePath = ''): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['html' => '', 'toc' => '', 'headings' => []];
        }

        $headings = [];
        $usedIds = [];

        $enriched = preg_replace_callback(
            '/<h([23])(\s[^>]*)?>(.*?)<\/h\1>/is',
            static function (array $matches) use (&$headings, &$usedIds): string {
                $level = (int) $matches[1];
                $attrs = $matches[2] ?? '';
                $inner = $matches[3];
                $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    return $matches[0];
                }

                if (preg_match('/\bid=(["\'])([^"\']+)\1/i', $attrs, $idMatch)) {
                    $id = $idMatch[2];
                } else {
                    $id = self::uniqueId($text, $usedIds);
                    $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
                }

                $usedIds[$id] = true;
                $headings[] = [
                    'level' => $level,
                    'id' => $id,
                    'text' => $text,
                ];

                return '<h' . $level . $attrs . '>' . $inner . '</h' . $level . '>';
            },
            $html
        );

        if (!is_string($enriched)) {
            $enriched = $html;
        }

        return [
            'html' => $enriched,
            'toc' => self::render($headings, $pagePath),
            'headings' => $headings,
        ];
    }

    /**
     * @param array<string, true> $usedIds
     */
    private static function uniqueId(string $text, array &$usedIds): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
        if ($base === '') {
            $base = 'section';
        }

        $candidate = $base;
        $suffix = 2;
        while (isset($usedIds[$candidate])) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param list<array{level:int,id:string,text:string}> $headings
     */
    private static function render(array $headings, string $pagePath = ''): string
    {
        if (count($headings) < 2) {
            return '';
        }

        $path = trim($pagePath);
        if ($path !== '' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $path = rtrim($path, '/');

        $parts = ['<nav class="fg-toc" aria-label="Table of contents" data-same-tab-root>'];
        $parts[] = '<h2 class="fg-toc__title">On this page</h2>';
        $parts[] = '<ol class="fg-toc__list">';

        $i = 0;
        $count = count($headings);

        while ($i < $count) {
            $heading = $headings[$i];
            if ($heading['level'] !== 2) {
                $i++;
                continue;
            }

            $label = htmlspecialchars($heading['text'], ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($path . '#' . $heading['id'], ENT_QUOTES, 'UTF-8');
            $parts[] = '<li class="fg-toc__item">';
            $parts[] = '<a class="fg-toc__link" href="' . $href . '">' . $label . '</a>';
            $parts[] = '</li>';
            $i++;
        }

        $parts[] = '</ol></nav>';

        return implode('', $parts);
    }
}
