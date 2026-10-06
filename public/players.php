<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\PlayerProfileService;

function pp_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$clubParam = strtoupper(trim((string) ($_GET['club'] ?? '')));
if ($clubParam !== '') {
    foreach (PlayerProfileService::listClubs() as $clubRow) {
        if (strtoupper((string) ($clubRow['short_name'] ?? '')) === $clubParam) {
            $hubPath = (string) ($clubRow['path'] ?? '');
            if ($hubPath !== '') {
                header('Location: ' . $hubPath, true, 301);
                exit;
            }
        }
    }
}

try {
    $ppClubs = PlayerProfileService::listClubs();
} catch (Throwable) {
    $ppClubs = [];
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Meta Pixel Code -->
  <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '1033677469478224');
    fbq('track', 'PageView');
  </script>
  <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1033677469478224&ev=PageView&noscript=1" alt="" /></noscript>
  <!-- End Meta Pixel Code -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-9101848035950927"
     crossorigin="anonymous"></script>
  <base href="/">
  <link rel="stylesheet" href="/css/app.css?v=20260904nav2">
  <link rel="stylesheet" href="/css/tool-guide.css?v=20261005mfx">
  <script src="/js/perf-head.js?v=20260901perf" data-page-css="/css/players.css?v=20260906clubhub"></script>
  <noscript>
    <link rel="stylesheet" href="/css/fonts.css?v=20260830perf">
    <link rel="stylesheet" href="/css/perf.css?v=20260830perf">
    <link rel="stylesheet" href="/css/players.css?v=20260906clubhub">
  </noscript>
  <title>Player Profiles | Metro Football</title>
  <meta name="description" content="Every number behind a player, in one place. Form against expected points, underlying Opta numbers, defensive contribution and what is coming up next.">
  <link rel="canonical" href="https://metrofootball.co.uk/players">
  <link rel="icon" href="./images/metro-football-favicon.png" type="image/png" sizes="500x500">
  <link rel="apple-touch-icon" href="./images/metro-football-favicon.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Metro Football">
  <meta property="og:title" content="Player Profiles | Metro Football">
  <meta property="og:description" content="Every number behind a player, in one place. Form against expected points, underlying Opta numbers, defensive contribution and what is coming up next.">
  <meta property="og:url" content="https://metrofootball.co.uk/players">
  <meta property="og:image" content="https://metrofootball.co.uk/images/metro-football-favicon.png">
  <meta property="og:image:width" content="500">
  <meta property="og:image:height" content="500">
  <meta property="og:locale" content="en_GB">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="Player Profiles | Metro Football">
  <meta name="twitter:description" content="Every number behind a player, in one place. Form against expected points, underlying Opta numbers, defensive contribution and what is coming up next.">
  <meta name="twitter:image" content="https://metrofootball.co.uk/images/metro-football-favicon.png">
  <link rel="modulepreload" href="/js/site-nav.js?v=20260907pub">
  <link rel="modulepreload" href="/js/auth.js?v=20260907pub">
  <link rel="modulepreload" href="/js/players.js?v=20260906clubhub">
</head>
<body class="players-page">
  <header class="site-header" data-header>
    <nav class="site-nav" aria-label="Main">
      <a href="./" class="site-logo" aria-label="Metro Football home">
          <img class="site-logo__img" src="./images/metro-football-correct-dark-logo-315.webp?v=20260830logo" srcset="./images/metro-football-correct-dark-logo-315.webp?v=20260830logo 315w, ./images/metro-football-correct-dark-logo-630.webp?v=20260830logo 630w" sizes="(min-width: 768px) 204px, 180px" alt="" width="938" height="313" decoding="async">
        </a>

        <div class="nav-menu desktop-only">
          <div class="nav-item" data-nav-item>
            <button type="button" class="nav-trigger" data-nav-trigger aria-expanded="false" aria-haspopup="true">
              Tools
              <svg class="nav-caret" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nav-panel" data-nav-panel hidden>
              <div class="nav-panel__card">
                <ul class="nav-list nav-list--grid">
                  <li>
                    <a class="list-item" href="./pick" data-auth-tool>
                      <span class="list-item__copy">
                        <span class="list-item__title">Rate My Team</span>
                        <span class="list-item__desc">Score your squad and pick a captain</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./ai-tips">
                      <span class="list-item__copy">
                        <span class="list-item__title">AI Transfers</span>
                        <span class="list-item__desc">Get the best transfer for your squad</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./chip-strategy">
                      <span class="list-item__copy">
                        <span class="list-item__title">Chip Strategy Planner</span>
                        <span class="list-item__desc">Find the best weeks for each chip</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./ai-tips#snipers">
                      <span class="list-item__copy">
                        <span class="list-item__title">Differential Finder</span>
                        <span class="list-item__desc">Spot low owned high scorers</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./fixture-difficulty">
                      <span class="list-item__copy">
                        <span class="list-item__title">Fixture Difficulty Ticker</span>
                        <span class="list-item__desc">See easy and hard fixture runs</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./mini-leagues">
                      <span class="list-item__copy">
                        <span class="list-item__title">Mini League Tracker</span>
                        <span class="list-item__desc">Watch rivals and rank swings</span>
                      </span>
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <div class="nav-item" data-nav-item>
            <button type="button" class="nav-trigger" data-nav-trigger aria-expanded="false" aria-haspopup="true">
              Reports
              <svg class="nav-caret" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nav-panel nav-panel--reports" data-nav-panel hidden>
              <div class="nav-company">
                <ul class="nav-list nav-list--stack">
                  <li>
                    <a class="list-item" href="./gameweek-recap">
                      <span class="list-item__copy">
                        <span class="list-item__title">Gameweek Recap</span>
                        <span class="list-item__desc">What just happened in FPL</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./price-changes">
                      <span class="list-item__copy">
                        <span class="list-item__title">Price Change Predictions</span>
                        <span class="list-item__desc">See who may rise or fall</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./fpl-injury-news">
                      <span class="list-item__copy">
                        <span class="list-item__title">Injury Risk Digest</span>
                        <span class="list-item__desc">Who may miss the next match</span>
                      </span>
                    </a>
                  </li>
                </ul>
                <ul class="nav-list nav-list--stack">
                  <li>
                    <a class="list-item" href="./players" aria-current="page">
                      <span class="list-item__copy">
                        <span class="list-item__title">Players</span>
                        <span class="list-item__desc">Search players and season stats</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./league-table">
                      <span class="list-item__copy">
                        <span class="list-item__title">League Table</span>
                        <span class="list-item__desc">See the table and recent form</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="/fpl-gameweek-tips" data-nav-gw-tips-link>
                      <span class="list-item__copy">
                        <span class="list-item__title" data-nav-gw-tips-label>Gameweek Tips</span>
                        <span class="list-item__desc">Picks and advice for this gameweek</span>
                      </span>
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <a data-pricing-nav class="nav-link" href="./join">Pricing</a>
          <a class="nav-link" href="./about">About</a>
          <a class="nav-link" href="./fpl-guides">FPL Guides</a>
          <a class="nav-link" href="./help-center">Help</a>
          <a class="nav-link" href="./contact">Contact</a>
        </div>

      <div class="site-nav__right desktop-only">
        <a class="mf-btn mf-btn--ink mf-btn--sm" href="./login" data-guest-only>
          <span class="mf-btn__label">Sign In</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <a class="mf-btn mf-btn--primary mf-btn--sm" href="./join" data-guest-only>
          <span class="mf-btn__label">Get Started</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <button type="button" class="mf-btn mf-btn--ink mf-btn--sm" data-auth-only data-sign-out hidden>
          <span class="mf-btn__label">Sign Out</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </button>
      </div>

      <button
        type="button"
        class="btn-nav btn-nav--icon mobile-only"
        data-mobile-toggle
        aria-expanded="false"
        aria-controls="mobile-menu"
        aria-label="Toggle menu"
      >
        <span class="menu-toggle-icon" data-menu-icon aria-hidden="true">
          <span></span><span></span><span></span>
        </span>
      </button>
    </nav>
  </header>

  <div id="mobile-menu" class="mobile-menu" data-mobile-menu hidden>
    <div class="mobile-menu__inner" data-mobile-inner>
      <div class="mobile-menu__scroll" data-mobile-scroll>
        <nav class="mobile-menu__nav" data-mobile-nav aria-label="Mobile navigation"></nav>
      </div>
      <div class="mobile-menu__actions">
        <a class="mf-btn mf-btn--ink mf-btn--block" href="./login" data-guest-only>
          <span class="mf-btn__label">Sign In</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <a class="mf-btn mf-btn--primary mf-btn--block" href="./join" data-guest-only>
          <span class="mf-btn__label">Get Started</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <button type="button" class="mf-btn mf-btn--ink mf-btn--block" data-auth-only data-sign-out hidden>
          <span class="mf-btn__label">Sign Out</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </button>
      </div>
    </div>
  </div>

  <div class="pp-shell">
    <section class="pp-hero" aria-labelledby="pp-hero-title">
      <div class="pp-hero__inner">
        <p class="pp-hero__eyebrow">Player Profiles</p>
        <h1 class="pp-hero__title" id="pp-hero-title">Every number behind a player, in one place</h1>
        <p class="pp-hero__lead">Form against expected points, the underlying Opta numbers ranked against the position, defensive contribution and what is coming up next.</p>

        <div class="pp-search">
          <svg class="pp-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <path d="M20 20l-3.5-3.5" stroke-linecap="round"></path>
          </svg>
          <label class="sr-only" for="pp-search-input">Search players</label>
          <input
            class="pp-search__input"
            id="pp-search-input"
            type="search"
            name="q"
            autocomplete="off"
            spellcheck="false"
            data-pp-search
          >
        </div>

        <div class="pp-owned">
          <span class="pp-owned__label">Most owned:</span>
          <div class="pp-owned__tags" data-pp-owned-tags aria-live="polite">
            <span class="pp-owned__loading">Loading most owned players</span>
          </div>
        </div>
      </div>
    </section>

    <main class="pp-main">
      <section class="pp-inform" aria-labelledby="pp-inform-title" data-pp-inform>
        <header class="pp-section-head">
          <div class="pp-section-head__copy">
            <h2 class="pp-section-title" id="pp-inform-title">In form right now</h2>
            <p class="pp-section-lead" data-pp-inform-lead>Most FPL points over the last 4 gameweeks</p>
          </div>
          <div class="pp-filters" role="group" aria-label="Filter by position" data-pp-filters>
            <button type="button" class="pp-filter is-active" data-pp-position="all" aria-pressed="true">All</button>
            <button type="button" class="pp-filter" data-pp-position="GK" aria-pressed="false">GK</button>
            <button type="button" class="pp-filter" data-pp-position="DEF" aria-pressed="false">DEF</button>
            <button type="button" class="pp-filter" data-pp-position="MID" aria-pressed="false">MID</button>
            <button type="button" class="pp-filter" data-pp-position="FWD" aria-pressed="false">FWD</button>
          </div>
        </header>

        <div class="pp-form-cards" data-pp-form-cards>
          <p class="pp-form-loading">Loading in-form players</p>
        </div>
      </section>

      <div class="pp-split" data-pp-split>
        <section class="pp-panel pp-recent" aria-labelledby="pp-recent-title" data-pp-recent>
          <h2 class="pp-panel__title" id="pp-recent-title">Recently viewed</h2>
          <div class="pp-recent-list" data-pp-recent-list></div>
        </section>

        <section class="pp-panel pp-profile-info" aria-labelledby="pp-profile-title">
          <h2 class="pp-panel__title" id="pp-profile-title">What is in a profile</h2>
          <ul class="pp-profile-list">
            <li>
              <h3>Form against expectation</h3>
              <p>Points next to expected points for every gameweek, so a hot streak is separable from a sustainable one.</p>
            </li>
            <li>
              <h3>The underlying numbers</h3>
              <p>Shots, big chances and key passes, each ranked against every other player in the same position.</p>
            </li>
            <li>
              <h3>Defensive contribution</h3>
              <p>How often a player clears the threshold that banks two points, and how close they get when they miss.</p>
            </li>
            <li>
              <h3>What is coming up</h3>
              <p>The next six fixtures with difficulty, predicted points and expected minutes.</p>
            </li>
          </ul>
        </section>
      </div>

      <section class="pp-clubs" aria-labelledby="pp-clubs-label">
        <h2 class="pp-clubs__label" id="pp-clubs-label">Browse by Club</h2>
        <div class="pp-clubs__grid" data-pp-clubs data-pp-clubs-ssr="1" aria-live="polite">
          <?php foreach ($ppClubs as $club): ?>
            <?php
              $clubPath = (string) ($club['path'] ?? '');
              $clubName = (string) ($club['name'] ?? $club['short_name'] ?? 'Club');
              $clubCode = (string) ($club['short_name'] ?? '');
              $clubBadge = (string) ($club['badge'] ?? '');
              if ($clubPath === '' || $clubCode === '') {
                  continue;
              }
            ?>
            <a
              class="pp-club"
              href="<?= pp_h($clubPath) ?>"
              title="<?= pp_h($clubName) ?>"
              data-pp-club="<?= pp_h($clubCode) ?>"
              data-pp-club-path="<?= pp_h($clubPath) ?>"
            >
              <?php if ($clubBadge !== ''): ?>
                <img class="pp-club__badge" src="<?= pp_h($clubBadge) ?>" alt="" width="28" height="28" loading="lazy" decoding="async">
              <?php endif; ?>
              <span class="pp-club__code"><?= pp_h($clubCode) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    </main>

  <section class="mf-explainer" data-mf-explainer aria-labelledby="mfx-title">
    <div class="container mf-explainer__shell">
      <aside class="mf-explainer__byline" aria-label="About this guide">
        <a class="mf-explainer__author" href="/author/chosen-esiwe">
          <img class="mf-explainer__avatar" src="/images/authors/chosen-esiwe.jpg" alt="" width="48" height="48" loading="lazy" decoding="async">
          <span class="mf-explainer__who">
            <span class="mf-explainer__name">Chosen Esiwe</span>
            <span class="mf-explainer__role">Founder, Metro Football</span>
          </span>
        </a>
        <ul class="mf-explainer__facts">
          <li><strong>Reviewed</strong> <time datetime="2026-10-05">5 October 2026</time></li>
          <li><strong>Source</strong> Player data from the official Fantasy Premier League feed, with underlying numbers from Opta</li>
        </ul>
      </aside>
      <article class="mf-explainer__body">
        <h2 class="mf-explainer__title" id="mfx-title">How to use Metro Football player profiles</h2>
        <p class="mf-explainer__lede">Points tell you what a player has done. Our profiles try to tell you what he is likely to do next. Each one puts form, underlying numbers and fixtures on one page so you can judge a player properly before he joins your team.</p>
        <h3>What is in each profile</h3>
        <p>Every player in Fantasy Premier League has a profile. You get his price, ownership and season points, his points per game and how long he plays when he starts. Form sits next to expected points for every gameweek, which helps you tell a lucky run from one that can last. His shots, big chances and key passes are ranked against every other player in his position. There is a record of how often he reaches the defensive contribution mark that adds two points, and his next six fixtures come with difficulty, predicted points and expected minutes.</p>
        <h3>How to judge a player in a few minutes</h3>
        <ol class="mf-explainer__steps">
        <li><strong>Start with minutes.</strong> A player who starts every week beats a better one who keeps getting rotated.</li>
        <li><strong>Compare points with expected points.</strong> A big gap above expected often shrinks. A gap below can mean points are on the way.</li>
        <li><strong>Use the position ranks, not raw totals.</strong> A midfielder in the top ten for big chances is getting into the right areas.</li>
        <li><strong>Check the next six fixtures before you buy.</strong> A strong player facing a hard run might be worth buying a little later.</li>
        <li><strong>Use compare.</strong> Put him next to the alternative you are weighing up and let the numbers settle it.</li>
        </ol>
        <h3>Browsing by club</h3>
        <p>The club pages list every squad member by position, most owned first, so you can see the popular picks at a glance and find cheaper options in the same team. The in form list at the top of this page shows who has scored the most over the last four gameweeks. For a plain English guide to the numbers in each profile, read <a href="/fpl-guides/fpl-stats-explained-12-numbers-every-manager-should-understand">12 FPL stats every manager should understand</a>.</p>
        <p class="mf-explainer__note">Profiles update as each gameweek is played. If you want to know who takes penalties and corners, our <a href="/fpl-guides/premier-league-set-piece-takers-2026-27">set piece takers guide</a> pairs well with any profile.</p>
      </article>
    </div>
  </section>

  </div>

  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-brand">
        <a class="brand" href="./">
          <img class="site-logo__img site-logo__img--light" src="./images/metro-football-logo-light-315.webp?v=20260830logo" srcset="./images/metro-football-logo-light-315.webp?v=20260830logo 315w, ./images/metro-football-logo-light-630.webp?v=20260830logo 630w" sizes="(min-width: 768px) 204px, 180px" alt="" width="938" height="313" decoding="async">
        </a>
        <p>Metro Football builds FPL tools and auto reports for managers who want clear answers before the deadline.</p>
        <div class="mf-social mf-social--footer" aria-label="Follow Metro Football">
          <p class="mf-social__label">Follow us</p>
          <ul class="mf-social__list">
            <li>
              <a class="mf-social__link" href="https://web.facebook.com/metrofootballuk/" aria-label="Metro Football on Facebook" target="_blank" rel="noopener noreferrer">
                <svg class="mf-social__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 22v-8.7h2.9l.44-3.4h-3.34V7.9c0-.98.27-1.65 1.68-1.65h1.8V3.1c-.31 0-1.38-.13-2.63-.13-2.6 0-4.38 1.58-4.38 4.48v2.5H7.5v3.4h3.09V22h3.91Z"/></svg>
              </a>
            </li>
            <li>
              <a class="mf-social__link" href="https://x.com/metrofootballuk" aria-label="Metro Football on X" target="_blank" rel="noopener noreferrer">
                <svg class="mf-social__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.687 3H20.5l-7.43 8.49L21.5 21h-6.05l-4.74-5.52L5.2 21H2.38l7.94-9.08L2.5 3h6.2l4.28 4.98L17.687 3Zm-2.12 16.2h1.72L8.57 4.67H6.73l8.837 14.53Z"/></svg>
              </a>
            </li>
            <li>
              <a class="mf-social__link" href="https://www.instagram.com/metrofootballuk/" aria-label="Metro Football on Instagram" target="_blank" rel="noopener noreferrer">
                <svg class="mf-social__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7.8 2h8.4A5.8 5.8 0 0 1 22 7.8v8.4A5.8 5.8 0 0 1 16.2 22H7.8A5.8 5.8 0 0 1 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2Zm0 2A3.8 3.8 0 0 0 4 7.8v8.4A3.8 3.8 0 0 0 7.8 20h8.4a3.8 3.8 0 0 0 3.8-3.8V7.8A3.8 3.8 0 0 0 16.2 4H7.8Zm9.65 1.5a1.05 1.05 0 1 1 0 2.1 1.05 1.05 0 0 1 0-2.1ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/></svg>
              </a>
            </li>
          </ul>
        </div>
      </div>
      <div class="footer-col">
        <h4>Explore</h4>
        <a href="./">Home</a>
        <a href="./players">Players</a>
        <a href="./league-table">League Table</a>
        <a href="./live">Dashboard</a>
        <a href="./join">Upgrade</a>
      </div>
      <div class="footer-col">
        <h4>Tools</h4>
        <a href="./fixture-difficulty">Fixture Difficulty Ticker</a>
        <a href="./ai-tips#snipers">Differential Finder</a>
        <a href="./mini-leagues">Mini League Tracker</a>
        <a href="./pick">Rate My Team</a>
        <a href="./ai-tips">Transfer Planner</a>
        <a href="./ai-tips">AI Transfers</a>
        <a href="./chip-strategy">Chip Strategy Planner</a>
      </div>
    </div>
    <div class="container footer-bottom">
      <span>© 2026 Metro Football. metrofootball.co.uk</span>
      <span>
        <a href="./privacy-policy">Privacy Policy</a>
        ·
        <a href="./terms">Terms of Service</a>
        ·
        <a href="./disclaimer">Disclaimer</a>
        ·
        <a href="./about">About Us</a>
        ·
        <a href="./contact">Contact Us</a>
      </span>
    </div>
  </footer>

  <script defer src="./js/gtag-config.js?v=2"></script>
  <script type="module" src="./js/players.js?v=20260906clubhub"></script>
  <script type="module" src="/js/tool-guide.js?v=20261005mfx2"></script>
</body>
</html>
