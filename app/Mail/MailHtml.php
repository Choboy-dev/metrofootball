<?php

declare(strict_types=1);

namespace App\Mail;

final class MailHtml
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'div', 'span', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 'a',
        'ul', 'ol', 'li', 'blockquote', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'tr', 'td', 'th', 'tbody', 'thead',
    ];

    /** @var list<string> */
    private const ALLOWED_STYLES = [
        'color', 'background-color', 'background', 'font-family', 'font-size', 'font-weight',
        'font-style', 'text-align', 'text-decoration', 'line-height', 'margin', 'margin-top',
        'margin-right', 'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right',
        'padding-bottom', 'padding-left', 'border', 'border-top', 'border-right', 'border-bottom',
        'border-left', 'border-color', 'border-width', 'border-style', 'width', 'height',
        'max-width', 'display', 'vertical-align', 'list-style-type',
    ];

    /** @var list<string> */
    private const FORBIDDEN_TAGS = [
        'script', 'iframe', 'form', 'input', 'textarea', 'button', 'select', 'option',
        'object', 'embed', 'link', 'meta', 'base', 'style', 'svg', 'math',
    ];

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = self::stripEventHandlers($html);
        $html = self::removeBlockedBlocks($html);
        $html = self::removeForbiddenTags($html);
        $html = self::stripCssLikeText($html);
        $html = self::stripLeadingNonMarkup($html);
        $html = self::stripCssLikeText($html);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="mail-root">' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('mail-root');
        if (!$root instanceof \DOMElement) {
            return '';
        }

        self::sanitizeNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    public static function plainText(?string $html): string
    {
        $text = strip_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function sanitizeNode(\DOMNode $node): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = (string) ($node->textContent ?? '');
            if (self::looksLikeCss($text)) {
                $node->parentNode?->removeChild($node);
            }
            return;
        }

        if ($node->nodeType === XML_ELEMENT_NODE) {
            /** @var \DOMElement $node */
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                self::unwrapNode($node);
                return;
            }

            self::sanitizeAttributes($node, $tag);

            if ($tag === 'a') {
                $href = trim((string) $node->getAttribute('href'));
                if ($href === '' || self::isUnsafeUrl($href)) {
                    $node->removeAttribute('href');
                } else {
                    $node->setAttribute('href', $href);
                    $node->setAttribute('rel', 'noopener noreferrer nofollow');
                    if (!str_starts_with(strtolower($href), 'mailto:')) {
                        $node->setAttribute('target', '_blank');
                    }
                }
            }

            if ($tag === 'img') {
                $src = trim((string) $node->getAttribute('src'));
                if ($src === '' || self::isUnsafeImageSrc($src)) {
                    $node->parentNode?->removeChild($node);
                    return;
                }
                $node->setAttribute('src', $src);
                if (!$node->hasAttribute('alt')) {
                    $node->setAttribute('alt', '');
                }
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            self::sanitizeNode($child);
        }

        if ($node->nodeType === XML_ELEMENT_NODE && $node instanceof \DOMElement) {
            self::pruneEmptyElement($node);
        }
    }

    private static function pruneEmptyElement(\DOMElement $node): void
    {
        if ($node->hasChildNodes()) {
            return;
        }

        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        $parent->removeChild($node);
    }

    private static function looksLikeCss(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || strlen($text) < 40) {
            return false;
        }

        if (stripos($text, '@media') !== false) {
            return true;
        }

        if (str_contains($text, '!important') && str_contains($text, '{') && str_contains($text, '}')) {
            return true;
        }

        if (preg_match('/\*?\[class\][^{]*\{/i', $text) === 1) {
            return true;
        }

        return preg_match('/(?:^|[\s;}])(?:[\.\#][\w-]+|\*[a-z\[])[^{]*\{[^}]*\}/i', $text) === 1
            && substr_count($text, '{') >= 2;
    }

    private static function stripCssLikeText(string $html): string
    {
        while (($pos = stripos($html, '@media')) !== false) {
            $html = self::removeCssBlockAt($html, $pos);
        }

        $html = (string) preg_replace('/\*\[class\][^{]*\{[^{}]*\}/is', '', $html);
        $html = (string) preg_replace('/(?:^|[>\s])([\.\#][\w-]+|\*[a-z\[])[^{]*\{[^{}]*\}/ims', ' ', $html);

        return $html;
    }

    private static function removeCssBlockAt(string $html, int $start): string
    {
        $len = strlen($html);
        $depth = 0;
        $started = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $html[$i];
            if ($ch === '{') {
                $depth++;
                $started = true;
            } elseif ($ch === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    return substr($html, 0, $start) . substr($html, $i + 1);
                }
            }
        }

        return substr($html, 0, $start);
    }

    private static function sanitizeAttributes(\DOMElement $node, string $tag): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'style', 'title'],
            'img' => ['src', 'alt', 'style', 'width', 'height'],
            default => ['style', 'class'],
        };

        $toRemove = [];
        foreach ($node->attributes ?? [] as $attr) {
            $name = strtolower($attr->name);
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $toRemove[] = $name;
            }
        }
        foreach ($toRemove as $name) {
            $node->removeAttribute($name);
        }

        if ($node->hasAttribute('style')) {
            $node->setAttribute('style', self::sanitizeStyle((string) $node->getAttribute('style')));
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $parts = [];
        foreach (explode(';', $style) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $chunk, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, self::ALLOWED_STYLES, true)) {
                continue;
            }
            if (self::containsUnsafeCss($value)) {
                continue;
            }
            $parts[] = $prop . ': ' . $value;
        }

        return implode('; ', $parts);
    }

    private static function containsUnsafeCss(string $value): bool
    {
        $lower = strtolower($value);
        return str_contains($lower, 'expression(')
            || str_contains($lower, 'javascript:')
            || str_contains($lower, 'vbscript:')
            || str_contains($lower, 'url(');
    }

    private static function isUnsafeUrl(string $url): bool
    {
        $lower = strtolower(trim($url));
        return str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'vbscript:')
            || str_starts_with($lower, 'data:text/html');
    }

    private static function isUnsafeImageSrc(string $src): bool
    {
        $lower = strtolower(trim($src));
        if (str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://')) {
            return false;
        }
        if (str_starts_with($lower, 'data:image/')) {
            return false;
        }
        if (str_starts_with($lower, 'cid:')) {
            return false;
        }
        return true;
    }

    private static function stripEventHandlers(string $html): string
    {
        return (string) preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    }

    private static function removeBlockedBlocks(string $html): string
    {
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);
        $html = (string) preg_replace('#<\s*(script|style|noscript)\b[^>]*>.*?</\s*\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<\s*style\b[^>]*>.*$#is', '', $html);
        $html = (string) preg_replace('#<\s*head\b[^>]*>.*?</\s*head\s*>#is', '', $html);

        return $html;
    }

    /** Drop CSS/text junk left before the first HTML tag (common in marketing mail). */
    private static function stripLeadingNonMarkup(string $html): string
    {
        if (preg_match('/<[a-z!/]/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $match[0][1];
            if ($pos > 0) {
                $html = substr($html, $pos);
            }
        }

        return $html;
    }

    private static function removeForbiddenTags(string $html): string
    {
        foreach (self::FORBIDDEN_TAGS as $tag) {
            $html = (string) preg_replace('#<\s*/?\s*' . preg_quote($tag, '#') . '[^>]*>#i', '', $html);
        }
        return $html;
    }

    private static function unwrapNode(\DOMElement $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}
