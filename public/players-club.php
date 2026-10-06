<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\PlayerProfileService;
use App\PlayerScoutReport;

function pch_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$clubSlug = trim((string) ($_GET['club'] ?? ''));

try {
    $hub = PlayerProfileService::resolveClubHub($clubSlug);
} catch (Throwable) {
    $hub = null;
}

if (!is_array($hub)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><title>Club not found | Metro Football</title><base href="/"><link rel="stylesheet" href="/css/app.css"></head><body><main class="container" style="padding:3rem 1rem"><h1>Club not found</h1><p><a href="/players">Back to player profiles</a></p></main></body></html>';
    exit;
}

try {
    $scoutReport = PlayerScoutReport::forClub($hub);
} catch (Throwable) {
    $scoutReport = null;
}

$clubName = (string) ($hub['name'] ?? 'Club');
$clubShort = (string) ($hub['short_name'] ?? '');
$clubBadge = (string) ($hub['badge'] ?? '');
$clubPath = (string) ($hub['path'] ?? '');
$playerCount = (int) ($hub['player_count'] ?? 0);
$groups = is_array($hub['groups'] ?? null) ? $hub['groups'] : [];
$otherClubs = is_array($hub['other_clubs'] ?? null) ? $hub['other_clubs'] : [];
$canonical = 'https://metrofootball.co.uk' . $clubPath;
$pageTitle = pch_h($clubName . ' FPL Squad | Metro Football');
$desc = pch_h(
    'Browse the full ' . $clubName . ' Fantasy Premier League squad. '
    . $playerCount . ' players with prices, ownership and profile links.'
);

function pch_face(array $player): string
{
    $name = pch_h((string) ($player['web_name'] ?? 'Player'));
    $initial = pch_h(substr((string) ($player['web_name'] ?? '?'), 0, 1));
    $photo = (string) ($player['photo'] ?? '');
    $shirt = (string) ($player['shirt'] ?? '');
    $shirtAttr = $shirt !== '' ? ' data-pp-shirt="' . pch_h($shirt) . '"' : '';

    if ($photo !== '') {
        return '<img class="pp-roster-player__img pp-roster-player__img--face" src="' . pch_h($photo) . '" alt="" width="40" height="40" loading="lazy" decoding="async"' . $shirtAttr . ' data-pp-initial="' . $initial . '">';
    }
    if ($shirt !== '') {
        return '<img class="pp-roster-player__img pp-roster-player__img--shirt" src="' . pch_h($shirt) . '" alt="" width="30" height="40" loading="lazy" decoding="async" data-pp-initial="' . $initial . '">';
    }

    return '<span class="pp-roster-player__fallback" aria-hidden="true">' . $initial . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/google-adsense.php'; ?>
  <base href="/">
  <link rel="stylesheet" href="/css/app.css?v=20260904nav2">
  <script src="/js/perf-head.js?v=20260901perf" data-page-css="/css/players.css?v=20260906clubhub"></script>
  <noscript>
    <link rel="stylesheet" href="/css/fonts.css?v=20260830perf">
    <link rel="stylesheet" href="/css/perf.css?v=20260830perf">
    <link rel="stylesheet" href="/css/players.css?v=20260906clubhub">
  </noscript>
  <link rel="stylesheet" href="/css/scout-report.css?v=20260929scout">
  <title><?= $pageTitle ?></title>
  <meta name="description" content="<?= $desc ?>">
  <link rel="canonical" href="<?= pch_h($canonical) ?>">
  <link rel="icon" href="/images/metro-football-favicon.png" type="image/png" sizes="500x500">
  <link rel="apple-touch-icon" href="/images/metro-football-favicon.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Metro Football">
  <meta property="og:title" content="<?= $pageTitle ?>">
  <meta property="og:description" content="<?= $desc ?>">
  <meta property="og:url" content="<?= pch_h($canonical) ?>">
  <meta property="og:image" content="https://metrofootball.co.uk/images/metro-football-favicon.png">
  <meta property="og:locale" content="en_GB">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= $pageTitle ?>">
  <meta name="twitter:description" content="<?= $desc ?>">
  <link rel="modulepreload" href="/js/site-nav.js?v=20260907pub">
  <link rel="modulepreload" href="/js/auth.js?v=20260907pub">
  <link rel="modulepreload" href="/js/players-club.js?v=20260906clubhub">
</head>
<body class="players-page players-club-page">
  <?php include __DIR__ . '/partials/site-header.php'; ?>

  <div class="pp-shell">
    <nav class="pp-crumb" aria-label="Breadcrumb">
      <a href="/players">Player profiles</a>
      <span class="pp-crumb__sep">/</span>
      <span aria-current="page"><?= pch_h($clubName) ?></span>
    </nav>

    <section class="pp-hero pp-club-hero" aria-labelledby="pp-club-hero-title">
      <div class="pp-hero__inner pp-club-hero__inner">
        <div class="pp-club-hero__brand">
          <?php if ($clubBadge !== ''): ?>
            <img class="pp-club-hero__badge" src="<?= pch_h($clubBadge) ?>" alt="" width="56" height="56" decoding="async">
          <?php endif; ?>
          <div class="pp-club-hero__copy">
            <p class="pp-hero__eyebrow"><?= pch_h($clubShort !== '' ? $clubShort : 'Club squad') ?></p>
            <h1 class="pp-hero__title" id="pp-club-hero-title"><?= pch_h($clubName) ?></h1>
            <p class="pp-hero__lead">
              Full FPL squad with real profile links.
              <?= (int) $playerCount ?> players listed by position.
            </p>
          </div>
        </div>
        <a class="mf-btn mf-btn--light" href="/players">
          <span class="mf-btn__label">All clubs</span>
          <span class="mf-btn__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
        </a>
      </div>
    </section>

    <main class="pp-main">
      <?php foreach ($groups as $group): ?>
        <?php
          $label = (string) ($group['label'] ?? 'Players');
          $players = is_array($group['players'] ?? null) ? $group['players'] : [];
          if ($players === []) {
              continue;
          }
        ?>
        <section class="pp-pos-group" aria-labelledby="pp-pos-<?= (int) ($group['pos'] ?? 0) ?>">
          <header class="pp-pos-group__head">
            <h2 class="pp-pos-group__title" id="pp-pos-<?= (int) ($group['pos'] ?? 0) ?>"><?= pch_h($label) ?></h2>
            <p class="pp-pos-group__count"><?= count($players) ?> players</p>
          </header>
          <div class="pp-club-roster__panel pp-pos-group__panel">
            <div class="pp-club-roster__grid" role="list" data-pp-roster-grid>
              <?php foreach ($players as $player): ?>
                <?php
                  $href = (string) ($player['path'] ?? '#');
                  $webName = (string) ($player['web_name'] ?? 'Player');
                  $posLabel = (string) ($player['pos_label'] ?? '?');
                  $price = number_format((float) ($player['price'] ?? 0), 1);
                  $ownership = number_format((float) ($player['ownership'] ?? 0), 1);
                ?>
                <a
                  class="pp-roster-player"
                  href="<?= pch_h($href) ?>"
                  role="listitem"
                  data-pp-player-id="<?= (int) ($player['id'] ?? 0) ?>"
                  data-pp-name="<?= pch_h($webName) ?>"
                >
                  <span class="pp-roster-player__face"><?= pch_face($player) ?></span>
                  <span class="pp-roster-player__copy">
                    <span class="pp-roster-player__name"><?= pch_h($webName) ?></span>
                    <span class="pp-roster-player__pos"><?= pch_h($posLabel) ?> · <?= pch_h($ownership) ?>% owned</span>
                  </span>
                  <span class="pp-roster-player__price">£<?= pch_h($price) ?>m</span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>

      <?php $scoutTitle = (string) ($scoutReport['title'] ?? ''); include __DIR__ . '/partials/scout-report.php'; ?>

      <?php if ($otherClubs !== []): ?>
        <section class="pp-clubs" aria-labelledby="pp-other-clubs-label">
          <h2 class="pp-clubs__label" id="pp-other-clubs-label">Other clubs</h2>
          <div class="pp-clubs__grid">
            <?php foreach ($otherClubs as $other): ?>
              <?php
                $otherPath = (string) ($other['path'] ?? '');
                $otherName = (string) ($other['name'] ?? $other['short_name'] ?? 'Club');
                $otherCode = (string) ($other['short_name'] ?? '');
                $otherBadge = (string) ($other['badge'] ?? '');
                if ($otherPath === '' || $otherCode === '') {
                    continue;
                }
              ?>
              <a class="pp-club" href="<?= pch_h($otherPath) ?>" title="<?= pch_h($otherName) ?>">
                <?php if ($otherBadge !== ''): ?>
                  <img class="pp-club__badge" src="<?= pch_h($otherBadge) ?>" alt="" width="28" height="28" loading="lazy" decoding="async">
                <?php endif; ?>
                <span class="pp-club__code"><?= pch_h($otherCode) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>

  <?php include __DIR__ . '/partials/site-footer.php'; ?>
  <script defer src="/js/gtag-config.js?v=2"></script>
  <script type="module" src="/js/players-club.js?v=20260906clubhub"></script>
  <script type="module" src="/js/scout-report.js?v=20260929scout"></script>
</body>
</html>
