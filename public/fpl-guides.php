<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\FplGuidesService;

$guides = FplGuidesService::listGuides();
$perPage = 12;
$totalGuides = count($guides);
$totalPages = max(1, (int) ceil($totalGuides / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pageGuides = array_slice($guides, $offset, $perPage);

$canonical = 'https://metrofootball.co.uk/fpl-guides';
if ($page > 1) {
    $canonical .= '?page=' . $page;
}

function fg_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fg_page_url(int $pageNum): string
{
    if ($pageNum <= 1) {
        return '/fpl-guides';
    }

    return '/fpl-guides?page=' . $pageNum;
}
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
  <link rel="stylesheet" href="/css/fpl-guides.css?v=20260920page">
  <title>FPL Guides | Metro Football</title>
  <meta name="description" content="Free Fantasy Premier League guides and gameweek tips from Metro Football. Learn chips, scoring, transfers, and strategy.">
  <link rel="canonical" href="<?= fg_h($canonical) ?>">
  <?php if ($page > 1): ?>
  <link rel="prev" href="<?= fg_h('https://metrofootball.co.uk' . fg_page_url($page - 1)) ?>">
  <?php endif; ?>
  <?php if ($page < $totalPages): ?>
  <link rel="next" href="<?= fg_h('https://metrofootball.co.uk' . fg_page_url($page + 1)) ?>">
  <?php endif; ?>
  <link rel="icon" href="/images/metro-football-favicon.png" type="image/png">
</head>
<body class="fg-page">
<?php include __DIR__ . '/partials/site-header.php'; ?>

<main class="fg-main">
  <div class="container fg-shell">
    <header class="fg-hero">
      <h1 class="fg-hero__title">FPL Academy</h1>
      <p class="fg-hero__lead">Clear guides and past gameweek tips for new and returning managers.</p>
    </header>

    <section class="fg-grid" aria-label="FPL guides">
      <?php if ($pageGuides === []): ?>
        <p class="fg-empty">No guides to show yet.</p>
      <?php endif; ?>
      <?php foreach ($pageGuides as $guide): ?>
        <?php
          $slug = (string) $guide['slug'];
          $href = trim((string) ($guide['href'] ?? ''));
          if ($href === '') {
              $href = '/fpl-guides/' . $slug;
          }
          $featured = trim((string) ($guide['featured_image'] ?? ''));
          $title = (string) $guide['title'];
          $isTips = (($guide['source'] ?? '') === 'gameweek_tips');
          $cta = $isTips ? 'Read tips' : 'Read guide';
        ?>
        <article class="fg-card">
          <?php if ($featured !== ''): ?>
            <a class="fg-card__media<?= $isTips ? ' fg-card__media--player' : '' ?>" href="<?= fg_h($href) ?>" tabindex="-1" aria-hidden="true">
              <img
                src="<?= fg_h($featured) ?>"
                alt=""
                width="600"
                height="300"
                loading="lazy"
                decoding="async"
              >
            </a>
          <?php else: ?>
            <div class="fg-card__media fg-card__media--placeholder" aria-hidden="true"></div>
          <?php endif; ?>
          <div class="fg-card__body">
            <p class="fg-card__meta"><?= fg_h((string) $guide['category']) ?> · <?= (int) $guide['read_mins'] ?> min read</p>
            <h2 class="fg-card__title"><a href="<?= fg_h($href) ?>"><?= fg_h($title) ?></a></h2>
            <p class="fg-card__desc"><?= fg_h((string) $guide['description']) ?></p>
            <a class="fg-card__link" href="<?= fg_h($href) ?>"><?= fg_h($cta) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <?php if ($totalPages > 1): ?>
      <nav class="fg-pagination" aria-label="Guide pages">
        <p class="fg-pagination__summary">
          Page <?= (int) $page ?> of <?= (int) $totalPages ?>
          <span class="fg-pagination__count"><?= (int) $totalGuides ?> guides</span>
        </p>
        <div class="fg-pagination__row">
          <?php if ($page > 1): ?>
            <a class="mf-btn mf-btn--ink mf-btn--sm" href="<?= fg_h(fg_page_url($page - 1)) ?>">
              <span class="mf-btn__label">Previous</span>
              <span class="mf-btn__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
            </a>
          <?php endif; ?>

          <ol class="fg-pagination__pages">
            <?php for ($i = 1; $i <= $totalPages; $i += 1): ?>
              <li>
                <?php if ($i === $page): ?>
                  <span class="fg-pagination__page is-current" aria-current="page"><?= $i ?></span>
                <?php else: ?>
                  <a class="fg-pagination__page" href="<?= fg_h(fg_page_url($i)) ?>"><?= $i ?></a>
                <?php endif; ?>
              </li>
            <?php endfor; ?>
          </ol>

          <?php if ($page < $totalPages): ?>
            <a class="mf-btn mf-btn--primary mf-btn--sm" href="<?= fg_h(fg_page_url($page + 1)) ?>">
              <span class="mf-btn__label">Next</span>
              <span class="mf-btn__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
            </a>
          <?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/partials/site-footer.php'; ?>
<script type="module" src="/js/fpl-guides.js?v=20260903auth"></script>
</body>
</html>
