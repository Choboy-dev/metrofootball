<?php

declare(strict_types=1);

/**
 * Server-rendered FPL Gameweek tips page.
 * /fpl-gameweek-tips redirects to the upcoming gameweek; /fpl-gameweek-N-tips renders that week.
 * Markup mirrors public/js/fpl-gameweek-tips.js so the client script can take over without a flash.
 */

require_once __DIR__ . '/api/bootstrap.php';

use App\GameweekTipsService;

$template = (string) file_get_contents(__DIR__ . '/fpl-gameweek-tips.html');
$requestedGw = isset($_GET['gw']) ? (int) $_GET['gw'] : 0;

try {
    $meta = GameweekTipsService::getMeta();
} catch (Throwable) {
    $meta = null;
}

$upcomingGw = (int) ($meta['upcoming_gw'] ?? 0);

if ($requestedGw < 1) {
    if ($upcomingGw > 0) {
        header('Cache-Control: no-store');
        header('Location: /fpl-gameweek-' . $upcomingGw . '-tips', true, 302);
        exit;
    }
    echo $template;
    exit;
}

try {
    $data = GameweekTipsService::getForRequest($requestedGw);
} catch (Throwable) {
    $data = null;
}

$upcomingPath = $upcomingGw > 0 ? '/fpl-gameweek-' . $upcomingGw . '-tips' : '/fpl-gameweek-tips';
$html = str_replace(
    ['href="/fpl-gameweek-tips" data-nav-gw-tips-link', 'data-nav-gw-tips-label>Gameweek Tips<'],
    ['href="' . $upcomingPath . '" data-nav-gw-tips-link', 'data-nav-gw-tips-label>' . ($upcomingGw > 0 ? 'Gameweek ' . $upcomingGw . ' Tips' : 'Gameweek Tips') . '<'],
    $template
);

if (!is_array($data)) {
    echo $html;
    exit;
}

$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$post = is_array($data['post'] ?? null) ? $data['post'] : null;
$gwName = (string) ($data['gameweek_name'] ?? ('Gameweek ' . $requestedGw));
$canonical = 'https://metrofootball.co.uk/fpl-gameweek-' . $requestedGw . '-tips';

$fgtCorrupt = static function ($text): bool {
    $v = trim((string) ($text ?? ''));
    if ($v === '' || strtolower($v) === 'closing') {
        return true;
    }
    if (str_contains($v, '"element_id"') || str_contains($v, 'element_id":')) {
        return true;
    }

    return (bool) preg_match('/^\s*[\[{]/', $v);
};

$fgtPara = static function ($text) use ($h): string {
    $raw = (string) ($text ?? '');
    if (preg_match('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', $raw, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1];
        $before = substr($raw, 0, $start);
        $after = substr($raw, $start + strlen($m[0][0]));
        $after = (string) preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '$1', $after);

        return '<p>' . $h($before)
            . '<a class="fin-inline-link" href="' . $h($m[2][0]) . '" rel="noopener noreferrer" target="_blank">' . $h($m[1][0]) . '</a>'
            . $h($after) . '</p>';
    }

    return '<p>' . $h($raw) . '</p>';
};

$fgtParas = static function ($list) use ($fgtCorrupt, $fgtPara): string {
    $out = '';
    foreach ((array) ($list ?? []) as $p) {
        if (!$fgtCorrupt($p)) {
            $out .= $fgtPara($p);
        }
    }

    return $out;
};

$fgtOwn = static fn ($v): string => is_numeric($v) ? number_format((float) $v, 1) . '%' : '—';
$fgtPrice = static fn ($v): string => is_numeric($v) ? '£' . number_format((float) $v, 1) : '—';

$fgtFacts = static function (array $tip) use ($h): string {
    $icons = [
        'fixture' => ['./images/fin/next-fixture.png', 'Next fixture'],
        'fdr' => ['./images/fin/fixture-difficulty.png', 'Fixture difficulty'],
        'form-good' => ['./images/fin/form.png', 'Good form'],
        'form-mid' => ['./images/fin/form-mid.png', 'Average form'],
        'form-poor' => ['./images/fin/form-poor.png', 'Poor form'],
    ];
    $fdrLabels = [1 => 'Easiest', 2 => 'Easy', 3 => 'Medium', 4 => 'Hard', 5 => 'Hardest'];

    $fixture = trim((string) ($tip['fixture_line'] ?? ''));
    if ($fixture !== '' && !preg_match('/^vs\s/i', $fixture)) {
        $fixture = 'vs ' . $fixture;
    }

    $fdr = null;
    if (is_numeric($tip['fixture_fdr'] ?? null) && (float) $tip['fixture_fdr'] >= 1 && (float) $tip['fixture_fdr'] <= 5) {
        $fdr = (int) round((float) $tip['fixture_fdr']);
    } elseif (preg_match('/(\d)/', (string) ($tip['opponent_rank'] ?? ''), $m)) {
        $fdr = max(1, min(5, (int) $m[1]));
    }

    $formText = trim((string) preg_replace('/^form\s+/i', '', trim((string) ($tip['form_line'] ?? ''))));
    $formScore = is_numeric($tip['stats']['form'] ?? null) ? (float) $tip['stats']['form'] : (is_numeric($formText) ? (float) $formText : null);
    $formIcon = $formScore === null ? 'form-good' : ($formScore < 3 ? 'form-poor' : ($formScore < 6 ? 'form-mid' : 'form-good'));

    $facts = [];
    if ($fixture !== '') {
        $facts[] = ['Next fixture', $h($fixture), 'fixture', ''];
    }
    if ($fdr !== null) {
        $label = $fdrLabels[$fdr] ?? 'Medium';
        $facts[] = ['Fixture difficulty level', '<span class="mf-fdr mf-fdr--' . $fdr . ' mf-fdr--md fin-key-fact__fdr" title="Fixture difficulty ' . $label . '" aria-label="Fixture difficulty ' . $label . '">' . $fdr . '</span>', 'fdr', ' fin-key-fact__value--fdr'];
    }
    if ($formText !== '') {
        $facts[] = ['Form', $h($formText), $formIcon, ' fin-key-fact__value--stat'];
    }
    if ($facts === []) {
        return '';
    }

    $out = '<div class="fin-key-facts" aria-label="Quick facts for ' . $h($tip['web_name'] ?? '') . '">';
    foreach ($facts as [$label, $value, $icon, $valueClass]) {
        $out .= '<article class="fin-key-fact"><span class="fin-key-fact__icon" aria-hidden="true">'
            . '<img class="fin-key-fact__icon-img" src="' . $h($icons[$icon][0]) . '" alt="' . $h($icons[$icon][1]) . '" width="22" height="22" loading="lazy" decoding="async">'
            . '</span><div class="fin-key-fact__copy"><p class="fin-key-fact__label">' . $h($label) . '</p>'
            . '<p class="fin-key-fact__value' . $valueClass . '">' . $value . '</p></div></article>';
    }

    return $out . '</div>';
};

$fgtStats = static function (array $tip) use ($h, $fgtOwn, $fgtPrice): string {
    $s = is_array($tip['stats'] ?? null) ? $tip['stats'] : [];
    $cells = [
        ['Pts', $s['total_points'] ?? null],
        ['G', $s['goals'] ?? null],
        ['A', $s['assists'] ?? null],
        ['xG', $s['xg'] ?? null],
        ['xA', $s['xa'] ?? null],
        ['Form', $s['form'] ?? null],
        ['Owned by', $fgtOwn($s['ownership'] ?? $tip['ownership'] ?? null)],
        ['Price', $fgtPrice($s['price'] ?? $tip['price'] ?? null)],
    ];
    $out = '<dl class="fin-stats" aria-label="Season stats for ' . $h($tip['web_name'] ?? '') . '">';
    foreach ($cells as [$label, $value]) {
        $out .= '<div class="fin-stats__item"><dt>' . $h($label) . '</dt><dd>' . $h($value ?? '—') . '</dd></div>';
    }

    return $out . '</dl>';
};

$fgtAvatar = static function (array $item, string $class, int $size, string $alt) use ($h): string {
    $photo = (string) ($item['photo'] ?? '');
    $shirt = (string) ($item['shirt'] ?? '');
    $src = $photo !== '' ? $photo : $shirt;
    if ($src === '') {
        return '<span class="' . $class . ' ' . $class . '--empty" aria-hidden="true"></span>';
    }

    return '<img class="' . $class . ($photo !== '' ? '' : ' ' . $class . '--shirt') . '" src="' . $h($src) . '" data-shirt="' . $h($shirt) . '" alt="' . $h($alt) . '" width="' . $size . '" height="' . $size . '" loading="lazy" referrerpolicy="no-referrer" onerror="window.finAvatarFallback&&window.finAvatarFallback(this)">';
};

$fgtTip = static function (array $tip) use ($h, $fgtParas, $fgtStats, $fgtFacts, $fgtAvatar, $fgtOwn): string {
    $why = ($tip['tip_type'] ?? '') === 'player_to_avoid' ? 'Why we are wary' : 'Why we like him';
    $suggest = (array) ($tip['what_we_suggest'] ?? []);
    $out = '<section class="fin-player is-in" id="' . $h($tip['slug'] ?? '') . '" data-fin-reveal>'
        . '<header class="fin-player__head"><div class="fin-player__identity">'
        . $fgtAvatar($tip, 'fin-avatar', 72, (string) ($tip['alt_text'] ?? $tip['web_name'] ?? ''))
        . '<div><h2 class="fin-player__title">' . $h($tip['heading'] ?? '') . '</h2>'
        . '<p class="fin-player__meta">' . $h($tip['team_name'] ?? '') . ' · ' . $h($tip['position'] ?? '') . ' · Owned by ' . $h($fgtOwn($tip['ownership'] ?? null)) . '</p>'
        . '</div></div></header>'
        . $fgtStats($tip)
        . $fgtFacts($tip);
    if (!empty($tip['story_lead'])) {
        $out .= '<p class="fin-player__lead">' . $h($tip['story_lead']) . '</p>';
    }
    $out .= '<div class="fin-blocks">'
        . '<div class="fin-block"><h3>' . $h($why) . '</h3>' . $fgtParas($tip['why_pick'] ?? []) . '</div>'
        . '<div class="fin-block"><h3>FPL impact</h3>' . $fgtParas($tip['fpl_impact'] ?? []) . '</div>';
    if ($suggest !== []) {
        $out .= '<div class="fin-block fin-block--suggest"><h3>What do we suggest?</h3>' . $fgtParas($suggest) . '</div>';
    }

    return $out . '</div></section>';
};

if ($post === null) {
    $html = preg_replace(
        '#<div class="fin-empty" data-fin-empty hidden>\s*<h2 class="fin-empty__title" data-fin-empty-title>[^<]*</h2>#',
        '<div class="fin-empty" data-fin-empty data-fin-ssr="1"><h2 class="fin-empty__title" data-fin-empty-title>' . $h($gwName . ' tips are not live yet') . '</h2>',
        $html,
        1
    ) ?? $html;
    $html = str_replace(
        ['https://metrofootball.co.uk/fpl-gameweek-tips"', '<title>FPL Gameweek tips | Metro Football</title>'],
        [$canonical . '"', '<title>' . $h('FPL ' . $gwName . ' tips') . ' | Metro Football</title>'],
        $html
    );
    echo $html;
    exit;
}

$seoTitle = (string) ($post['seo_title'] ?? ('FPL ' . $gwName . ' tips'));
$metaDesc = (string) ($post['meta_description'] ?? '');
$intro = array_values((array) ($post['intro_paragraphs'] ?? []));
$lead = (string) ($intro[0] ?? '');
$postCanonical = (string) ($post['canonical'] ?? $canonical);
$isUpcoming = !empty($data['is_upcoming']);

$updatedLine = '';
if (!empty($post['updated_at'])) {
    try {
        $dt = (new DateTimeImmutable((string) $post['updated_at']))->setTimezone(new DateTimeZone('Europe/London'));
        $updatedLine = 'Updated ' . $dt->format('j M Y, H:i');
    } catch (Throwable) {
        $updatedLine = '';
    }
}
if (!$isUpcoming) {
    $updatedLine .= $updatedLine !== '' ? ' · Archived tips' : 'Archived tips';
}

// Head tags.
$headSwaps = [
    '<title>FPL Gameweek tips | Metro Football</title>' => '<title>' . $h($seoTitle) . ' | Metro Football</title>',
    'content="FPL Gameweek tips | Metro Football"' => 'content="' . $h($seoTitle) . ' | Metro Football"',
    'https://metrofootball.co.uk/fpl-gameweek-tips"' => $h($postCanonical) . '"',
];
if ($metaDesc !== '') {
    $headSwaps['content="FPL Gameweek tips. See who to captain, who to bench, and what Metro Football thinks managers should do before the deadline."'] = 'content="' . $h($metaDesc) . '"';
}
$html = str_replace(array_keys($headSwaps), array_values($headSwaps), $html);

// Article header.
$html = preg_replace('#<p class="fin-kicker" data-fin-kicker>[^<]*</p>#', '<p class="fin-kicker" data-fin-kicker>' . $h($gwName . ' tips briefing') . '</p>', $html, 1) ?? $html;
$html = preg_replace('#<h1 class="fin-title" data-fin-title>[^<]*</h1>#', '<h1 class="fin-title" data-fin-title>' . $h($post['h1'] ?? 'FPL Gameweek tips') . '</h1>', $html, 1) ?? $html;
if ($lead !== '') {
    $html = preg_replace('#<p class="fin-lead" data-fin-lead>[^<]*</p>#', '<p class="fin-lead" data-fin-lead>' . $h($lead) . '</p>', $html, 1) ?? $html;
}
if ($updatedLine !== '') {
    $html = str_replace('<p class="fin-meta" data-fin-meta hidden></p>', '<p class="fin-meta" data-fin-meta>' . $h($updatedLine) . '</p>', $html);
}

// Table of contents.
$toc = '<h2 class="fin-toc__title">On this page</h2><ol class="fin-toc__list">';
foreach ((array) ($post['toc'] ?? []) as $item) {
    $toc .= '<li><a href="#' . $h($item['id'] ?? '') . '">' . $h($item['label'] ?? '') . '</a></li>';
}
$toc .= '</ol>';

// Body.
$body = '<div class="fin-intro is-in" data-fin-reveal>';
foreach (array_slice($intro, 1) as $p) {
    $body .= '<p>' . $h($p) . '</p>';
}
$body .= '<p class="fin-internal">Also check our <a href="./fpl-injury-news">injury risk digest</a>, <a href="./price-changes">price change predictions</a>, and <a href="./gameweek-recap">gameweek recap</a> before you lock your team.</p></div>';

$quick = (array) ($post['quick_picks'] ?? []);
if ($quick !== []) {
    $body .= '<section class="fin-watch is-in" id="quick-picks" data-fin-reveal><h2>Quick picks</h2><div class="fin-watch__grid fin-watch__grid--quick">';
    foreach ($quick as $item) {
        $item = (array) $item;
        $name = (string) ($item['web_name'] ?? $item['label'] ?? '');
        $metaBits = implode(' · ', array_filter([(string) ($item['team_short'] ?? ''), (string) ($item['position'] ?? '')]));
        $body .= '<article class="fin-watch-card">'
            . $fgtAvatar($item, 'fin-watch-card__avatar', 56, $name)
            . '<h3 class="fin-watch-card__name">' . $h($name) . '</h3>'
            . ($metaBits !== '' ? '<p class="fin-watch-card__meta">' . $h($metaBits) . '</p>' : '')
            . '<span class="fin-pill fin-pill--a">' . $h($item['label'] ?? 'Pick') . '</span>'
            . (!empty($item['teaser']) ? '<p class="fin-watch-card__news">' . $h($item['teaser']) . '</p>' : '')
            . '</article>';
    }
    $body .= '</div></section>';
}

foreach ((array) ($post['tips'] ?? []) as $tip) {
    $body .= $fgtTip((array) $tip);
}

$body .= '<section class="fin-close is-in" data-fin-reveal>' . $fgtParas($post['closing'] ?? []) . '</section>';

$archives = array_values(array_filter((array) ($data['archive_paths'] ?? []), static fn ($row): bool => (int) ($row['gw'] ?? 0) !== $requestedGw));
if ($archives !== []) {
    $body .= '<nav class="fgt-archive" data-fin-reveal aria-label="Other gameweek tips"><h2 class="fgt-archive__title">More Gameweek tips</h2><ul class="fgt-archive__list">';
    foreach (array_slice($archives, 0, 6) as $row) {
        $body .= '<li><a href="' . $h($row['path'] ?? '') . '">' . $h($row['label'] ?? '') . '</a></li>';
    }
    $body .= '</ul></nav>';
}

$html = str_replace(
    '<article class="fin-article" data-fin-article hidden>
        <nav class="fin-toc" data-fin-toc aria-label="Table of contents"></nav>
        <div class="fin-body" data-fin-body></div>
      </article>',
    '<article class="fin-article" data-fin-article data-fin-ssr="1">
        <nav class="fin-toc" data-fin-toc aria-label="Table of contents">' . $toc . '</nav>
        <div class="fin-body" data-fin-body>' . $body . '</div>
      </article>',
    $html
);

echo $html;
