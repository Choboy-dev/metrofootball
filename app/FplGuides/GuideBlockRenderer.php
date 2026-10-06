<?php

declare(strict_types=1);

namespace App\FplGuides;

/**
 * Converts editor block JSON to public guide HTML.
 */
final class GuideBlockRenderer
{
    /**
     * @param list<array<string, mixed>> $blocks
     */
    public static function toHtml(array $blocks): string
    {
        $normalized = self::attachTrailingCaptions($blocks);
        $parts = [];
        foreach ($normalized as $block) {
            if (!is_array($block)) {
                continue;
            }
            $html = self::renderBlock($block);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        return self::promoteInlineImageCaptions(trim(implode("\n\n", $parts)));
    }

    /**
     * Turn inline editor HTML like <p><img>Caption text</p> into real figures.
     * Published posts store body_html, so this must run at display time too.
     */
    public static function promoteInlineImageCaptions(string $html): string
    {
        $html = trim($html);
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        // <p><img ...>Caption text</p>  (caption as text sibling of the image)
        $html = preg_replace_callback(
            '/<p(?:\s[^>]*)?>\s*(<img\b[^>]*>)\s*([^<]+?)\s*<\/p>/iu',
            static function (array $m): string {
                return self::figureFromImgTag($m[1], $m[2]);
            },
            $html
        ) ?? $html;

        // <p><img ...></p><p>Short caption</p>
        $html = preg_replace_callback(
            '/<p(?:\s[^>]*)?>\s*(<img\b[^>]*>)\s*<\/p>\s*<p(?:\s[^>]*)?>\s*([^<]{1,220}?)\s*<\/p>/iu',
            static function (array $m): string {
                $caption = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($caption === '' || strlen($caption) > 180) {
                    return $m[0];
                }

                return self::figureFromImgTag($m[1], $caption);
            },
            $html
        ) ?? $html;

        // <p><img ...></p> with no caption
        $html = preg_replace_callback(
            '/<p(?:\s[^>]*)?>\s*(<img\b[^>]*>)\s*<\/p>/iu',
            static function (array $m): string {
                return self::figureFromImgTag($m[1], '');
            },
            $html
        ) ?? $html;

        // Upgrade older figures that still have a bare <img> (no media wrapper)
        $html = preg_replace_callback(
            '/<figure class="(fg-figure(?:--[a-z0-9-]+)*)">\s*(<img\b[^>]*>)\s*(?:<figcaption\b[^>]*>(.*?)<\/figcaption>)?\s*<\/figure>/isu',
            static function (array $m): string {
                $class = trim($m[1]);
                $img = $m[2];
                $captionInner = isset($m[3])
                    ? trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : '';

                return self::figureFromImgTag($img, $captionInner, $class);
            },
            $html
        ) ?? $html;

        return $html;
    }

    private static function figureFromImgTag(string $imgTag, string $caption, string $figureClass = 'fg-figure'): string
    {
        $caption = trim(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $caption = preg_replace('/\s+/u', ' ', $caption) ?? $caption;
        $figureClass = trim($figureClass) !== '' ? trim($figureClass) : 'fg-figure';

        $html = '<figure class="' . htmlspecialchars($figureClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"><div class="fg-figure__media">' . $imgTag . '</div>';
        if ($caption !== '') {
            $html .= '<figcaption class="fg-caption"><span class="fg-caption__line">'
                . htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span></figcaption>';
        }
        $html .= '</figure>';

        return $html;
    }

    /**
     * Pull short paragraphs that sit under an image/video into the caption field
     * so they render as captions instead of body copy.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private static function attachTrailingCaptions(array $blocks): array
    {
        $out = [];
        $count = count($blocks);

        for ($i = 0; $i < $count; $i++) {
            $block = $blocks[$i];
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if ($type !== 'image' && $type !== 'youtube') {
                $out[] = $block;
                continue;
            }

            $captionLines = [];
            $existing = trim((string) ($block['caption'] ?? ''));
            if ($existing !== '') {
                $captionLines[] = $existing;
            }

            $absorbed = 0;
            while ($i + 1 < $count && $absorbed < 2) {
                $next = $blocks[$i + 1];
                if (!is_array($next) || (string) ($next['type'] ?? '') !== 'paragraph') {
                    break;
                }
                $text = trim((string) ($next['text'] ?? ''));
                if ($text === '' || !self::isCaptionLikeParagraph($text)) {
                    break;
                }
                $captionLines[] = strip_tags($text);
                $i++;
                $absorbed++;
            }

            if ($captionLines !== []) {
                $block['caption'] = implode("\n", $captionLines);
            }
            $out[] = $block;
        }

        return $out;
    }

    private static function isCaptionLikeParagraph(string $text): bool
    {
        if (preg_match('/<(ul|ol|h[1-6]|blockquote|table)\b/i', $text) === 1) {
            return false;
        }

        $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            return false;
        }

        // Captions stay short; longer copy stays as article body.
        return mb_strlen($plain) <= 180;
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');

        return match ($type) {
            'heading' => self::renderHeading($block),
            'quote' => self::renderQuote($block),
            'image' => self::renderImage($block),
            'youtube' => self::renderYoutube($block),
            'list' => self::renderList($block),
            'table' => self::renderTable($block),
            'callout' => self::renderCallout($block),
            'separator' => '<hr class="fg-separator">',
            default => self::renderParagraph($block),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderTable(array $block): string
    {
        $rows = $block['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $normalized = [];
        $colCount = 1;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = (string) $cell;
            }
            if ($cells === []) {
                continue;
            }
            $colCount = max($colCount, count($cells));
            $normalized[] = $cells;
        }
        if ($normalized === []) {
            return '';
        }

        foreach ($normalized as &$row) {
            while (count($row) < $colCount) {
                $row[] = '';
            }
            if (count($row) > $colCount) {
                $row = array_slice($row, 0, $colCount);
            }
        }
        unset($row);

        $header = ($block['header'] ?? true) !== false;
        $sticky = max(0, min(6, (int) ($block['stickyCols'] ?? 0)));
        if ($sticky >= $colCount) {
            $sticky = max(0, $colCount - 1);
        }

        $colWidths = self::normalizeTableSizes($block['colWidths'] ?? null, $colCount, 48, 640);
        $rowHeights = self::normalizeTableSizes($block['rowHeights'] ?? null, count($normalized), 28, 480);

        return self::renderTableMarkup($normalized, $header, $sticky, $colWidths, $rowHeights);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function normalizeTableSizes($raw, int $count, int $min, int $max): array
    {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $value) {
                $n = (int) round((float) $value);
                if ($n > 0) {
                    $n = max($min, min($max, $n));
                } else {
                    $n = 0;
                }
                $out[] = $n;
            }
        }
        while (count($out) < $count) {
            $out[] = 0;
        }

        return array_slice($out, 0, $count);
    }

    /**
     * @param list<list<string>> $rows
     * @param list<int> $colWidths
     * @param list<int> $rowHeights
     */
    private static function renderTableMarkup(
        array $rows,
        bool $header,
        int $sticky,
        array $colWidths = [],
        array $rowHeights = []
    ): string {
        $wrapClass = 'fg-table-wrap';
        $tableClass = 'fg-table';
        $hasSizedCols = false;
        foreach ($colWidths as $w) {
            if ((int) $w > 0) {
                $hasSizedCols = true;
                break;
            }
        }
        $hasSizedRows = false;
        foreach ($rowHeights as $h) {
            if ((int) $h > 0) {
                $hasSizedRows = true;
                break;
            }
        }
        if ($sticky > 0) {
            $wrapClass .= ' fg-table-wrap--sticky';
            $tableClass .= ' fg-table--sticky-' . $sticky;
        }
        if ($hasSizedCols || $hasSizedRows) {
            $tableClass .= ' fg-table--sized';
        }

        $html = '<div class="' . $wrapClass . '"'
            . ($sticky > 0 ? ' data-sticky-cols="' . $sticky . '"' : '')
            . '><table class="' . $tableClass . '">';

        if ($hasSizedCols) {
            $html .= '<colgroup>';
            foreach ($colWidths as $w) {
                $px = (int) $w > 0 ? (int) $w : 128;
                $html .= '<col style="width:' . $px . 'px">';
            }
            $html .= '</colgroup>';
        }

        $defaultCol = 128;
        $stickyLeft = static function (int $c) use ($colWidths, $defaultCol): int {
            $left = 0;
            for ($i = 0; $i < $c; $i++) {
                $w = (int) ($colWidths[$i] ?? 0);
                $left += $w > 0 ? $w : $defaultCol;
            }

            return $left;
        };

        $start = 0;
        if ($header && isset($rows[0])) {
            $html .= '<thead><tr';
            $rowH = (int) ($rowHeights[0] ?? 0);
            if ($rowH > 0) {
                $html .= ' style="height:' . $rowH . 'px"';
            }
            $html .= '>';
            foreach ($rows[0] as $c => $cell) {
                $styles = [];
                $classes = [];
                if ($sticky > 0 && $c < $sticky) {
                    $classes[] = 'fg-table__sticky';
                    $styles[] = 'left:' . $stickyLeft((int) $c) . 'px';
                }
                $w = (int) ($colWidths[$c] ?? 0);
                if ($w > 0) {
                    $styles[] = 'width:' . $w . 'px';
                    $styles[] = 'min-width:' . $w . 'px';
                    $styles[] = 'max-width:' . $w . 'px';
                }
                if ($rowH > 0) {
                    $styles[] = 'height:' . $rowH . 'px';
                }
                $attrs = '';
                if ($classes !== []) {
                    $attrs .= ' class="' . implode(' ', $classes) . '"';
                }
                if ($styles !== []) {
                    $attrs .= ' style="' . implode(';', $styles) . '"';
                }
                $inner = trim(self::richTextHtml((string) $cell, false));
                if ($inner === '') {
                    $inner = '&nbsp;';
                }
                $html .= '<th' . $attrs . '>' . $inner . '</th>';
            }
            $html .= '</tr></thead>';
            $start = 1;
        }

        if (isset($rows[$start])) {
            $html .= '<tbody>';
            for ($r = $start, $n = count($rows); $r < $n; $r++) {
                $html .= '<tr';
                $rowH = (int) ($rowHeights[$r] ?? 0);
                if ($rowH > 0) {
                    $html .= ' style="height:' . $rowH . 'px"';
                }
                $html .= '>';
                foreach ($rows[$r] as $c => $cell) {
                    $styles = [];
                    $classes = [];
                    if ($sticky > 0 && $c < $sticky) {
                        $classes[] = 'fg-table__sticky';
                        $styles[] = 'left:' . $stickyLeft((int) $c) . 'px';
                    }
                    $w = (int) ($colWidths[$c] ?? 0);
                    if ($w > 0) {
                        $styles[] = 'width:' . $w . 'px';
                        $styles[] = 'min-width:' . $w . 'px';
                        $styles[] = 'max-width:' . $w . 'px';
                    }
                    if ($rowH > 0) {
                        $styles[] = 'height:' . $rowH . 'px';
                    }
                    $attrs = '';
                    if ($classes !== []) {
                        $attrs .= ' class="' . implode(' ', $classes) . '"';
                    }
                    if ($styles !== []) {
                        $attrs .= ' style="' . implode(';', $styles) . '"';
                    }
                    $inner = trim(self::richTextHtml((string) $cell, false));
                    if ($inner === '') {
                        $inner = '&nbsp;';
                    }
                    $html .= '<td' . $attrs . '>' . $inner . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';
        }

        $html .= '</table></div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderParagraph(array $block): string
    {
        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        // Lists and tables are block-level HTML and must not be wrapped in <p>.
        if (preg_match('/<(ul|ol|table)\b/i', $text) === 1) {
            return self::richTextHtml($text, true);
        }

        return '<p>' . self::richTextHtml($text, false) . '</p>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderHeading(array $block): string
    {
        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        $level = (int) ($block['level'] ?? 2);
        $level = max(1, min(6, $level));
        $tag = 'h' . $level;

        return '<' . $tag . '>' . self::escape($text) . '</' . $tag . '>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderQuote(array $block): string
    {
        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        $cite = trim((string) ($block['cite'] ?? ''));
        $citeHtml = $cite !== '' ? '<cite>' . self::escape($cite) . '</cite>' : '';

        return '<blockquote class="fg-quote"><p>' . self::richTextHtml($text, false) . '</p>' . $citeHtml . '</blockquote>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderImage(array $block): string
    {
        $url = trim((string) ($block['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        $alt = self::escape((string) ($block['alt'] ?? ''));
        $caption = trim((string) ($block['caption'] ?? ''));
        $safeUrl = self::escape($url);
        $captionHtml = $caption !== ''
            ? '<figcaption class="fg-caption">' . self::captionHtml($caption) . '</figcaption>'
            : '';

        return '<figure class="fg-figure">'
            . '<div class="fg-figure__media">'
            . '<img src="' . $safeUrl . '" alt="' . $alt . '" loading="lazy" decoding="async">'
            . '</div>'
            . $captionHtml
            . '</figure>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderYoutube(array $block): string
    {
        $videoId = trim((string) ($block['videoId'] ?? ''));
        if ($videoId === '') {
            $videoId = self::youtubeIdFromUrl((string) ($block['url'] ?? ''));
        }
        if ($videoId === '') {
            return '';
        }

        $caption = trim((string) ($block['caption'] ?? ''));
        $captionHtml = $caption !== ''
            ? '<figcaption class="fg-caption">' . self::captionHtml($caption) . '</figcaption>'
            : '';
        $embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);

        return '<figure class="fg-figure fg-figure--video">'
            . '<div class="fg-figure__media">'
            . '<div class="fg-video"><iframe src="' . self::escape($embed) . '" title="YouTube video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>'
            . '</div>'
            . $captionHtml
            . '</figure>';
    }

    private static function captionHtml(string $caption): string
    {
        $lines = preg_split("/\R/u", $caption) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts[] = '<span class="fg-caption__line">' . self::escape($line) . '</span>';
        }

        return $parts !== [] ? implode('', $parts) : self::escape($caption);
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderList(array $block): string
    {
        $items = $block['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return '';
        }

        $ordered = (($block['style'] ?? 'ul') === 'ol');
        $tag = $ordered ? 'ol' : 'ul';
        $lis = [];
        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $lis[] = '<li>' . self::richTextHtml($text, false) . '</li>';
        }
        if ($lis === []) {
            return '';
        }

        return '<' . $tag . '>' . implode('', $lis) . '</' . $tag . '>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderCallout(array $block): string
    {
        $title = trim((string) ($block['title'] ?? ''));
        $text = trim((string) ($block['text'] ?? ''));
        if ($title === '' && $text === '') {
            return '';
        }

        $titleHtml = $title !== ''
            ? '<p class="fg-callout__title">' . self::escape($title) . '</p>'
            : '';
        $textHtml = $text !== ''
            ? '<p>' . self::richTextHtml($text, false) . '</p>'
            : '';

        return '<aside class="fg-callout">' . $titleHtml . $textHtml . '</aside>';
    }

    public static function featuredFigure(string $url, string $alt = ''): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return '<figure class="fg-figure fg-figure--featured">'
            . '<div class="fg-figure__media">'
            . '<img src="' . self::escape($url) . '" width="1200" height="600" loading="eager" fetchpriority="high" decoding="async" alt="' . self::escape($alt) . '">'
            . '</div>'
            . '</figure>';
    }

    private static function youtubeIdFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{6,})/', $url, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private static function inlineHtml(string $text): string
    {
        return self::richTextHtml($text, false);
    }

    private static function richTextHtml(string $text, bool $allowLists): string
    {
        $allowed = '<strong><em><a><br><img><u><s>';
        if ($allowLists) {
            $allowed .= '<ul><ol><li><table><thead><tbody><tfoot><tr><th><td><caption>';
        }
        $clean = strip_tags($text, $allowed);

        $clean = preg_replace_callback(
            '/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i',
            static function (array $m): string {
                $src = self::sanitizeHref($m[1]);
                if ($src === '') {
                    return '';
                }
                $alt = '';
                if (preg_match('/\balt=["\']([^"\']*)["\']/i', $m[0], $altMatch) === 1) {
                    $alt = self::escape($altMatch[1]);
                }

                return '<img src="' . self::escape($src) . '" alt="' . $alt . '" loading="lazy" decoding="async">';
            },
            $clean
        ) ?? $clean;

        $clean = preg_replace_callback(
            '/<a\s+href="([^"]+)"[^>]*>/i',
            static function (array $m): string {
                $href = self::sanitizeHref($m[1]);
                if ($href === '') {
                    return '';
                }

                return '<a href="' . self::escape($href) . '" class="fg-link fg-link--internal">';
            },
            $clean
        ) ?? $clean;

        if ($allowLists && preg_match('/<table\b/i', $clean) === 1) {
            $clean = self::normalizeGuideTables($clean);
        }

        return $clean;
    }

    private static function normalizeGuideTables(string $html): string
    {
        return preg_replace_callback(
            '/<table\b[^>]*>.*?<\/table>/is',
            static function (array $m): string {
                $table = $m[0];
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $table, $classMatch) === 1) {
                    $classes = trim((string) $classMatch[2]);
                    if (!preg_match('/\bfg-table\b/', $classes)) {
                        $classes = trim($classes . ' fg-table');
                    }
                    $table = preg_replace(
                        '/\bclass\s*=\s*(["\']).*?\1/i',
                        'class="' . self::escape($classes) . '"',
                        $table,
                        1
                    ) ?? $table;
                } else {
                    $table = preg_replace('/<table\b/i', '<table class="fg-table"', $table, 1) ?? $table;
                }

                return '<div class="fg-table-wrap">' . $table . '</div>';
            },
            $html
        ) ?? $html;
    }

    private static function sanitizeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, '/')) {
            return $href;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        return '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
