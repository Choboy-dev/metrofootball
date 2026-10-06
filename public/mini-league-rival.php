<?php

declare(strict_types=1);

function mlr_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$leagueSlug = trim((string) ($_GET['league'] ?? ''));
$rivalSlug = trim((string) ($_GET['rival'] ?? ''));
$leagueSlug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', str_replace(["'", '’', '.'], '', $leagueSlug)) ?? '');
$rivalSlug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', str_replace(["'", '’', '.'], '', $rivalSlug)) ?? '');
$leagueSlug = trim($leagueSlug, '-');
$rivalSlug = trim($rivalSlug, '-');

$rivalLabel = $rivalSlug !== '' ? ucwords(str_replace('-', ' ', $rivalSlug)) : 'Rival';
$leagueLabel = $leagueSlug !== '' ? ucwords(str_replace('-', ' ', $leagueSlug)) : 'League';
$canonical = $leagueSlug !== '' && $rivalSlug !== ''
    ? 'https://metrofootball.co.uk/mini-leagues/' . rawurlencode($leagueSlug) . '/' . rawurlencode($rivalSlug)
    : 'https://metrofootball.co.uk/mini-leagues';
$pageTitle = $rivalLabel . ' | ' . $leagueLabel . ' | Metro Football';
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/meta-pixel.php'; ?>
  <base href="/">
  <title><?= mlr_h($pageTitle) ?></title>
  <meta name="description" content="View <?= mlr_h($rivalLabel) ?>'s FPL squad, scores, transfers, and chips in <?= mlr_h($leagueLabel) ?> on Metro Football.">
  <link rel="canonical" href="<?= mlr_h($canonical) ?>">
  <link rel="stylesheet" href="/css/app.css?v=20260902bonus">
  <script src="/js/perf-head.js?v=20260901perf"></script>
  <noscript>
    <link rel="stylesheet" href="/css/fonts.css?v=20260830perf">
    <link rel="stylesheet" href="/css/perf.css?v=20260830perf">
  </noscript>
  <link rel="icon" href="/images/metro-football-favicon.png" type="image/png" sizes="500x500">
  <link rel="modulepreload" href="/js/site-nav.js?v=20260907pub">
  <link rel="modulepreload" href="/js/auth.js?v=20260907pub">
  <link rel="modulepreload" href="/js/mini-league-rival.js?v=20260902bonus">
</head>
<body
  class="pick-page ml-rival-page"
  data-auth-page="team-tool"
  data-tool="mini-leagues"
  data-ml-rival-page
  data-league-slug="<?= mlr_h($leagueSlug) ?>"
  data-rival-slug="<?= mlr_h($rivalSlug) ?>"
>
  <?php include __DIR__ . '/partials/site-header.php'; ?>

  <nav class="team-tabs" aria-label="My Team" data-team-tabs>
    <div class="container team-tabs__shell">
      <div class="team-tabs__root" data-team-tabs-root>
        <div class="team-tabs__hover" data-team-tabs-hover aria-hidden="true"></div>
        <div class="team-tabs__active" data-team-tabs-active aria-hidden="true"></div>
        <div class="team-tabs__list">
          <a class="team-tabs__tab" href="/live" data-team-tab>Live</a>
          <a class="team-tabs__tab" href="/pick" data-team-tab>Pick</a>
          <a class="team-tabs__tab" href="/ai-tips" data-team-tab>AI Tips</a>
          <a class="team-tabs__tab" href="/ai-teams" data-team-tab>AI Teams</a>
          <a class="team-tabs__tab is-active" href="/mini-leagues" aria-current="page" data-team-tab>Mini Leagues</a>
        </div>
      </div>
    </div>
  </nav>

  <main class="pick-main" data-ml-rival-main>
    <div class="container pick-shell">
      <section class="premium-lock" data-premium-lock aria-labelledby="ml-rival-lock-title">
        <div class="premium-lock__banner">
          <span class="premium-lock__gem" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5 3.5 9.2 12 21.5 20.5 9.2 12 2.5Zm0 2.4 5.4 4.3L12 18.2 6.6 9.2 12 4.9Z"/></svg>
          </span>
          <p class="premium-lock__banner-text">Exclusive content for members only</p>
        </div>
        <div class="premium-lock__body">
          <div class="premium-lock__intro">
            <span class="premium-lock__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>
            </span>
            <div class="premium-lock__copy">
              <h1 class="premium-lock__title" id="ml-rival-lock-title">Unlock exclusive content</h1>
              <p class="premium-lock__lead">Upgrade for access to rival profiles in Mini League Tracker</p>
            </div>
          </div>
          <a class="mf-btn mf-btn--primary" href="/join" data-upgrade-cta>
            <span class="mf-btn__label">Upgrade</span>
            <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </div>
      </section>

      <div data-premium-unlocked hidden>
        <p class="pick-status is-loading" data-ml-rival-status>Loading rival profile</p>
        <p class="pick-error auth-form__error" data-ml-rival-error hidden></p>

        <section class="ml-rival-identity" data-ml-rival-identity hidden aria-labelledby="ml-rival-team-name">
          <div class="ml-rival-identity__sheen" aria-hidden="true"></div>
          <div class="ml-rival-identity__head">
            <span class="ml-rival-identity__league" data-ml-rival-league-pill><?= mlr_h($leagueLabel) ?></span>
            <span class="ml-rival-identity__you" data-ml-rival-you-badge hidden>You</span>
          </div>
          <h1 class="ml-rival-identity__team" id="ml-rival-team-name" data-ml-rival-team-name><?= mlr_h($rivalLabel) ?></h1>
          <p class="ml-rival-identity__manager" data-ml-rival-manager-name>Loading manager</p>
        </section>

        <section class="pick-summary" data-ml-rival-summary hidden aria-label="Rival summary">
          <div class="pick-summary__gw">
            <button type="button" class="pick-gw-nav" data-ml-rival-gw-prev aria-label="Previous gameweek" disabled>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 6 9 12l6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="pick-summary__gw-copy">
              <p class="pick-summary__gw-name" data-ml-rival-gw-name>Gameweek</p>
              <p class="pick-summary__deadline" data-ml-rival-gw-sub>Squad and scores for this gameweek</p>
            </div>
            <button type="button" class="pick-gw-nav" data-ml-rival-gw-next aria-label="Next gameweek" disabled>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>

          <div class="pick-summary__stats">
            <article class="pick-metric">
              <p class="pick-metric__value" data-ml-rival-stat-gw>—</p>
              <p class="pick-metric__label">GW points</p>
            </article>
            <article class="pick-metric">
              <p class="pick-metric__value pick-metric__value--bank" data-ml-rival-stat-formation>—</p>
              <p class="pick-metric__label">Formation</p>
            </article>
            <article class="pick-metric">
              <p class="pick-metric__value" data-ml-rival-stat-rank>—</p>
              <p class="pick-metric__label">League rank</p>
            </article>
            <article class="pick-metric">
              <p class="pick-metric__value" data-ml-rival-stat-total>—</p>
              <p class="pick-metric__label">Total points</p>
            </article>
          </div>

          <div class="ml-rival-back">
            <a class="mf-btn mf-btn--ink mf-btn--sm" href="/mini-leagues">
              <span class="mf-btn__label">Back to league</span>
              <span class="mf-btn__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
            </a>
          </div>
        </section>

        <p class="pick-lock-banner" data-ml-rival-hidden hidden></p>

        <div class="pick-layout" data-ml-rival-layout hidden>
          <section class="pick-stage" aria-label="Rival squad">
            <div class="pick-pitch" data-ml-rival-pitch>
              <div class="pick-pitch__markings" aria-hidden="true">
                <span class="pick-pitch__box--far"></span>
              </div>
              <div class="pick-pitch__rows">
                <div class="pick-pitch__row" data-pitch-row="gkp"></div>
                <div class="pick-pitch__row" data-pitch-row="def"></div>
                <div class="pick-pitch__row" data-pitch-row="mid"></div>
                <div class="pick-pitch__row" data-pitch-row="fwd"></div>
              </div>
            </div>
            <div class="pick-bench" aria-label="Bench">
              <div class="pick-bench__track" data-ml-rival-bench></div>
            </div>
          </section>

          <section class="pick-summary ml-rival-extra" data-ml-rival-extra hidden aria-label="Transfers and chips">
            <div class="ml-rival-extra__grid">
              <div class="ml-rival-extra__block" data-ml-rival-transfers-wrap hidden>
                <h2 class="pick-metric__label">Transfers this gameweek</h2>
                <ul class="ml-rival-transfers" data-ml-rival-transfers></ul>
              </div>
              <div class="ml-rival-extra__block" data-ml-rival-chips-wrap hidden>
                <h2 class="pick-metric__label">Chips used</h2>
                <ul class="ml-rival-chips" data-ml-rival-chips></ul>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/partials/site-footer.php'; ?>

  <script defer src="/js/gtag-config.js?v=2"></script>
  <script type="module" src="/js/mini-league-rival.js?v=20260902bonus"></script>
</body>
</html>
