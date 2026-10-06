import { initSiteNav } from './site-nav.js?v=20260907pub';
import { syncHeaderHeight } from './sticky-aside.js?v=20260830q';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  isPremiumActive,
  pageUrl,
  apiUrl,
} from './auth.js?v=20260907pub';

const state = {
  premium: false,
  upgradePath: '/join',
  profile: null,
  filters: {
    venue: 'all',
    window: 'season',
    basis: 'per90',
  },
};

function premiumUpgradePath() {
  return pageUrl('join');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatNumber(value, decimals = 2) {
  const num = Number(value);
  if (!Number.isFinite(num)) return '0';
  return num.toFixed(decimals);
}

function loadProfileData() {
  const node = document.getElementById('ppf-data');
  if (!node) return null;
  try {
    return JSON.parse(node.textContent || '{}');
  } catch {
    return null;
  }
}

function openPremiumModal() {
  const modal = document.querySelector('[data-ppf-modal]');
  if (!modal) return;
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
  modal.querySelector('[data-ppf-modal-close]')?.focus();
}

function closePremiumModal() {
  const modal = document.querySelector('[data-ppf-modal]');
  if (!modal) return;
  modal.hidden = true;
  document.body.style.overflow = '';
}

function bindPremiumModal() {
  const modal = document.querySelector('[data-ppf-modal]');
  if (!modal) return;

  modal.querySelector('[data-ppf-modal-close]')?.addEventListener('click', closePremiumModal);
  modal.querySelector('[data-ppf-modal-backdrop]')?.addEventListener('click', closePremiumModal);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) {
      closePremiumModal();
    }
  });

  modal.querySelector('[data-ppf-modal-upgrade]')?.addEventListener('click', () => {
    window.location.href = state.upgradePath;
  });
}

function requirePremium(event) {
  if (state.premium) return;
  event.preventDefault();
  event.stopPropagation();
  openPremiumModal();
}

function bindPremiumTriggers() {
  document.querySelectorAll('[data-ppf-premium]').forEach((node) => {
    node.addEventListener('click', requirePremium);
  });
}

function resolvePremium(session) {
  if (document.body.dataset.ppfPremium === '1') return true;
  if (isPremiumActive(session?.user)) return true;
  if (document.body.classList.contains('is-premium')) return true;
  if (document.body.classList.contains('ppf-is-premium')) return true;
  if (document.documentElement.dataset.authHint === 'premium') return true;
  return false;
}

function applyPremiumUnlock() {
  document.body.classList.toggle('ppf-is-premium', state.premium);

  document.querySelectorAll('[data-ppf-premium-gate], .ppf-gate[data-ppf-premium]').forEach((node) => {
    node.hidden = state.premium;
  });

  document.querySelectorAll('[data-ppf-premium-content]').forEach((node) => {
    node.hidden = !state.premium;
  });

  document.querySelectorAll('.ppf-gate__panel[data-ppf-premium]').forEach((node) => {
    node.hidden = state.premium;
  });

  document.querySelector('.ppf-gate--overlay')?.classList.toggle('ppf-gate--locked', !state.premium);

  document.querySelectorAll('[data-ppf-filter-group]').forEach((group) => {
    if (!state.premium) return;
    group.dataset.ppfLocked = 'false';
    group.querySelectorAll('.ppf-filter-group__lock').forEach((lock) => lock.remove());
    group.querySelectorAll('.ppf-filter-btn--locked').forEach((btn) => btn.classList.remove('ppf-filter-btn--locked'));
  });

  document.querySelectorAll('[data-ppf-compare-action], [data-ppf-opta-action]').forEach((node) => {
    if (state.premium) {
      node.removeAttribute('data-ppf-premium');
    }
  });
}

function filterHistory(history) {
  let rows = Array.isArray(history) ? [...history] : [];

  if (state.filters.venue === 'home') {
    rows = rows.filter((row) => row.was_home);
  } else if (state.filters.venue === 'away') {
    rows = rows.filter((row) => !row.was_home);
  }

  rows.sort((a, b) => (a.gw ?? 0) - (b.gw ?? 0));

  if (state.filters.window === 'last6') {
    rows = rows.slice(-6);
  } else if (state.filters.window === 'last3') {
    rows = rows.slice(-3);
  }

  return rows;
}

function appearanceRows(rows) {
  return rows.filter((row) => Number(row.minutes) > 0);
}

function isGoalkeeperProfile() {
  return Number(state.profile?.player?.pos) === 1;
}

function logRowPlayed(row) {
  const minutes = Number(row.minutes) || 0;
  if (minutes < 1) {
    return false;
  }
  if (isGoalkeeperProfile()) {
    return Number(row.starts) > 0;
  }
  return true;
}

function logCell(value, played) {
  return played ? String(value) : '—';
}

function basisLabel() {
  if (state.filters.basis === 'per_game') return 'per game';
  if (state.filters.basis === 'total') return 'total';
  return 'per 90 minutes';
}

function venueLabel() {
  if (state.filters.venue === 'home') return 'home fixtures';
  if (state.filters.venue === 'away') return 'away fixtures';
  return 'all fixtures';
}

function windowLabel(count) {
  if (state.filters.window === 'last6') return 'last 6 gameweeks';
  if (state.filters.window === 'last3') return 'last 3 gameweeks';
  if (count > 0) return `${count} gameweek${count === 1 ? '' : 's'}`;
  return 'season';
}

function aggregateOverUnder(rows) {
  const totals = rows.reduce(
    (acc, row) => {
      acc.points += Number(row.points) || 0;
      acc.expected += Number(row.expected_points) || 0;
      acc.goals += Number(row.goals) || 0;
      acc.xg += Number(row.xg) || 0;
      acc.assists += Number(row.assists) || 0;
      acc.xa += Number(row.xa) || 0;
      return acc;
    },
    { points: 0, expected: 0, goals: 0, xg: 0, assists: 0, xa: 0 },
  );

  return {
    points: totals.points,
    expected: totals.expected,
    goals: totals.goals,
    xg: totals.xg,
    assists: totals.assists,
    xa: totals.xa,
    deltaPoints: totals.points - totals.expected,
    deltaGoals: totals.goals - totals.xg,
    deltaAssists: totals.assists - totals.xa,
  };
}

function deltaClass(delta) {
  if (delta > 0.05) return 'ppf-delta--over';
  if (delta < -0.05) return 'ppf-delta--under';
  return 'ppf-delta--even';
}

function formatDelta(delta, decimals = 1) {
  const value = Number(delta);
  if (!Number.isFinite(value)) return '0';
  const prefix = value > 0 ? '+' : '';
  return `${prefix}${value.toFixed(decimals)}`;
}

function renderOverUnder(rows) {
  const mount = document.querySelector('[data-ppf-premium-content].ppf-over');
  if (!mount) return;

  if (!rows.length) {
    mount.innerHTML = '<p class="ppf-card__lead">No gameweek data yet for this filter.</p>';
    return;
  }

  const data = aggregateOverUnder(rows);
  const metrics = [
    {
      label: 'Points',
      actual: data.points,
      expected: data.expected,
      delta: data.deltaPoints,
      actualDecimals: 0,
      expectedDecimals: 1,
    },
    {
      label: 'Goals',
      actual: data.goals,
      expected: data.xg,
      delta: data.deltaGoals,
      actualDecimals: 0,
      expectedDecimals: 2,
    },
    {
      label: 'Assists',
      actual: data.assists,
      expected: data.xa,
      delta: data.deltaAssists,
      actualDecimals: 0,
      expectedDecimals: 2,
    },
  ];

  mount.innerHTML = `
    <div class="ppf-over__table" role="table" aria-label="Over and underperformance">
      <div class="ppf-over__table-head" role="row">
        <span role="columnheader"></span>
        <span role="columnheader">Actual</span>
        <span role="columnheader">Expected</span>
        <span role="columnheader">Diff</span>
      </div>
      ${metrics.map((metric) => `
        <div class="ppf-over__table-row" role="row">
          <span class="ppf-over__metric-label" role="cell">${escapeHtml(metric.label)}</span>
          <span class="ppf-over__num ppf-over__num--actual" role="cell">${escapeHtml(formatNumber(metric.actual, metric.actualDecimals))}</span>
          <span class="ppf-over__num ppf-over__num--expected" role="cell">${escapeHtml(formatNumber(metric.expected, metric.expectedDecimals))}</span>
          <span class="ppf-over__num ppf-delta ${deltaClass(metric.delta)}" role="cell">${escapeHtml(formatDelta(metric.delta, metric.expectedDecimals))}</span>
        </div>
      `).join('')}
    </div>
  `;
}

function statValueForBasis(stat) {
  if (state.filters.basis === 'per_game') return stat.value_per_game;
  if (state.filters.basis === 'total') return stat.value_total;
  return stat.value_per90;
}

function renderAttackingThreat() {
  const mount = document.querySelector('[data-ppf-premium-content].ppf-threat');
  const pack = state.profile?.premium?.attacking;
  if (!mount || !pack) return;

  const stats = Array.isArray(pack.stats) ? pack.stats : [];
  if (!stats.length) {
    mount.innerHTML = '<p class="ppf-card__lead">Not enough minutes yet to rank against other players.</p>';
    return;
  }

  mount.innerHTML = `
    <table class="ppf-threat-table">
      <thead>
        <tr>
          <th scope="col">Stat</th>
          <th scope="col">Value</th>
          <th scope="col">Rank</th>
        </tr>
      </thead>
      <tbody>
        ${stats.map((stat) => {
          const value = statValueForBasis(stat);
          const rank = Number(stat.rank) || 0;
          const peerCount = Number(stat.peer_count) || 0;
          const rankLabel = rank > 0 && peerCount > 0
            ? `${rank}${rank === 1 ? 'st' : rank === 2 ? 'nd' : rank === 3 ? 'rd' : 'th'} of ${peerCount}`
            : '—';
          return `
            <tr>
              <td>${escapeHtml(stat.label)}</td>
              <td>${escapeHtml(formatNumber(value, Number(stat.decimals) || 2))}</td>
              <td>${escapeHtml(rankLabel)}</td>
            </tr>
          `;
        }).join('')}
      </tbody>
    </table>
    <p class="ppf-threat-note">Ranked against other ${escapeHtml(pack.pos_label || '')} players with at least 90 minutes.</p>
  `;
}

function buildDefGwColumns(rows, slotCount = 6) {
  const byGw = new Map();
  rows.forEach((row) => {
    const gw = Number(row.gw);
    if (gw > 0) {
      byGw.set(gw, Number(row.defensive_contribution) || 0);
    }
  });

  const played = [...byGw.keys()].sort((a, b) => a - b);
  let startGw = 1;

  if (state.filters.window === 'last6' && played.length) {
    startGw = Math.max(1, played[played.length - 1] - slotCount + 1);
  } else if (state.filters.window === 'last3' && played.length) {
    startGw = Math.max(1, played[played.length - 1] - 2);
  }

  const columns = [];
  for (let i = 0; i < slotCount; i += 1) {
    const gw = startGw + i;
    columns.push({
      gw,
      value: byGw.has(gw) ? byGw.get(gw) : null,
    });
  }
  return columns;
}

function renderDefensiveDetail(rows) {
  const mount = document.querySelector('[data-ppf-premium-content].ppf-def');
  if (!mount) return;

  const columns = buildDefGwColumns(rows, 6);

  mount.innerHTML = `
    <div class="ppf-def__grid" role="table" aria-label="Defensive contribution by gameweek">
      ${columns.map((col, index) => `
        <div class="ppf-def__col${index >= 3 ? ' ppf-def__col--desktop' : ''}" role="cell">
          <span class="ppf-def__col-label">GW${escapeHtml(String(col.gw))}</span>
          <span class="ppf-def__col-value${col.value === null ? ' ppf-def__col-value--empty' : ''}">${col.value === null ? '—' : escapeHtml(String(col.value))}</span>
        </div>
      `).join('')}
    </div>
  `;
}

function formatChartNumber(value) {
  const num = Number(value);
  if (!Number.isFinite(num)) return '0';
  if (Math.abs(num - Math.round(num)) < 0.05) return String(Math.round(num));
  return num.toFixed(1);
}

function renderChart(rows) {
  const mount = document.querySelector('[data-ppf-chart]');
  if (!mount) return;

  if (!rows.length) {
    mount.innerHTML = '<p class="ppf-card__lead">No gameweek data yet for this filter.</p>';
    return;
  }

  const shown = rows.slice(-6);
  let maxChart = 1;
  shown.forEach((row) => {
    maxChart = Math.max(maxChart, Number(row.points) || 0, Number(row.expected_points) || 0);
  });

  mount.innerHTML = shown.map((row) => {
    const pts = Number(row.points) || 0;
    const exp = Number(row.expected_points) || 0;
    const ptsH = Math.max(4, Math.round((pts / maxChart) * 100));
    const expH = Math.max(4, Math.round((exp / maxChart) * 100));
    return `
      <div class="ppf-chart__col">
        <div class="ppf-chart__value">
          <span class="ppf-chart__value-pts" title="Points">${escapeHtml(formatChartNumber(pts))}</span>
          <span class="ppf-chart__value-exp" title="Expected points">${escapeHtml(formatChartNumber(exp))}</span>
        </div>
        <div class="ppf-chart__bars">
          <span class="ppf-chart__bar ppf-chart__bar--points" style="height:${ptsH}%"></span>
          <span class="ppf-chart__bar ppf-chart__bar--expected" style="height:${expH}%"></span>
        </div>
        <div class="ppf-chart__label"><strong>${escapeHtml(row.label || '')}</strong>GW${escapeHtml(String(row.gw ?? ''))}</div>
      </div>
    `;
  }).join('');
}

function renderLog(rows) {
  const mount = document.querySelector('[data-ppf-log-body]');
  if (!mount) return;

  const isGk = isGoalkeeperProfile();
  const colSpan = isGk ? 7 : 8;
  const sorted = [...rows].sort((a, b) => (b.gw ?? 0) - (a.gw ?? 0));
  if (!sorted.length) {
    mount.innerHTML = `<tr><td colspan="${colSpan}">No gameweeks yet for this filter.</td></tr>`;
    return;
  }

  mount.innerHTML = sorted.map((row) => {
    const played = logRowPlayed(row);
    const statCells = isGk
      ? `<td>${escapeHtml(logCell(row.minutes ?? '', played))}</td>
         <td>${escapeHtml(logCell(row.goals ?? '', played))}</td>
         <td>${escapeHtml(logCell(row.assists ?? '', played))}</td>
         <td>${escapeHtml(logCell(row.clean_sheets ?? '', played))}</td>`
      : `<td>${escapeHtml(logCell(row.minutes ?? '', played))}</td>
         <td>${escapeHtml(logCell(row.goals ?? '', played))}</td>
         <td>${escapeHtml(logCell(row.assists ?? '', played))}</td>
         <td>${escapeHtml(logCell(formatNumber(row.xg, 2), played))}</td>
         <td>${escapeHtml(logCell(formatNumber(row.xa, 2), played))}</td>`;

    return `
    <tr>
      <td class="ppf-log__sticky ppf-log__sticky--gw">${escapeHtml(String(row.gw ?? ''))}</td>
      <td class="ppf-log__sticky ppf-log__sticky--opp">${escapeHtml(row.opponent_label || row.label || '')}</td>
      ${statCells}
      <td class="ppf-log__pts">${escapeHtml(logCell(row.points ?? '', played))}</td>
    </tr>
  `;
  }).join('');
}

function availabilityRisk(player) {
  if (!player) return null;
  const status = String(player.status || '').toLowerCase();
  const chance = player.chance_of_playing_next_round;
  if (status === 'i' || status === 's' || status === 'u' || chance === 0) {
    return 'out';
  }
  if (status === 'd' || (chance != null && chance !== '' && Number(chance) < 100)) {
    return 'doubt';
  }
  return null;
}

function injuryIcon(player) {
  const risk = availabilityRisk(player);
  if (!risk) return '';
  const label = risk === 'out' ? 'Unavailable' : 'Doubtful';
  const title = escapeHtml(player.news || label);
  return `<span class="ppf-fixture__warn ppf-fixture__warn--${risk}" title="${title}" aria-label="${escapeHtml(label)}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg></span>`;
}

function renderFixtures() {
  const mount = document.querySelector('[data-ppf-fixtures]');
  const fixtures = state.profile?.fixtures_next;
  const player = state.profile?.player;
  if (!mount || !Array.isArray(fixtures)) return;

  const warnHtml = injuryIcon(player);

  mount.innerHTML = fixtures.map((fx) => {
    const fdr = Math.max(1, Math.min(5, Number(fx.difficulty) || 3));
    const pred = fx.predicted_points;
    const predDisplay = Number.isFinite(Number(pred)) ? formatNumber(pred, 1) : '—';

    return `
      <article class="ppf-fixture">
        <div class="ppf-fixture__fdr mf-fdr--${fdr}"></div>
        <div class="ppf-fixture__body">
          <div class="ppf-fixture__gw">GW ${escapeHtml(String(fx.gw ?? ''))}</div>
          <div class="ppf-fixture__opp">${escapeHtml(fx.label || '')}</div>
          <div class="ppf-fixture__pred" data-ppf-fixture-pred>${escapeHtml(predDisplay)}</div>
          <div class="ppf-fixture__sub">Pred pts</div>
          ${warnHtml}
        </div>
      </article>
    `;
  }).join('');
}

function renderExplainer(rows) {
  const mount = document.querySelector('[data-ppf-explainer]');
  const player = state.profile?.player;
  const team = state.profile?.team;
  if (!mount || !player || !team) return;

  const appearances = rows.length;
  const totalPts = rows.reduce((sum, row) => sum + (Number(row.points) || 0), 0);
  const totalExp = rows.reduce((sum, row) => sum + (Number(row.expected_points) || 0), 0);
  const ppg = appearances > 0 ? totalPts / appearances : Number(player.points_per_game) || 0;
  const expPpg = appearances > 0 ? totalExp / appearances : 0;

  const lead = `${player.web_name} is a ${player.pos_label} for ${team.name}, priced at £${formatNumber(player.price, 1)}m, and owned by ${formatNumber(player.ownership, 1)}% of managers.`;

  if (appearances < 1) {
    mount.textContent = `${lead} We will add deeper analysis once they have played this season.`;
    return;
  }

  let tail = ` So far they have ${ppg.toFixed(2)} points per appearance against an expected ${expPpg.toFixed(2)}, with ${totalPts} total points from ${appearances} gameweek${appearances === 1 ? '' : 's'}.`;

  if (totalPts > totalExp + 2) {
    tail += ' Returns are running ahead of the underlying numbers.';
  } else if (totalPts + 2 < totalExp) {
    tail += ' Returns are running behind the underlying numbers so far.';
  } else {
    tail += ' Returns are broadly in line with the underlying numbers.';
  }

  mount.textContent = lead + tail;
}

function renderFilterMeta(rows) {
  const mount = document.querySelector('[data-ppf-filter-meta]');
  if (!mount) return;

  const season = state.profile?.season || '';
  const shownCount = Math.min(6, rows.length);
  const parts = [
    season,
    venueLabel(),
    windowLabel(rows.length),
    basisLabel(),
    shownCount > 0 ? `last ${shownCount} shown` : 'season shown',
  ].filter(Boolean);

  mount.textContent = parts.join(' · ');
}

function applyFilters() {
  const rows = filterHistory(state.profile?.history || []);
  const appearances = appearanceRows(rows);
  renderFilterMeta(appearances);
  renderChart(appearances);
  renderLog(rows);
  renderExplainer(appearances);
  renderOverUnder(appearances);
  renderAttackingThreat();
  renderDefensiveDetail(appearances);
}

function bindFilterGroups() {
  document.querySelectorAll('[data-ppf-filter-group]').forEach((group) => {
    group.querySelectorAll('[data-ppf-filter]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        const locked = group.dataset.ppfLocked === 'true';
        if (locked && !state.premium) {
          requirePremium(event);
          return;
        }

        const key = btn.dataset.ppfFilter;
        const value = btn.dataset.ppfValue;
        if (!key || !value) return;

        group.querySelectorAll('[data-ppf-filter]').forEach((peer) => {
          peer.classList.remove('is-active');
          peer.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-pressed', 'true');

        state.filters[key] = value;
        applyFilters();
      });
    });
  });
}

function bindPremiumActions() {
  document.querySelector('[data-ppf-compare-action]')?.addEventListener('click', (event) => {
    if (!state.premium) {
      requirePremium(event);
      return;
    }
    document.querySelector('.ppf-link-group')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.querySelector('[data-ppf-opta-action]')?.addEventListener('click', (event) => {
    if (!state.premium) {
      event.preventDefault();
      requirePremium(event);
      return;
    }

    const url = state.profile?.player?.opta_stats_url;
    if (!url) {
      event.preventDefault();
    }
  });
}

let searchTimer = null;

function searchFaceHtml(row) {
  const initial = escapeHtml(String(row.label || '?').slice(0, 1));
  const shirt = row.shirt ? escapeHtml(row.shirt) : '';

  if (row.photo) {
    return `<img class="ppf-search__img ppf-search__img--face" src="${escapeHtml(row.photo)}" alt="" width="36" height="36" loading="lazy" decoding="async" data-ppf-search-photo data-ppf-shirt="${shirt}" data-ppf-initial="${initial}">`;
  }
  if (row.shirt) {
    return `<img class="ppf-search__img ppf-search__img--shirt" src="${shirt}" alt="" width="28" height="36" loading="lazy" decoding="async" data-ppf-search-photo data-ppf-initial="${initial}">`;
  }
  return `<span class="ppf-search__fallback" aria-hidden="true">${initial}</span>`;
}

function bindSearchImageFallbacks() {
  document.querySelectorAll('[data-ppf-search-photo]').forEach((img) => {
    img.addEventListener('error', () => {
      const shirt = img.dataset.ppfShirt;
      const initial = img.dataset.ppfInitial || '?';
      const wrap = img.closest('.ppf-search__face');
      if (!wrap) return;

      if (shirt && !img.dataset.ppfTriedShirt) {
        img.dataset.ppfTriedShirt = '1';
        img.src = shirt;
        img.classList.remove('ppf-search__img--face');
        img.classList.add('ppf-search__img--shirt');
        return;
      }

      wrap.innerHTML = `<span class="ppf-search__fallback" aria-hidden="true">${escapeHtml(initial)}</span>`;
    }, { once: false });
  });
}

async function runSearch(query) {
  const panel = document.querySelector('[data-ppf-search-panel]');
  if (!panel) return;

  const trimmed = query.trim();
  if (trimmed.length < 2) {
    panel.hidden = true;
    panel.innerHTML = '';
    return;
  }

  try {
    const res = await fetch(apiUrl(`fpl/player?q=${encodeURIComponent(trimmed)}`), {
      credentials: 'same-origin',
    });
    const data = await res.json();
    const results = Array.isArray(data.results) ? data.results : [];

    if (!results.length) {
      panel.innerHTML = '<p class="ppf-search__empty">No players found</p>';
      panel.hidden = false;
      return;
    }

    panel.innerHTML = results.map((row) => `
      <a class="ppf-search__option" href="${escapeHtml(row.path)}">
        <span class="ppf-search__face">${searchFaceHtml(row)}</span>
        <span class="ppf-search__copy">
          <span class="ppf-search__name">${escapeHtml(row.label)}</span>
          <span class="ppf-search__meta">${escapeHtml(row.pos_label || '')} · ${escapeHtml(row.team_short || '')}</span>
        </span>
      </a>
    `).join('');
    bindSearchImageFallbacks();
    panel.hidden = false;
  } catch {
    panel.hidden = true;
  }
}

function bindSearch() {
  const input = document.querySelector('[data-ppf-search-input]');
  const panel = document.querySelector('[data-ppf-search-panel]');
  if (!input || !panel) return;

  input.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runSearch(input.value), 180);
  });

  input.addEventListener('focus', () => {
    if (input.value.trim().length >= 2) {
      runSearch(input.value);
    }
  });

  document.addEventListener('click', (event) => {
    if (!panel.contains(event.target) && event.target !== input) {
      panel.hidden = true;
    }
  });
}

function recordRecentView() {
  const id = Number(document.body.dataset.playerId || 0);
  if (!Number.isInteger(id) || id < 1) return;

  const key = 'mf-pp-recent-v1';
  try {
    const raw = localStorage.getItem(key);
    const parsed = raw ? JSON.parse(raw) : [];
    const ids = Array.isArray(parsed)
      ? parsed.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0)
      : [];
    const next = [id, ...ids.filter((existing) => existing !== id)].slice(0, 10);
    localStorage.setItem(key, JSON.stringify(next));
  } catch {
    // ignore
  }
}

function bindStickyFilterStrip() {
  const strip = document.querySelector('[data-ppf-filter-strip]');
  if (!strip) return;

  const mobileQuery = window.matchMedia('(max-width: 899px)');
  let stickyObserver = null;

  const syncStickyTop = () => {
    syncHeaderHeight();
    const header = document.querySelector('[data-header]');
    if (header && mobileQuery.matches) {
      strip.style.top = `${header.offsetHeight}px`;
    } else {
      strip.style.removeProperty('top');
    }
  };

  const bindStickyObserver = () => {
    stickyObserver?.disconnect();
    strip.classList.remove('is-stuck');

    if (!mobileQuery.matches) return;

    const sentinel = strip.previousElementSibling?.classList.contains('ppf-sticky-sentinel')
      ? strip.previousElementSibling
      : null;
    if (!sentinel) return;

    stickyObserver = new IntersectionObserver(
      ([entry]) => {
        strip.classList.toggle('is-stuck', entry.intersectionRatio === 0);
      },
      { threshold: [0, 1] },
    );
    stickyObserver.observe(sentinel);
  };

  if (!strip.previousElementSibling?.classList.contains('ppf-sticky-sentinel')) {
    const sentinel = document.createElement('div');
    sentinel.className = 'ppf-sticky-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    strip.parentNode?.insertBefore(sentinel, strip);
  }

  syncStickyTop();
  bindStickyObserver();

  window.addEventListener('resize', () => {
    syncStickyTop();
    bindStickyObserver();
  }, { passive: true });

  mobileQuery.addEventListener('change', () => {
    syncStickyTop();
    bindStickyObserver();
  });
}

function bindImageFallbacks() {
  document.querySelectorAll('[data-ppf-photo]').forEach((img) => {
    img.addEventListener('error', () => {
      const shirt = img.dataset.ppfShirt;
      const initial = img.dataset.ppfInitial || '?';
      const wrap = img.closest('.ppf-hero__face');
      if (!wrap) return;

      if (shirt && !img.dataset.ppfTriedShirt) {
        img.dataset.ppfTriedShirt = '1';
        img.src = shirt;
        img.classList.remove('ppf-hero__img');
        img.classList.add('ppf-hero__img--shirt');
        return;
      }

      wrap.innerHTML = `<span class="ppf-hero__fallback" aria-hidden="true">${escapeHtml(initial)}</span>`;
    }, { once: false });
  });
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();

  state.profile = loadProfileData();
  state.premium = resolvePremium(null);
  applyPremiumUnlock();

  const session = await fetchSession();
  applyAuthNav(session);

  const sessionPremium = resolvePremium(session);
  if (sessionPremium || !session?.transient) {
    state.premium = sessionPremium;
  }
  state.upgradePath = premiumUpgradePath();

  document.querySelectorAll('[data-ppf-upgrade]').forEach((node) => {
    if (node.tagName === 'A') {
      node.setAttribute('href', state.upgradePath);
    }
  });

  applyPremiumUnlock();
  bindPremiumModal();
  bindPremiumTriggers();
  bindPremiumActions();
  bindFilterGroups();
  bindSearch();
  bindStickyFilterStrip();
  bindImageFallbacks();
  recordRecentView();
  renderFixtures();
  applyFilters();
}

boot();
