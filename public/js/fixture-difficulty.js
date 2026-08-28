import { animate, stagger } from './motion.js';
import { initSiteNav } from './site-nav.js';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
  redirectToLogin,
  pageUrl,
} from './auth.js';
import { initMfSelects, setMfSelectValue } from './mf-select.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  horizon: 5,
  sort: 'easiest',
  ticker: null,
};

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

function isPremiumActive(user) {
  return user?.plan === 'premium' && user?.subscription_status === 'active';
}

function upgradePath(user) {
  if (user?.plan === 'premium' && user?.subscription_status !== 'active') {
    return pageUrl('payment');
  }
  return pageUrl('join');
}

function applyGate(session) {
  const lock = document.querySelector('[data-premium-lock]');
  const unlocked = document.querySelector('[data-premium-unlocked]');
  const cta = document.querySelector('[data-upgrade-cta]');
  const user = session?.user;
  const premium = isPremiumActive(user);

  if (cta) cta.setAttribute('href', upgradePath(user));
  if (lock) lock.hidden = premium;
  if (unlocked) unlocked.hidden = !premium;

  const reveal = premium ? unlocked : lock;
  if (reveal && !reduceMotion) {
    animate(reveal, { opacity: [0, 1], y: [12, 0] }, { duration: 0.4, easing: [0.17, 0.55, 0.55, 1] });
  }
  return premium;
}

function progressEls() {
  return {
    top: document.querySelector('[data-fdr-progress]'),
    topBar: document.querySelector('[data-fdr-progress-bar]'),
    panel: document.querySelector('[data-fdr-load]'),
    fill: document.querySelector('[data-fdr-load-fill]'),
    track: document.querySelector('[data-fdr-load-track]'),
    label: document.querySelector('[data-fdr-load-label]'),
    pct: document.querySelector('[data-fdr-load-pct]'),
    sr: document.querySelector('[data-fdr-load-sr]'),
    main: document.querySelector('[data-fdr-main]'),
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

function startProgress(label = 'Loading fixtures') {
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

function setProgress(target, { immediate = false, label } = {}) {
  if (label) setProgressLabel(label);
  const next = Math.max(progressUi.value, Math.min(100, target));
  if (progressUi.anim) {
    progressUi.anim.stop();
    progressUi.anim = null;
  }
  if (immediate || reduceMotion) {
    progressUi.value = next;
    applyProgressVisual(next);
    return;
  }
  const from = progressUi.value;
  progressUi.anim = animate(from, next, {
    duration: 0.35,
    ease: [0.17, 0.55, 0.55, 1],
    onUpdate: (latest) => {
      progressUi.value = latest;
      applyProgressVisual(latest);
    },
  });
}

async function finishProgress(label = 'Fixtures ready') {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }
  setProgressLabel(label);
  setProgress(100, { immediate: reduceMotion });
  if (progressUi.anim) {
    try {
      await progressUi.anim;
    } catch {
      // ignore
    }
  }
  const els = progressEls();
  if (els.top) els.top.classList.add('is-complete');
  await new Promise((r) => setTimeout(r, reduceMotion ? 40 : 220));
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
  applyProgressVisual(0);
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

function fdrLevel(fdr) {
  const n = Number(fdr);
  if (!Number.isFinite(n)) return 3;
  return Math.max(1, Math.min(5, Math.round(n)));
}

function setError(text) {
  const el = document.querySelector('[data-fdr-error]');
  if (!el) return;
  if (!text) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = text;
}

function sortedTeams(teams) {
  const list = [...(teams || [])];
  if (state.sort === 'hardest') {
    list.sort((a, b) => b.avg_fdr - a.avg_fdr || String(a.short_name).localeCompare(String(b.short_name)));
  } else if (state.sort === 'name') {
    list.sort((a, b) => String(a.short_name).localeCompare(String(b.short_name)));
  } else {
    list.sort((a, b) => a.avg_fdr - b.avg_fdr || String(a.short_name).localeCompare(String(b.short_name)));
  }
  return list;
}

function renderLegend(legend) {
  const el = document.querySelector('[data-fdr-legend]');
  if (!el) return;
  const items = legend || [
    { fdr: 1, label: 'Easiest' },
    { fdr: 2, label: 'Easy' },
    { fdr: 3, label: 'Medium' },
    { fdr: 4, label: 'Hard' },
    { fdr: 5, label: 'Hardest' },
  ];
  el.innerHTML = items.map((item) => `
    <span class="fdr-legend__item">
      <span class="mf-fdr mf-fdr--${fdrLevel(item.fdr)} mf-fdr--md">${fdrLevel(item.fdr)}</span>
      <span class="fdr-legend__label">${escapeHtml(item.label)}</span>
    </span>
  `).join('');
}

function renderInsights(teams) {
  const el = document.querySelector('[data-fdr-insights]');
  if (!el) return;
  const list = sortedTeams(teams);
  if (!list.length) {
    el.innerHTML = '';
    return;
  }
  const easiest = [...list].sort((a, b) => a.avg_fdr - b.avg_fdr).slice(0, 3);
  const hardest = [...list].sort((a, b) => b.avg_fdr - a.avg_fdr).slice(0, 3);

  el.innerHTML = `
    <article class="fdr-insight fdr-insight--easy">
      <h2 class="fdr-insight__title">Easiest runs</h2>
      <ul class="fdr-insight__list">
        ${easiest.map((team) => `
          <li>
            <span class="fdr-insight__club">${escapeHtml(team.short_name)}</span>
            <span class="fdr-insight__avg">
              <span class="mf-fdr mf-fdr--${fdrLevel(team.avg_fdr)} mf-fdr--md" title="Average fixture difficulty">${Number(team.avg_fdr).toFixed(1)}</span>
            </span>
          </li>
        `).join('')}
      </ul>
    </article>
    <article class="fdr-insight fdr-insight--hard">
      <h2 class="fdr-insight__title">Toughest runs</h2>
      <ul class="fdr-insight__list">
        ${hardest.map((team) => `
          <li>
            <span class="fdr-insight__club">${escapeHtml(team.short_name)}</span>
            <span class="fdr-insight__avg">
              <span class="mf-fdr mf-fdr--${fdrLevel(team.avg_fdr)} mf-fdr--md" title="Average fixture difficulty">${Number(team.avg_fdr).toFixed(1)}</span>
            </span>
          </li>
        `).join('')}
      </ul>
    </article>
  `;
}

function fixtureCellHtml(fx) {
  if (fx?.blank) {
    return `
      <div class="fdr-cell fdr-cell--blank" title="Blank gameweek">
        <span class="fdr-cell__opp">BLK</span>
        <span class="fdr-cell__ha">GW</span>
        <span class="fdr-cell__blank-mark" aria-label="Blank">-</span>
      </div>
    `;
  }
  const fdr = fdrLevel(fx.fdr);
  const ha = fx.was_home ? 'H' : 'A';
  return `
    <div class="fdr-cell" title="${escapeHtml(fx.label)} · FDR ${fdr}">
      <span class="fdr-cell__opp">${escapeHtml(fx.opponent || fx.label)}</span>
      <span class="fdr-cell__ha">${ha}</span>
      <span class="mf-fdr mf-fdr--${fdr} mf-fdr--sm">${fdr}</span>
    </div>
  `;
}

function renderGrid(teams) {
  const grid = document.querySelector('[data-fdr-grid]');
  if (!grid) return;
  const list = sortedTeams(teams);

  grid.innerHTML = list.map((team) => `
    <article class="fdr-card" data-fdr-card>
      <div class="fdr-card__head">
        <div class="fdr-card__club">
          ${team.badge
            ? `<img class="fdr-card__badge" src="${escapeHtml(team.badge)}" alt="" width="28" height="28" loading="lazy" decoding="async">`
            : `<span class="fdr-card__badge fdr-card__badge--empty" aria-hidden="true"></span>`}
          <div class="fdr-card__names">
            <p class="fdr-card__short">${escapeHtml(team.short_name)}</p>
            <p class="fdr-card__name">${escapeHtml(team.name)}</p>
          </div>
        </div>
        <p class="fdr-card__avg" title="Average fixture difficulty">
          <span class="fdr-card__avg-label">Avg</span>
          <span class="mf-fdr mf-fdr--${fdrLevel(team.avg_fdr)} mf-fdr--avg">${Number(team.avg_fdr).toFixed(1)}</span>
        </p>
      </div>
      <div class="fdr-card__ticker" aria-label="Upcoming fixtures for ${escapeHtml(team.name)}">
        ${(team.fixtures || []).map(fixtureCellHtml).join('')}
      </div>
    </article>
  `).join('');

  animateCards(grid);
}

function animateCards(grid) {
  if (reduceMotion || !grid) return;
  const cards = [...grid.querySelectorAll('[data-fdr-card]')];
  if (!cards.length) return;
  cards.forEach((card) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(14px)';
  });
  animate(
    cards,
    { opacity: 1, y: 0 },
    {
      duration: 0.4,
      delay: stagger(0.03),
      easing: [0.17, 0.55, 0.55, 1],
    }
  );
}

function renderTicker(ticker) {
  state.ticker = ticker;
  const eventEl = document.querySelector('[data-fdr-event]');
  if (eventEl) eventEl.textContent = ticker?.event?.name || 'Gameweek';

  const board = document.querySelector('[data-fdr-board]');
  if (board) board.hidden = false;

  renderLegend(ticker?.legend);
  renderInsights(ticker?.teams || []);
  renderGrid(ticker?.teams || []);
  syncControls();
}

function syncControls() {
  document.querySelectorAll('[data-horizon]').forEach((btn) => {
    const active = Number(btn.getAttribute('data-horizon')) === state.horizon;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
  });
  const sort = document.querySelector('[data-fdr-sort]');
  if (sort) setMfSelectValue(sort, state.sort, { silent: true });
}

async function loadTicker(horizon = state.horizon) {
  setError('');
  const board = document.querySelector('[data-fdr-board]');
  if (board) board.hidden = true;

  startProgress('Loading fixtures');
  setProgress(24, { label: 'Fetching Premier League fixtures' });

  try {
    const response = await fetch(apiUrl(`fpl/fixture-difficulty?horizon=${horizon}`), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    setProgress(70, { label: 'Building difficulty tickers' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      if (data.redirect) {
        failProgress();
        window.location.href = pageUrl(String(data.redirect).replace(/^\//, ''));
        return;
      }
      throw new Error(data.error || 'Could not load fixture difficulty.');
    }
    state.horizon = Number(data.ticker?.horizon) || horizon;
    renderTicker(data.ticker);
    await finishProgress('Fixtures ready');
  } catch (error) {
    failProgress();
    setError(error.message || 'Could not load fixture difficulty.');
  }
}

function bindControls() {
  document.querySelectorAll('[data-horizon]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = Number(btn.getAttribute('data-horizon'));
      if (!Number.isFinite(next) || next === state.horizon) return;
      state.horizon = next;
      syncControls();
      loadTicker(next);
    });
  });

  const sort = document.querySelector('[data-fdr-sort]');
  if (sort) {
    sort.addEventListener('mf-select:change', (event) => {
      state.sort = event.detail?.value || 'easiest';
      if (state.ticker) {
        renderInsights(state.ticker.teams || []);
        renderGrid(state.ticker.teams || []);
      }
    });
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initMfSelects();
  bindControls();

  const session = await fetchSession();
  if (!session?.authenticated) {
    redirectToLogin();
    return;
  }

  applyAuthNav(session);
  const premium = applyGate(session);
  if (!premium) return;

  await loadTicker(state.horizon);
}

boot();
