<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\FplGuidesService;
use App\GuideAuthor;
use App\GuidePostService;

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: /fpl-guides', true, 302);
    exit;
}

$guide = FplGuidesService::getGuide($slug);
if ($guide === null) {
    http_response_code(404);
    echo 'Guide not found';
    exit;
}

function fgd_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$canonical = 'https://metrofootball.co.uk/fpl-guides/' . $slug;
$author = GuideAuthor::default();
$readMins = max(1, (int) ($guide['read_mins'] ?? 1));
$adjacent = FplGuidesService::adjacentAuthorGuides($slug);
$prevGuide = $adjacent['prev'];
$nextGuide = $adjacent['next'];
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/google-adsense.php'; ?>
  <base href="/">
  <link rel="stylesheet" href="/css/fonts.css?v=20260830perf">
  <link rel="stylesheet" href="/css/app.css?v=20260903guides">
  <link rel="stylesheet" href="/css/fpl-guides.css?v=20260921nav2">
  <title><?= fgd_h((string) $guide['title']) ?> | Metro Football</title>
  <meta name="description" content="<?= fgd_h((string) $guide['description']) ?>">
  <meta name="author" content="<?= fgd_h($author['name']) ?>">
  <link rel="canonical" href="<?= fgd_h($canonical) ?>">
  <?php if (is_array($prevGuide)): ?>
  <link rel="prev" href="<?= fgd_h('https://metrofootball.co.uk' . (string) ($prevGuide['href'] ?? ('/fpl-guides/' . $prevGuide['slug']))) ?>">
  <?php endif; ?>
  <?php if (is_array($nextGuide)): ?>
  <link rel="next" href="<?= fgd_h('https://metrofootball.co.uk' . (string) ($nextGuide['href'] ?? ('/fpl-guides/' . $nextGuide['slug']))) ?>">
  <?php endif; ?>
  <link rel="icon" href="/images/metro-football-favicon.png" type="image/png">
  <?php
    $schemaParts = [];
    foreach (($guide['headings'] ?? []) as $heading) {
        if (!is_array($heading) || (int) ($heading['level'] ?? 0) !== 2) {
            continue;
        }
        $schemaParts[] = [
            '@type' => 'WebPageElement',
            'name' => (string) $heading['text'],
            'url' => $canonical . '#' . (string) $heading['id'],
        ];
    }
    $schemaType = (string) ($guide['schema_type'] ?? 'Article');
    if (!in_array($schemaType, GuidePostService::SCHEMA_TYPES, true)) {
        $schemaType = 'Article';
    }
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => $schemaType,
        'headline' => (string) $guide['title'],
        'description' => (string) $guide['description'],
        'author' => GuideAuthor::schemaPerson(),
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Metro Football',
            'url' => 'https://metrofootball.co.uk',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => 'https://metrofootball.co.uk/images/metro-football-correct-dark-logo-630.webp',
            ],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $canonical,
        ],
        'url' => $canonical,
        'timeRequired' => 'PT' . $readMins . 'M',
        'hasPart' => $schemaParts,
    ];
    $featuredImage = trim((string) ($guide['featured_image'] ?? ''));
    if ($featuredImage !== '') {
        $schema['image'] = str_starts_with($featuredImage, 'http')
            ? $featuredImage
            : 'https://metrofootball.co.uk' . $featuredImage;
    }
  ?>
  <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body class="fg-page fg-page--article">
<?php include __DIR__ . '/partials/site-header.php'; ?>

<main class="fg-main">
  <article class="container fg-article" itemscope itemtype="https://schema.org/Article">
    <meta itemprop="author" content="<?= fgd_h($author['name']) ?>">
    <nav class="fg-crumb" aria-label="Breadcrumb">
      <a href="/fpl-guides">FPL Guides</a>
      <span aria-hidden="true">/</span>
      <span><?= fgd_h((string) $guide['category']) ?></span>
    </nav>
    <header class="fg-article__head">
      <p class="fg-article__meta"><?= fgd_h((string) $guide['category']) ?></p>
      <h1 class="fg-article__title" itemprop="headline"><?= fgd_h((string) $guide['title']) ?></h1>
      <?php
        $shareTitle = (string) $guide['title'];
        include __DIR__ . '/partials/guide-byline.php';
      ?>
      <p class="fg-article__deck" itemprop="description"><?= fgd_h((string) $guide['description']) ?></p>
    </header>
    <?php if (!empty($guide['toc'])): ?>
      <?= (string) $guide['toc'] ?>
    <?php endif; ?>
    <div class="fg-article__body" itemprop="articleBody">
      <?= (string) $guide['html'] ?>
    </div>
    <?php if (!empty($guide['tool_links'])): ?>
      <section class="fg-tools" aria-label="Related tools">
        <h2 class="fg-tools__title">Try these tools</h2>
        <div class="fg-tools__row">
          <?php foreach ($guide['tool_links'] as $link): ?>
            <a class="mf-btn mf-btn--ink mf-btn--sm" href="<?= fgd_h((string) $link['href']) ?>">
              <span class="mf-btn__label"><?= fgd_h((string) $link['label']) ?></span>
              <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if (is_array($prevGuide) || is_array($nextGuide)): ?>
      <nav class="fg-adjacent" aria-label="More guides" data-fg-adjacent>
        <div class="fg-adjacent__grid">
          <?php if (is_array($prevGuide)): ?>
            <?php
              $prevHref = (string) ($prevGuide['href'] ?? ('/fpl-guides/' . $prevGuide['slug']));
              $prevImage = trim((string) ($prevGuide['featured_image'] ?? ''));
            ?>
            <a class="fg-adjacent__card fg-adjacent__card--prev" href="<?= fgd_h($prevHref) ?>" rel="prev">
              <span class="fg-adjacent__dir">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18 9 12l6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Previous
              </span>
              <?php if ($prevImage !== ''): ?>
                <span class="fg-adjacent__media" aria-hidden="true">
                  <img src="<?= fgd_h($prevImage) ?>" alt="" width="160" height="90" loading="lazy" decoding="async">
                </span>
              <?php endif; ?>
              <span class="fg-adjacent__copy">
                <span class="fg-adjacent__meta"><?= fgd_h((string) ($prevGuide['category'] ?? 'Guide')) ?></span>
                <span class="fg-adjacent__title"><?= fgd_h((string) ($prevGuide['title'] ?? '')) ?></span>
              </span>
            </a>
          <?php else: ?>
            <span class="fg-adjacent__card fg-adjacent__card--empty" aria-hidden="true"></span>
          <?php endif; ?>

          <?php if (is_array($nextGuide)): ?>
            <?php
              $nextHref = (string) ($nextGuide['href'] ?? ('/fpl-guides/' . $nextGuide['slug']));
              $nextImage = trim((string) ($nextGuide['featured_image'] ?? ''));
            ?>
            <a class="fg-adjacent__card fg-adjacent__card--next" href="<?= fgd_h($nextHref) ?>" rel="next">
              <span class="fg-adjacent__dir">
                Next
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              <span class="fg-adjacent__copy">
                <span class="fg-adjacent__meta"><?= fgd_h((string) ($nextGuide['category'] ?? 'Guide')) ?></span>
                <span class="fg-adjacent__title"><?= fgd_h((string) ($nextGuide['title'] ?? '')) ?></span>
              </span>
              <?php if ($nextImage !== ''): ?>
                <span class="fg-adjacent__media" aria-hidden="true">
                  <img src="<?= fgd_h($nextImage) ?>" alt="" width="160" height="90" loading="lazy" decoding="async">
                </span>
              <?php endif; ?>
            </a>
          <?php else: ?>
            <span class="fg-adjacent__card fg-adjacent__card--empty" aria-hidden="true"></span>
          <?php endif; ?>
        </div>
        <p class="fg-adjacent__hub">
          <a href="/fpl-guides">All FPL guides</a>
        </p>
      </nav>
    <?php else: ?>
      <p class="fg-back"><a href="/fpl-guides">All FPL guides</a></p>
    <?php endif; ?>
  </article>
</main>

<?php include __DIR__ . '/partials/site-footer.php'; ?>
<script type="module" src="/js/fpl-guides.js?v=20260921nav"></script>
</body>
</html>
