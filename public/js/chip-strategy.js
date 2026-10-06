import { animate, inView, stagger } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  applyPremiumGate,
  apiUrl,
  bindSignOutButtons,
  fetchSession,
  redirectToLogin,
  pageUrl,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  plan: null,
  selectedChip: 'bboost',
};

const CHIP_THEME = {
  bboost: 'bench',
  '3xc': 'captain',
  freehit: 'freehit',
  wildcard: 'wildcard',
};

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

function applyGate(session) {
  return applyPremiumGate(session, {
    animateReveal: reduceMotion ? null : animate,
  }).premium;
}

function progressEls() {
  return {
    top: document.querySelector('[data-csp-progress]'),
    topBar: document.querySelector('[data-csp-progress-bar]'),
    panel: document.querySelector('[data-csp-load]'),
    fill: document.querySelector('[data-csp-load-fill]'),
    track: document.querySelector('[data-csp-load-track]'),
    label: document.querySelector('[data-csp-load-label]'),
    pct: document.querySelector('[data-csp-load-pct]'),
    sr: document.querySelector('[data-csp-load-sr]'),
    main: document.querySelector('[data-csp-main]'),
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

function startProgress(label = 'Reading your squad') {
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

async function finishProgress(label = 'Plan ready') {
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
  if (progressUi.trickleTimer) clearInterval(progressUi.trickleTimer);
  if (progressUi.showTimer) clearTimeout(progressUi.showTimer);
  progressUi.trickleTimer = null;
  progressUi.showTimer = null;
  const els = progressEls();
  if (els.top) {
    els.top.classList.remove('is-visible', 'is-complete');
    els.top.hidden = true;
  }
  if (els.panel) {
    els.panel.classList.remove('is-visible', 'is-active');
    els.panel.hidden = true;
  }
  if (els.main) els.main.removeAttribute('aria-busy');
  progressUi.visible = false;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function setError(text) {
  const el = document.querySelector('[data-csp-error]');
  if (!el) return;
  if (!text) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = text;
}

function chipFooterLabel(chip, currentEventId) {
  if (!chip.available) {
    if (chip.used_events?.length) {
      return `Used GW${chip.used_events.join(', GW')}`;
    }
    return 'Used';
  }

  const targetId = Number(chip.target?.event_id ?? 0);
  if (targetId > 0 && currentEventId >= targetId) {
    return chip.target?.name || `Gameweek ${targetId}`;
  }

  return 'Keep waiting';
}

function statusLabel(chip) {
  if (!chip.available) {
    if (chip.used_events?.length) {
      return `Played in GW${chip.used_events.join(', GW')}`;
    }
    return 'Already used';
  }

  const target = chip.target?.name || '';
  return target ? `Target ${target}` : '';
}

function renderDetail(chip) {
  const title = document.querySelector('[data-csp-detail-title]');
  const metrics = document.querySelector('[data-csp-detail-metrics]');
  const reason = document.querySelector('[data-csp-detail-reason]');

  if (title) {
    title.textContent = chip?.label ? `${chip.label} watch` : 'What we are watching';
  }
  if (reason) reason.textContent = chip?.reason || '';

  if (!metrics) return;

  const rows = chip?.insights || [];
  metrics.innerHTML = rows.map((row) => `
    <li class="csp-detail__metric">
      <span class="csp-detail__metric-label">${escapeHtml(row.label || '')}</span>
      <span class="csp-detail__metric-value">${escapeHtml(row.value || '')}</span>
    </li>
  `).join('');
}

function renderMeta(plan) {
  const eventEl = document.querySelector('[data-csp-event]');
  const teamEl = document.querySelector('[data-csp-team]');
  const strengthEl = document.querySelector('[data-csp-strength]');
  const predictedEl = document.querySelector('[data-csp-predicted]');

  const eventName = plan.event?.name || 'This gameweek';
  if (eventEl) eventEl.textContent = eventName;

  const teamName = plan.entry?.team_name || plan.entry?.name || plan.entry?.manager_name || 'Your team';
  if (teamEl) teamEl.textContent = teamName;

  if (strengthEl) {
    strengthEl.textContent = `${plan.strength ?? 0}%`;
    strengthEl.classList.toggle('is-good', (plan.strength ?? 0) >= 90);
  }
  if (predictedEl) {
    predictedEl.textContent = `${Number(plan.predicted_points ?? 0).toFixed(1)} pts`;
  }
}

function renderSummary(plan) {
  const headline = document.querySelector('[data-csp-summary-headline]');
  const lead = document.querySelector('[data-csp-summary-lead]');
  const summary = plan.summary || {};

  if (headline) headline.textContent = summary.headline || 'Your chip plan';
  if (lead) lead.textContent = summary.lead || '';

  if (summary.focus_chip) {
    state.selectedChip = summary.focus_chip;
  }
}

function renderChipCards(chips) {
  const root = document.querySelector('[data-csp-chips]');
  if (!root) return;

  const currentEventId = Number(state.plan?.event?.id ?? 0);

  root.innerHTML = chips.map((chip) => {
    const theme = CHIP_THEME[chip.id] || 'bench';
    const active = chip.id === state.selectedChip ? ' is-active' : '';
    const used = chip.available ? '' : ' is-used';
    const heat = chip.target?.heat ?? 0;

    return `
      <button
        type="button"
        class="csp-chip csp-chip--${theme}${active}${used}"
        data-csp-chip="${escapeHtml(chip.id)}"
        role="tab"
        aria-selected="${chip.id === state.selectedChip ? 'true' : 'false'}"
      >
        <div class="csp-chip__top">
          <span class="csp-chip__badge csp-chip__badge--${theme}">${escapeHtml(chip.short)}</span>
          <div class="csp-chip__copy">
            <span class="csp-chip__name">${escapeHtml(chip.label)}</span>
            <span class="csp-chip__status"${chip.target?.name ? '' : ' hidden'}>${escapeHtml(statusLabel(chip))}</span>
          </div>
        </div>
        ${chip.available ? `<span class="csp-chip__heat csp-chip__heat--${theme}" style="--csp-heat:${heat}"><span class="csp-chip__heat-fill csp-chip__heat-fill--${theme}"></span></span>` : ''}
        <span class="csp-chip__confidence csp-chip__confidence--${theme}">${escapeHtml(chipFooterLabel(chip, currentEventId))}</span>
      </button>
    `;
  }).join('');

  root.querySelectorAll('[data-csp-chip]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.selectedChip = btn.getAttribute('data-csp-chip');
      renderAll(state.plan, { animate: true });
    });
  });
}

function renderLegend(chips) {
  const root = document.querySelector('[data-csp-legend]');
  if (!root) return;

  const items = chips
    .filter((chip) => chip.available)
    .map((chip) => {
      const theme = CHIP_THEME[chip.id] || 'bench';
      return `
        <span class="csp-legend__item">
          <span class="csp-legend__swatch csp-legend__swatch--${theme}" aria-hidden="true"></span>
          <span class="csp-legend__label">${escapeHtml(chip.label)}</span>
        </span>
      `;
    })
    .join('');

  root.innerHTML = items;
}

function renderTimeline(timeline) {
  const root = document.querySelector('[data-csp-timeline]');
  if (!root) return;

  root.innerHTML = timeline.map((row) => {
    const pick = row.pick || null;
    const chipId = pick?.chip || null;
    const theme = chipId ? (CHIP_THEME[chipId] || 'bench') : '';
    const heat = pick?.heat ?? 0;
    const empty = chipId ? '' : ' is-empty';
    const highlight = chipId && chipId === state.selectedChip ? ' is-highlight' : '';
    const current = row.is_current ? ' is-current' : '';
    const next = row.is_next ? ' is-next' : '';
    const short = String(row.name || '').replace('Gameweek ', 'GW');
    const aria = pick
      ? `${short}: ${pick.label}`
      : `${short}: hold your chips`;

    return `
      <div class="csp-timeline__col${empty}${highlight}${current}${next}" data-chip="${escapeHtml(chipId || '')}" title="${escapeHtml(aria)}">
        <div class="csp-timeline__bar">
          <span class="csp-timeline__bar-fill${theme ? ` csp-timeline__bar-fill--${theme}` : ''}" style="--csp-heat:${heat}"></span>
        </div>
        <span class="csp-timeline__label">${escapeHtml(short)}</span>
      </div>
    `;
  }).join('');

  if (!reduceMotion) {
    root.querySelectorAll('.csp-timeline__bar-fill[class*="csp-timeline__bar-fill--"]').forEach((bar, index) => {
      animate(bar, { scaleY: [0, 1] }, {
        duration: 0.45,
        delay: index * 0.03,
        ease: [0.17, 0.55, 0.55, 1],
      });
    });
  }
}

function renderBoard(plan) {
  const board = document.querySelector('[data-csp-board]');
  if (board) board.hidden = false;

  renderMeta(plan);
  renderSummary(plan);
  renderChipCards(plan.chips || []);
  renderLegend(plan.chips || []);

  const chip = (plan.chips || []).find((c) => c.id === state.selectedChip) || plan.chips?.[0];
  if (chip) state.selectedChip = chip.id;

  renderTimeline(plan.timeline || []);
  renderDetail(chip);
}

function renderAll(plan, { animate: animateIn = false } = {}) {
  if (!plan) return;
  state.plan = plan;
  renderBoard(plan);

  if (animateIn && !reduceMotion) {
    const cards = document.querySelectorAll('.csp-chip');
    animate(cards, { opacity: [0, 1], y: [10, 0] }, {
      duration: 0.35,
      delay: stagger(0.06),
      ease: [0.17, 0.55, 0.55, 1],
    });
  }
}

async function loadPlan() {
  startProgress('Reading your squad');
  setError('');

  try {
    setProgress(35, { label: 'Finding best weeks' });
    const response = await fetch(apiUrl('fpl/chip-strategy'), { credentials: 'include' });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.ok) {
      failProgress();
      if (data.redirect && (response.status === 401 || response.status === 422)) {
        window.location.href = pageUrl(data.redirect.replace(/^\//, ''));
        return;
      }
      setError(data.error || 'We could not load your chip plan right now.');
      return;
    }

    setProgress(85, { label: 'Building your plan' });
    await finishProgress('Plan ready');
    renderAll(data.plan, { animate: true });

    const board = document.querySelector('[data-csp-board]');
    if (board && !reduceMotion) {
      inView(board, () => {
        animate(board, { opacity: [0.96, 1] }, { duration: 0.3 });
      }, { amount: 0.2 });
    }
  } catch {
    failProgress();
    setError('We could not reach the server. Try again in a moment.');
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initTeamTabs();

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

  await loadPlan();
}

boot();
