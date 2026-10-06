import { animate, stagger } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav, scheduleGwTipsHydration } from './site-nav.js?v=20260907pub';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
  redirectToLogin,
  pageUrl,
  isPremiumActive,
  setPageBootstrapping,
} from './auth.js?v=20260907pub';
import { initMfSelects } from './mf-select.js';
import { consumeOneTapFix } from './one-tap-fix.js?v=20260904tips4';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const DETAIL_PROJ_MOBILE_MQ = window.matchMedia('(max-width: 640px)');
const PAGE_SIZE = 12;

function detailProjectionCount() {
  return DETAIL_PROJ_MOBILE_MQ.matches ? 6 : 5;
}

function detailProjectionLabel(fixtureCount) {
  const max = detailProjectionCount();
  const count = fixtureCount == null ? max : Math.min(fixtureCount, max);
  return `Next ${count} GWs`;
}

function detailProjectionFixtures(player, count) {
  const catalogue = state.players.find((p) => p.id === player.id);
  const catalogueFixtures = Array.isArray(catalogue?.fixtures) ? catalogue.fixtures : [];
  const squadFixtures = Array.isArray(player.fixtures) ? player.fixtures : [];
  const source = catalogueFixtures.length >= squadFixtures.length ? catalogueFixtures : squadFixtures;
  return source.slice(0, count);
}
const PROGRESS_SHOW_DELAY_MS = 80;

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

function progressEls() {
  return {
    top: document.querySelector('[data-pick-progress]'),
    topBar: document.querySelector('[data-pick-progress-bar]'),
    panel: document.querySelector('[data-pick-load]'),
    fill: document.querySelector('[data-pick-load-fill]'),
    track: document.querySelector('[data-pick-load-track]'),
    label: document.querySelector('[data-pick-load-label]'),
    pct: document.querySelector('[data-pick-load-pct]'),
    sr: document.querySelector('[data-pick-load-sr]'),
    main: document.querySelector('[data-pick-main]'),
    status: document.querySelector('[data-pick-status]'),
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
  if (els.status && progressUi.visible) els.status.textContent = text;
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

function startProgress(label = 'Getting your squad ready') {
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

async function finishProgress(label = 'Squad ready') {
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

function friendlyLoadError(message) {
  const text = String(message || '');
  if (/timed out|timeout|slow right now|could not reach the fpl/i.test(text)) {
    return 'Your squad is taking longer than usual to load. We are retrying automatically — if this keeps happening, refresh in a minute.';
  }
  return text || 'Could not load your pick team.';
}

function sleep(ms) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

async function fetchPickPayload(path = 'fpl/pick', maxAttempts = 3, gameweekId = null) {
  let lastError = null;
  const gw = gameweekId ?? state.selectedGwId;
  const query = gw ? `?gw=${encodeURIComponent(String(gw))}` : '';
  const requestPath = path.includes('?') ? path : `${path}${query}`;

  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    if (attempt > 0) {
      setProgress(28 + attempt * 8, { label: 'Still loading your squad — retrying…' });
      await sleep(1200 * attempt);
    }

    const response = await fetch(apiUrl(requestPath), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });

    let data = {};
    try {
      data = await response.json();
    } catch {
      data = {};
    }

    if (response.ok && data.ok) {
      return data;
    }

    lastError = new Error(data.error || 'Could not load your pick team.');
    lastError.status = response.status;

    if (response.status === 401 && data.redirect) {
      return { redirect: String(data.redirect).replace(/^\//, '') };
    }

    const retryable = response.status >= 500
      || /slow right now|timed out|timeout|could not reach the fpl/i.test(String(data.error || ''));
    if (!retryable || attempt >= maxAttempts - 1) {
      throw lastError;
    }
  }

  throw lastError || new Error('Could not load your pick team.');
}

const state = {
  premium: false,
  locks: {},
  fplSquad: [],
  fplBank: 0,
  originalSquad: [],
  squad: [],
  players: [],
  bankBase: 0,
  freeTransfers: 1,
  transferCost: 4,
  plannedTransfers: 0,
  transferRestore: {},
  transferRestorePrior: {},
  hasSavedDraft: false,
  editable: true,
  lockedReason: null,
  isLiveLocked: false,
  viewMode: 'plan',
  gameweekOptions: [],
  selectedGwId: null,
  gwPoints: null,
  loadingGw: false,
  isDirty: false,
  saving: false,
  strengthHorizon: 'gw',
  pitchView: 'next',
  mode: 'view', // view | sub | transfer
  selectedId: null,
  suggestedTransfers: [],
  pendingTransferInId: null,
  filters: {
    pos: 'all',
    sort: 'price',
    min: 4,
    max: 15.5,
    q: '',
    affordable: false,
  },
  page: 1,
  event: null,
  strength: { gw: null, five_gw: null },
  predictedPoints: { gw: null, five_gw: null },
  aiNotes: '',
  aiTimer: null,
  aiBusy: false,
  modal: {
    kind: null,
    playerId: null,
    fromPool: false,
  },
};

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function money(value) {
  const n = Number(value);
  if (Number.isNaN(n)) return '£0.0';
  return `£${n.toFixed(1)}`;
}

function pitchFxLabel(label) {
  return String(label || '-')
    .replace(/\s+H\b(?!\))/g, ' (H)')
    .replace(/\s+A\b(?!\))/g, ' (A)');
}

function pitchFxMarkup(label) {
  const parts = pitchFxParts(label);
  const opp = escapeHtml(parts.opp || '-');
  if (!parts.ha) {
    return `<span class="pick-player__fx"><span class="pick-player__fx-opp">${opp}</span></span>`;
  }
  return `<span class="pick-player__fx"><span class="pick-player__fx-opp">${opp}</span><span class="pick-player__fx-ha">${escapeHtml(parts.ha)}</span></span>`;
}

function syncPitchViewUi() {
  const view = state.pitchView || 'next';
  document.querySelector('[data-pick-pitch]')?.setAttribute('data-pick-pitch-view', view);
  document.querySelector('.pick-stage')?.setAttribute('data-pick-pitch-view', view);
}

/** Official FPL FDR scale 1 (easiest) to 5 (hardest). */
function fdrLevel(value) {
  const n = Math.round(Number(value));
  if (!Number.isFinite(n)) return 3;
  return Math.max(1, Math.min(5, n));
}

function fdrBadgeHtml(fdr, size = 'md') {
  const level = fdrLevel(fdr);
  return `<span class="mf-fdr mf-fdr--${level} mf-fdr--${size}" title="Fixture difficulty ${level}" aria-label="Fixture difficulty ${level}">${level}</span>`;
}

function formatStat(value, digits = 1) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  if (Number.isInteger(n)) return String(n);
  return n.toFixed(digits);
}

function priceChangeMeta(player) {
  const pct = Number(player?.price_change_percent);
  const safePct = Number.isFinite(pct) ? pct : 0;
  const rising = safePct >= 0;
  const abs = Math.abs(safePct);
  const calibrating = Boolean(player?.price_change_calibrating);
  const lockedUntil = player?.price_change_locked_until;
  let when = '>1 Week';

  if (lockedUntil) {
    when = 'Locked';
  } else if (calibrating) {
    when = 'Calibrating';
  } else if (abs >= 100) {
    when = 'Tonight';
  } else {
    const projs = Array.isArray(player?.price_change_projections)
      ? player.price_change_projections
      : [];
    const hit = projs.find((row) => Math.abs(Number(row.projected_percent)) >= 100);
    if (hit) {
      const offset = Number(hit.offset) || 0;
      if (offset <= 0) when = 'Tonight';
      else if (offset === 1) when = 'Tomorrow';
      else if (offset <= 3) when = `${offset} Days`;
      else if (offset <= 7) when = '1 Week';
      else when = '>1 Week';
    } else if (abs >= 70) {
      when = 'Soon';
    } else if (abs >= 35) {
      when = '1 Week';
    }
  }

  const bar = Math.max(0, Math.min(100, abs >= 100 ? 100 : abs));
  return {
    pct: safePct,
    rising,
    when,
    bar,
    label: `${safePct > 0 ? '+' : ''}${safePct.toFixed(1)}%`,
  };
}

function priceChangeCardHtml(player) {
  const meta = priceChangeMeta(player);
  const arrow = meta.rising
    ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 14 6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>`
    : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 10 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

  return `
    <span class="pick-price-change ${meta.rising ? 'is-rise' : 'is-fall'}">
      <span class="pick-price-change__left">
        <span class="pick-price-change__arrow">${arrow}</span>
        <span class="pick-price-change__when">${escapeHtml(meta.when)}</span>
      </span>
      <span class="pick-price-change__pct" style="--prc:${meta.bar}%">${escapeHtml(meta.label)}</span>
    </span>
  `;
}

function lockIcon() {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>`;
}

function playerHeadshot(player) {
  if (player?.headshot) {
    return String(player.headshot).replace('/photos/players/250x250/', '/photos/players/110x140/');
  }
  const photo = String(player?.photo || '').replace(/\.jpg$/i, '').trim();
  if (photo) {
    return `https://resources.premierleague.com/premierleague/photos/players/110x140/p${photo}.png`;
  }
  return '';
}

function clubBadgeUrl(player) {
  const code = Number(player?.team_code) || 0;
  if (code < 1) return '';
  return `https://resources.premierleague.com/premierleague/badges/70/t${code}.png`;
}

function modalActionIcon(kind) {
  if (kind === 'captain') {
    return `<span class="pick-detail__action-mark">C</span>`;
  }
  if (kind === 'vice') {
    return `<span class="pick-detail__action-mark">V</span>`;
  }
  if (kind === 'substitute') {
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 3h5v5M8 21H3v-5" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 3 14 10M3 21l7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  }
  if (kind === 'restore') {
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10h10a5 5 0 0 1 5 5v1M3 10l4-4M3 10l4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  }
  // transfer out
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke-linecap="round"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6" stroke-linecap="round"/></svg>`;
}

function showError(message) {
  const el = document.querySelector('[data-pick-error]');
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function applyAiResult(payload) {
  if (payload?.strength) {
    state.strength = {
      gw: Number(payload.strength.gw),
      five_gw: Number(payload.strength.five_gw),
    };
  }
  if (payload?.predicted_points) {
    state.predictedPoints = {
      gw: Number(payload.predicted_points.gw),
      five_gw: Number(payload.predicted_points.five_gw),
    };
  } else if (payload?.predicted_points === null) {
    state.predictedPoints = { gw: null, five_gw: null };
  }
  if (typeof payload?.notes === 'string') {
    state.aiNotes = payload.notes;
  }
  renderSummary();
}

function composeScores(squad) {
  const xi = squad.filter((p) => p.position <= 11);
  if (!xi.length) {
    return {
      strength: { gw: 0, five_gw: 0 },
      predictedPoints: { gw: 0, five_gw: 0 },
    };
  }

  let predGw = 0;
  let pred5 = 0;

  xi.forEach((player) => {
    const projs = Array.isArray(player.ai?.projections)
      ? player.ai.projections.map(Number)
      : [Number(player.ep_next) || 0, 0, 0, 0, 0];
    while (projs.length < 5) projs.push(0);
    // Captain doubles next-GW points only (hub / FPL style).
    const gw0 = Number.isFinite(projs[0]) ? projs[0] : 0;
    const captainMul = player.is_captain ? 2 : 1;
    predGw += gw0 * captainMul;
    pred5 += gw0 * captainMul + projs[1] + projs[2] + projs[3] + projs[4];
  });

  return {
    strength: {
      gw: pointsToStrengthPercent(predGw),
      five_gw: pointsToStrengthPercent((pred5 / 5) * 1.03),
    },
    predictedPoints: {
      gw: Math.round(predGw * 10) / 10,
      five_gw: Math.round(pred5 * 10) / 10,
    },
  };
}

/**
 * Next-GW projection as shown in UI / totals.
 * Captain (starting XI only) doubles GW0; later GWs stay single.
 */
function displayProjection(player, index = 0) {
  const raw = Number(player?.ai?.projections?.[index]);
  if (!Number.isFinite(raw)) return null;
  const onPitch = Number(player?.position) > 0 && Number(player.position) <= 11;
  if (index === 0 && player?.is_captain && onPitch) {
    return Math.round(raw * 2 * 10) / 10;
  }
  return raw;
}

function pointsToStrengthPercent(xiPoints) {
  // Hub-style bands on captain-adjusted XI expected points:
  // 62–65 → 89%, then +1% per 4 pts (66–69 → 90%, …, 100 → 99%).
  // Below 62: same 4-pt step downward.
  const pts = Number(xiPoints);
  if (!Number.isFinite(pts) || pts <= 0) return 5;
  if (pts >= 100) return 99;
  const pct = Math.floor((pts - 62) / 4) + 89;
  return Math.max(5, Math.min(98, pct));
}

function applyLocalScores() {
  const next = composeScores(state.squad);
  state.strength = next.strength;
  if (state.premium && !state.locks.predicted) {
    state.predictedPoints = next.predictedPoints;
  }
  renderSummary();
}

async function requestAiAnalyse() {
  // Instant local recompute from shared scout sheet (no OpenAI).
  applyLocalScores();
  if (!state.premium) return;

  try {
    const response = await fetch(apiUrl('fpl/pick-analyse'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        squad: state.squad,
        bank: bankNow(),
      }),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) return;
    if (data.strength) {
      state.strength = {
        gw: Number(data.strength.gw),
        five_gw: Number(data.strength.five_gw),
      };
    }
    if (data.predicted_points) {
      state.predictedPoints = {
        gw: Number(data.predicted_points.gw),
        five_gw: Number(data.predicted_points.five_gw),
      };
    }
    if (Array.isArray(data.suggested_transfers)) {
      state.suggestedTransfers = data.suggested_transfers;
    }
    if (Array.isArray(data.squad)) {
      // Keep AI projection fields in sync after server enrich.
      const byId = new Map(data.squad.map((p) => [p.id, p]));
      state.squad = state.squad.map((p) => {
        const fresh = byId.get(p.id);
        return fresh?.ai ? { ...p, ai: fresh.ai } : p;
      });
    }
    renderSummary();
  } catch {
    // Local scores already applied.
  }
}

function queueAiAnalyse() {
  if (state.aiTimer) clearTimeout(state.aiTimer);
  applyLocalScores();
  state.aiTimer = window.setTimeout(() => {
    requestAiAnalyse();
  }, 250);
}

function formationCounts(squad) {
  const counts = { 1: 0, 2: 0, 3: 0, 4: 0 };
  squad.filter((p) => p.position <= 11).forEach((p) => {
    counts[p.element_type] = (counts[p.element_type] || 0) + 1;
  });
  return counts;
}

function isValidFormation(squad) {
  const c = formationCounts(squad);
  return c[1] === 1 && c[2] >= 3 && c[3] >= 2 && c[4] >= 1;
}

function reorderSquad(squad) {
  const xi = squad
    .filter((p) => p.position <= 11)
    .sort((a, b) => a.element_type - b.element_type || a.web_name.localeCompare(b.web_name));
  const bench = squad
    .filter((p) => p.position > 11)
    .sort((a, b) => a.position - b.position);

  const next = [...xi, ...bench].map((player, index) => ({
    ...player,
    position: index + 1,
    multiplier: index < 11 ? (player.is_captain ? 2 : 1) : 0,
  }));
  return next;
}

function ownedIds() {
  return new Set(state.squad.map((p) => p.id));
}

function originalById() {
  const map = new Map();
  state.originalSquad.forEach((p) => map.set(p.id, p));
  return map;
}

function transferBaseline() {
  return state.fplSquad.length ? state.fplSquad : state.originalSquad;
}

function countTransfersVsBaseline(squad = state.squad) {
  const original = new Set(transferBaseline().map((p) => p.id));
  let out = 0;
  squad.forEach((p) => {
    if (!original.has(p.id)) out += 1;
  });
  return out;
}

function transferDiff() {
  return state.plannedTransfers;
}

function remainingFreeTransfers() {
  return Math.max(0, (Number(state.freeTransfers) || 0) - state.plannedTransfers);
}

function isRestorablePlayer(playerId) {
  return Object.prototype.hasOwnProperty.call(state.transferRestore, playerId);
}

function restoreTargetName(playerId) {
  return state.transferRestore[playerId]?.web_name || 'previous player';
}

function rebuildTransferRestore() {
  state.transferRestore = {};
  state.transferRestorePrior = {};
  const baseline = transferBaseline();
  const baseIds = new Set(baseline.map((p) => p.id));
  const removed = baseline.filter((p) => !state.squad.some((s) => s.id === p.id));
  const added = state.squad.filter((p) => !baseIds.has(p.id));
  if (!added.length) return;

  const usedRemoved = new Set();
  added.forEach((inPlayer) => {
    let candidates = removed.filter(
      (r) => r.element_type === inPlayer.element_type && !usedRemoved.has(r.id)
    );
    if (!candidates.length) return;
    candidates.sort(
      (a, b) => Math.abs(a.position - inPlayer.position) - Math.abs(b.position - inPlayer.position)
    );
    const outPlayer = candidates[0];
    usedRemoved.add(outPlayer.id);
    state.transferRestore[inPlayer.id] = structuredClone(
      baseline.find((p) => p.id === outPlayer.id) || outPlayer
    );
  });
}

function syncTransferTracking() {
  state.plannedTransfers = countTransfersVsBaseline();
  rebuildTransferRestore();
}

function recordTransfer(out, incoming) {
  const prior = state.transferRestore[out.id] || null;
  if (Object.prototype.hasOwnProperty.call(state.transferRestore, out.id)) {
    delete state.transferRestore[out.id];
    delete state.transferRestorePrior[out.id];
  }

  state.transferRestore[incoming.id] = structuredClone(out);
  if (prior) {
    state.transferRestorePrior[incoming.id] = structuredClone(prior);
  }

  state.plannedTransfers += 1;
}

function restorePreviousPlayer(playerId) {
  const outPlayer = state.transferRestore[playerId];
  if (!outPlayer) return;

  const current = state.squad.find((p) => p.id === playerId);
  if (!current) return;

  const prior = state.transferRestorePrior[playerId] || null;
  const restored = {
    ...structuredClone(outPlayer),
    position: current.position,
    is_captain: current.is_captain,
    is_vice_captain: current.is_vice_captain,
    multiplier: current.multiplier,
  };

  delete state.transferRestore[playerId];
  delete state.transferRestorePrior[playerId];

  state.squad = state.squad.map((p) => (p.id === playerId ? restored : p));
  state.squad = reorderSquad(state.squad);

  const baselineIds = new Set(transferBaseline().map((p) => p.id));
  if (!baselineIds.has(restored.id) && prior) {
    state.transferRestore[restored.id] = structuredClone(prior);
  }

  state.plannedTransfers = Math.max(0, state.plannedTransfers - 1);
  clearInteractionMode();
  showError('');
  renderAll();
  queueAiAnalyse();
}

function squadsMatch(a, b) {
  if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
  const snap = (squad) =>
    squad
      .map(
        (p) =>
          `${p.id}:${p.position}:${p.is_captain ? 1 : 0}:${p.is_vice_captain ? 1 : 0}`
      )
      .sort()
      .join('|');
  return snap(a) === snap(b);
}

function squadFromFplBaseline(fplSquad) {
  return fplSquad.map((p) => {
    const pool = state.players.find((c) => c.id === p.id);
    if (!pool) return structuredClone(p);
    return {
      ...pool,
      ...p,
      position: p.position,
      is_captain: Boolean(p.is_captain),
      is_vice_captain: Boolean(p.is_vice_captain),
      multiplier: p.multiplier,
      purchase_price: p.purchase_price ?? pool.now_cost,
      selling_price: p.selling_price ?? pool.now_cost,
    };
  });
}

function applyFplBaseline(payload) {
  const baseline = Array.isArray(payload?.squad) ? payload.squad : [];
  if (baseline.length !== 15) {
    throw new Error('Your FPL squad could not be loaded right now.');
  }

  state.fplSquad = structuredClone(baseline);
  state.fplBank = Number(payload.bank) || 0;
  if (payload.free_transfers != null) {
    state.freeTransfers = Number(payload.free_transfers) || 0;
  }
  state.squad = squadFromFplBaseline(baseline);
  syncTransferTracking();
}

async function resetToFplTeam() {
  if (!state.editable) return;

  const resetBtn = document.querySelector('[data-reset-team]');
  const saveBtn = document.querySelector('[data-save-team]');
  const resetLabel = resetBtn?.querySelector('.mf-btn__label');
  const previousLabel = resetLabel?.textContent || 'Reset team';
  if (resetBtn) resetBtn.disabled = true;
  if (saveBtn) saveBtn.disabled = true;
  if (resetLabel) resetLabel.textContent = 'Loading from FPL…';
  showError('');

  try {
    const response = await fetch(apiUrl('fpl/pick-baseline'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Could not load your FPL team.');
    }

    applyFplBaseline(data);
    clearInteractionMode();
    renderAll({ animateIn: true });
    queueAiAnalyse();
  } catch (error) {
    showError(error.message || 'Could not reset to your FPL team.');
    renderAll();
  } finally {
    if (resetLabel) resetLabel.textContent = previousLabel;
    if (resetBtn) resetBtn.disabled = !state.editable;
    updateSaveButton();
  }
}

function bankNow() {
  if (state.fplSquad.length && squadsMatch(state.squad, state.fplSquad)) {
    return state.fplBank;
  }

  const originals = originalById();
  let bank = state.bankBase;
  state.originalSquad.forEach((p) => {
    if (!state.squad.some((s) => s.id === p.id)) {
      bank += Number(p.selling_price ?? p.now_cost);
    }
  });
  state.squad.forEach((p) => {
    if (!originals.has(p.id)) {
      bank -= Number(p.now_cost);
    }
  });
  return Math.round(bank * 10) / 10;
}

function squadSnapshot(squad, bank) {
  return JSON.stringify({
    bank,
    players: squad.map((p) => ({
      id: p.id,
      position: p.position,
      is_captain: Boolean(p.is_captain),
      is_vice_captain: Boolean(p.is_vice_captain),
    })),
  });
}

function updateSaveButton() {
  const btn = document.querySelector('[data-save-team]');
  if (!btn) return;
  btn.disabled = !state.editable || !state.isDirty || state.saving;
  const label = btn.querySelector('.mf-btn__label');
  if (label) {
    label.textContent = state.saving ? 'Saving…' : 'Save team';
  }
}

function applyEditLock() {
  const locked = !state.editable;
  document.body.dataset.pickEditable = locked ? 'false' : 'true';
  document.body.classList.toggle('pick-page--locked', locked);

  const banner = document.querySelector('[data-pick-lock-banner]');
  if (banner) {
    banner.hidden = !locked;
    if (locked) {
      if (state.lockedReason === 'past_gameweek') {
        banner.textContent = 'You are viewing a past gameweek. This squad is read only.';
      } else if (state.lockedReason === 'live_gameweek') {
        banner.textContent =
          'This gameweek is live on FPL. We are showing your locked FPL team and formation until the next gameweek opens.';
      } else {
        banner.textContent = 'Your team is locked right now.';
      }
    }
  }

  const saveBtn = document.querySelector('[data-save-team]');
  const resetBtn = document.querySelector('[data-reset-team]');
  const optimiseBtn = document.querySelector('[data-optimise-cta]');
  if (saveBtn) saveBtn.disabled = locked || !state.isDirty || state.saving;
  if (resetBtn) resetBtn.disabled = locked;
  if (optimiseBtn && locked) {
    optimiseBtn.setAttribute('aria-disabled', 'true');
    optimiseBtn.tabIndex = -1;
  } else if (optimiseBtn) {
    optimiseBtn.removeAttribute('aria-disabled');
    optimiseBtn.tabIndex = 0;
  }

  if (locked) {
    clearInteractionMode();
    state.isDirty = false;
  }
}

function markDirty() {
  if (!state.editable) {
    state.isDirty = false;
    updateSaveButton();
    return;
  }
  const nextDirty =
    squadSnapshot(state.squad, bankNow()) !==
    squadSnapshot(state.originalSquad, state.bankBase);
  state.isDirty = nextDirty;
  updateSaveButton();
}

async function saveTeam() {
  if (!state.editable || !state.isDirty || state.saving) return;
  state.saving = true;
  updateSaveButton();
  showError('');

  try {
    const response = await fetch(apiUrl('fpl/pick-save'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        gameweek_id: state.event?.id,
        squad: state.squad,
        bank: bankNow(),
        strength: state.strength,
        predicted_points: state.predictedPoints,
      }),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Could not save your team.');
    }

    state.bankBase = bankNow();
    state.originalSquad = structuredClone(state.squad);
    state.hasSavedDraft = true;
    state.isDirty = false;
    applyAiResult({
      strength: data.strength || state.strength,
      predicted_points: data.predicted_points || state.predictedPoints,
      notes: state.aiNotes,
    });
    renderSummary();
  } catch (error) {
    showError(error.message || 'Could not save your team.');
  } finally {
    state.saving = false;
    updateSaveButton();
  }
}

function hitPoints() {
  const made = transferDiff();
  const extra = Math.max(0, made - state.freeTransfers);
  return extra * state.transferCost;
}

function fixtureLabel(player) {
  if (state.pitchView === 'price') {
    const change = Number(player.cost_change_event) || 0;
    if (change > 0) return `+£${change.toFixed(1)}`;
    if (change < 0) return `-£${Math.abs(change).toFixed(1)}`;
    return '£0.0';
  }
  const take = state.pitchView === 'next3' ? 3 : 1;
  const bits = (player.fixtures || []).slice(0, take).map((f) => f.label);
  return bits.join(' · ') || '-';
}

function availabilityRisk(player) {
  const chance = player.chance_of_playing_next_round;
  const status = String(player.status || '').toLowerCase();
  if (status === 'i' || status === 's' || status === 'u' || chance === 0) {
    return 'out';
  }
  if (status === 'd' || (chance != null && chance < 100)) {
    return 'doubt';
  }
  return null;
}

function riskClass(player) {
  const risk = availabilityRisk(player);
  if (risk === 'out') return ' is-risk-out';
  if (risk === 'doubt') return ' is-risk-doubt';
  return '';
}

function warningIcon(player) {
  const risk = availabilityRisk(player);
  if (!risk) return '';
  const label = risk === 'out' ? 'Unavailable' : 'Doubtful';
  const title = escapeHtml(player.news || label);
  return `<span class="pick-player__warn-wrap" title="${title}" aria-label="${label}"><svg class="pick-player__warn-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg></span>`;
}

function detailWarningIcon(player) {
  const risk = availabilityRisk(player);
  if (!risk) return '';
  const label = risk === 'out' ? 'Unavailable' : 'Doubtful';
  const title = escapeHtml(player.news || label);
  const tone = risk === 'out' ? 'out' : 'doubt';
  return `<span class="pick-detail__warn-wrap pick-detail__warn-wrap--${tone}" title="${title}" aria-label="${label}"><svg class="pick-detail__warn-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg></span>`;
}

function detailStats(player) {
  if (player.element_type === 1) {
    return [
      ['Clean sheets', player.clean_sheets, 'This season'],
      ['Goals conceded', player.goals_conceded, 'This season'],
      ['Points', player.total_points, 'FPL total'],
      ['Saves', player.saves, 'This season'],
      ['Bonus', player.bonus, 'Bonus points'],
      ['Starts', player.starts, 'League starts'],
    ];
  }
  if (player.element_type === 2) {
    return [
      ['Goals', player.goals_scored, 'This season'],
      ['Assists', player.assists, 'This season'],
      ['Points', player.total_points, 'FPL total'],
      ['Clean sheets', player.clean_sheets, 'This season'],
      ['Def con', player.defensive_contribution, 'Defensive contribution'],
      ['Starts', player.starts, 'League starts'],
    ];
  }
  if (player.element_type === 3) {
    return [
      ['Goals', player.goals_scored, 'This season'],
      ['Assists', player.assists, 'This season'],
      ['Points', player.total_points, 'FPL total'],
      ['xGI', player.expected_goal_involvements, 'Underlying output'],
      ['Creativity', player.creativity, 'Chance creation'],
      ['Starts', player.starts, 'League starts'],
    ];
  }
  return [
    ['Goals', player.goals_scored, 'This season'],
    ['Assists', player.assists, 'This season'],
    ['Points', player.total_points, 'FPL total'],
    ['xGI', player.expected_goal_involvements, 'Underlying output'],
    ['Threat', player.threat, 'Shot threat'],
    ['Starts', player.starts, 'League starts'],
  ];
}

function renderProjectionCards(player) {
  const count = detailProjectionCount();
  const fixtures = detailProjectionFixtures(player, count);
  if (state.locks.premium_card_stats) {
    return `
      <div class="pick-detail__locked-panel">
        <span class="pick-detail__locked-icon">${lockIcon()}</span>
        <p class="pick-detail__locked-title">Premium predictions</p>
        <p class="pick-gate__copy">Unlock expected points for the next gameweeks.</p>
        <a class="mf-btn mf-btn--primary mf-btn--sm" href="${pageUrl('join')}">
          <span class="mf-btn__label">Become a member</span>
          <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
      </div>
    `;
  }
  return `
    <div class="pick-detail__proj-grid">
      ${fixtures.map((fixture, index) => {
        const onPitch = Number(player.position) > 0 && Number(player.position) <= 11;
        const isCaptGw = Boolean(player.is_captain && onPitch && index === 0);
        const raw = Number(player.ai?.projections?.[index]);
        const base = Number.isFinite(raw) ? raw : null;
        const shown = base == null ? formatStat(0, 1) : base.toFixed(1);
        const valueHtml = `<span class="pick-detail__proj-value">${escapeHtml(shown)}</span>`;
        return `
        <article class="pick-detail__proj-card">
          <span class="pick-detail__proj-gw">GW ${escapeHtml(String(fixture.event || index + 1))}${isCaptGw ? ' · C' : ''}</span>
          ${valueHtml}
          <span class="pick-detail__proj-fixture">${escapeHtml(fixture.label || '-')}</span>
          ${fdrBadgeHtml(fixture.fdr, 'md')}
        </article>
      `;
      }).join('')}
    </div>
  `;
}

function renderStatCards(player) {
  return `
    <div class="pick-detail__stats-grid">
      ${detailStats(player).map(([label, value]) => `
        <article class="pick-detail__stat-card${state.locks.premium_card_stats ? ' is-locked' : ''}">
          <span class="pick-detail__stat-label">${escapeHtml(String(label))}</span>
          <p class="pick-detail__stat-value">${state.locks.premium_card_stats ? lockIcon() : escapeHtml(formatStat(value))}</p>
        </article>
      `).join('')}
    </div>
  `;
}

function findModalPlayer(id) {
  return (
    state.squad.find((p) => p.id === id)
    || state.players.find((p) => p.id === id)
    || null
  );
}

function renderPlayerModal(player) {
  const modalName = player.web_name || player.display_name || 'Player';
  const ownedPct = Number(player.selected_by_percent) || 0;
  const inSquad = state.squad.some((p) => p.id === player.id);
  const face = playerHeadshot(player);
  const badge = clubBadgeUrl(player);
  const avatar = face
    ? `<img class="pick-detail__face" src="${escapeHtml(face)}" alt="${escapeHtml(modalName)}" loading="lazy" decoding="async" data-player-face>`
    : `<span class="pick-detail__avatar-fallback" aria-hidden="true">${escapeHtml(String(modalName).slice(0, 1))}</span>`;
  const crest = badge
    ? `<img class="pick-detail__crest" src="${escapeHtml(badge)}" alt="" width="22" height="22" loading="lazy" decoding="async">`
    : '';
  const posChip = `<span class="pick-detail__pos">${escapeHtml(player.pos_label || '')}</span>`;

  const outSelected = state.mode === 'transfer' && state.selectedId
    ? state.squad.find((p) => p.id === state.selectedId)
    : null;
  const canTransferInNow = Boolean(
    outSelected && outSelected.element_type === player.element_type
  );

  const restorable = inSquad && isRestorablePlayer(player.id);
  const actions = !state.editable
    ? ''
    : inSquad
    ? `
      <button type="button" class="mf-btn mf-btn--primary mf-btn--sm" data-modal-action="captain" data-modal-player="${player.id}">
        <span class="mf-btn__label">Make captain</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('captain')}</span>
      </button>
      <button type="button" class="mf-btn mf-btn--light mf-btn--sm" data-modal-action="vice" data-modal-player="${player.id}">
        <span class="mf-btn__label">Vice captain</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('vice')}</span>
      </button>
      <button type="button" class="mf-btn mf-btn--ink mf-btn--sm" data-modal-action="substitute" data-modal-player="${player.id}">
        <span class="mf-btn__label">Substitute</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('substitute')}</span>
      </button>
      ${player.position > 11
        ? `<button type="button" class="mf-btn mf-btn--ink mf-btn--sm" data-modal-action="bench-order" data-modal-player="${player.id}">
        <span class="mf-btn__label">Change bench order</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('substitute')}</span>
      </button>`
        : ''}
      ${restorable
        ? `<button type="button" class="mf-btn mf-btn--accent mf-btn--sm" data-modal-action="restore" data-modal-player="${player.id}">
        <span class="mf-btn__label">Restore ${escapeHtml(restoreTargetName(player.id))}</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('restore')}</span>
      </button>`
        : `<button type="button" class="mf-btn mf-btn--accent mf-btn--sm" data-modal-action="transfer" data-modal-player="${player.id}">
        <span class="mf-btn__label">Transfer out</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('transfer')}</span>
      </button>`}
    `
    : `
      <button type="button" class="mf-btn mf-btn--primary mf-btn--sm pick-detail__actions--wide" data-modal-action="transfer-in" data-modal-player="${player.id}">
        <span class="mf-btn__label">${canTransferInNow ? `Transfer in for ${outSelected.web_name}` : 'Transfer in'}</span>
        <span class="mf-btn__icon" aria-hidden="true">${modalActionIcon('transfer')}</span>
      </button>
    `;

  return `
    <section class="pick-detail">
      <header class="pick-detail__head">
        <div class="pick-detail__avatar">
          ${avatar}
          <span class="pick-detail__avatar-meta">
            ${crest}
            ${posChip}
          </span>
        </div>
        <div class="pick-detail__copy">
          <div class="pick-detail__title-row">
            <h2 class="pick-detail__title" id="pick-modal-title">${escapeHtml(modalName)}${detailWarningIcon(player)}</h2>
            <span class="pick-detail__price">${money(player.now_cost)}m</span>
          </div>
          <p class="pick-detail__meta">${escapeHtml(player.team_name)} · ${escapeHtml(player.pos_label)} · ${escapeHtml(ownedPct.toFixed(1))}% owned</p>
        </div>
      </header>

      <section class="pick-detail__section">
        <div class="pick-detail__section-head">
          <h3 class="pick-detail__section-title">Predicted points</h3>
          <span class="pick-detail__section-note">${detailProjectionLabel(detailProjectionFixtures(player, detailProjectionCount()).length)}</span>
        </div>
        ${renderProjectionCards(player)}
      </section>

      <section class="pick-detail__section">
        <div class="pick-detail__section-head">
          <h3 class="pick-detail__section-title">Season stats</h3>
          <span class="pick-detail__section-note">${state.locks.premium_card_stats ? 'Members only' : 'Live'}</span>
        </div>
        ${renderStatCards(player)}
      </section>

      <div class="pick-detail__actions${inSquad ? '' : ' pick-detail__actions--single'}${!state.editable ? ' pick-detail__actions--hidden' : ''}">
        ${actions}
      </div>
    </section>
  `;
}

function renderGateModal(kind) {
  const copy = kind === 'optimise'
    ? {
        title: 'Unlock Optimise lineup',
        body: 'Upgrade to Premium to let Metro Football reorder your current squad for the strongest legal next gameweek lineup.',
      }
    : {
        title: 'Unlock Premium detail',
        body: 'Upgrade to Premium to see predictions, player stats, and richer squad insights across Pick.',
      };
  return `
    <section class="pick-gate">
      <span class="pick-gate__icon">${lockIcon()}</span>
      <h2 class="pick-gate__title" id="pick-modal-title">${escapeHtml(copy.title)}</h2>
      <p class="pick-gate__copy">${escapeHtml(copy.body)}</p>
      <div class="pick-gate__actions">
        <a class="mf-btn mf-btn--primary mf-btn--block" href="${pageUrl('join')}">
          <span class="mf-btn__label">Upgrade now</span>
          <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
      </div>
    </section>
  `;
}

function playerCard(player, { bench = false } = {}) {
  const selected = state.selectedId === player.id ? ' is-selected' : '';
  let interaction = '';
  if (state.mode === 'sub' && state.selectedId) {
    if (player.id !== state.selectedId) {
      interaction = canSwapPlayers(state.selectedId, player.id) ? ' is-sub-target' : ' is-dimmed';
    }
  } else if (state.mode === 'transfer' && state.selectedId && player.id !== state.selectedId) {
    interaction = ' is-dimmed';
  }

  const badge = player.is_captain
    ? '<span class="pick-player__badge" aria-label="Captain">C</span>'
    : player.is_vice_captain
      ? '<span class="pick-player__badge pick-player__badge--vice" aria-label="Vice captain">V</span>'
      : '';
  const pos = bench
    ? `<span class="pick-player__pos">${escapeHtml(player.pos_label)}</span>`
    : '';

  let gwCells = '';
  if (state.viewMode !== 'plan') {
    const scored = bench
      ? Number(player.points ?? player.team_points ?? 0)
      : Number(player.team_points ?? player.points ?? 0);
    const fx = (player.fixtures || [])[0];
    const fxMarkup = fx
      ? pitchFxMarkup(fx.label || '-')
      : `<span class="pick-player__fx"><span class="pick-player__fx-opp">-</span></span>`;
    gwCells = `
      <span class="pick-player__gw pick-player__gw--single">
        <span class="pick-player__score" title="Gameweek points">${escapeHtml(String(scored))}</span>
        ${fxMarkup}
      </span>
    `;
  } else if (state.pitchView === 'price') {
    gwCells = priceChangeCardHtml(player);
  } else {
    const take = state.pitchView === 'next3' ? 3 : 1;
    const fixtures = (player.fixtures || []).slice(0, take);
    gwCells = fixtures
      .map((fx, index) => {
        let pred;
        if (state.locks.premium_card_stats) {
          pred = `<span class="pick-player__score pick-player__score--locked" title="Predicted score unlocks with Premium" aria-label="Predicted score locked">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>
          </span>`;
        } else {
          const raw = Number(player.ai?.projections?.[index]);
          const base = Number.isFinite(raw) ? raw : null;
          const shown = base == null ? '—' : base.toFixed(1);
          pred = `<span class="pick-player__score" title="Predicted points">${escapeHtml(shown)}</span>`;
        }
        const showFdr = state.pitchView !== 'next3';
        const fxMarkup = state.pitchView === 'next3'
          ? pitchFxMarkup(fx.label || '-')
          : `<span class="pick-player__fx">${escapeHtml(pitchFxLabel(fx.label || '-'))}</span>`;
        return `
          <span class="pick-player__gw${take === 1 ? ' pick-player__gw--single' : ''}">
            ${pred}
            ${fxMarkup}
            ${showFdr ? fdrBadgeHtml(fx.fdr, 'sm') : ''}
          </span>
        `;
      })
      .join('');
  }

  const shirt = player.shirt
    ? `<img class="pick-player__shirt" src="${escapeHtml(player.shirt)}" alt="" width="52" height="68" loading="lazy" decoding="async">`
    : `<span class="pick-player__shirt pick-player__shirt--fallback" aria-hidden="true"></span>`;

  const risk = riskClass(player);
  const warn = warningIcon(player);
  const projClass = state.viewMode !== 'plan' || state.pitchView !== 'next3'
    ? ' pick-player__proj--single'
    : ' pick-player__proj--next3';

  return `
    <button type="button" class="pick-player${selected}${interaction}${risk}" data-squad-id="${player.id}" aria-pressed="${state.selectedId === player.id}">
      ${pos}
      <div class="pick-player__kit">
        ${badge}
        ${shirt}
      </div>
      <span class="pick-player__card">
        <span class="pick-player__top">
          <span class="pick-player__name">${warn}${escapeHtml(player.web_name)}</span>
          <span class="pick-player__price">${money(player.now_cost)}</span>
        </span>
        <span class="pick-player__proj${projClass}">
          ${gwCells}
        </span>
      </span>
    </button>
  `;
}

function renderPitch() {
  syncPitchViewUi();
  const rows = { gkp: [], def: [], mid: [], fwd: [] };
  state.squad
    .filter((p) => p.position <= 11)
    .forEach((p) => {
      rows[p.row]?.push(p);
    });

  Object.entries(rows).forEach(([row, players]) => {
    const el = document.querySelector(`[data-pitch-row="${row}"]`);
    if (!el) return;
    el.dataset.count = String(players.length);
    el.innerHTML = players.map((p) => playerCard(p)).join('');
  });

  const bench = document.querySelector('[data-pick-bench]');
  if (bench) {
    const benchPlayers = state.squad
      .filter((p) => p.position > 11)
      .sort((a, b) => a.position - b.position);
    bench.dataset.count = String(benchPlayers.length);
    bench.innerHTML = benchPlayers.map((p) => playerCard(p, { bench: true })).join('');
  }
}

function filteredPlayers() {
  const owned = ownedIds();
  const bank = bankNow();
  let list = state.players.filter((p) => !owned.has(p.id));

  if (state.mode === 'transfer' && state.selectedId) {
    const selected = state.squad.find((p) => p.id === state.selectedId);
    if (selected) {
      list = list.filter((p) => p.element_type === selected.element_type);
    }
  } else if (state.filters.pos !== 'all') {
    list = list.filter((p) => String(p.element_type) === String(state.filters.pos));
  }

  list = list.filter(
    (p) => p.now_cost >= state.filters.min && p.now_cost <= state.filters.max
  );

  if (state.filters.affordable) {
    list = list.filter((p) => {
      if (!(state.mode === 'transfer' && state.selectedId)) {
        return p.now_cost <= bank + 0.05;
      }
      const out = state.squad.find((s) => s.id === state.selectedId);
      const sell = Number(out?.selling_price ?? out?.now_cost ?? 0);
      return p.now_cost <= bank + sell + 0.05;
    });
  }

  const q = state.filters.q.trim().toLowerCase();
  if (q) {
    list = list.filter(
      (p) =>
        p.web_name.toLowerCase().includes(q) ||
        p.team_short.toLowerCase().includes(q) ||
        p.team_name.toLowerCase().includes(q)
    );
  }

  list.sort((a, b) => {
    switch (state.filters.sort) {
      case 'form':
        return Number(b.form) - Number(a.form);
      case 'points':
        return b.total_points - a.total_points;
      case 'name':
        return a.web_name.localeCompare(b.web_name);
      default:
        return b.now_cost - a.now_cost;
    }
  });

  return list;
}

function gwRangeLabel(fixtures = []) {
  const events = fixtures
    .slice(0, 3)
    .map((f) => Number(f.event))
    .filter((n) => Number.isFinite(n) && n > 0);
  if (events.length >= 2) {
    return `GW ${events[0]} - ${events[events.length - 1]}`;
  }
  if (events.length === 1) {
    return `GW ${events[0]}`;
  }
  const start = Number(state.event?.id) || 1;
  return `GW ${start} - ${start + 2}`;
}

function pitchFxParts(label) {
  const raw = pitchFxLabel(label);
  const match = String(raw).match(/^(.*?)(?:\s*)(\([HA]\))$/i);
  if (match) {
    return { opp: match[1].trim(), ha: match[2].toUpperCase() };
  }
  const loose = String(raw).match(/^(.*?)(?:\s+)([HA])$/i);
  if (loose) {
    return { opp: loose[1].trim(), ha: `(${loose[2].toUpperCase()})` };
  }
  return { opp: raw, ha: '' };
}

function fixtureFxMarkup(label) {
  const parts = pitchFxParts(label);
  const opp = escapeHtml(parts.opp || '-');
  if (!parts.ha) {
    return `<span class="pick-row__fx"><span class="pick-row__fx-opp">${opp}</span></span>`;
  }
  return `<span class="pick-row__fx"><span class="pick-row__fx-opp">${opp}</span><span class="pick-row__fx-ha">${escapeHtml(parts.ha)}</span></span>`;
}

function fixturePredCell(player, fixture, index) {
  const fxMarkup = fixtureFxMarkup(fixture.label || '-');
  const fdr = state.pitchView === 'next3' ? '' : fdrBadgeHtml(fixture.fdr, 'sm');
  if (state.locks.premium_card_stats) {
    return `
      <span class="pick-row__gw">
        <span class="pick-row__pred pick-row__pred--locked" title="Predicted score unlocks with Premium" aria-label="Predicted score locked">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>
        </span>
        ${fxMarkup}
        ${fdr}
      </span>
    `;
  }

  const value = Number(player.ai?.projections?.[index]);
  const score = Number.isFinite(value) ? value.toFixed(1) : '—';

  return `
    <span class="pick-row__gw">
      <span class="pick-row__pred">${escapeHtml(score)}</span>
      ${fxMarkup}
      ${fdr}
    </span>
  `;
}

function renderList() {
  const list = filteredPlayers();
  const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
  state.page = Math.min(state.page, pages);
  const start = (state.page - 1) * PAGE_SIZE;
  const slice = list.slice(start, start + PAGE_SIZE);
  const root = document.querySelector('[data-player-list]');
  if (!root) return;

  const sampleFixtures = slice[0]?.fixtures || state.players[0]?.fixtures || [];
  const rangeEl = document.querySelector('[data-gw-range-label]');
  if (rangeEl) rangeEl.textContent = gwRangeLabel(sampleFixtures);

  root.innerHTML = slice
    .map((player) => {
      const fixtures = (player.fixtures || []).slice(0, 3);
      const gwCells = fixtures.map((fx, index) => fixturePredCell(player, fx, index)).join('');
      const prc = priceChangeMeta(player);
      return `
        <button type="button" class="pick-row${riskClass(player)}" data-pool-id="${player.id}">
          <span class="pick-row__player">
            <img class="pick-row__shirt" src="${escapeHtml(player.shirt || '')}" alt="" width="28" height="36" loading="lazy" decoding="async">
            <span class="pick-row__copy">
              <span class="pick-row__name">${warningIcon(player)}${escapeHtml(player.web_name)}</span>
              <span class="pick-row__meta">${escapeHtml(player.team_short)} · ${escapeHtml(player.pos_label)}</span>
            </span>
          </span>
          <span class="pick-row__price">${money(player.now_cost)}m</span>
          <span class="pick-row__fixtures">${gwCells}</span>
          <span class="pick-row__prc ${prc.rising ? 'is-rise' : 'is-fall'}" title="${escapeHtml(prc.when)}">${escapeHtml(prc.label)}</span>
        </button>
      `;
    })
    .join('');

  const label = document.querySelector('[data-page-label]');
  if (label) label.textContent = `Page ${state.page}`;
  const prev = document.querySelector('[data-page-prev]');
  const next = document.querySelector('[data-page-next]');
  if (prev) prev.disabled = state.page <= 1;
  if (next) next.disabled = state.page >= pages;
}

function renderSummary() {
  const reviewMode = state.viewMode !== 'plan';
  const five = state.strengthHorizon === '5gw';
  const strengthVal = five ? state.strength.five_gw : state.strength.gw;
  const strengthEl = document.querySelector('[data-pick-strength]');
  if (strengthEl) {
    if (reviewMode) {
      strengthEl.textContent = '—';
    } else {
      strengthEl.textContent =
        strengthVal == null || Number.isNaN(Number(strengthVal))
          ? '…'
          : `${Math.round(Number(strengthVal))}%`;
    }
  }

  const predictedEl = document.querySelector('[data-pick-predicted]');
  const predictedLock = document.querySelector('[data-predicted-lock]');
  const predictedBox = document.querySelector('[data-lock-predicted]');
  const predictedLabel = document.querySelector('[data-pick-predicted-label]');
  const predictedVal = five ? state.predictedPoints.five_gw : state.predictedPoints.gw;
  const showPredicted = reviewMode
    ? state.gwPoints != null
    : (state.premium && !state.locks.predicted);

  if (predictedLabel) {
    predictedLabel.textContent = reviewMode ? 'Points' : 'Predicted';
  }
  if (predictedBox) predictedBox.classList.toggle('pick-metric--locked', !showPredicted);
  if (predictedLock) predictedLock.hidden = showPredicted;
  if (predictedEl) {
    if (showPredicted) {
      predictedEl.hidden = false;
      if (reviewMode) {
        predictedEl.textContent = String(Number(state.gwPoints) || 0);
      } else {
        predictedEl.textContent =
          predictedVal == null || Number.isNaN(Number(predictedVal))
            ? '…'
            : Number(predictedVal).toFixed(1);
      }
    } else {
      predictedEl.hidden = true;
      predictedEl.textContent = '-';
    }
  }

  const bankEl = document.querySelector('[data-pick-bank]');
  if (bankEl) bankEl.textContent = `${money(bankNow())}m`;

  const ftEl = document.querySelector('[data-pick-free-transfers]');
  const ftLabel = document.querySelector('[data-pick-ft-label]');
  if (ftLabel) {
    ftLabel.textContent = reviewMode ? 'Transfers made' : 'Available transfers';
  }
  if (ftEl) {
    if (reviewMode) {
      ftEl.textContent = String(Number(state.freeTransfers) || 0);
      ftEl.classList.remove('pick-metric__value--ft-zero');
    } else {
      const available = remainingFreeTransfers();
      ftEl.textContent = String(available);
      ftEl.classList.toggle('pick-metric__value--ft-zero', available === 0);
    }
  }

  const hitsEl = document.querySelector('[data-pick-hits]');
  const hits = hitPoints();
  const made = transferDiff();
  if (hitsEl) {
    if (!reviewMode && made > 0 && hits > 0) {
      hitsEl.hidden = false;
      hitsEl.textContent = `${made} transfer${made === 1 ? '' : 's'} planned · -${hits} pts`;
    } else {
      hitsEl.hidden = true;
      hitsEl.textContent = '';
    }
  }

  const modeLabel = document.querySelector('[data-pick-mode-label]');
  if (modeLabel) {
    if (reviewMode) {
      modeLabel.textContent = 'Viewing this gameweek squad';
    } else if (state.mode === 'transfer' && state.selectedId) {
      const p = state.squad.find((s) => s.id === state.selectedId);
      modeLabel.textContent = p
        ? `Pick a ${p.pos_label} from the list to replace ${p.web_name}`
        : 'Choose a replacement from the list';
    } else if (state.mode === 'sub' && state.selectedId) {
      const p = state.squad.find((s) => s.id === state.selectedId);
      const onPitch = (p?.position || 0) <= 11;
      modeLabel.textContent = p
        ? onPitch
          ? `Choose a bench player to sub in for ${p.web_name}`
          : `Choose a starter to sub out for ${p.web_name}`
        : 'Choose a player to complete the sub';
    } else {
      modeLabel.textContent = 'Tap a player for options';
    }
  }

  renderGameweekNav();
}

function renderAll({ animateIn = false } = {}) {
  renderSummary();
  renderPitch();
  renderList();
  markDirty();
  if (state.modal.kind) {
    renderModal();
  }

  if (animateIn && !reduceMotion) {
    const cards = document.querySelectorAll('.pick-player');
    animate(
      cards,
      { opacity: [0, 1], y: [10, 0] },
      { duration: 0.28, delay: stagger(0.015), easing: [0.17, 0.55, 0.55, 1] }
    );
  }
}

function renderModal() {
  const shell = document.querySelector('[data-pick-modal]');
  const body = document.querySelector('[data-pick-modal-body]');
  const dialog = document.querySelector('[data-pick-modal-dialog]');
  if (!shell || !body || !dialog) return;

  if (!state.modal.kind) {
    shell.hidden = true;
    body.innerHTML = '';
    document.body.style.overflow = '';
    return;
  }

  let html = '';
  if (state.modal.kind === 'player') {
    const player = findModalPlayer(state.modal.playerId);
    if (!player) {
      state.modal.kind = null;
      state.modal.playerId = null;
      state.modal.fromPool = false;
      shell.hidden = true;
      body.innerHTML = '';
      document.body.style.overflow = '';
      return;
    }
    html = renderPlayerModal(player);
  } else {
    html = renderGateModal(state.modal.kind);
  }

  body.innerHTML = html;
  const faceImg = body.querySelector('[data-player-face]');
  if (faceImg) {
    faceImg.addEventListener('error', () => {
      const player = findModalPlayer(state.modal.playerId);
      if (player?.shirt) {
        faceImg.src = player.shirt;
        faceImg.classList.add('pick-detail__face--shirt');
        return;
      }
      const initial = String(player?.web_name || player?.display_name || '?').slice(0, 1);
      const fallback = document.createElement('span');
      fallback.className = 'pick-detail__avatar-fallback';
      fallback.setAttribute('aria-hidden', 'true');
      fallback.textContent = initial;
      faceImg.replaceWith(fallback);
    }, { once: true });
  }
  shell.hidden = false;
  document.body.style.overflow = 'hidden';
  dialog.focus();
}

function openPlayerModal(id, { fromPool = false } = {}) {
  state.modal.kind = 'player';
  state.modal.playerId = id;
  state.modal.fromPool = fromPool;
  renderModal();
  if (!reduceMotion) {
    const dialog = document.querySelector('[data-pick-modal-dialog]');
    if (dialog) {
      animate(dialog, { opacity: [0, 1], y: [10, 0], scale: [0.98, 1] }, { duration: 0.2, easing: 'ease-out' });
    }
  }
}

function openGateModal(kind) {
  state.modal.kind = kind;
  state.modal.playerId = null;
  state.modal.fromPool = false;
  renderModal();
  if (!reduceMotion) {
    const dialog = document.querySelector('[data-pick-modal-dialog]');
    if (dialog) {
      animate(dialog, { opacity: [0, 1], y: [10, 0], scale: [0.98, 1] }, { duration: 0.2, easing: 'ease-out' });
    }
  }
}

function closeModal() {
  state.modal.kind = null;
  state.modal.playerId = null;
  state.modal.fromPool = false;
  renderModal();
}

function setCaptain(id, vice = false) {
  const chosen = state.squad.find((player) => player.id === id);
  if (!chosen || chosen.position > 11) {
    showError('Captain and vice captain must be in your starting XI.');
    return;
  }

  if (vice) {
    state.squad = state.squad.map((player) => ({
      ...player,
      is_vice_captain: player.id === id,
      is_captain: player.id === id ? false : player.is_captain,
    }));
  } else {
    let fallbackVice = state.squad.find((player) => player.is_vice_captain && player.id !== id && player.position <= 11);
    if (!fallbackVice) {
      fallbackVice = state.squad.find((player) => player.id !== id && player.position <= 11);
    }
    state.squad = state.squad.map((player) => ({
      ...player,
      is_captain: player.id === id,
      is_vice_captain: player.id === id ? false : Boolean(fallbackVice && player.id === fallbackVice.id),
    }));
  }

  state.squad = reorderSquad(state.squad);
  applyLocalScores();
  renderAll();
  queueAiAnalyse();
}

function optimizeLineup() {
  if (!state.editable) return;
  const byPos = {
    1: state.squad.filter((p) => p.element_type === 1),
    2: state.squad.filter((p) => p.element_type === 2),
    3: state.squad.filter((p) => p.element_type === 3),
    4: state.squad.filter((p) => p.element_type === 4),
  };
  const projection = (player) => Number(player?.ai?.projections?.[0] ?? player?.ep_next ?? 0);
  Object.values(byPos).forEach((list) => list.sort((a, b) => projection(b) - projection(a)));

  const formations = [
    [3, 4, 3], [3, 5, 2], [4, 4, 2], [4, 3, 3], [4, 5, 1], [5, 4, 1], [5, 3, 2], [5, 2, 3],
  ];
  let best = null;

  formations.forEach(([defCount, midCount, fwdCount]) => {
    if (byPos[2].length < defCount || byPos[3].length < midCount || byPos[4].length < fwdCount || byPos[1].length < 1) {
      return;
    }
    const starters = [
      ...byPos[1].slice(0, 1),
      ...byPos[2].slice(0, defCount),
      ...byPos[3].slice(0, midCount),
      ...byPos[4].slice(0, fwdCount),
    ];
    const total = starters.reduce((sum, player) => sum + projection(player), 0);
    if (!best || total > best.total) {
      best = { starters, total };
    }
  });

  if (!best) return;

  const starterIds = new Set(best.starters.map((player) => player.id));
  const starters = state.squad
    .filter((player) => starterIds.has(player.id))
    .sort((a, b) => a.element_type - b.element_type || projection(b) - projection(a));
  const bench = state.squad
    .filter((player) => !starterIds.has(player.id))
    .sort((a, b) => {
      if (a.element_type === 1 && b.element_type !== 1) return -1;
      if (b.element_type === 1 && a.element_type !== 1) return 1;
      return projection(b) - projection(a);
    });

  const topStarter = starters.reduce((bestPlayer, player) => (
    !bestPlayer || projection(player) > projection(bestPlayer) ? player : bestPlayer
  ), null);
  let viceId = null;
  starters.forEach((player) => {
    if (!topStarter || player.id === topStarter.id) return;
    if (!viceId || projection(player) > projection(starters.find((p) => p.id === viceId))) {
      viceId = player.id;
    }
  });

  state.squad = [...starters, ...bench].map((player, index) => ({
    ...player,
    position: index + 1,
    is_captain: Boolean(topStarter && player.id === topStarter.id),
    is_vice_captain: Boolean(viceId && player.id === viceId),
    multiplier: index < 11 ? (topStarter && player.id === topStarter.id ? 2 : 1) : 0,
  }));
  clearInteractionMode();
  closeModal();
  renderAll();
  queueAiAnalyse();
}

function setMode(mode, { selectedId = null, pendingTransferInId = null } = {}) {
  if (!state.editable && mode !== 'view') return;
  state.mode = mode;
  state.selectedId = selectedId;
  state.pendingTransferInId = pendingTransferInId;
  document.body.dataset.pickMode = mode;
  renderAll();
}

function clearInteractionMode() {
  state.mode = 'view';
  state.selectedId = null;
  state.pendingTransferInId = null;
  document.body.dataset.pickMode = 'view';
}

function canSwapPlayers(aId, bId) {
  const a = state.squad.find((p) => p.id === aId);
  const b = state.squad.find((p) => p.id === bId);
  if (!a || !b || aId === bId) return false;

  const aStarter = a.position <= 11;
  const bStarter = b.position <= 11;
  if (aStarter === bStarter) return false;

  if (a.element_type === 1 || b.element_type === 1) {
    if (a.element_type !== b.element_type) return false;
  }

  const trial = state.squad.map((p) => {
    if (p.id === aId) return { ...p, position: b.position };
    if (p.id === bId) return { ...p, position: a.position };
    return { ...p };
  });
  return isValidFormation(reorderSquad(trial));
}

function canSwapBenchOrder(aId, bId) {
  const a = state.squad.find((p) => p.id === aId);
  const b = state.squad.find((p) => p.id === bId);
  return Boolean(a && b && a.position > 11 && b.position > 11 && aId !== bId);
}

function swapBenchOrder(aId, bId) {
  if (!canSwapBenchOrder(aId, bId)) return false;
  const a = state.squad.find((p) => p.id === aId);
  const b = state.squad.find((p) => p.id === bId);
  if (!a || !b) return false;
  const aPos = a.position;
  a.position = b.position;
  b.position = aPos;
  state.squad = reorderSquad(state.squad);
  applyLocalScores();
  return true;
}

function applyBenchOrder(orderedIds) {
  if (!Array.isArray(orderedIds) || !orderedIds.length) return false;
  const starters = state.squad.filter((p) => p.position <= 11);
  const benchById = new Map(
    state.squad.filter((p) => p.position > 11).map((p) => [p.id, p])
  );
  const orderedBench = [];
  orderedIds.forEach((id) => {
    const player = benchById.get(Number(id));
    if (player) {
      orderedBench.push(player);
      benchById.delete(Number(id));
    }
  });
  benchById.forEach((player) => orderedBench.push(player));
  state.squad = [...starters, ...orderedBench].map((player, index) => ({
    ...player,
    position: index + 1,
    multiplier: index < 11 ? (player.is_captain ? 2 : 1) : 0,
  }));
  return true;
}

function showOneTapBanner(message) {
  const banner = document.querySelector('[data-pick-onetap-banner]');
  if (!banner) {
    showError(message);
    return;
  }
  banner.textContent = message;
  banner.hidden = false;
}

function applyOneTapTransfer(outId, inId) {
  const out = state.squad.find((p) => p.id === outId);
  const incoming = state.players.find((p) => p.id === inId);
  if (!out || !incoming) return false;
  state.selectedId = outId;
  transferIn(inId);
  return true;
}

function applyOneTapFix(fix) {
  if (!fix || !state.editable) return false;
  let ok = false;

  if (fix.type === 'captain' && fix.captainId) {
    setCaptain(Number(fix.captainId), false);
    if (fix.viceId) setCaptain(Number(fix.viceId), true);
    ok = true;
  } else if (fix.type === 'transfer' && fix.outId && fix.inId) {
    ok = applyOneTapTransfer(Number(fix.outId), Number(fix.inId));
  } else if (fix.type === 'bench' && Array.isArray(fix.order)) {
    ok = applyBenchOrder(fix.order.map(Number));
    if (ok) {
      applyLocalScores();
      renderAll();
    }
  }

  if (ok) {
    state.isDirty = true;
    updateSaveButton();
    showOneTapBanner(fix.message || 'We applied your change. Press Save to keep it.');
  }
  return ok;
}

function swapPlayers(aId, bId) {
  if (!canSwapPlayers(aId, bId)) {
    showError('That sub would break FPL formation rules (min 3 DEF, 2 MID, 1 FWD).');
    return false;
  }

  const a = state.squad.find((p) => p.id === aId);
  const b = state.squad.find((p) => p.id === bId);
  if (!a || !b) return false;

  const aPos = a.position;
  const bPos = b.position;
  a.position = bPos;
  b.position = aPos;

  // Keep captain on the pitch.
  if (a.position > 11 && a.is_captain) {
    a.is_captain = false;
    b.is_captain = true;
    b.is_vice_captain = false;
  }
  if (b.position > 11 && b.is_captain) {
    b.is_captain = false;
    a.is_captain = true;
    a.is_vice_captain = false;
  }

  state.squad = reorderSquad(state.squad);
  showError('');
  applyLocalScores();
  return true;
}

function transferIn(poolId) {
  const out = state.squad.find((p) => p.id === state.selectedId);
  const incoming = state.players.find((p) => p.id === poolId);
  if (!out || !incoming) return;
  if (out.element_type !== incoming.element_type) {
    showError('Transfers must keep the same position.');
    return;
  }
  if (ownedIds().has(incoming.id)) {
    showError('That player is already in your squad.');
    return;
  }

  const sell = Number(out.selling_price ?? out.now_cost);
  const nextBank = bankNow() + sell - Number(incoming.now_cost);
  if (nextBank < -0.05) {
    showError('Not enough money in the bank for that transfer.');
    return;
  }

  const teamCount = state.squad.filter(
    (p) => p.team === incoming.team && p.id !== out.id
  ).length;
  if (teamCount >= 3) {
    showError('You can only have three players from the same club.');
    return;
  }

  const replacement = {
    ...incoming,
    position: out.position,
    is_captain: out.is_captain,
    is_vice_captain: out.is_vice_captain,
    multiplier: out.multiplier,
    purchase_price: incoming.now_cost,
    selling_price: incoming.now_cost,
  };

  state.squad = state.squad.map((p) => (p.id === out.id ? replacement : p));
  state.squad = reorderSquad(state.squad);
  recordTransfer(out, replacement);
  clearInteractionMode();
  showError('');
  renderAll();
  queueAiAnalyse();
}

function onSquadClick(id) {
  if (!state.editable) {
    openPlayerModal(id);
    return;
  }

  // Transfer mode: pitch/bench are locked except cancelling the selected out player.
  if (state.mode === 'transfer' && state.selectedId) {
    if (id === state.selectedId) {
      clearInteractionMode();
      showError('');
      renderAll();
      return;
    }
    return;
  }

  // Bench order mode: swap priority between two bench players.
  if (state.mode === 'bench-order' && state.selectedId) {
    if (id === state.selectedId) {
      clearInteractionMode();
      showError('');
      renderAll();
      return;
    }
    if (!canSwapBenchOrder(state.selectedId, id)) {
      showError('Choose another bench player to swap order with.');
      return;
    }
    const ok = swapBenchOrder(state.selectedId, id);
    clearInteractionMode();
    if (ok) {
      state.isDirty = true;
      updateSaveButton();
      renderAll();
    } else {
      renderAll();
    }
    return;
  }

  // Sub mode: pick the other half of the swap.
  if (state.mode === 'sub' && state.selectedId) {
    if (id === state.selectedId) {
      clearInteractionMode();
      showError('');
      renderAll();
      return;
    }
    if (!canSwapPlayers(state.selectedId, id)) {
      showError('Choose a valid starter or bench partner for that sub.');
      return;
    }
    const ok = swapPlayers(state.selectedId, id);
    clearInteractionMode();
    if (ok) {
      renderAll();
      queueAiAnalyse();
    } else {
      renderAll();
    }
    return;
  }

  // Default: always open the player modal.
  openPlayerModal(id);
}

function bindUi() {
  bindGameweekNav();

  document.querySelectorAll('[data-strength-horizon]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.strengthHorizon = btn.getAttribute('data-strength-horizon') || 'gw';
      document.querySelectorAll('[data-strength-horizon]').forEach((b) => {
        b.classList.toggle('is-active', b === btn);
      });
      renderSummary();
    });
  });

  document.querySelectorAll('[data-pitch-view]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (state.viewMode !== 'plan') return;
      state.pitchView = btn.getAttribute('data-pitch-view') || 'next';
      document.querySelectorAll('[data-pitch-view]').forEach((b) => {
        b.classList.toggle('is-active', b === btn);
      });
      syncPitchViewUi();
      renderPitch();
      renderList();
    });
  });

  document.querySelector('[data-save-team]')?.addEventListener('click', () => {
    saveTeam();
  });

  document.querySelector('[data-reset-team]')?.addEventListener('click', () => {
    resetToFplTeam();
  });

  document.querySelector('[data-optimise-cta]')?.addEventListener('click', (e) => {
    e.preventDefault();
    if (!state.editable) return;
    if (state.locks.optimise) {
      openGateModal('optimise');
      return;
    }
    optimizeLineup();
  });

  document.querySelectorAll('[data-pos-filter]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.filters.pos = btn.getAttribute('data-pos-filter') || 'all';
      state.page = 1;
      document.querySelectorAll('[data-pos-filter]').forEach((b) => {
        b.classList.toggle('is-active', b === btn);
      });
      renderList();
    });
  });

  document.querySelector('[data-sort-by]')?.addEventListener('mf-select:change', (e) => {
    state.filters.sort = e.detail?.value || 'price';
    state.page = 1;
    renderList();
  });

  const minInput = document.querySelector('[data-price-min]');
  const maxInput = document.querySelector('[data-price-max]');
  const priceTrack = document.querySelector('[data-price-track]');
  const priceFloor = 4;
  const priceCeil = 15.5;
  const syncPrice = () => {
    if (!minInput || !maxInput) return;
    let min = Number(minInput.value);
    let max = Number(maxInput.value);
    if (min > max) {
      if (document.activeElement === minInput) max = min;
      else min = max;
      minInput.value = String(min);
      maxInput.value = String(max);
    }
    state.filters.min = min;
    state.filters.max = max;
    const minLabel = document.querySelector('[data-price-min-label]');
    const maxLabel = document.querySelector('[data-price-max-label]');
    if (minLabel) minLabel.textContent = min.toFixed(1);
    if (maxLabel) maxLabel.textContent = max.toFixed(1);
    if (priceTrack) {
      const span = priceCeil - priceFloor;
      const minPct = ((min - priceFloor) / span) * 100;
      const maxPct = ((max - priceFloor) / span) * 100;
      priceTrack.style.setProperty('--price-min', `${minPct}%`);
      priceTrack.style.setProperty('--price-max', `${maxPct}%`);
    }
    state.page = 1;
    renderList();
  };
  minInput?.addEventListener('input', syncPrice);
  maxInput?.addEventListener('input', syncPrice);
  syncPrice();

  document.querySelector('[data-player-search]')?.addEventListener('input', (e) => {
    state.filters.q = e.target.value || '';
    state.page = 1;
    renderList();
  });

  document.querySelector('[data-affordable-only]')?.addEventListener('change', (e) => {
    state.filters.affordable = Boolean(e.target.checked);
    state.page = 1;
    renderList();
  });

  document.querySelector('[data-page-prev]')?.addEventListener('click', () => {
    state.page = Math.max(1, state.page - 1);
    renderList();
  });
  document.querySelector('[data-page-next]')?.addEventListener('click', () => {
    state.page += 1;
    renderList();
  });

  document.querySelector('[data-pick-pitch]')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-squad-id]');
    if (!btn) return;
    onSquadClick(Number(btn.getAttribute('data-squad-id')));
  });
  document.querySelector('[data-pick-bench]')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-squad-id]');
    if (!btn) return;
    onSquadClick(Number(btn.getAttribute('data-squad-id')));
  });
  document.querySelector('[data-player-list]')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-pool-id]');
    if (!btn) return;
    const id = Number(btn.getAttribute('data-pool-id'));
    if (!state.editable) {
      openPlayerModal(id, { fromPool: true });
      return;
    }
    if (state.mode === 'transfer' && state.selectedId) {
      transferIn(id);
      return;
    }
    openPlayerModal(id, { fromPool: true });
  });

  document.querySelector('[data-pick-modal]')?.addEventListener('click', (e) => {
    if (e.target.closest('[data-pick-modal-close]')) {
      closeModal();
      return;
    }
    const actionBtn = e.target.closest('[data-modal-action]');
    if (!actionBtn) return;
    const action = actionBtn.getAttribute('data-modal-action');
    const playerId = Number(actionBtn.getAttribute('data-modal-player'));
    if (!playerId || !action) return;
    if (!state.editable) return;
    if (action === 'captain') {
      setCaptain(playerId, false);
      closeModal();
      return;
    }
    if (action === 'vice') {
      setCaptain(playerId, true);
      closeModal();
      return;
    }
    if (action === 'substitute') {
      closeModal();
      setMode('sub', { selectedId: playerId });
      showError('Choose who to swap with.');
      return;
    }
    if (action === 'bench-order') {
      closeModal();
      setMode('bench-order', { selectedId: playerId });
      showError('Choose another bench player to swap order with.');
      return;
    }
    if (action === 'restore') {
      closeModal();
      restorePreviousPlayer(playerId);
      return;
    }
    if (action === 'transfer') {
      closeModal();
      setMode('transfer', { selectedId: playerId });
      state.page = 1;
      state.filters.pos = String(
        state.squad.find((p) => p.id === playerId)?.element_type || 'all'
      );
      document.querySelectorAll('[data-pos-filter]').forEach((b) => {
        b.classList.toggle(
          'is-active',
          b.getAttribute('data-pos-filter') === state.filters.pos
        );
      });
      showError('Choose a same position replacement from the list on the right.');
      return;
    }
    if (action === 'transfer-in') {
      const incoming = state.players.find((p) => p.id === playerId);
      if (!incoming) return;
      if (state.mode === 'transfer' && state.selectedId) {
        const out = state.squad.find((p) => p.id === state.selectedId);
        if (out && out.element_type === incoming.element_type) {
          closeModal();
          transferIn(playerId);
          return;
        }
        showError('That replacement must be the same position as the player you are transferring out.');
        return;
      }
      // From the pool with no out player yet: ask user to open a squad player and use Transfer out.
      closeModal();
      showError(`Open a ${incoming.pos_label} in your squad and choose Transfer out, then pick ${incoming.web_name}.`);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (state.modal.kind) {
      closeModal();
      return;
    }
    if (state.mode !== 'view') {
      clearInteractionMode();
      showError('');
      renderAll();
    }
  });
}

function applyLocks(premium, locks, tipsRemaining = 0) {
  state.premium = premium;
  state.locks = locks || {};

  const optimise = document.querySelector('[data-optimise-cta]');
  if (optimise) {
    if (locks.optimise) {
      optimise.classList.add('pick-actions__locked');
      const icon = optimise.querySelector('.mf-btn__icon');
      if (icon) {
        icon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>`;
      }
      optimise.setAttribute('href', pageUrl('join'));
    } else {
      optimise.classList.remove('pick-actions__locked');
      const icon = optimise.querySelector('.mf-btn__icon');
      if (icon) {
        icon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
      }
      optimise.setAttribute('href', '#');
    }
  }

  const tipRow = document.querySelector('[data-pick-tip-row]');
  if (tipRow) tipRow.hidden = !premium;

  renderSummary();
  applyEditLock();
}

function currentGwIndex() {
  const options = state.gameweekOptions || [];
  return options.findIndex((option) => Number(option.id) === Number(state.selectedGwId));
}

function renderGameweekNav() {
  const options = state.gameweekOptions || [];
  const idx = currentGwIndex();
  const prev = document.querySelector('[data-gw-prev]');
  const next = document.querySelector('[data-gw-next]');
  if (prev) prev.disabled = state.loadingGw || idx <= 0;
  if (next) next.disabled = state.loadingGw || idx < 0 || idx >= options.length - 1;
}

async function loadGameweek(gameweekId) {
  const gw = Number(gameweekId);
  if (!Number.isInteger(gw) || gw < 1 || state.loadingGw) return;
  if (Number(state.selectedGwId) === gw) return;

  state.loadingGw = true;
  renderGameweekNav();
  showError('');

  try {
    const session = { user: { premium: state.premium } };
    const data = await fetchPickPayload('fpl/pick', 2, gw);
    if (!data?.ok || data.redirect) {
      throw new Error('Could not load that gameweek.');
    }
    applyPickPayload(data, session, { enriched: data.pick?.view_mode !== 'plan' });
    renderAll();
    updateSaveButton();
    if (state.viewMode === 'plan') {
      enrichPickInBackground(session);
    }
  } catch (error) {
    showError(error?.message || 'Could not load that gameweek.');
    renderGameweekNav();
  } finally {
    state.loadingGw = false;
    renderGameweekNav();
  }
}

function bindGameweekNav() {
  document.querySelector('[data-gw-prev]')?.addEventListener('click', () => {
    const options = state.gameweekOptions || [];
    const idx = currentGwIndex();
    if (idx <= 0) return;
    loadGameweek(options[idx - 1].id);
  });

  document.querySelector('[data-gw-next]')?.addEventListener('click', () => {
    const options = state.gameweekOptions || [];
    const idx = currentGwIndex();
    if (idx < 0 || idx >= options.length - 1) return;
    loadGameweek(options[idx + 1].id);
  });
}

function applyPickPayload(data, session, { enriched = true } = {}) {
  const pick = data.pick;
  state.fplSquad = structuredClone(pick.fpl_squad || pick.squad);
  state.fplBank = Number(pick.fpl_bank ?? pick.bank) || 0;
  state.players = pick.players || [];
  state.originalSquad = structuredClone(pick.squad);
  state.squad = squadFromFplBaseline(pick.squad);
  syncTransferTracking();
  state.bankBase = Number(pick.bank) || 0;
  state.freeTransfers = Number(pick.free_transfers) || 0;
  state.transferCost = Number(pick.transfer_cost) || 4;
  state.hasSavedDraft = Boolean(pick.has_saved_draft);
  state.editable = pick.editable !== false;
  state.lockedReason = pick.locked_reason || null;
  state.isLiveLocked = Boolean(pick.is_live_locked);
  state.viewMode = pick.view_mode || 'plan';
  state.gameweekOptions = Array.isArray(pick.gameweek?.options) ? pick.gameweek.options : [];
  state.selectedGwId = Number(pick.gameweek?.selected_id || pick.event?.id) || null;
  state.gwPoints = pick.gw_points == null ? null : Number(pick.gw_points);
  state.isDirty = false;
  state.event = pick.event;
  state.mode = 'view';
  state.selectedId = null;
  state.suggestedTransfers = Array.isArray(pick.suggested_transfers)
    ? pick.suggested_transfers
    : [];

  applyLocks(
    Boolean(data.premium) || isPremiumActive(session.user),
    pick.locks || {},
    Number(pick.ai?.tips_remaining ?? 0)
  );

  if (enriched) {
    applyAiResult({
      strength: pick.strength,
      predicted_points: pick.predicted_points,
      notes: pick.ai?.notes || '',
    });
    if (state.viewMode === 'plan') {
      applyLocalScores();
    }
  }

  if (state.viewMode !== 'plan' && state.gwPoints != null) {
    state.predictedPoints = {
      gw: Number(state.gwPoints),
      five_gw: Number(state.gwPoints),
    };
  }

  const status = document.querySelector('[data-pick-status]');
  if (status) {
    status.textContent = enriched && pick.ai?.notes
      ? pick.ai.notes
      : `${pick.entry?.team_name || 'Your team'} · Rate My Team`;
  }
  const gw = document.querySelector('[data-pick-gw-name]');
  if (gw) gw.textContent = pick.event?.name || 'Gameweek';
  const deadline = document.querySelector('[data-pick-deadline]');
  if (deadline) {
    if (state.viewMode === 'history') {
      deadline.textContent = state.gwPoints != null
        ? `${Number(state.gwPoints)} pts this gameweek`
        : 'Past gameweek';
    } else if (state.viewMode === 'live' || state.isLiveLocked) {
      deadline.textContent = 'Live on FPL';
    } else if (pick.event?.deadline_label) {
      deadline.textContent = `Deadline: ${pick.event.deadline_label}`;
    } else {
      deadline.textContent = '';
    }
  }

  const tipRow = document.querySelector('[data-pick-tip-row]');
  if (tipRow) tipRow.hidden = !(state.premium && state.viewMode === 'plan');

  const viewToggle = document.querySelector('.pick-view-toggle');
  if (viewToggle) viewToggle.hidden = state.viewMode !== 'plan';

  applyEditLock();
  renderGameweekNav();
}

async function enrichPickInBackground(session) {
  if (state.viewMode !== 'plan') return;
  try {
    const data = await fetchPickPayload('fpl/pick-enrich', 2, state.selectedGwId);
    if (!data?.ok || data.redirect) return;
    applyPickPayload(data, session, { enriched: true });
    renderAll();
    updateSaveButton();
  } catch {
    // Squad stays usable without projections.
  }
}

async function boot() {
  setPageBootstrapping(true);
  bindSignOutButtons();
  initTeamTabs();
  initMfSelects();
  bindUi();

  startProgress('Checking your account');

  let session;
  try {
    session = await fetchSession();
  } catch {
    session = null;
  }

  if (!session?.authenticated) {
    failProgress();
    setPageBootstrapping(false);
    if (session?.transient) {
      showError('The server is busy right now. Please wait a moment and refresh the page.');
      return;
    }
    redirectToLogin();
    return;
  }

  initSiteNav({ session, deferGwTips: true });
  applyAuthNav(session);

  const redirect = String(session.redirect || '/pick').replace(/^\//, '');
  if (redirect === 'link-team') {
    failProgress();
    setPageBootstrapping(false);
    window.location.href = pageUrl('link-team');
    return;
  }

  try {
    setProgress(28, { label: 'Loading your squad from FPL' });
    const data = await fetchPickPayload();
    if (data.redirect) {
      failProgress();
      setPageBootstrapping(false);
      window.location.href = pageUrl(data.redirect);
      return;
    }
    setProgress(72, { label: 'Building your pitch' });

    applyPickPayload(data, session, { enriched: false });

    document.querySelector('[data-pick-summary]')?.removeAttribute('hidden');
    document.querySelector('[data-pick-layout]')?.removeAttribute('hidden');
    showError('');
    setProgress(94, { label: 'Almost ready' });
    renderAll({ animateIn: true });
    updateSaveButton();
    const oneTap = consumeOneTapFix();
    if (oneTap) applyOneTapFix(oneTap);
    await finishProgress('Squad ready');
    setPageBootstrapping(false);
    scheduleGwTipsHydration();
    enrichPickInBackground(session);
  } catch (error) {
    failProgress();
    setPageBootstrapping(false);
    const status = document.querySelector('[data-pick-status]');
    if (status) status.textContent = 'Could not load your squad';
    showError(friendlyLoadError(error.message));
  }
}

boot();
