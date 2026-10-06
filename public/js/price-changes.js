import { animate } from './motion.js?v=20260901perf';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;
const INITIAL_SIZE = 100;
const MORE_SIZE = 50;

const state = {
  players: [],
  filtered: [],
  visible: INITIAL_SIZE,
  pos: 'all',
  search: '',
  pageSize: INITIAL_SIZE,
  loadMoreSize: MORE_SIZE,
};

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function progressEls() {
  return {
    top: document.querySelector('[data-pcp-progress]'),
    topBar: document.querySelector('[data-pcp-progress-bar]'),
    panel: document.querySelector('[data-pcp-load]'),
    fill: document.querySelector('[data-pcp-load-fill]'),
    track: document.querySelector('[data-pcp-load-track]'),
    label: document.querySelector('[data-pcp-load-label]'),
    pct: document.querySelector('[data-pcp-load-pct]'),
    sr: document.querySelector('[data-pcp-load-sr]'),
    main: document.querySelector('[data-pcp-main]'),
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

function startProgress(label = 'Loading') {
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

function finishProgress() {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }
  setProgress(100, { immediate: reduceMotion, label: 'Ready' });
  window.setTimeout(() => {
    const els = progressEls();
    if (els.top) {
      els.top.classList.remove('is-visible');
      els.top.hidden = true;
      els.top.setAttribute('aria-hidden', 'true');
    }
    if (els.panel) {
      els.panel.hidden = true;
      els.panel.classList.remove('is-visible', 'is-active');
    }
    if (els.main) els.main.removeAttribute('aria-busy');
    progressUi.visible = false;
    progressUi.value = 0;
  }, reduceMotion ? 80 : 320);
}

function showError(message) {
  const el = document.querySelector('[data-pcp-error]');
  if (!el) return;
  el.hidden = !message;
  el.textContent = message || '';
}

function formatHourly(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0.00%';
  const abs = Math.abs(n).toFixed(2);
  return `${n > 0 ? '' : n < 0 ? '-' : ''}${abs}%`;
}

function progressPill(p) {
  const tone = p.direction === 'rise' ? 'rise' : 'fall';
  const label = `${p.pct_display > 0 ? '' : ''}${p.pct_display}%`;
  return `<span class="pcp-pill pcp-pill--${tone}">${escapeHtml(String(label))}</span>`;
}

function predictionCell(p) {
  const rise = p.direction === 'rise';
  const icon = rise
    ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7M10 7h7v7" stroke-linecap="round" stroke-linejoin="round"/></svg>`
    : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 7 17 17M17 10v7h-7" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  return `
    <span class="pcp-pred pcp-pred--${rise ? 'rise' : 'fall'}">
      <span class="pcp-pred__icon">${icon}</span>
      <span>${escapeHtml(p.prediction || (rise ? 'Increase' : 'Decrease'))}</span>
    </span>
  `;
}

function hourlyCell(p) {
  const rise = Number(p.hourly) >= 0;
  const chev = rise
    ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 14 6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>`
    : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 10 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  return `
    <span class="pcp-hourly pcp-hourly--${rise ? 'rise' : 'fall'}">
      <span>${escapeHtml(formatHourly(p.hourly))}</span>
      <span class="pcp-hourly__icon">${chev}</span>
    </span>
  `;
}

function whenCell(p) {
  if (p.when === 'Tonight') {
    return `
      <span class="pcp-when pcp-when--tonight">
        <svg class="pcp-when__warn" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="currentColor" d="M12.86 2.95c-.38-.66-1.34-.66-1.72 0L1.6 19.05c-.38.66.1 1.5.86 1.5h19.08c.76 0 1.24-.84.86-1.5L12.86 2.95z"/>
          <path fill="#fff" d="M11.25 9.2h1.5v6.1h-1.5V9.2zm0 7.4h1.5v1.55h-1.5V16.6z"/>
        </svg>
        Tonight
      </span>
    `;
  }
  return `<span class="pcp-when">${escapeHtml(p.when || '')}</span>`;
}

function avatarFallback(img) {
  if (!(img instanceof HTMLImageElement)) return;
  const shirt = img.getAttribute('data-shirt') || '';
  if (shirt && img.getAttribute('data-fallback') !== 'shirt') {
    img.setAttribute('data-fallback', 'shirt');
    img.src = shirt;
    img.classList.remove('is-broken');
    img.classList.add('pcp-avatar--shirt');
    return;
  }
  img.removeAttribute('src');
  img.classList.add('is-broken');
}

window.pcpAvatarFallback = avatarFallback;

function playerCell(p) {
  const photo = p.photo || '';
  const shirt = p.shirt || '';
  const src = photo || shirt;
  if (!src) {
    return `
      <div class="pcp-player">
        <span class="pcp-avatar pcp-avatar--empty" aria-hidden="true"></span>
        <div class="pcp-player__copy">
          <span class="pcp-player__name">${escapeHtml(p.web_name)}</span>
          <span class="pcp-player__meta">${escapeHtml(p.team_short)} • ${escapeHtml(p.pos_label)}</span>
        </div>
      </div>
    `;
  }
  return `
    <div class="pcp-player">
      <img
        class="pcp-avatar${photo ? '' : ' pcp-avatar--shirt'}"
        src="${escapeHtml(src)}"
        data-shirt="${escapeHtml(shirt)}"
        ${photo ? '' : 'data-fallback="shirt"'}
        alt=""
        width="40"
        height="40"
        loading="lazy"
        referrerpolicy="no-referrer"
        onerror="window.pcpAvatarFallback(this)"
      >
      <div class="pcp-player__copy">
        <span class="pcp-player__name">${escapeHtml(p.web_name)}</span>
        <span class="pcp-player__meta">${escapeHtml(p.team_short)} • ${escapeHtml(p.pos_label)}</span>
      </div>
    </div>
  `;
}

function applyFilters() {
  const q = state.search.trim().toLowerCase();
  state.filtered = state.players.filter((p) => {
    if (state.pos !== 'all' && String(p.pos) !== String(state.pos)) return false;
    if (!q) return true;
    const hay = `${p.web_name} ${p.team_short} ${p.team_name}`.toLowerCase();
    return hay.includes(q);
  });
  state.visible = state.pageSize;
  renderRows();
}

function renderRows() {
  const tbody = document.querySelector('[data-pcp-rows]');
  const board = document.querySelector('[data-pcp-board]');
  const more = document.querySelector('[data-pcp-more]');
  if (!tbody || !board) return;

  board.hidden = false;
  const slice = state.filtered.slice(0, state.visible);

  if (!slice.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="pcp-empty">No players match these filters.</td></tr>`;
  } else {
    tbody.innerHTML = slice.map((p) => `
      <tr>
        <td class="pcp-col pcp-col--player">${playerCell(p)}</td>
        <td class="pcp-col pcp-col--when">${whenCell(p)}</td>
        <td class="pcp-col pcp-col--progress">${progressPill(p)}</td>
        <td class="pcp-col pcp-col--scroll">£${escapeHtml(String(p.price))}m</td>
        <td class="pcp-col pcp-col--scroll">${predictionCell(p)}</td>
        <td class="pcp-col pcp-col--scroll">${hourlyCell(p)}</td>
      </tr>
    `).join('');
  }

  if (more) {
    more.hidden = state.visible >= state.filtered.length;
  }
}

function bindTipButtons() {
  const buttons = Array.from(document.querySelectorAll('[data-pcp-tip]'));
  let activeBtn = null;

  const tipFor = (btn) => {
    const id = btn.getAttribute('aria-describedby');
    return id ? document.getElementById(id) : null;
  };

  const hideTip = (btn) => {
    const tip = tipFor(btn);
    if (!tip) return;
    tip.classList.remove('is-visible');
    tip.style.left = '';
    tip.style.top = '';
    btn.classList.remove('is-open');
    if (activeBtn === btn) activeBtn = null;
  };

  const hideAll = (except = null) => {
    buttons.forEach((btn) => {
      if (btn !== except) hideTip(btn);
    });
  };

  const placeTip = (btn) => {
    const tip = tipFor(btn);
    if (!tip) return;
    if (tip.parentElement !== document.body) {
      document.body.appendChild(tip);
    }
    tip.classList.add('is-visible');
    tip.style.left = '0px';
    tip.style.top = '0px';

    const rect = btn.getBoundingClientRect();
    const tipRect = tip.getBoundingClientRect();
    const gap = 8;
    let left = rect.left + rect.width / 2 - tipRect.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
    let top = rect.bottom + gap;
    if (top + tipRect.height > window.innerHeight - 8) {
      top = Math.max(8, rect.top - tipRect.height - gap);
    }
    tip.style.left = `${Math.round(left)}px`;
    tip.style.top = `${Math.round(top)}px`;
  };

  const showTip = (btn) => {
    hideAll(btn);
    btn.classList.add('is-open');
    activeBtn = btn;
    placeTip(btn);
  };

  buttons.forEach((btn) => {
    btn.addEventListener('mouseenter', () => showTip(btn));
    btn.addEventListener('mouseleave', () => {
      if (!btn.classList.contains('is-pinned')) hideTip(btn);
    });
    btn.addEventListener('focus', () => showTip(btn));
    btn.addEventListener('blur', () => {
      if (!btn.classList.contains('is-pinned')) hideTip(btn);
    });
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const pinned = btn.classList.contains('is-pinned');
      hideAll();
      buttons.forEach((b) => b.classList.remove('is-pinned'));
      if (!pinned) {
        btn.classList.add('is-pinned');
        showTip(btn);
      }
    });
  });

  document.addEventListener('click', () => {
    buttons.forEach((btn) => {
      btn.classList.remove('is-pinned');
      hideTip(btn);
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      buttons.forEach((btn) => {
        btn.classList.remove('is-pinned');
        hideTip(btn);
      });
    }
  });
  window.addEventListener('scroll', () => {
    if (activeBtn) placeTip(activeBtn);
  }, true);
  window.addEventListener('resize', () => {
    if (activeBtn) placeTip(activeBtn);
  });
}

function bindControls() {
  bindTipButtons();
  document.querySelectorAll('[data-pcp-pos]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.pos = btn.getAttribute('data-pcp-pos') || 'all';
      document.querySelectorAll('[data-pcp-pos]').forEach((b) => {
        b.classList.toggle('is-active', b === btn);
      });
      applyFilters();
    });
  });

  const search = document.querySelector('[data-pcp-search]');
  if (search) {
    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = window.setTimeout(() => {
        state.search = search.value || '';
        applyFilters();
      }, 120);
    });
  }

  const loadMore = document.querySelector('[data-pcp-load-more]');
  if (loadMore) {
    loadMore.addEventListener('click', () => {
      state.visible = Math.min(
        state.filtered.length,
        state.visible + state.loadMoreSize
      );
      renderRows();
    });
  }
}

async function loadReport() {
  showError('');
  startProgress('Loading');
  setProgress(24, { label: 'Getting the latest price change predictions' });
  try {
    const res = await fetch(apiUrl('fpl/price-changes'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    setProgress(72, { label: 'Building list' });
    const data = await res.json();
    if (!res.ok || !data?.ok || !data.report) {
      throw new Error(data?.error || 'Could not load price predictions.');
    }
    state.players = Array.isArray(data.report.players) ? data.report.players : [];
    state.pageSize = Number(data.report.page_size) || INITIAL_SIZE;
    state.loadMoreSize = Number(data.report.load_more_size) || MORE_SIZE;
    applyFilters();
    finishProgress();
  } catch (err) {
    finishProgress();
    showError(err?.message || 'Could not load price predictions.');
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  bindControls();
  try {
    const session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }
  await loadReport();
}

boot();
