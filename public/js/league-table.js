import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  view: 'table',
  table: null,
};

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

function progressEls() {
  return {
    top: document.querySelector('[data-lt-progress]'),
    topBar: document.querySelector('[data-lt-progress-bar]'),
    panel: document.querySelector('[data-lt-load]'),
    fill: document.querySelector('[data-lt-load-fill]'),
    track: document.querySelector('[data-lt-load-track]'),
    label: document.querySelector('[data-lt-load-label]'),
    pct: document.querySelector('[data-lt-load-pct]'),
    sr: document.querySelector('[data-lt-load-sr]'),
    main: document.querySelector('[data-lt-main]'),
  };
}

function applyProgressVisual(value) {
  const els = progressEls();
  const clamped = Math.max(0, Math.min(100, value));
  const scale = clamped / 100;
  if (els.topBar) {
    els.topBar.style.transform = `scaleX(${scale})`;
    els.topBar.setAttribute('aria-valuenow', String(Math.round(clamped)));
  }
  if (els.fill) els.fill.style.transform = `scaleX(${scale})`;
  if (els.track) els.track.setAttribute('aria-valuenow', String(Math.round(clamped)));
  if (els.pct) els.pct.textContent = `${Math.round(clamped)}%`;
}

function setProgressLabel(text) {
  const els = progressEls();
  if (els.label) els.label.textContent = text;
  if (els.sr) els.sr.textContent = text;
}

function revealProgressUi() {
  if (progressUi.visible) return;
  progressUi.visible = true;
  const els = progressEls();
  if (els.top) {
    els.top.hidden = false;
    els.top.setAttribute('aria-hidden', 'false');
    els.top.classList.add('is-visible');
  }
  if (els.panel) {
    els.panel.hidden = false;
    els.panel.classList.add('is-visible', 'is-active');
  }
  if (els.main) els.main.setAttribute('aria-busy', 'true');
}

function startProgress(label = 'Loading table') {
  progressUi.value = 0;
  applyProgressVisual(0);
  setProgressLabel(label);
  if (progressUi.showTimer) clearTimeout(progressUi.showTimer);
  progressUi.showTimer = window.setTimeout(revealProgressUi, PROGRESS_SHOW_DELAY_MS);
  if (progressUi.trickleTimer) clearInterval(progressUi.trickleTimer);
  progressUi.trickleTimer = window.setInterval(() => {
    if (progressUi.value >= 88) return;
    const next = progressUi.value + Math.max(0.4, (90 - progressUi.value) * 0.05);
    setProgress(Math.min(88, next), { immediate: reduceMotion });
  }, reduceMotion ? 400 : 200);
}

function setProgress(target, { immediate = true, label } = {}) {
  if (label) setProgressLabel(label);
  const next = Math.max(progressUi.value, Math.min(100, target));
  if (progressUi.anim) {
    progressUi.anim = null;
  }
  progressUi.value = next;
  applyProgressVisual(next);
}

async function finishProgress(label = 'Table ready') {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }
  setProgress(100, { label });
  await new Promise((resolve) => window.setTimeout(resolve, reduceMotion ? 80 : 220));
  const els = progressEls();
  if (els.top) {
    els.top.classList.add('is-complete');
    window.setTimeout(() => {
      els.top.classList.remove('is-visible', 'is-complete');
      els.top.hidden = true;
      els.top.setAttribute('aria-hidden', 'true');
    }, reduceMotion ? 0 : 320);
  }
  if (els.panel) {
    els.panel.classList.remove('is-visible', 'is-active');
    els.panel.hidden = true;
  }
  if (els.main) els.main.removeAttribute('aria-busy');
  progressUi.visible = false;
  progressUi.value = 0;
}

function failProgress() {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }
  const els = progressEls();
  if (els.top) {
    els.top.classList.remove('is-visible', 'is-complete');
    els.top.hidden = true;
    els.top.setAttribute('aria-hidden', 'true');
  }
  if (els.panel) {
    els.panel.classList.remove('is-visible', 'is-active');
    els.panel.hidden = true;
  }
  if (els.main) els.main.removeAttribute('aria-busy');
  progressUi.visible = false;
  progressUi.value = 0;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function setError(text) {
  const el = document.querySelector('[data-lt-error]');
  if (!el) return;
  if (!text) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = text;
}

function formHtml(form) {
  const pills = (form || []).map((result) => {
    const key = String(result || '').toUpperCase();
    const cls = key === 'W' ? 'w' : key === 'D' ? 'd' : 'l';
    return `<span class="lt-form lt-form--${cls}" title="${escapeHtml(key)}">${escapeHtml(key)}</span>`;
  }).join('');
  return `<span class="lt-form-row">${pills || '<span class="lt-form lt-form--empty">-</span>'}</span>`;
}

function gdText(value) {
  const n = Number(value) || 0;
  if (n > 0) return `+${n}`;
  return String(n);
}

function renderStandings(rows) {
  return `
    <div class="lt-table-wrap">
      <table class="lt-table">
        <thead>
          <tr>
            <th scope="col" class="lt-table__pos">#</th>
            <th scope="col" class="lt-table__club">Club</th>
            <th scope="col">P</th>
            <th scope="col">W</th>
            <th scope="col">D</th>
            <th scope="col">L</th>
            <th scope="col">GF</th>
            <th scope="col">GA</th>
            <th scope="col">GD</th>
            <th scope="col" class="lt-table__pts">Pts</th>
            <th scope="col" class="lt-table__form">Form</th>
          </tr>
        </thead>
        <tbody>
          ${(rows || []).map((row) => `
            <tr class="lt-row lt-row--${escapeHtml(row.zone || 'mid')}" data-lt-row>
              <td class="lt-table__pos"><span class="lt-pos">${escapeHtml(row.position)}</span></td>
              <td class="lt-table__club">
                <span class="lt-club">
                  <span class="lt-club__badge-slot">
                    ${row.badge
                      ? `<img class="lt-club__badge" src="${escapeHtml(row.badge)}" alt="" width="24" height="24" loading="lazy" decoding="async">`
                      : `<span class="lt-club__badge lt-club__badge--empty" aria-hidden="true"></span>`}
                  </span>
                  <span class="lt-club__text">
                    <span class="lt-club__short">${escapeHtml(row.short_name)}</span>
                    <span class="lt-club__name">${escapeHtml(row.name)}</span>
                  </span>
                </span>
              </td>
              <td>${escapeHtml(row.played)}</td>
              <td>${escapeHtml(row.won)}</td>
              <td>${escapeHtml(row.drawn)}</td>
              <td>${escapeHtml(row.lost)}</td>
              <td>${escapeHtml(row.gf)}</td>
              <td>${escapeHtml(row.ga)}</td>
              <td>${escapeHtml(gdText(row.gd))}</td>
              <td class="lt-table__pts"><strong>${escapeHtml(row.points)}</strong></td>
              <td class="lt-table__form">${formHtml(row.form)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function faceHtml(player) {
  const name = escapeHtml(player.name || 'Player');
  const initial = escapeHtml(String(player.name || '?').slice(0, 1));
  const shirt = player.shirt ? ` data-lt-shirt="${escapeHtml(player.shirt)}"` : '';
  if (player.photo) {
    return `<img class="lt-face__img" src="${escapeHtml(player.photo)}" alt="" width="44" height="44" loading="lazy" decoding="async"${shirt} data-lt-initial="${initial}">`;
  }
  if (player.shirt) {
    return `<img class="lt-face__shirt" src="${escapeHtml(player.shirt)}" alt="" width="36" height="48" loading="lazy" decoding="async" data-lt-initial="${initial}">`;
  }
  return `<span class="lt-face__fallback" aria-hidden="true">${initial}</span>`;
}

function renderLeaders(rows, label) {
  if (!rows?.length) {
    return `<p class="lt-empty">No ${escapeHtml(label)} yet this season.</p>`;
  }
  return `
    <ol class="lt-leaders">
      ${rows.map((player) => `
        <li class="lt-leader" data-lt-row>
          <span class="lt-leader__rank">${escapeHtml(player.rank)}</span>
          <span class="lt-face">${faceHtml(player)}</span>
          <span class="lt-leader__meta">
            <span class="lt-leader__name">${escapeHtml(player.name)}</span>
            <span class="lt-leader__team">
              <span class="lt-leader__team-badge">
                ${player.badge
                  ? `<img src="${escapeHtml(player.badge)}" alt="" width="16" height="16" loading="lazy" decoding="async">`
                  : ''}
              </span>
              <span class="lt-leader__team-name">${escapeHtml(player.team)}</span>
            </span>
          </span>
          <span class="lt-leader__value" title="${escapeHtml(label)}">${escapeHtml(player.value)}</span>
        </li>
      `).join('')}
    </ol>
  `;
}

function animateRows(scope) {
  if (!scope) return;
  scope.querySelectorAll('[data-lt-row]').forEach((row) => {
    row.style.opacity = '';
    row.style.transform = '';
  });
}

function renderPanel() {
  const panel = document.querySelector('[data-lt-panel]');
  const legend = document.querySelector('[data-lt-legend]');
  if (!panel || !state.table) return;

  const view = state.view;
  if (legend) legend.hidden = view !== 'table';

  if (view === 'table') {
    panel.innerHTML = renderStandings(state.table.standings || []);
  } else if (view === 'goals') {
    panel.innerHTML = renderLeaders(state.table.leaders?.goals || [], 'Goals');
  } else if (view === 'assists') {
    panel.innerHTML = renderLeaders(state.table.leaders?.assists || [], 'Assists');
  } else if (view === 'clean_sheets') {
    panel.innerHTML = renderLeaders(state.table.leaders?.clean_sheets || [], 'Clean sheets');
  } else if (view === 'avg_rating') {
    panel.innerHTML = renderLeaders(state.table.leaders?.avg_rating || [], 'Avg rating');
  } else if (view === 'chances_created') {
    panel.innerHTML = renderLeaders(state.table.leaders?.chances_created || [], 'Chances created');
  } else if (view === 'minutes_played') {
    panel.innerHTML = renderLeaders(state.table.leaders?.minutes_played || [], 'Minutes played');
  } else if (view === 'goals_plus_assists') {
    panel.innerHTML = renderLeaders(state.table.leaders?.goals_plus_assists || [], 'Goals + Assists');
  } else if (view === 'goals_per_90') {
    panel.innerHTML = renderLeaders(state.table.leaders?.goals_per_90 || [], 'Goals per 90');
  } else {
    panel.innerHTML = renderLeaders(state.table.leaders?.fpl_points || [], 'FPL points');
  }

  animateRows(panel);
}

function syncTabs() {
  document.querySelectorAll('[data-lt-view]').forEach((btn) => {
    const active = btn.getAttribute('data-lt-view') === state.view;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
  });
}

function bindTabs() {
  document.querySelectorAll('[data-lt-view]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = btn.getAttribute('data-lt-view') || 'table';
      if (next === state.view) return;
      state.view = next;
      syncTabs();
      renderPanel();
    });
  });
}

function initFaceFallbacks() {
  if (window.__mfLtFaceBound) return;
  window.__mfLtFaceBound = true;
  document.addEventListener('error', (event) => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;
    if (!img.classList.contains('lt-face__img') && !img.classList.contains('lt-face__shirt')) return;

    const shirt = img.getAttribute('data-lt-shirt');
    if (img.classList.contains('lt-face__img') && shirt) {
      img.classList.remove('lt-face__img');
      img.classList.add('lt-face__shirt');
      img.removeAttribute('data-lt-shirt');
      img.src = shirt;
      return;
    }

    const initial = img.getAttribute('data-lt-initial') || '?';
    const span = document.createElement('span');
    span.className = 'lt-face__fallback';
    span.setAttribute('aria-hidden', 'true');
    span.textContent = initial;
    img.replaceWith(span);
  }, true);
}

async function loadTable() {
  setError('');
  const board = document.querySelector('[data-lt-board]');
  if (board) board.hidden = true;

  startProgress('Loading table');
  setProgress(28, { label: 'Fetching Premier League data' });

  try {
    const response = await fetch(apiUrl('fpl/league-table'), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    setProgress(72, { label: 'Building standings' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Could not load the league table.');
    }

    state.table = data.table;
    const eventEl = document.querySelector('[data-lt-event]');
    if (eventEl) {
      const season = data.table?.season ? `${data.table.season}` : 'Premier League';
      const gw = data.table?.event?.name || '';
      eventEl.textContent = gw ? `${season} · ${gw}` : season;
    }

    if (board) board.hidden = false;
    syncTabs();
    renderPanel();
    await finishProgress('Table ready');
  } catch (error) {
    failProgress();
    setError(error.message || 'Could not load the league table.');
  }
}

async function boot() {
  try {
    initFaceFallbacks();
    initSiteNav();
    bindSignOutButtons();
    bindTabs();

    const [session] = await Promise.all([
      fetchSession(),
      loadTable(),
    ]);
    applyAuthNav(session || { authenticated: false });
  } catch (error) {
    failProgress();
    setError(error?.message || 'Could not start the league table page.');
  }
}

boot();
