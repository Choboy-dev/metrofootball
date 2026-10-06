<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\AuthService;
use App\BillingService;
use App\PlayerProfileService;
use App\PlayerScoutReport;
use App\Session;

Session::start();

function ppf_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ppf_gate(string $featureTitle, string $lead): string
{
    $title = ppf_h($featureTitle) . ' is a <u>member feature</u>';

    return <<<HTML
<div class="ppf-gate" data-ppf-premium>
  <span class="ppf-gate__lock" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>
  </span>
  <p class="ppf-gate__title">{$title}</p>
  <p class="ppf-gate__lead">{$lead}</p>
  <button type="button" class="mf-btn mf-btn--primary ppf-gate__cta" data-ppf-premium>
    <span class="mf-btn__label">Unlock with membership</span>
    <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
  </button>
</div>
HTML;
}

function ppf_is_premium_user(?array $user): bool
{
    return is_array($user) && BillingService::isPremiumActive($user);
}

function ppf_filter_lock_markup(bool $locked): string
{
    if (!$locked) {
        return '';
    }

    return ' <svg class="ppf-filter-group__lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
}

function ppf_availability_risk(array $player): ?string
{
    $status = strtolower((string) ($player['status'] ?? 'a'));
    $chance = $player['chance_of_playing_next_round'] ?? null;
    if (in_array($status, ['i', 's', 'u'], true) || $chance === 0 || $chance === '0') {
        return 'out';
    }
    if ($status === 'd' || ($chance !== null && $chance !== '' && (int) $chance < 100)) {
        return 'doubt';
    }

    return null;
}

function ppf_status_tier(array $player): string
{
    $risk = ppf_availability_risk($player);
    if ($risk === 'out') {
        return 'out';
    }
    if ($risk === 'doubt') {
        return 'doubt';
    }

    return 'fit';
}

function ppf_status_icon(array $player): string
{
    $tier = ppf_status_tier($player);
    $news = (string) ($player['news'] ?? '');
    $label = match ($tier) {
        'out' => (string) ($player['status_label'] ?? 'Unavailable'),
        'doubt' => 'Doubtful',
        default => 'Fit',
    };
    $title = ppf_h($news !== '' ? $news : $label);
    $aria = ppf_h($label);

    $class = match ($tier) {
        'out' => 'ppf-status-icon--out',
        'doubt' => 'ppf-status-icon--doubt',
        default => 'ppf-status-icon--fit',
    };

    $inner = $tier === 'fit'
        ? '<circle cx="12" cy="8.5" r="1.15" fill="#fff"/><path d="M12 11v5" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
        : '<path d="M12 8v4M12 16h.01" stroke="#fff" stroke-width="2" stroke-linecap="round"/>';

    return '<span class="ppf-status-icon ' . $class . '" title="' . $title . '" aria-label="' . $aria . '"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2 22 20H2L12 2z"/>' . $inner . '</svg></span>';
}

function ppf_injury_icon(?string $risk, string $news = ''): string
{
    if ($risk === null) {
        return '';
    }

    $label = $risk === 'out' ? 'Unavailable' : 'Doubtful';
    $title = ppf_h($news !== '' ? $news : $label);

    return '<span class="ppf-fixture__warn ppf-fixture__warn--' . ppf_h($risk) . '" title="' . $title . '" aria-label="' . ppf_h($label) . '"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg></span>';
}

function ppf_log_row_played(array $row, bool $isGoalkeeper): bool
{
    $minutes = (int) ($row['minutes'] ?? 0);
    if ($minutes < 1) {
        return false;
    }

    if ($isGoalkeeper) {
        return (int) ($row['starts'] ?? 0) > 0;
    }

    return true;
}

function ppf_log_cell(string $value, bool $played): string
{
    return ppf_h($played ? $value : '—');
}

function ppf_log_row_html(array $row, bool $isGoalkeeper): string
{
    $played = ppf_log_row_played($row, $isGoalkeeper);
    $html = '<tr>';
    $html .= '<td class="ppf-log__sticky ppf-log__sticky--gw">' . ppf_h((string) ($row['gw'] ?? '')) . '</td>';
    $html .= '<td class="ppf-log__sticky ppf-log__sticky--opp">' . ppf_h((string) ($row['opponent_label'] ?? '')) . '</td>';
    $html .= '<td>' . ppf_log_cell((string) ($row['minutes'] ?? ''), $played) . '</td>';
    $html .= '<td>' . ppf_log_cell((string) ($row['goals'] ?? ''), $played) . '</td>';
    $html .= '<td>' . ppf_log_cell((string) ($row['assists'] ?? ''), $played) . '</td>';

    if ($isGoalkeeper) {
        $cs = (int) ($row['clean_sheets'] ?? 0);
        $html .= '<td>' . ppf_log_cell((string) $cs, $played) . '</td>';
    } else {
        $html .= '<td>' . ppf_log_cell(number_format((float) ($row['xg'] ?? 0), 2), $played) . '</td>';
        $html .= '<td>' . ppf_log_cell(number_format((float) ($row['xa'] ?? 0), 2), $played) . '</td>';
    }

    $html .= '<td class="ppf-log__pts">' . ppf_log_cell((string) ($row['points'] ?? ''), $played) . '</td>';
    $html .= '</tr>';

    return $html;
}

$club = trim((string) ($_GET['club'] ?? ''));
$player = trim((string) ($_GET['player'] ?? ''));

try {
    $profile = PlayerProfileService::resolve($club, $player);
} catch (Throwable) {
    $profile = null;
}

if (!is_array($profile)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><title>Player not found | Metro Football</title><base href="/"><link rel="stylesheet" href="/css/app.css"></head><body><main class="container" style="padding:3rem 1rem"><h1>Player not found</h1><p><a href="/players">Back to player profiles</a></p></main></body></html>';
    exit;
}

try {
    $scoutReport = PlayerScoutReport::forPlayer($profile);
} catch (Throwable) {
    $scoutReport = null;
}

$ppfUser = null;
$ppfPremium = false;
$ppfUserId = Session::userId();
if ($ppfUserId) {
    $ppfUser = AuthService::findUserById($ppfUserId);
    $ppfPremium = ppf_is_premium_user($ppfUser);
}
$ppfFilterLocked = !$ppfPremium;
$ppfFilterLockedAttr = $ppfFilterLocked ? 'true' : 'false';
$ppfFilterBtnLockedClass = $ppfFilterLocked ? ' ppf-filter-btn--locked' : '';
$ppfPremiumAttr = $ppfPremium ? ' data-ppf-premium="1"' : '';
$ppfBodyClass = 'player-profile-page'
    . ($ppfPremium ? ' ppf-is-premium is-premium is-authenticated' : '');

$p = $profile['player'];
$t = $profile['team'];
$meta = $profile['filter_meta'];
$chart = $profile['points_vs_expected'];
$log = $profile['gameweek_log'];
$fixtures = $profile['fixtures_next'];
$explainer = (string) ($profile['explainer'] ?? '');
$compare = $profile['compare_with'];
$mates = $profile['club_mates'];
$seasons = $profile['seasons'];
$showDefensiveContribution = in_array((int) ($p['pos'] ?? 0), [2, 3], true);
$isGoalkeeper = (int) ($p['pos'] ?? 0) === 1;
$currentSeasonLabel = ppf_h((string) ($meta['season'] ?? ($seasons[0] ?? '')));
$seasonDisplay = '<span class="ppf-filter-btn is-active ppf-filter-btn--static" aria-current="true">' . $currentSeasonLabel . '</span>';
$canonical = 'https://metrofootball.co.uk' . ($profile['profile_path'] ?? '');
$pageTitle = ppf_h($p['web_name'] . ' FPL Stats & Profile | ' . $t['name'] . ' | Metro Football');
$desc = ppf_h($p['web_name'] . ' FPL stats for ' . $t['name'] . ': price £' . number_format((float) $p['price'], 1) . 'm, ' . number_format((float) $p['ownership'], 1) . '% owned, ' . (int) $p['total_points'] . ' season points.');
$face = $p['photo'] ?: $p['shirt'];
$initial = ppf_h(substr((string) $p['web_name'], 0, 1));
$maxChart = 1.0;
foreach ($chart as $row) {
    $maxChart = max($maxChart, (float) ($row['points'] ?? 0), (float) ($row['expected_points'] ?? 0));
}

$chartHtml = '';
foreach ($chart as $row) {
    $pts = (int) ($row['points'] ?? 0);
    $exp = round((float) ($row['expected_points'] ?? 0), 1);
    $ptsH = max(4, (int) round(($pts / $maxChart) * 100));
    $expH = max(4, (int) round(($exp / $maxChart) * 100));
    $expLabel = fmod($exp, 1.0) === 0.0 ? (string) (int) $exp : number_format($exp, 1);
    $chartHtml .= '<div class="ppf-chart__col">';
    $chartHtml .= '<div class="ppf-chart__value">';
    $chartHtml .= '<span class="ppf-chart__value-pts" title="Points">' . ppf_h((string) $pts) . '</span>';
    $chartHtml .= '<span class="ppf-chart__value-exp" title="Expected points">' . ppf_h($expLabel) . '</span>';
    $chartHtml .= '</div>';
    $chartHtml .= '<div class="ppf-chart__bars">';
    $chartHtml .= '<span class="ppf-chart__bar ppf-chart__bar--points" style="height:' . $ptsH . '%"></span>';
    $chartHtml .= '<span class="ppf-chart__bar ppf-chart__bar--expected" style="height:' . $expH . '%"></span>';
    $chartHtml .= '</div>';
    $chartHtml .= '<div class="ppf-chart__label"><strong>' . ppf_h($row['label'] ?? '') . '</strong>GW' . ppf_h((string) ($row['gw'] ?? '')) . '</div>';
    $chartHtml .= '</div>';
}

$logRows = '';
foreach ($log as $row) {
    $logRows .= ppf_log_row_html($row, $isGoalkeeper);
}
$logColspan = $isGoalkeeper ? 7 : 8;

$fixtureCards = '';
$fixtureRisk = ppf_availability_risk($p);
$fixtureNews = (string) ($p['news'] ?? '');
foreach ($fixtures as $fx) {
    $fdr = max(1, min(5, (int) ($fx['difficulty'] ?? 3)));
    $pred = $fx['predicted_points'] ?? null;
    $predDisplay = is_numeric($pred) ? number_format((float) $pred, 1) : '—';
    $injuryIcon = ppf_injury_icon($fixtureRisk, $fixtureNews);
    $fixtureCards .= '<article class="ppf-fixture">';
    $fixtureCards .= '<div class="ppf-fixture__fdr mf-fdr--' . $fdr . '"></div>';
    $fixtureCards .= '<div class="ppf-fixture__body">';
    $fixtureCards .= '<div class="ppf-fixture__gw">GW ' . ppf_h((string) ($fx['gw'] ?? '')) . '</div>';
    $fixtureCards .= '<div class="ppf-fixture__opp">' . ppf_h($fx['label'] ?? '') . '</div>';
    $fixtureCards .= '<div class="ppf-fixture__pred" data-ppf-fixture-pred>' . ppf_h($predDisplay) . '</div>';
    $fixtureCards .= '<div class="ppf-fixture__sub">Pred pts</div>';
    if ($injuryIcon !== '') {
        $fixtureCards .= $injuryIcon;
    }
    $fixtureCards .= '</div></article>';
}

$profileJson = json_encode($profile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($profileJson)) {
    $profileJson = '{}';
}

$compareHtml = '';
foreach ($compare as $item) {
    $compareHtml .= '<a class="ppf-link-pill" href="' . ppf_h($item['path']) . '">' . ppf_h($p['web_name'] . ' vs ' . $item['label']) . '</a>';
}

$matesHtml = '';
foreach ($mates as $item) {
    $matesHtml .= '<a class="ppf-link-pill" href="' . ppf_h($item['path']) . '">' . ppf_h($item['label']) . '</a>';
}

$filterMetaLine = ppf_h(implode(' · ', array_filter([
    $meta['season'] ?? '',
    $meta['venue'] ?? '',
    $meta['window'] ?? '',
    $meta['basis'] ?? '',
    $meta['shown'] ?? '',
])));

$faceHtml = $face
    ? '<img class="ppf-hero__img' . ($p['photo'] ? '' : ' ppf-hero__img--shirt') . '" src="' . ppf_h($face) . '" alt="" width="96" height="96" decoding="async" data-ppf-photo data-ppf-shirt="' . ppf_h((string) ($p['shirt'] ?? '')) . '" data-ppf-initial="' . $initial . '">'
    : '<span class="ppf-hero__fallback" aria-hidden="true">' . $initial . '</span>';

$optaStatsUrl = (string) ($p['opta_stats_url'] ?? '');
$optaStatsHref = $optaStatsUrl !== '' ? ppf_h($optaStatsUrl) : '#';
$optaStatsDisabled = $optaStatsUrl === '' ? ' aria-disabled="true"' : '';

?><!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/meta-pixel.php'; ?>
  <?php include __DIR__ . '/partials/google-adsense.php'; ?>
  <base href="/">
  <link rel="stylesheet" href="/css/app.css?v=20260830social2">
  <title><?= $pageTitle ?></title>
  <meta name="description" content="<?= $desc ?>">
  <link rel="canonical" href="<?= ppf_h($canonical) ?>">
  <link rel="icon" href="/images/metro-football-favicon.png" type="image/png" sizes="500x500">
  <meta property="og:type" content="profile">
  <meta property="og:site_name" content="Metro Football">
  <meta property="og:title" content="<?= $pageTitle ?>">
  <meta property="og:description" content="<?= $desc ?>">
  <meta property="og:url" content="<?= ppf_h($canonical) ?>">
  <?php if ($p['photo']): ?>
  <meta property="og:image" content="<?= ppf_h((string) $p['photo']) ?>">
  <?php endif; ?>
  <script src="/js/perf-head.js?v=20260830perf" data-page-css="/css/player-profile.css?v=20260906xpts"></script>
  <noscript>
    <link rel="stylesheet" href="/css/fonts.css?v=20260830perf">
    <link rel="stylesheet" href="/css/perf.css?v=20260830perf">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/player-profile.css?v=20260906xpts">
  </noscript>
  <link rel="stylesheet" href="/css/scout-report.css?v=20260929scout">
  <?php if ($ppfPremium): ?>
  <style>
    .ppf-gate[data-ppf-premium],
    .ppf-gate__panel[data-ppf-premium],
    .ppf-filter-group__lock { display: none !important; }
    [data-ppf-premium-content] { display: block !important; }
    .ppf-gate--overlay .ppf-fixtures-grid { filter: none !important; pointer-events: auto !important; user-select: auto !important; }
    .ppf-gate--overlay .ppf-gate__panel { display: none !important; }
  </style>
  <?php endif; ?>
  <link rel="modulepreload" href="/js/site-nav.js?v=20260907pub">
  <link rel="modulepreload" href="/js/auth.js?v=20260907pub">
  <link rel="modulepreload" href="/js/player-profile.js?v=20260906xpts">
</head>
<body class="<?= ppf_h($ppfBodyClass) ?>" data-player-id="<?= (int) $p['id'] ?>"<?= $ppfPremiumAttr ?>>
  <?php include __DIR__ . '/partials/site-header.php'; ?>

  <div class="ppf-shell">
    <nav class="ppf-crumb" aria-label="Breadcrumb">
      <a href="/players">Player profiles</a><span class="ppf-crumb__sep">/</span><a href="<?= ppf_h(PlayerProfileService::clubHubPath((string) ($t['slug'] ?? $t['name'] ?? ''))) ?>"><?= ppf_h($t['name']) ?></a><span class="ppf-crumb__sep">/</span><span aria-current="page"><?= ppf_h($p['web_name']) ?></span>
    </nav>

    <section class="ppf-hero-panel" aria-labelledby="ppf-player-name">
      <div class="ppf-hero-panel__grid">
        <div class="ppf-search-block ppf-hero-panel__search">
          <label class="ppf-search-block__label" for="ppf-search">Search for another player</label>
          <div class="ppf-search">
            <svg class="ppf-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5" stroke-linecap="round"/></svg>
            <input class="ppf-search__input" id="ppf-search" type="search" placeholder="Search players" autocomplete="off" data-ppf-search-input>
            <div class="ppf-search__panel" data-ppf-search-panel hidden></div>
          </div>
        </div>

        <div class="ppf-hero__identity">
          <div class="ppf-hero__face"><?= $faceHtml ?></div>
          <div class="ppf-hero__copy">
            <h1 class="ppf-hero__name" id="ppf-player-name"><?= ppf_h($p['web_name']) ?></h1>
            <?php if ($p['full_name']): ?><p class="ppf-hero__full"><?= ppf_h($p['full_name']) ?></p><?php endif; ?>
            <div class="ppf-hero__pills">
              <span class="ppf-pill ppf-pill--muted"><?= ppf_h($p['pos_label']) ?></span>
              <span class="ppf-pill ppf-pill--muted"><?= ppf_h($t['name']) ?></span>
              <span class="ppf-pill ppf-pill--price">£<?= ppf_h(number_format((float) $p['price'], 1)) ?>m</span>
              <?= ppf_status_icon($p) ?>
            </div>
            <div class="ppf-hero__actions">
              <button type="button" class="mf-btn mf-btn--primary"<?= $ppfPremium ? '' : ' data-ppf-premium' ?> data-ppf-compare-action>
                <span class="mf-btn__label">Compare with…</span>
                <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              </button>
              <a class="mf-btn mf-btn--outline" href="<?= $optaStatsHref ?>" target="_blank" rel="noopener noreferrer" data-ppf-opta-action<?= $optaStatsDisabled ?><?= $ppfPremium ? '' : ' data-ppf-premium' ?>>
                <span class="mf-btn__label">Full OPTA stats</span>
                <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              </a>
            </div>
          </div>
        </div>

        <div class="ppf-summary ppf-hero-panel__stats" aria-label="Season summary">
          <div class="ppf-summary__cell"><span class="ppf-summary__label">Season points</span><span class="ppf-summary__value"><?= (int) $p['total_points'] ?></span></div>
          <div class="ppf-summary__cell"><span class="ppf-summary__label">Points / game</span><span class="ppf-summary__value"><?= ppf_h(number_format((float) $p['points_per_game'], 2)) ?></span></div>
          <div class="ppf-summary__cell"><span class="ppf-summary__label">Ownership</span><span class="ppf-summary__value"><?= ppf_h(number_format((float) $p['ownership'], 1)) ?>%</span></div>
          <div class="ppf-summary__cell"><span class="ppf-summary__label">Minutes / start</span><span class="ppf-summary__value"><?= (int) $p['minutes_per_start'] ?></span></div>
        </div>
      </div>
    </section>

    <div class="ppf-hero-panel__filters ppf-sticky-wrap" data-ppf-filter-strip>
        <div class="ppf-filters" role="group" aria-label="Profile filters">
        <div class="ppf-filter-group" data-ppf-filter-group>
          <p class="ppf-filter-group__label">Season</p>
          <div class="ppf-filter-group__options"><?= $seasonDisplay ?></div>
        </div>
        <div class="ppf-filter-group" data-ppf-filter-group data-ppf-locked="<?= ppf_h($ppfFilterLockedAttr) ?>">
          <p class="ppf-filter-group__label">Venue<?= ppf_filter_lock_markup($ppfFilterLocked) ?></p>
          <div class="ppf-filter-group__options">
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?> is-active" data-ppf-filter="venue" data-ppf-value="all" aria-pressed="true">All</button>
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?>" data-ppf-filter="venue" data-ppf-value="home" aria-pressed="false">Home</button>
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?>" data-ppf-filter="venue" data-ppf-value="away" aria-pressed="false">Away</button>
          </div>
        </div>
        <div class="ppf-filter-group" data-ppf-filter-group data-ppf-locked="<?= ppf_h($ppfFilterLockedAttr) ?>">
          <p class="ppf-filter-group__label">Window<?= ppf_filter_lock_markup($ppfFilterLocked) ?></p>
          <div class="ppf-filter-group__options">
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?> is-active" data-ppf-filter="window" data-ppf-value="season" aria-pressed="true">Season</button>
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?>" data-ppf-filter="window" data-ppf-value="last6" aria-pressed="false">Last 6</button>
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?>" data-ppf-filter="window" data-ppf-value="last3" aria-pressed="false">Last 3</button>
          </div>
        </div>
        <div class="ppf-filter-group ppf-filter-group--basis" data-ppf-filter-group data-ppf-locked="<?= ppf_h($ppfFilterLockedAttr) ?>">
          <p class="ppf-filter-group__label">Basis<?= ppf_filter_lock_markup($ppfFilterLocked) ?></p>
          <div class="ppf-filter-group__options">
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?> is-active" data-ppf-filter="basis" data-ppf-value="per90" aria-pressed="true">Per 90</button>
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?>" data-ppf-filter="basis" data-ppf-value="per_game" aria-pressed="false">Per game</button>
            <button type="button" class="ppf-filter-btn<?= ppf_h($ppfFilterBtnLockedClass) ?>" data-ppf-filter="basis" data-ppf-value="total" aria-pressed="false">Total</button>
          </div>
        </div>
      </div>
    </div>

    <div class="ppf-chart-row">
    <section class="ppf-card ppf-chart-card" aria-labelledby="ppf-chart-title">
      <div class="ppf-card__head ppf-chart-card__head">
        <div class="ppf-chart-card__intro">
          <h2 class="ppf-card__title" id="ppf-chart-title">Points vs expected points</h2>
          <p class="ppf-card__meta" data-ppf-filter-meta><?= $filterMetaLine ?></p>
        </div>
        <div class="ppf-chart-legend" aria-hidden="true">
          <span class="ppf-chart-legend__item"><span class="ppf-chart-legend__swatch ppf-chart-legend__swatch--points"></span>Points</span>
          <span class="ppf-chart-legend__item"><span class="ppf-chart-legend__swatch ppf-chart-legend__swatch--expected"></span>Expected points</span>
        </div>
      </div>
      <div class="ppf-card__body ppf-chart-card__body">
        <div class="ppf-chart" data-ppf-chart><?= $chartHtml ?: '<p class="ppf-card__lead">No gameweek data yet this season.</p>' ?></div>
      </div>
    </section>

    <section class="ppf-card ppf-gate-card" aria-labelledby="ppf-over-title">
      <div class="ppf-card__head">
        <h2 class="ppf-card__title" id="ppf-over-title">Over / underperformance</h2>
      </div>
      <?php if (!$ppfPremium): ?>
      <?= ppf_gate('Over / underperformance', 'See whether returns are running ahead of or behind expected points, goals and assists.') ?>
      <?php endif; ?>
      <div class="ppf-over" data-ppf-premium-content<?= $ppfPremium ? '' : ' hidden' ?>></div>
    </section>
    </div>

    <?php if ($isGoalkeeper): ?>
    <section class="ppf-card" aria-labelledby="ppf-shot-title">
      <div class="ppf-card__head">
        <h2 class="ppf-card__title" id="ppf-shot-title">Shot stopping and defence</h2>
        <p class="ppf-card__lead">Clean sheets, expected goals conceded and saves.</p>
      </div>
      <?php if (!$ppfPremium): ?>
      <?= ppf_gate('Shot stopping and defence', 'See clean sheets, expected goals conceded and saves ranked against other goalkeepers.') ?>
      <?php endif; ?>
      <div class="ppf-threat" data-ppf-premium-content<?= $ppfPremium ? '' : ' hidden' ?>></div>
    </section>
    <?php else: ?>
    <section class="ppf-card" aria-labelledby="ppf-attack-title">
      <div class="ppf-card__head">
        <h2 class="ppf-card__title" id="ppf-attack-title">Attacking threat</h2>
        <p class="ppf-card__lead">Chance creation and goal threat</p>
      </div>
      <?php if (!$ppfPremium): ?>
      <?= ppf_gate('Attacking threat', 'See every underlying number ranked against other ' . ppf_h($p['pos_label']) . ' players with enough minutes to compare.') ?>
      <?php endif; ?>
      <div class="ppf-threat" data-ppf-premium-content<?= $ppfPremium ? '' : ' hidden' ?>></div>
    </section>
    <?php endif; ?>

    <?php if ($showDefensiveContribution): ?>
    <section class="ppf-card" aria-labelledby="ppf-def-title">
      <div class="ppf-card__head">
        <h2 class="ppf-card__title" id="ppf-def-title">Defensive contribution</h2>
      </div>
      <?php if (!$ppfPremium): ?>
      <?= ppf_gate('Defensive contribution', 'See defensive contribution for every gameweek in your filter.') ?>
      <?php endif; ?>
      <div class="ppf-def" data-ppf-premium-content<?= $ppfPremium ? '' : ' hidden' ?>></div>
    </section>
    <?php endif; ?>

    <section class="ppf-card ppf-gate--overlay<?= $ppfFilterLocked ? ' ppf-gate--locked' : '' ?>" aria-labelledby="ppf-fixtures-title">
      <div class="ppf-card__head ppf-fixtures-head">
        <div>
          <h2 class="ppf-card__title" id="ppf-fixtures-title">Next six gameweeks</h2>
          <p class="ppf-card__lead">Predicted points from the Metro Football model.</p>
        </div>
        <div class="ppf-fixtures-legend">
          <span>Easier</span>
          <span class="ppf-fixtures-legend__bar"><span style="background:#22c55e"></span><span style="background:#86efac"></span><span style="background:#fde047"></span><span style="background:#fb923c"></span><span style="background:#ef4444"></span></span>
          <span>Harder</span>
          <a class="ppf-fixtures-link" href="/fixture-difficulty">Fixture analyser →</a>
        </div>
      </div>
      <div class="ppf-card__body">
        <div class="ppf-fixtures-grid" data-ppf-fixtures><?= $fixtureCards ?></div>
        <?php if (!$ppfPremium): ?>
        <div class="ppf-gate__panel" data-ppf-premium>
          <span class="ppf-gate__lock" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
          <p class="ppf-gate__title">Predicted points are a <u>member feature</u></p>
          <p class="ppf-gate__lead">See the model's predicted points and expected minutes for every upcoming fixture.</p>
          <button type="button" class="mf-btn mf-btn--primary ppf-gate__cta" data-ppf-premium>
            <span class="mf-btn__label">Unlock with membership</span>
            <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="ppf-card" aria-labelledby="ppf-log-title">
      <div class="ppf-card__head">
        <h2 class="ppf-card__title" id="ppf-log-title">Gameweek log</h2>
        <p class="ppf-card__lead">Every gameweek with minutes, returns and points.</p>
      </div>
      <div class="ppf-card__body ppf-log-wrap">
        <table class="ppf-log" data-ppf-log-table>
          <thead>
            <tr>
              <th scope="col" class="ppf-log__sticky ppf-log__sticky--gw">GW</th>
              <th scope="col" class="ppf-log__sticky ppf-log__sticky--opp">Opponent</th>
              <th scope="col">Mins</th>
              <th scope="col">G</th>
              <th scope="col">A</th>
              <?php if ($isGoalkeeper): ?>
              <th scope="col">CS</th>
              <?php else: ?>
              <th scope="col">xG</th>
              <th scope="col">xA</th>
              <?php endif; ?>
              <th scope="col">Points</th>
            </tr>
          </thead>
          <tbody data-ppf-log-body><?= $logRows ?: '<tr><td colspan="' . $logColspan . '">No gameweeks yet this season.</td></tr>' ?></tbody>
        </table>
      </div>
    </section>

    <?php $scoutTitle = 'Our verdict on ' . $p['web_name']; include __DIR__ . '/partials/scout-report.php'; ?>

    <section class="ppf-card ppf-split-card" aria-labelledby="ppf-explainer-title">
      <div class="ppf-explainer">
        <h2 class="ppf-explainer__title" id="ppf-explainer-title"><?= ppf_h($p['web_name']) ?> FPL stats explained</h2>
        <p class="ppf-explainer__text" data-ppf-explainer><?= ppf_h($explainer) ?></p>
      </div>
      <div class="ppf-explainer">
        <?php if ($compareHtml): ?>
        <div class="ppf-link-group">
          <p class="ppf-link-group__label">Compare with</p>
          <div class="ppf-link-pills"><?= $compareHtml ?></div>
        </div>
        <?php endif; ?>
        <?php if ($matesHtml): ?>
        <div class="ppf-link-group">
          <p class="ppf-link-group__label">More <?= ppf_h($t['name']) ?> profiles</p>
          <div class="ppf-link-pills"><?= $matesHtml ?></div>
        </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="ppf-modal" data-ppf-modal hidden role="dialog" aria-modal="true" aria-labelledby="ppf-modal-title">
    <div class="ppf-modal__backdrop" data-ppf-modal-backdrop></div>
    <div class="ppf-modal__dialog">
      <div class="ppf-modal__banner">
        <div class="ppf-modal__banner-copy">
          <span class="ppf-modal__gem" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5 3.5 9.2 12 21.5 20.5 9.2 12 2.5Zm0 2.4 5.4 4.3L12 18.2 6.6 9.2 12 4.9Z"/></svg></span>
          <p class="ppf-modal__banner-text">Exclusive Content · For members only</p>
        </div>
        <button type="button" class="ppf-modal__close" data-ppf-modal-close aria-label="Close">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round"/></svg>
        </button>
      </div>
      <div class="ppf-modal__body">
        <h2 class="ppf-modal__title" id="ppf-modal-title">Unlock the player profile tool 🔓</h2>
        <p class="ppf-modal__lead">Upgrade for access to this tool</p>
        <button type="button" class="mf-btn mf-btn--primary" data-ppf-modal-upgrade>
          <span class="mf-btn__label">Upgrade</span>
          <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </button>
      </div>
    </div>
  </div>

  <script type="application/json" id="ppf-data"><?= $profileJson ?></script>
  <?php include __DIR__ . '/partials/site-footer.php'; ?>
  <script defer src="/js/gtag-config.js?v=2"></script>
  <script type="module" src="/js/mobile-menu.js?v=20260907pub"></script>
  <script type="module" src="/js/player-profile.js?v=20260906xpts"></script>
  <script type="module" src="/js/scout-report.js?v=20260929scout"></script>
</body>
</html>
