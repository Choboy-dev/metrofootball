import { animate, stagger } from './motion.js';
import { initSiteNav } from './site-nav.js';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  apiUrl,
  bindSignOutButtons,
  fetchSession,
  pageUrl,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  payload: null,
  activeId: null,
  viewGw: null,
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
    panel: document.querySelector('[data-ait-load]'),
    fill: document.querySelector('[data-ait-load-fill]'),
    track: document.querySelector('[data-ait-load-track]'),
    label: document.querySelector('[data-ait-load-label]'),
    pct: document.querySelector('[data-ait-load-pct]'),
    sr: document.querySelector('[data-ait-load-sr]'),
  };
}

function applyProgressVisual(value) {
  const els = progressEls();
  const clamped = Math.max(0, Math.min(100, value));
  const scale = clamped / 100;
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
  if (els.panel) {
    els.panel.hidden = false;
    els.panel.classList.add('is-visible', 'is-active');
  }
}

function startProgress(label = 'Loading AI season') {
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

async function finishProgress(label = 'AI season ready') {
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
  await new Promise((r) => setTimeout(r, reduceMotion ? 40 : 220));
  if (els.panel) {
    els.panel.classList.remove('is-visible', 'is-active');
    els.panel.hidden = true;
  }
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
  if (els.panel) {
    els.panel.classList.remove('is-visible', 'is-active');
    els.panel.hidden = true;
  }
  progressUi.visible = false;
  progressUi.value = 0;
}

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

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function money(value) {
  const n = Number(value);
  if (Number.isNaN(n)) return '£0.0m';
  return `£${n.toFixed(1)}m`;
}

function pitchFxLabel(label) {
  return String(label || '-')
    .replace(/\s+H\b(?!\))/g, ' (H)')
    .replace(/\s+A\b(?!\))/g, ' (A)');
}

function fdrLevel(value) {
  const n = Math.round(Number(value));
  if (!Number.isFinite(n)) return 3;
  return Math.max(1, Math.min(5, n));
}

function fdrBadgeHtml(fdr, size = 'sm') {
  const level = fdrLevel(fdr);
  return `<span class="mf-fdr mf-fdr--${level} mf-fdr--${size}" title="Fixture difficulty ${level}" aria-label="Fixture difficulty ${level}">${level}</span>`;
}

function setError(message) {
  const el = document.querySelector('[data-ait-error]');
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function gameweekList() {
  return state.payload?.gameweeks || [];
}

function currentGameweekMeta() {
  const list = gameweekList();
  const id = state.viewGw ?? state.payload?.view_gw;
  return list.find((gw) => gw.id === id) || list[list.length - 1] || null;
}

function playerCard(player, { bench = false } = {}) {
  const badge = player.is_captain
    ? '<span class="pick-player__badge" aria-label="Captain">C</span>'
    : player.is_vice_captain
      ? '<span class="pick-player__badge pick-player__badge--vice" aria-label="Vice captain">V</span>'
      : '';
  const pos = bench
    ? `<span class="pick-player__pos">${escapeHtml(player.pos_label)}</span>`
    : '';
  const shirt = player.shirt
    ? `<img class="pick-player__shirt" src="${escapeHtml(player.shirt)}" alt="" width="52" height="68" loading="lazy" decoding="async">`
    : `<span class="pick-player__shirt pick-player__shirt--fallback" aria-hidden="true"></span>`;

  const fx = (player.fixtures || [])[0];
  const raw = Number(player.ai?.projections?.[0] ?? player.proj);
  const base = Number.isFinite(raw) ? raw : null;
  const onPitch = !bench;
  const mul = Number(player.multiplier) || (player.is_captain && onPitch ? 2 : 1);
  const isCaptBoost = Boolean(player.is_captain && onPitch && base != null && mul >= 2);
  const boosted = isCaptBoost ? Math.round(base * mul * 10) / 10 : null;

  let pred;
  if (isCaptBoost && boosted != null) {
    const mulLabel = mul >= 3 ? 'x 3' : 'x 2';
    pred = `<span class="pick-player__score pick-player__score--captain" title="GW projected ${base.toFixed(1)}, captain ${mulLabel} equals ${boosted.toFixed(1)}" aria-label="Projected ${base.toFixed(1)} times ${mul} equals ${boosted.toFixed(1)}"><span class="pick-player__cap-raw">${escapeHtml(base.toFixed(1))}</span><span class="pick-player__cap-mul" aria-hidden="true">${mulLabel}</span><span class="pick-player__cap-sep" aria-hidden="true">–</span><span class="pick-player__cap-total">${escapeHtml(boosted.toFixed(1))}</span></span>`;
  } else {
    const shown = base == null ? '—' : base.toFixed(1);
    pred = `<span class="pick-player__score" title="Projected points">${escapeHtml(shown)}</span>`;
  }

  const fxLabel = pitchFxLabel(fx?.label || '-');
  const fdr = fdrBadgeHtml(fx?.fdr ?? 3, 'sm');

  return `
    <article class="pick-player pick-player--ait" data-player-id="${player.id}">
      ${pos}
      <div class="pick-player__kit">
        ${badge}
        ${shirt}
      </div>
      <span class="pick-player__card">
        <span class="pick-player__top">
          <span class="pick-player__name">${escapeHtml(player.web_name)}</span>
          <span class="pick-player__price">£${escapeHtml(Number(player.now_cost || 0).toFixed(1))}</span>
        </span>
        <span class="pick-player__proj pick-player__proj--single">
          <span class="pick-player__gw pick-player__gw--single">
            ${pred}
            <span class="pick-player__fx">${escapeHtml(fxLabel)}</span>
            ${fdr}
          </span>
        </span>
      </span>
    </article>
  `;
}

const TAB_SHORT = {
  value: 'Value',
  attackers: 'Attackers',
  midfielders: 'Midfielders',
  defenders: 'Defenders',
};

const TAB_MOBILE_MQ = window.matchMedia('(max-width: 640px)');

function tabShortLabel(team) {
  return TAB_SHORT[team.id] || team.title;
}

function scrollActiveTabIntoView() {
  if (!TAB_MOBILE_MQ.matches) return;
  const root = document.querySelector('[data-ait-tabs]');
  const active = root?.querySelector('.ait-tab.is-active');
  if (!active) return;
  active.scrollIntoView({
    inline: 'center',
    block: 'nearest',
    behavior: reduceMotion ? 'auto' : 'smooth',
  });
}

function updateTabLead(team) {
  const lead = document.querySelector('[data-ait-tab-lead]');
  if (!lead) return;
  const text = team?.lead || '';
  if (!text || !TAB_MOBILE_MQ.matches) {
    lead.hidden = true;
    lead.textContent = '';
    return;
  }
  lead.hidden = false;
  lead.textContent = text;
}

function renderTabs() {
  const root = document.querySelector('[data-ait-tabs]');
  if (!root || !state.payload) return;

  const teams = state.payload.teams || [];
  root.innerHTML = teams
    .map((team) => {
      const active = team.id === state.activeId;
      return `
        <button
          type="button"
          class="ait-tab${active ? ' is-active' : ''}"
          role="tab"
          id="ait-tab-${escapeHtml(team.id)}"
          aria-selected="${active ? 'true' : 'false'}"
          aria-controls="ait-panel"
          tabindex="${active ? '0' : '-1'}"
          data-ait-tab="${escapeHtml(team.id)}"
          aria-label="${escapeHtml(team.title)}"
        >
          <span class="ait-tab__label ait-tab__label--full">${escapeHtml(team.title)}</span>
          <span class="ait-tab__label ait-tab__label--short">${escapeHtml(tabShortLabel(team))}</span>
        </button>
      `;
    })
    .join('');

  scrollActiveTabIntoView();
}

function renderGameweekNav() {
  const nav = document.querySelector('[data-ait-gw-nav]');
  const nameEl = document.querySelector('[data-ait-gw-name]');
  const subEl = document.querySelector('[data-ait-gw-sub]');
  const prev = document.querySelector('[data-ait-gw-prev]');
  const next = document.querySelector('[data-ait-gw-next]');
  if (!nav) return;

  const list = gameweekList();
  if (!list.length) {
    nav.hidden = true;
    return;
  }

  nav.hidden = false;
  const gw = currentGameweekMeta();
  const idx = list.findIndex((row) => row.id === (gw?.id ?? state.viewGw));

  if (nameEl) nameEl.textContent = gw?.name || `Gameweek ${state.viewGw}`;
  if (subEl) {
    const planning = state.payload?.planning_gw?.id;
    subEl.textContent = gw?.id === planning ? 'Current gameweek' : (gw?.finished ? 'Finished' : 'Season archive');
  }
  if (prev) prev.disabled = idx <= 0;
  if (next) next.disabled = idx < 0 || idx >= list.length - 1;
}

function renderChipNote(team) {
  const el = document.querySelector('[data-ait-chip]');
  if (!el) return;

  const chip = team?.chip;
  if (!chip?.played || !chip?.label) {
    el.hidden = true;
    el.textContent = '';
    return;
  }

  el.hidden = false;
  el.textContent = `${chip.label} chip played this gameweek.`;
}

function renderTransfers(team) {
  const root = document.querySelector('[data-ait-transfers]');
  if (!root) return;

  const transfers = team?.transfers || [];
  if (!transfers.length) {
    root.hidden = true;
    root.innerHTML = '';
    return;
  }

  root.hidden = false;
  root.innerHTML = `
    <p class="ait-transfers__label">Transfers</p>
    <ul class="ait-transfers__list">
      ${transfers.map((t) => {
        const hit = Number(t.hit_points) > 0 ? ` · ${t.hit_points} pt hit` : '';
        return `<li class="ait-transfers__item"><span class="ait-transfers__out">${escapeHtml(t.out_name)}</span> → <span class="ait-transfers__in">${escapeHtml(t.in_name)}</span>${hit ? `<span class="ait-transfers__hit">${escapeHtml(hit)}</span>` : ''}</li>`;
      }).join('')}
    </ul>
  `;
}

function renderMeta(team) {
  const meta = document.querySelector('[data-ait-meta]');
  if (!team) return;

  renderChipNote(team);
  renderTransfers(team);

  if (meta) {
    const gwMeta = currentGameweekMeta();
    const finished = Boolean(gwMeta?.finished);
    const strength = Number(team.strength ?? 0);
    const strengthOk = strength >= 90;
    const scoreLabel = finished ? 'Points' : 'Predicted';
    const scoreValue = finished
      ? Number(team.gw_points || 0).toFixed(1)
      : Number(team.predicted_points || 0).toFixed(1);

    meta.innerHTML = `
      <div class="ait-stat">
        <span class="ait-stat__label">Strength</span>
        <span class="ait-stat__value${strengthOk ? ' is-good' : ''}">${escapeHtml(String(strength))}%</span>
      </div>
      <div class="ait-stat">
        <span class="ait-stat__label">${escapeHtml(scoreLabel)}</span>
        <span class="ait-stat__value">${escapeHtml(scoreValue)} pts</span>
      </div>
      <div class="ait-stat">
        <span class="ait-stat__label">Team value</span>
        <span class="ait-stat__value">${escapeHtml(money(team.team_value))}</span>
      </div>
      <div class="ait-stat">
        <span class="ait-stat__label">Free transfers</span>
        <span class="ait-stat__value">${escapeHtml(String(team.free_transfers ?? '—'))}</span>
      </div>
    `;
  }
}

function renderPitch(team) {
  if (!team) return;

  ['gkp', 'def', 'mid', 'fwd'].forEach((row) => {
    const rowEl = document.querySelector(`[data-pitch-row="${row}"]`);
    if (!rowEl) return;
    const players = team.pitch?.[row] || [];
    rowEl.dataset.count = String(players.length);
    rowEl.innerHTML = players.map((player) => playerCard(player)).join('');
  });

  const bench = document.querySelector('[data-ait-bench]');
  if (bench) {
    const benchPlayers = team.bench || [];
    bench.dataset.count = String(benchPlayers.length);
    bench.innerHTML = benchPlayers
      .map((player) => playerCard(player, { bench: true }))
      .join('');
  }

  if (!reduceMotion) {
    const chips = document.querySelectorAll('.pick-player--ait');
    chips.forEach((chip) => {
      chip.style.opacity = '0';
      chip.style.transform = 'translateY(12px)';
    });
    animate(
      chips,
      { opacity: 1, y: 0 },
      { duration: 0.3, delay: stagger(0.025), easing: [0.17, 0.55, 0.55, 1] }
    );
  }
}

function selectTeam(id, { pushHash = true } = {}) {
  const teams = state.payload?.teams || [];
  const next = teams.find((t) => t.id === id) || teams[0];
  if (!next) return;

  state.activeId = next.id;
  renderTabs();
  renderMeta(next);
  renderPitch(next);
  updateTabLead(next);

  if (pushHash) {
    const url = new URL(window.location.href);
    url.hash = next.id;
    history.replaceState(null, '', url);
  }

  const panel = document.getElementById('ait-panel');
  if (panel) {
    panel.setAttribute('aria-labelledby', `ait-tab-${next.id}`);
  }
}

function bindTabs() {
  const root = document.querySelector('[data-ait-tabs]');
  if (!root || root.dataset.bound === '1') return;
  root.dataset.bound = '1';

  root.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-ait-tab]');
    if (!btn) return;
    selectTeam(btn.getAttribute('data-ait-tab'));
  });

  root.addEventListener('keydown', (event) => {
    const tabs = [...root.querySelectorAll('[data-ait-tab]')];
    if (!tabs.length) return;
    const current = tabs.findIndex((t) => t.classList.contains('is-active'));
    let next = current;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      next = (current + 1) % tabs.length;
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      next = (current - 1 + tabs.length) % tabs.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = tabs.length - 1;
    } else {
      return;
    }
    event.preventDefault();
    const id = tabs[next].getAttribute('data-ait-tab');
    selectTeam(id);
    tabs[next].focus();
  });
}

function bindGameweekNav() {
  const prev = document.querySelector('[data-ait-gw-prev]');
  const next = document.querySelector('[data-ait-gw-next]');
  if (!prev || !next || prev.dataset.bound === '1') return;
  prev.dataset.bound = '1';
  next.dataset.bound = '1';

  prev.addEventListener('click', () => {
    const list = gameweekList();
    const idx = list.findIndex((gw) => gw.id === state.viewGw);
    if (idx > 0) {
      loadTeams(list[idx - 1].id, { animateIn: true });
    }
  });

  next.addEventListener('click', () => {
    const list = gameweekList();
    const idx = list.findIndex((gw) => gw.id === state.viewGw);
    if (idx >= 0 && idx < list.length - 1) {
      loadTeams(list[idx + 1].id, { animateIn: true });
    }
  });
}

function renderBoard(payload, { animateIn = true } = {}) {
  state.payload = payload;
  state.viewGw = payload.view_gw;

  const gwMeta = currentGameweekMeta();
  const eventEl = document.querySelector('[data-ait-event]');
  if (eventEl) {
    eventEl.textContent = gwMeta?.name || payload.planning_gw?.name || 'Gameweek';
  }

  const hash = window.location.hash.replace('#', '');
  const teams = payload.teams || [];
  const fromHash = teams.find((t) => t.id === hash);
  if (!state.activeId) {
    state.activeId = fromHash?.id || teams[0]?.id || null;
  }

  const board = document.querySelector('[data-ait-board]');
  if (board) board.hidden = false;

  renderGameweekNav();
  bindTabs();
  bindGameweekNav();
  selectTeam(state.activeId, { pushHash: Boolean(fromHash) });

  if (animateIn && !reduceMotion && board) {
    animate(board, { opacity: [0, 1], y: [10, 0] }, { duration: 0.4, easing: [0.17, 0.55, 0.55, 1] });
  }
}

async function loadTeams(viewGw = null, { animateIn = false } = {}) {
  setError('');
  if (!animateIn) {
    const board = document.querySelector('[data-ait-board]');
    if (board) board.hidden = true;
    startProgress('Loading AI season');
    setProgress(22, { label: 'Reading season sheet' });
  }

  try {
    if (!animateIn) setProgress(48, { label: 'Simulating gameweeks' });
    const url = new URL(apiUrl('fpl/ai-teams'), window.location.origin);
    if (viewGw) url.searchParams.set('gw', String(viewGw));
    const response = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      if (data.redirect) {
        failProgress();
        window.location.href = pageUrl(String(data.redirect).replace(/^\//, ''));
        return;
      }
      throw new Error(data.error || 'Could not load AI teams.');
    }
    if (!animateIn) setProgress(88, { label: 'Building the pitch' });
    renderBoard(data.ai_teams, { animateIn });
    if (!animateIn) await finishProgress('AI season ready');
  } catch (error) {
    failProgress();
    setError(error.message || 'Could not load AI teams.');
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initTeamTabs();

  const session = await fetchSession();
  if (!session?.authenticated) {
    window.location.href = pageUrl('login');
    return;
  }

  applyAuthNav(session);
  const premium = applyGate(session);

  const tabs = document.querySelector('[data-team-tabs]');
  if (tabs && !reduceMotion) {
    animate(tabs, { opacity: [0, 1], y: [-6, 0] }, { duration: 0.35, easing: [0.17, 0.55, 0.55, 1] });
  }

  if (premium) {
    await loadTeams();
  }
}

boot();
