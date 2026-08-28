import { animate, inView, stagger } from './motion.js';
import { initSiteNav } from './site-nav.js';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  apiUrl,
  bindSignOutButtons,
  fetchSession,
  redirectToLogin,
  pageUrl,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  overview: null,
  dashboard: null,
  activeLeagueId: null,
  addOpen: false,
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
    top: document.querySelector('[data-ml-progress]'),
    topBar: document.querySelector('[data-ml-progress-bar]'),
    overlay: document.querySelector('[data-ml-overlay]'),
    fill: document.querySelector('[data-ml-overlay-fill]'),
    track: document.querySelector('[data-ml-overlay-track]'),
    label: document.querySelector('[data-ml-overlay-label]'),
    hint: document.querySelector('[data-ml-overlay-hint]'),
    pct: document.querySelector('[data-ml-overlay-pct]'),
    sr: document.querySelector('[data-ml-overlay-sr]'),
    main: document.querySelector('[data-ml-main]'),
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

function setProgressLabel(text, hint) {
  const els = progressEls();
  if (els.label) els.label.textContent = text;
  if (els.sr) els.sr.textContent = text;
  if (hint && els.hint) els.hint.textContent = hint;
}

function revealProgressUi() {
  if (progressUi.visible) return;
  progressUi.visible = true;
  const els = progressEls();

  document.body.classList.add('is-importing');
  document.body.setAttribute('aria-busy', 'true');

  if (els.top) {
    els.top.hidden = false;
    els.top.setAttribute('aria-hidden', 'false');
    els.top.classList.add('is-visible');
  }
  if (els.overlay) {
    els.overlay.hidden = false;
    els.overlay.setAttribute('aria-hidden', 'false');
    els.overlay.classList.add('is-visible', 'is-active');
  }
  if (els.main) els.main.setAttribute('aria-busy', 'true');
}

function hideProgressUi() {
  const els = progressEls();
  if (els.top) {
    els.top.classList.remove('is-visible', 'is-complete');
    els.top.hidden = true;
    els.top.setAttribute('aria-hidden', 'true');
  }
  if (els.overlay) {
    els.overlay.classList.remove('is-visible', 'is-active');
    els.overlay.hidden = true;
    els.overlay.setAttribute('aria-hidden', 'true');
  }
  if (els.main) els.main.removeAttribute('aria-busy');
  document.body.classList.remove('is-importing');
  document.body.removeAttribute('aria-busy');
  progressUi.visible = false;
}

function startProgress(label = 'Reading your leagues', hint = 'We pull live standings from the official FPL site.') {
  progressUi.value = 0;
  applyProgressVisual(0);
  setProgressLabel(label, hint);
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

async function finishProgress(label = 'League ready', hint = 'Opening your league view.') {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }
  if (!progressUi.visible) {
    revealProgressUi();
  }
  setProgressLabel(label, hint);
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
  if (els.overlay) els.overlay.classList.remove('is-active');
  await new Promise((r) => setTimeout(r, reduceMotion ? 40 : 220));
  hideProgressUi();
  progressUi.value = 0;
  applyProgressVisual(0);
}

function failProgress() {
  if (progressUi.trickleTimer) clearInterval(progressUi.trickleTimer);
  if (progressUi.showTimer) clearTimeout(progressUi.showTimer);
  if (progressUi.anim) {
    progressUi.anim.stop();
    progressUi.anim = null;
  }
  progressUi.trickleTimer = null;
  progressUi.showTimer = null;
  hideProgressUi();
  progressUi.value = 0;
  applyProgressVisual(0);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function setError(text) {
  const el = document.querySelector('[data-ml-error]');
  if (!el) return;
  if (!text) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = text;
}

function rankChangeHtml(change) {
  const value = Number(change ?? 0);
  if (value > 0) {
    return `<span class="ml-change ml-change--up" aria-label="Up ${value} places">▲ ${value}</span>`;
  }
  if (value < 0) {
    return `<span class="ml-change ml-change--down" aria-label="Down ${Math.abs(value)} places">▼ ${Math.abs(value)}</span>`;
  }
  return '<span class="ml-change ml-change--flat" aria-label="No change">—</span>';
}

function pointsGapLabel(gap) {
  const value = Number(gap ?? 0);
  if (value === 0) return 'Level on points';
  if (value > 0) return `${value} pts ahead`;
  return `${Math.abs(value)} pts behind`;
}

function hasTrackedLeagues() {
  return (state.overview?.tracked_leagues || []).length > 0;
}

function updateAddFormCopy() {
  const tracked = hasTrackedLeagues();
  const title = document.querySelector('.ml-add__title');
  const lead = document.querySelector('.ml-add__lead');
  const submitLabel = document.querySelector('[data-ml-add-form] .mf-btn__label');

  if (title) {
    title.textContent = tracked ? 'Add another league' : 'Track your first league';
  }
  if (lead) {
    lead.textContent = tracked
      ? 'Paste the league ID from the FPL site. Your linked team must already be in that league.'
      : 'Paste a classic league ID from FPL. We show your rank, rivals, and recent moves.';
  }
  if (submitLabel) {
    submitLabel.textContent = tracked ? 'Track league' : 'Add league';
  }
}

function syncAddUi({ open = state.addOpen } = {}) {
  const tracked = hasTrackedLeagues();
  const panel = document.querySelector('[data-ml-add]');
  const closeBtn = document.querySelector('[data-ml-add-close]');

  state.addOpen = tracked ? open : true;

  if (closeBtn) closeBtn.hidden = !tracked;
  if (panel) panel.hidden = !state.addOpen;
  updateAddFormCopy();
}

function renderEmpty() {
  const board = document.querySelector('[data-ml-board]');
  if (board) board.hidden = true;
  syncAddUi({ open: true });
}

function renderLeagueRail(tracked, activeId) {
  const root = document.querySelector('[data-ml-rail]');
  if (!root) return;

  const tabs = (tracked || []).map((league) => {
    const id = league.fpl_league_id;
    const active = id === activeId ? ' is-active' : '';
    const name = league.league_name || `League ${id}`;
    return `
      <button
        type="button"
        class="ml-rail__tab${active}"
        data-ml-league="${id}"
        aria-pressed="${id === activeId ? 'true' : 'false'}"
      >${escapeHtml(name)}</button>
    `;
  }).join('');

  root.innerHTML = tabs;

  root.querySelectorAll('[data-ml-league]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = Number(btn.getAttribute('data-ml-league'));
      if (id && id !== state.activeLeagueId) {
        loadDashboard(id);
      }
    });
  });
}

function renderHeader(dashboard) {
  const eventEl = document.querySelector('[data-ml-event]');
  const metaEl = document.querySelector('[data-ml-league-meta]');

  if (eventEl) eventEl.textContent = dashboard.event?.name || 'Gameweek';
  if (metaEl) {
    const name = dashboard.league?.name || 'Your league';
    const count = dashboard.league?.rank_count ?? 0;
    metaEl.textContent = count ? `${name} · ${count} managers` : name;
  }
}

function renderStats(dashboard) {
  const stats = dashboard.stats || {};
  const you = dashboard.you || {};

  const map = {
    '[data-ml-stat-rank]': stats.rank ?? '—',
    '[data-ml-stat-total]': `${stats.total ?? 0} pts`,
    '[data-ml-stat-gap]': stats.rank === 1 ? 'You lead' : `${stats.gap_to_leader ?? 0} pts`,
    '[data-ml-stat-change]': stats.rank_change > 0
      ? `Up ${stats.rank_change}`
      : stats.rank_change < 0
        ? `Down ${Math.abs(stats.rank_change)}`
        : 'No change',
  };

  Object.entries(map).forEach(([sel, text]) => {
    const el = document.querySelector(sel);
    if (el) el.textContent = text;
  });

  const gwEl = document.querySelector('[data-ml-stat-gw]');
  if (gwEl) gwEl.textContent = `${you.event_total ?? 0} pts this week`;

  const changeTile = document.querySelector('[data-ml-stat-change-wrap]');
  if (changeTile) {
    changeTile.classList.toggle('is-up', stats.rank_change > 0);
    changeTile.classList.toggle('is-down', stats.rank_change < 0);
    changeTile.classList.toggle('is-flat', stats.rank_change === 0);
  }
}

function renderBrief(dashboard) {
  const el = document.querySelector('[data-ml-brief]');
  if (el) el.textContent = dashboard.brief || '';
}

function renderStandings(dashboard) {
  const body = document.querySelector('[data-ml-standings]');
  const cards = document.querySelector('[data-ml-standings-cards]');
  const rows = dashboard.standings || [];

  if (body) {
    body.innerHTML = rows.map((row) => {
      const you = row.is_you ? ' is-you' : '';
      return `
        <tr class="ml-row${you}">
          <td class="ml-row__rank">${escapeHtml(row.rank)}</td>
          <td class="ml-row__team">
            <span class="ml-row__name">${escapeHtml(row.entry_name)}</span>
            <span class="ml-row__manager">${escapeHtml(row.player_name)}</span>
          </td>
          <td class="ml-row__gw">${escapeHtml(row.event_total)}</td>
          <td class="ml-row__total">${escapeHtml(row.total)}</td>
          <td class="ml-row__move">${rankChangeHtml(row.rank_change)}</td>
        </tr>
      `;
    }).join('');
  }

  if (cards) {
    cards.innerHTML = rows.map((row) => {
      const you = row.is_you ? ' is-you' : '';
      const youLabel = row.is_you ? '<span class="ml-standing-card__you">You</span>' : '';
      return `
        <article class="ml-standing-card${you}">
          <span class="ml-standing-card__rank">${escapeHtml(row.rank)}</span>
          <div class="ml-standing-card__main">
            <div class="ml-standing-card__head">
              <span class="ml-standing-card__team">${escapeHtml(row.entry_name)}</span>
              ${youLabel}
              <span class="ml-standing-card__move">${rankChangeHtml(row.rank_change)}</span>
            </div>
            <span class="ml-standing-card__manager">${escapeHtml(row.player_name)}</span>
          </div>
          <div class="ml-standing-card__scores">
            <span class="ml-standing-card__total">${escapeHtml(row.total)}</span>
            <span class="ml-standing-card__gw">${escapeHtml(row.event_total)} GW</span>
          </div>
        </article>
      `;
    }).join('');
  }
}

function renderRivals(dashboard) {
  const root = document.querySelector('[data-ml-rivals]');
  if (!root) return;

  const rivals = dashboard.rivals || [];
  if (!rivals.length) {
    root.innerHTML = '<li class="ml-side__empty">No rivals within 20 points right now.</li>';
    return;
  }

  root.innerHTML = rivals.map((rival) => `
    <li class="ml-rival">
      <div class="ml-rival__head">
        <span class="ml-rival__name">${escapeHtml(rival.entry_name)}</span>
        <span class="ml-rival__rank">#${escapeHtml(rival.rank)}</span>
      </div>
      <p class="ml-rival__meta">${escapeHtml(pointsGapLabel(rival.points_gap))} · ${escapeHtml(rival.total)} pts</p>
    </li>
  `).join('');
}

function renderMovers(dashboard) {
  const upRoot = document.querySelector('[data-ml-movers-up]');
  const downRoot = document.querySelector('[data-ml-movers-down]');
  const movers = dashboard.movers || { up: [], down: [] };

  const renderList = (items, emptyText) => {
    if (!items.length) return `<li class="ml-side__empty">${escapeHtml(emptyText)}</li>`;
    return items.map((row) => `
      <li class="ml-mover">
        <span class="ml-mover__name">${escapeHtml(row.entry_name)}</span>
        <span class="ml-mover__change">${rankChangeHtml(row.rank_change)}</span>
      </li>
    `).join('');
  };

  if (upRoot) upRoot.innerHTML = renderList(movers.up || [], 'No big climbers this week.');
  if (downRoot) downRoot.innerHTML = renderList(movers.down || [], 'No big fallers this week.');
}

function renderActivity(dashboard) {
  const root = document.querySelector('[data-ml-activity]');
  if (!root) return;

  const items = dashboard.activity || [];
  if (!items.length) {
    root.innerHTML = '<li class="ml-side__empty">No recent rival transfers to show.</li>';
    return;
  }

  root.innerHTML = items.map((item) => `
    <li class="ml-activity">
      <p class="ml-activity__who">${escapeHtml(item.entry_name)}</p>
      <p class="ml-activity__move">
        <span class="ml-activity__out">${escapeHtml(item.player_out)}</span>
        <span class="ml-activity__arrow" aria-hidden="true">→</span>
        <span class="ml-activity__in">${escapeHtml(item.player_in)}</span>
      </p>
      <p class="ml-activity__meta">GW${escapeHtml(item.event)}</p>
    </li>
  `).join('');
}

function renderAlerts(dashboard) {
  const toggle = document.querySelector('[data-ml-alerts]');
  if (!toggle) return;
  toggle.checked = Boolean(dashboard.alerts_enabled);
  toggle.dataset.leagueId = String(dashboard.league?.id ?? state.activeLeagueId ?? '');
}

function renderBoard(dashboard, { animateIn = false } = {}) {
  const board = document.querySelector('[data-ml-board]');
  if (board) board.hidden = false;

  state.dashboard = dashboard;
  state.activeLeagueId = dashboard.league?.id ?? state.activeLeagueId;

  renderLeagueRail(state.overview?.tracked_leagues || [dashboard.tracked].filter(Boolean), state.activeLeagueId);
  syncAddUi({ open: false });
  renderHeader(dashboard);
  renderStats(dashboard);
  renderBrief(dashboard);
  renderStandings(dashboard);
  renderRivals(dashboard);
  renderMovers(dashboard);
  renderActivity(dashboard);
  renderAlerts(dashboard);

  if (animateIn && !reduceMotion && board) {
    animate(board, { opacity: [0, 1], y: [12, 0] }, { duration: 0.4, ease: [0.17, 0.55, 0.55, 1] });
    const tiles = board.querySelectorAll('.ml-stat');
    animate(tiles, { opacity: [0, 1], y: [8, 0] }, {
      duration: 0.35,
      delay: stagger(0.05),
      ease: [0.17, 0.55, 0.55, 1],
    });
  }
}

function toggleAddPanel(open) {
  if (!hasTrackedLeagues()) {
    syncAddUi({ open: true });
    return;
  }
  syncAddUi({ open });
}

async function apiRequest(method, body = null, query = '') {
  const url = apiUrl(`fpl/mini-leagues${query}`);
  const options = {
    method,
    credentials: 'include',
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  };
  const response = await fetch(url, options);
  const data = await response.json().catch(() => ({}));
  return { response, data };
}

async function loadOverview() {
  startProgress('Reading your leagues');
  setError('');

  try {
    setProgress(40, { label: 'Loading tracked leagues' });
    const { response, data } = await apiRequest('GET');

    if (!response.ok || !data.ok) {
      failProgress();
      if (data.redirect && (response.status === 401 || response.status === 422 || response.status === 403)) {
        window.location.href = pageUrl(data.redirect.replace(/^\//, ''));
        return;
      }
      setError(data.error || 'We could not load your leagues right now.');
      return;
    }

    state.overview = data.overview;
    await finishProgress('Leagues ready');

    const tracked = data.overview?.tracked_leagues || [];
    if (!tracked.length) {
      renderEmpty();
      renderLeagueRail([], null);
      return;
    }

    const activeId = data.overview?.active_league_id || tracked[0]?.fpl_league_id;
    await loadDashboard(activeId, { skipProgress: true });
  } catch {
    failProgress();
    setError('We could not reach the server. Try again in a moment.');
  }
}

async function loadDashboard(leagueId, { skipProgress = false } = {}) {
  if (!skipProgress) {
    startProgress('Reading league standings');
  }
  setError('');

  try {
    setProgress(55, { label: 'Finding your rank' });
    const { response, data } = await apiRequest('GET', null, `?league_id=${leagueId}`);

    if (!response.ok || !data.ok) {
      if (!skipProgress) failProgress();
      setError(data.error || 'We could not load this league right now.');
      return;
    }

    if (!skipProgress) {
      setProgress(90, { label: 'Building your view' });
      await finishProgress('League ready');
    }

    renderBoard(data.dashboard, { animateIn: !skipProgress });

    const board = document.querySelector('[data-ml-board]');
    if (board && !reduceMotion) {
      inView(board, () => {
        animate(board, { opacity: [0.98, 1] }, { duration: 0.25 });
      }, { amount: 0.15 });
    }
  } catch {
    if (!skipProgress) failProgress();
    setError('We could not reach the server. Try again in a moment.');
  }
}

async function addLeague(form) {
  const input = form.querySelector('[data-ml-add-id]');
  const leagueId = Number(input?.value?.trim());
  if (!leagueId || leagueId < 1) {
    setError('Enter a valid FPL league ID.');
    return;
  }

  setError('');
  startProgress('Adding league');

  try {
    const { response, data } = await apiRequest('POST', { league_id: leagueId });

    if (!response.ok || !data.ok) {
      failProgress();
      setError(data.error || 'We could not add that league.');
      return;
    }

    state.overview = state.overview || {};
    const tracked = state.overview.tracked_leagues || [];
    const exists = tracked.some((l) => l.fpl_league_id === leagueId);
    if (!exists && data.tracked) {
      tracked.push(data.tracked);
      state.overview.tracked_leagues = tracked;
    }

    if (input) input.value = '';
    toggleAddPanel(false);
    await finishProgress('League added');
    renderBoard(data.dashboard, { animateIn: true });
  } catch {
    failProgress();
    setError('We could not reach the server. Try again in a moment.');
  }
}

async function removeActiveLeague() {
  const leagueId = state.activeLeagueId;
  if (!leagueId) return;

  if (!window.confirm('Stop tracking this league?')) return;

  setError('');
  startProgress('Removing league');

  try {
    const { response, data } = await apiRequest('DELETE', { league_id: leagueId });

    if (!response.ok || !data.ok) {
      failProgress();
      setError(data.error || 'We could not remove that league.');
      return;
    }

    state.overview = data.overview;
    state.dashboard = null;
    state.activeLeagueId = null;
    await finishProgress('League removed');

    const tracked = data.overview?.tracked_leagues || [];
    if (!tracked.length) {
      renderEmpty();
      renderLeagueRail([], null);
      return;
    }

    await loadDashboard(tracked[0].fpl_league_id, { skipProgress: true });
  } catch {
    failProgress();
    setError('We could not reach the server. Try again in a moment.');
  }
}

async function toggleAlerts(enabled) {
  const leagueId = state.activeLeagueId;
  if (!leagueId) return;

  try {
    const { response, data } = await apiRequest('PATCH', {
      league_id: leagueId,
      alerts_enabled: enabled,
    });

    if (!response.ok || !data.ok) {
      setError(data.error || 'We could not save your alert setting.');
      const toggle = document.querySelector('[data-ml-alerts]');
      if (toggle) toggle.checked = !enabled;
      return;
    }

    if (state.dashboard) {
      state.dashboard.alerts_enabled = enabled;
    }
  } catch {
    setError('We could not save your alert setting.');
    const toggle = document.querySelector('[data-ml-alerts]');
    if (toggle) toggle.checked = !enabled;
  }
}

function bindUi() {
  const addForm = document.querySelector('[data-ml-add-form]');
  if (addForm) {
    addForm.addEventListener('submit', (event) => {
      event.preventDefault();
      addLeague(addForm);
    });
  }

  document.querySelector('[data-ml-add-open]')?.addEventListener('click', () => toggleAddPanel(true));
  document.querySelector('[data-ml-add-close]')?.addEventListener('click', () => toggleAddPanel(false));
  document.querySelector('[data-ml-remove]')?.addEventListener('click', removeActiveLeague);

  const alerts = document.querySelector('[data-ml-alerts]');
  if (alerts) {
    alerts.addEventListener('change', () => toggleAlerts(alerts.checked));
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initTeamTabs();
  bindUi();

  const session = await fetchSession();
  if (!session?.authenticated) {
    redirectToLogin();
    return;
  }

  applyAuthNav(session);
  const premium = applyGate(session);

  const tabs = document.querySelector('[data-team-tabs]');
  if (tabs && !reduceMotion) {
    animate(tabs, { opacity: [0, 1], y: [-6, 0] }, { duration: 0.35, easing: [0.17, 0.55, 0.55, 1] });
  }

  if (!premium) return;

  if (!session.user?.fpl_team_id) {
    window.location.href = pageUrl('link-team');
    return;
  }

  await loadOverview();
}

boot();
