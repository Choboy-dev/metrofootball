<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\FplGuidesService;
use App\GuideAuthor;

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$author = GuideAuthor::bySlug($slug);
if ($author === null) {
    http_response_code(404);
    echo 'Author not found';
    exit;
}

function author_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$canonical = 'https://metrofootball.co.uk' . $author['url'];
$guides = [];
try {
    $guides = FplGuidesService::listAuthorGuides();
} catch (Throwable $e) {
    error_log('[author page] listAuthorGuides failed: ' . $e->getMessage());
    $guides = [];
}
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'mainEntity' => GuideAuthor::schemaPerson(),
    'url' => $canonical,
    'name' => $author['name'] . ' | Metro Football',
    'description' => $author['bio'],
];
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
  <link rel="stylesheet" href="/css/fpl-guides.css?v=20260920tableresize">
  <title><?= author_h($author['name']) ?> | Metro Football</title>
  <meta name="description" content="<?= author_h($author['bio']) ?>">
  <link rel="canonical" href="<?= author_h($canonical) ?>">
  <link rel="icon" href="/images/metro-football-favicon.png" type="image/png">
  <meta property="og:type" content="profile">
  <meta property="og:title" content="<?= author_h($author['name'] . ' | Metro Football') ?>">
  <meta property="og:description" content="<?= author_h($author['bio']) ?>">
  <meta property="og:url" content="<?= author_h($canonical) ?>">
  <meta property="og:image" content="<?= author_h($author['image_abs']) ?>">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= author_h($author['name'] . ' | Metro Football') ?>">
  <meta name="twitter:description" content="<?= author_h($author['bio']) ?>">
  <meta name="twitter:image" content="<?= author_h($author['image_abs']) ?>">
  <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body class="fg-page fg-page--author">
<?php include __DIR__ . '/partials/site-header.php'; ?>

<main class="fg-main">
  <div class="container fg-author-page">
    <nav class="fg-crumb" aria-label="Breadcrumb">
      <a href="/fpl-guides">FPL Guides</a>
      <span aria-hidden="true">/</span>
      <span>Author</span>
    </nav>

    <header class="fg-author-hero">
      <img
        class="fg-author-hero__avatar"
        src="<?= author_h($author['image']) ?>"
        alt="<?= author_h($author['name']) ?>"
        width="128"
        height="128"
        loading="eager"
        decoding="async"
      >
      <div class="fg-author-hero__copy">
        <h1 class="fg-author-hero__name"><?= author_h($author['name']) ?></h1>
        <p class="fg-author-hero__bio"><?= author_h($author['bio']) ?></p>
        <div class="fg-author-hero__links">
          <a class="fg-author-hero__link" href="mailto:<?= author_h($author['email']) ?>">
            <span class="fg-author-hero__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <span class="fg-author-hero__sep" aria-hidden="true">:</span>
            <span class="fg-author-hero__link-text"><?= author_h($author['email']) ?></span>
          </a>
          <a class="fg-author-hero__link" href="<?= author_h($author['instagram']) ?>" target="_blank" rel="noopener noreferrer">
            <span class="fg-author-hero__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
            </span>
            <span class="fg-author-hero__sep" aria-hidden="true">:</span>
            <span class="fg-author-hero__link-text">instagram.com/chosenesiwe/</span>
          </a>
        </div>
      </div>
    </header>

    <section class="fg-author-articles" aria-labelledby="fg-author-articles-title">
      <h2 id="fg-author-articles-title" class="fg-author-articles__title">Recent guides</h2>
      <ul class="fg-author-articles__list">
        <?php foreach ($guides as $guide): ?>
          <?php
          $href = (string) ($guide['href'] ?? ('/fpl-guides/' . ($guide['slug'] ?? '')));
          $featured = trim((string) ($guide['featured_image'] ?? ''));
          ?>
          <li>
            <a class="fg-author-card" href="<?= author_h($href) ?>">
              <?php if ($featured !== ''): ?>
                <span class="fg-author-card__media">
                  <img src="<?= author_h($featured) ?>" alt="" loading="lazy" decoding="async">
                </span>
              <?php endif; ?>
              <span class="fg-author-card__body">
                <span class="fg-author-card__cat"><?= author_h((string) ($guide['category'] ?? 'Guide')) ?></span>
                <span class="fg-author-card__title"><?= author_h((string) ($guide['title'] ?? '')) ?></span>
                <span class="fg-author-card__meta"><?= (int) ($guide['read_mins'] ?? 1) ?> min read</span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</main>

<?php include __DIR__ . '/partials/site-footer.php'; ?>
<script type="module" src="/js/fpl-guides.js?v=20260920author"></script>
</body>
</html>
