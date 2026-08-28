import { animate, stagger } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav } from './site-nav.js';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
  pageUrl,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const LIVE_STORAGE_KEY = 'mf_live_snapshot_v1';
const PROGRESS_SHOW_DELAY_MS = 120;

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
  panelMode: false,
};

function progressEls() {
  return {
    top: document.querySelector('[data-live-progress]'),
    topBar: document.querySelector('[data-live-progress-bar]'),
    panel: document.querySelector('[data-live-load]'),
    fill: document.querySelector('[data-live-load-fill]'),
    track: document.querySelector('[data-live-load-track]'),
    label: document.querySelector('[data-live-load-label]'),
    pct: document.querySelector('[data-live-load-pct]'),
    sr: document.querySelector('[data-live-load-sr]'),
    main: document.querySelector('.live-main'),
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
  if (els.fill) {
    els.fill.style.transform = `scaleX(${scale})`;
  }
  if (els.track) {
    els.track.setAttribute('aria-valuenow', String(Math.round(clamped)));
  }
  if (els.pct) {
    els.pct.textContent = `${Math.round(clamped)}%`;
  }
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
  if (progressUi.panelMode && els.panel) {
    els.panel.hidden = false;
    els.panel.classList.add('is-visible', 'is-active');
  }
  if (els.main) {
    els.main.setAttribute('aria-busy', 'true');
  }
}

function startProgress({ panel = true, label = 'Getting your squad ready' } = {}) {
  progressUi.panelMode = panel;
  progressUi.value = 0;
  applyProgressVisual(0);
  setProgressLabel(label);

  if (progressUi.showTimer) clearTimeout(progressUi.showTimer);
  progressUi.showTimer = window.setTimeout(() => {
    revealProgressUi();
  }, PROGRESS_SHOW_DELAY_MS);

  stopTrickle();
  progressUi.trickleTimer = window.setInterval(() => {
    if (progressUi.value >= 88) return;
    const next = progressUi.value + Math.max(0.35, (90 - progressUi.value) * 0.045);
    setProgress(Math.min(88, next), { immediate: reduceMotion });
  }, reduceMotion ? 400 : 220);
}

function setProgressLabel(text) {
  const els = progressEls();
  if (els.label) els.label.textContent = text;
  if (els.sr) els.sr.textContent = text;
}

function setProgress(target, { label, immediate = false } = {}) {
  const next = Math.max(progressUi.value, Math.min(100, target));
  if (label) setProgressLabel(label);

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
    duration: Math.min(0.55, 0.18 + Math.abs(next - from) / 160),
    easing: [0.22, 1, 0.36, 1],
    onUpdate: (latest) => {
      progressUi.value = latest;
      applyProgressVisual(latest);
    },
  });
}

function stopTrickle() {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
}

async function completeProgress(label = 'Live team ready') {
  stopTrickle();
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }

  // Fast cache hits: never flash the bar.
  if (!progressUi.visible) {
    progressUi.value = 0;
    applyProgressVisual(0);
    const els = progressEls();
    if (els.main) els.main.removeAttribute('aria-busy');
    return;
  }

  setProgress(100, { label, immediate: reduceMotion });
  if (progressUi.anim) {
    try {
      await progressUi.anim;
    } catch {
      // cancelled
    }
  }

  const els = progressEls();
  if (els.top) els.top.classList.add('is-complete');
  if (els.panel) els.panel.classList.remove('is-active');

  await new Promise((resolve) => window.setTimeout(resolve, reduceMotion ? 40 : 180));

  if (els.top) {
    els.top.classList.remove('is-visible', 'is-complete');
    window.setTimeout(() => {
      els.top.hidden = true;
      els.top.setAttribute('aria-hidden', 'true');
      applyProgressVisual(0);
      progressUi.value = 0;
      progressUi.visible = false;
    }, reduceMotion ? 0 : 200);
  }
  if (els.panel) {
    els.panel.classList.remove('is-visible');
    window.setTimeout(() => {
      els.panel.hidden = true;
    }, reduceMotion ? 0 : 200);
  }
  if (els.main) els.main.removeAttribute('aria-busy');
}

function failProgress() {
  stopTrickle();
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
  applyProgressVisual(0);
}

function formatNumber(value) {
  if (value == null || Number.isNaN(Number(value))) return '-';
  return Number(value).toLocaleString('en-GB');
}

function formatRankChange(value) {
  if (value == null || Number.isNaN(Number(value))) return '-';
  const n = Number(value);
  if (n === 0) return '0';
  return n > 0 ? `+${formatNumber(n)}` : formatNumber(n);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function eventIcons(events) {
  const bits = [];
  if (events.goals > 0) {
    bits.push(`<span class="live-event live-event--goal" title="Goals">${events.goals > 1 ? `<span class="live-event__count">${events.goals}</span>` : ''}<img class="live-event__icon" src="./img/live/goal.png" alt="" width="14" height="14" decoding="async"></span>`);
  }
  if (events.assists > 0) {
    bits.push(`<span class="live-event live-event--assist" title="Assists">${events.assists > 1 ? `<span class="live-event__count">${events.assists}</span>` : ''}<img class="live-event__icon" src="./img/live/assist.png" alt="" width="14" height="14" decoding="async"></span>`);
  }
  if (events.yellow > 0) {
    bits.push('<span class="live-event live-event--yellow" title="Yellow card" aria-label="Yellow card"></span>');
  }
  if (events.red > 0) {
    bits.push('<span class="live-event live-event--red" title="Red card" aria-label="Red card"></span>');
  }
  if (events.bonus > 0) {
    bits.push(`<span class="live-event live-event--bonus" title="Bonus">${events.bonus}</span>`);
  }
  return bits.join('');
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
    ? `<img class="pick-player__shirt" src="${escapeHtml(player.shirt)}" alt="" width="52" height="68" loading="eager" decoding="async">`
    : `<span class="pick-player__shirt pick-player__shirt--fallback" aria-hidden="true"></span>`;

  const scored = Number(player.team_points ?? player.points ?? 0);
  const events = eventIcons(player.events || {});
  const topRight = player.is_captain
    ? '<span class="pick-player__price" title="Captain">x2</span>'
    : `<span class="pick-player__price">${escapeHtml(player.team_short || '')}</span>`;

  return `
    <article class="pick-player pick-player--live" data-player-id="${player.id}">
      ${pos}
      <div class="pick-player__kit">
        ${badge}
        ${shirt}
      </div>
      <span class="pick-player__card">
        <span class="pick-player__top">
          <span class="pick-player__name">${escapeHtml(player.web_name)}</span>
          ${topRight}
        </span>
        <span class="pick-player__proj pick-player__proj--single">
          <span class="pick-player__gw pick-player__gw--single">
            <span class="pick-player__score" title="Live points">${escapeHtml(String(scored))}</span>
            <span class="pick-player__fx pick-player__fx--events">${events || '<span class="pick-player__fx-empty">-</span>'}</span>
          </span>
        </span>
      </span>
    </article>
  `;
}

function showError(message) {
  const el = document.querySelector('[data-live-error]');
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function readLocalLive() {
  try {
    const raw = localStorage.getItem(LIVE_STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed?.live?.pitch) return null;
    return parsed;
  } catch {
    return null;
  }
}

function writeLocalLive(live, userKey) {
  try {
    localStorage.setItem(
      LIVE_STORAGE_KEY,
      JSON.stringify({
        saved_at: Date.now(),
        user_key: userKey || null,
        live,
      })
    );
  } catch {
    // ignore
  }
}

function renderLive(live, { animateIn = true } = {}) {
  const updated = document.querySelector('[data-live-updated]');
  const stats = document.querySelector('[data-live-stats]');
  const pitchWrap = document.querySelector('[data-live-pitch-wrap]');
  const bench = document.querySelector('[data-live-bench]');

  const when = live.updated_at ? new Date(live.updated_at) : new Date();
  const time = when.toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
  const teamName = live.entry?.team_name || 'Your team';
  const eventName = live.event?.name || 'Gameweek';
  const scoresEvent = live.scores_event;
  const eventLabel =
    scoresEvent?.id && live.event?.id && scoresEvent.id !== live.event.id
      ? `${eventName} team · ${scoresEvent.name || 'Gameweek'}: ${formatNumber(live.stats?.gw_points)} pts`
      : eventName;
  if (updated) {
    updated.textContent = `${teamName} · ${eventLabel} · Updated ${time}`;
    updated.classList.remove('is-loading');
  }

  if (stats) {
    stats.hidden = false;
    stats.querySelector('[data-stat="gw_points"]').textContent = formatNumber(live.stats?.gw_points);
    stats.querySelector('[data-stat="live_rank"]').textContent = formatNumber(live.stats?.live_rank);
    stats.querySelector('[data-stat="last_gw_rank"]').textContent = formatNumber(live.stats?.last_gw_rank);
    const changeEl = stats.querySelector('[data-stat="rank_change"]');
    changeEl.textContent = formatRankChange(live.stats?.rank_change);
    changeEl.classList.toggle('is-up', Number(live.stats?.rank_change) > 0);
    changeEl.classList.toggle('is-down', Number(live.stats?.rank_change) < 0);
  }

  ['gkp', 'def', 'mid', 'fwd'].forEach((row) => {
    const rowEl = document.querySelector(`[data-pitch-row="${row}"]`);
    if (!rowEl) return;
    const players = live.pitch?.[row] || [];
    rowEl.innerHTML = players.map((player) => playerCard(player)).join('');
  });

  if (bench) {
    const benchPlayers = live.bench || [];
    bench.dataset.count = String(benchPlayers.length);
    bench.innerHTML = benchPlayers
      .map((player) => playerCard(player, { bench: true }))
      .join('');
  }

  if (pitchWrap) pitchWrap.hidden = false;

  if (animateIn && !reduceMotion) {
    const chips = document.querySelectorAll('.pick-player--live');
    animate(
      chips,
      { opacity: [0, 1], y: [12, 0] },
      { duration: 0.28, delay: stagger(0.02), easing: [0.17, 0.55, 0.55, 1] }
    );
  }
}

async function fetchLivePayload() {
  const response = await fetch(apiUrl('fpl/live'), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const data = await response.json();
  if (!response.ok || !data.ok) {
    const error = new Error(data.error || 'Could not load your live team.');
    error.redirect = data.redirect;
    error.status = response.status;
    throw error;
  }
  return data;
}

function queueBackgroundRefresh() {
  // Fire and forget. Never block paint on FPL rebuilds.
  fetch(apiUrl('fpl/live-refresh'), {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers: { Accept: 'application/json' },
  })
    .then(async (response) => {
      if (!response.ok) return;
      // Soft revalidate UI once the background rebuild finishes.
      const next = await fetchLivePayload();
      if (next?.live) {
        writeLocalLive(next.live);
        renderLive(next.live, { animateIn: false });
      }
    })
    .catch(() => {});
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initTeamTabs();

  const local = readLocalLive();
  const hasLocal = Boolean(local?.live);

  if (hasLocal) {
    renderLive(local.live, { animateIn: false });
    startProgress({
      panel: false,
      label: 'Checking for live updates',
    });
    setProgress(18, { immediate: true });
  } else {
    const updated = document.querySelector('[data-live-updated]');
    if (updated) {
      updated.textContent = 'Fetching live points';
      updated.classList.add('is-loading');
    }
    startProgress({
      panel: true,
      label: 'Getting your squad ready',
    });
    setProgress(8, { immediate: true });
  }

  let session;
  try {
    setProgress(hasLocal ? 32 : 22, {
      label: hasLocal ? 'Confirming your session' : 'Checking your account',
    });
    session = await fetchSession();
  } catch {
    session = null;
  }

  if (!session?.authenticated) {
    failProgress();
    window.location.href = pageUrl('login');
    return;
  }

  applyAuthNav(session);

  const redirect = String(session.redirect || '/live').replace(/^\//, '');
  if (redirect === 'link-team') {
    failProgress();
    window.location.href = pageUrl('link-team');
    return;
  }
  if (redirect === 'payment') {
    failProgress();
    window.location.href = pageUrl('payment');
    return;
  }

  try {
    setProgress(hasLocal ? 55 : 42, {
      label: hasLocal ? 'Syncing live points' : 'Fetching live points',
    });
    const liveResult = await fetchLivePayload();
    setProgress(92, { label: 'Building your pitch' });
    showError('');
    writeLocalLive(liveResult.live, session.user?.email || null);
    renderLive(liveResult.live, { animateIn: !hasLocal });
    await completeProgress(
      liveResult.cache === 'HIT' ? 'Live team ready' : 'Live team updated'
    );

    if (liveResult.needs_refresh || liveResult.cache === 'STALE') {
      queueBackgroundRefresh();
    }
  } catch (error) {
    failProgress();
    if (error.redirect) {
      window.location.href = pageUrl(String(error.redirect).replace(/^\//, ''));
      return;
    }
    if (!hasLocal) {
      showError(error.message || 'Could not load your live team.');
    }
  }
}

boot();
