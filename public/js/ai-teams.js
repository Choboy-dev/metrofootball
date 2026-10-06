import { animate, inView, stagger } from './motion.js?v=20260901perf';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  applyPremiumGate,
  apiUrl,
  bindSignOutButtons,
  fetchSession,
  pageUrl,
} from './auth.js?v=20260907pub';

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

function applyGate(session) {
  return applyPremiumGate(session, {
    animateReveal: reduceMotion ? null : animate,
  }).premium;
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

function formatPoints(value, { actual = false } = {}) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  if (actual) return String(Math.round(n));
  const rounded = Math.round(n * 10) / 10;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

function isPlanningGameweekView(team) {
  return Boolean(team?.is_planning_view);
}

function playerFaceHtml(photo, shirt, name, modifier = 'default') {
  const raw = String(name || 'Player');
  const initial = escapeHtml(raw.slice(0, 1));
  const mod = modifier ? ` ai-face__img--${modifier}` : '';
  const shirtAttr = shirt ? ` data-face-shirt="${escapeHtml(shirt)}"` : '';
  const initialAttr = ` data-face-initial="${initial}"`;

  if (photo) {
    return `<img class="ai-face__img${mod}" src="${escapeHtml(photo)}" alt="" width="96" height="96" loading="lazy" decoding="async"${shirtAttr}${initialAttr}>`;
  }
  if (shirt) {
    return `<img class="ai-face__shirt${mod}" src="${escapeHtml(shirt)}" alt="" width="56" height="74" loading="lazy" decoding="async"${initialAttr}>`;
  }
  return `<span class="ai-face__fallback" aria-hidden="true">${initial}</span>`;
}

function initFaceFallbacks() {
  if (window.__mfFaceFallbackBound) return;
  window.__mfFaceFallbackBound = true;
  document.addEventListener(
    'error',
    (event) => {
      const img = event.target;
      if (!(img instanceof HTMLImageElement)) return;
      if (!img.classList.contains('ai-face__img') && !img.classList.contains('ai-face__shirt')) return;
      const stage = img.dataset.faceStage || 'photo';
      const shirt = img.getAttribute('data-face-shirt');
      if (stage !== 'shirt' && shirt) {
        img.dataset.faceStage = 'shirt';
        img.classList.remove('ai-face__img');
        img.classList.add('ai-face__shirt');
        img.removeAttribute('width');
        img.removeAttribute('height');
        img.src = shirt;
        return;
      }
      const initial = img.getAttribute('data-face-initial') || '?';
      const span = document.createElement('span');
      span.className = 'ai-face__fallback';
      span.setAttribute('aria-hidden', 'true');
      span.textContent = initial;
      img.replaceWith(span);
    },
    true
  );
}

function transferPlayerMeta(transfer, side) {
  const team = transfer[`${side}_team_short`] || '';
  const pos = transfer.pos || '';
  const price = Number(transfer[`${side}_price`]);
  const priceLabel = Number.isFinite(price) && price > 0 ? `£${price.toFixed(1)}m` : '';
  return [team, pos, priceLabel].filter(Boolean).join(' · ');
}

function animateTransferSwaps(container) {
  if (reduceMotion || !container) return;
  const cards = [...container.querySelectorAll('[data-ai-swap]')];
  if (!cards.length) return;

  cards.forEach((card) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(18px)';
  });

  animate(cards, { opacity: 1, y: 0 }, {
    duration: 0.45,
    delay: stagger(0.1),
    easing: [0.17, 0.55, 0.55, 1],
  });

  inView(cards, (element) => {
    const outFace = element.querySelector('.ai-swap__face--out');
    const inFace = element.querySelector('.ai-swap__face--in');
    const switchOrb = element.querySelector('.ai-swap__switch-orb');
    if (outFace) {
      animate(outFace, { scale: [0.88, 1], opacity: [0, 1] }, { duration: 0.4, easing: [0.22, 1, 0.36, 1] });
    }
    if (inFace) {
      animate(inFace, { scale: [0.88, 1], opacity: [0, 1] }, { duration: 0.4, delay: 0.08, easing: [0.22, 1, 0.36, 1] });
    }
    if (switchOrb) {
      animate(switchOrb, { rotate: [0, 180], scale: [0.7, 1] }, { duration: 0.55, delay: 0.05, easing: [0.22, 1, 0.36, 1] });
    }
  }, { amount: 0.35 });
}

function gameweekUsesActualPoints() {
  const gw = currentGameweekMeta();
  return Boolean(gw?.uses_actual_points ?? gw?.finished);
}

function playerDisplayPoints(player, { bench = false, usesActual = false } = {}) {
  if (usesActual && player.gw_points != null) {
    const base = Number(player.gw_points);
    if (!Number.isFinite(base)) return '—';
    const onPitch = !bench;
    if (onPitch && player.is_captain) {
      const mul = Number(player.multiplier) || 2;
      return formatPoints(base * mul, { actual: true });
    }
    return formatPoints(base, { actual: true });
  }

  const raw = Number(player.ai?.projections?.[0] ?? player.proj);
  const base = Number.isFinite(raw) ? raw : null;
  if (base == null) return '—';
  return formatPoints(base, { actual: false });
}

function playerCard(player, { bench = false, usesActual = false } = {}) {
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
  const shown = playerDisplayPoints(player, { bench, usesActual });
  const scoreTitle = usesActual ? 'Gameweek points' : 'Predicted points';
  const pred = `<span class="pick-player__score" title="${escapeHtml(scoreTitle)}">${escapeHtml(shown)}</span>`;

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
    subEl.textContent = gw?.id === planning ? 'Upcoming gameweek' : (gw?.uses_actual_points || gw?.finished ? 'Finished' : 'Season archive');
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

  if (!isPlanningGameweekView(team)) {
    root.hidden = true;
    root.innerHTML = '';
    return;
  }

  const plan = team.transfer_plan || {};
  const transfers = plan.transfers || team.transfers || [];

  if (!transfers.length && !plan.note) {
    root.hidden = true;
    root.innerHTML = '';
    return;
  }

  const cards = transfers
    .map((t) => {
      const outName = t.out_name || 'Out';
      const inName = t.in_name || 'In';
      const outMeta = transferPlayerMeta(t, 'out');
      const inMeta = transferPlayerMeta(t, 'in');
      const hit = Number(t.hit_points) > 0;
      const hitBadge = hit
        ? `<span class="ait-transfer-plan__hit">${escapeHtml(String(t.hit_points))} pt hit</span>`
        : '';
      const delta = Number(t.proj_delta);
      const deltaBadge = Number.isFinite(delta) && delta > 0
        ? `<span class="ait-transfer-plan__gain">+${escapeHtml(formatPoints(delta))} pts</span>`
        : '';

      return `
    <article class="ai-swap" data-ai-swap>
      <div class="ai-swap__arena" aria-label="Transfer ${escapeHtml(outName)} out for ${escapeHtml(inName)}">
        <div class="ai-swap__player ai-swap__player--out">
          <span class="ai-swap__tag ai-swap__tag--out">Out</span>
          <div class="ai-swap__face ai-swap__face--out">
            ${playerFaceHtml(t.out_photo, t.out_shirt, outName, 'out')}
          </div>
          <p class="ai-swap__name">${escapeHtml(outName)}</p>
          ${outMeta ? `<p class="ai-swap__club">${escapeHtml(outMeta)}</p>` : ''}
        </div>

        <div class="ai-swap__switch" aria-hidden="true">
          <span class="ai-swap__switch-orb">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M7 8h11l-2.5-2.5M17 16H6l2.5 2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </div>

        <div class="ai-swap__player ai-swap__player--in">
          <span class="ai-swap__tag ai-swap__tag--in">In</span>
          <div class="ai-swap__face ai-swap__face--in">
            ${playerFaceHtml(t.in_photo, t.in_shirt, inName, 'in')}
          </div>
          <p class="ai-swap__name">${escapeHtml(inName)}</p>
          ${inMeta ? `<p class="ai-swap__club">${escapeHtml(inMeta)}</p>` : ''}
        </div>
      </div>

      <div class="ait-transfer-plan__foot">
        ${deltaBadge}${hitBadge}
        <p class="ai-swap__reason">${escapeHtml(t.reason || 'Upgrade within budget and squad rules')}</p>
      </div>
    </article>`;
    })
    .join('');

  root.hidden = false;
  root.innerHTML = `
    <section class="ait-transfer-plan" aria-label="Planned transfers for the upcoming gameweek">
      <div class="ait-transfer-plan__head">
        <h2 class="ait-transfer-plan__title">Planned transfers</h2>
        <p class="ait-transfer-plan__lead">Suggested moves before the next deadline.</p>
      </div>
      ${transfers.length ? `<div class="ait-transfer-plan__cards">${cards}</div>` : ''}
      ${plan.note ? `<p class="ait-transfer-plan__note">${escapeHtml(plan.note)}</p>` : ''}
    </section>
  `;

  initFaceFallbacks();
  animateTransferSwaps(root.querySelector('.ait-transfer-plan__cards'));
}

function renderMeta(team) {
  const meta = document.querySelector('[data-ait-meta]');
  if (!team) return;

  renderChipNote(team);
  renderTransfers(team);

  if (meta) {
    const usesActual = Boolean(team.uses_actual_points ?? gameweekUsesActualPoints());
    const strength = Number(team.strength ?? 0);
    const strengthOk = strength >= 90;
    const scoreLabel = usesActual ? 'Points' : 'Predicted';
    const scoreValue = formatPoints(
      usesActual ? team.gw_points : team.predicted_points,
      { actual: usesActual }
    );

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

  const usesActual = Boolean(team.uses_actual_points ?? gameweekUsesActualPoints());

  ['gkp', 'def', 'mid', 'fwd'].forEach((row) => {
    const rowEl = document.querySelector(`[data-pitch-row="${row}"]`);
    if (!rowEl) return;
    const players = team.pitch?.[row] || [];
    rowEl.dataset.count = String(players.length);
    rowEl.innerHTML = players.map((player) => playerCard(player, { usesActual })).join('');
  });

  const bench = document.querySelector('[data-ait-bench]');
  if (bench) {
    const benchPlayers = team.bench || [];
    bench.dataset.count = String(benchPlayers.length);
    bench.innerHTML = benchPlayers
      .map((player) => playerCard(player, { bench: true, usesActual }))
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
