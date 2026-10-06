import { animate } from './motion.js?v=20260901perf';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  applyAuthRedirect,
  bindSignOutButtons,
  captureReturnFromUrl,
  fetchSession,
  getReturnPath,
  loginUrlWithReturn,
  pageUrl,
  postJson,
  setBusy,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const IMPORT_SHOW_DELAY_MS = 80;

const importUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

function importEls() {
  return {
    overlay: document.querySelector('[data-import-overlay]'),
    top: document.querySelector('[data-import-progress]'),
    topBar: document.querySelector('[data-import-progress-bar]'),
    fill: document.querySelector('[data-import-fill]'),
    track: document.querySelector('[data-import-track]'),
    label: document.querySelector('[data-import-label]'),
    hint: document.querySelector('[data-import-hint]'),
    pct: document.querySelector('[data-import-pct]'),
    sr: document.querySelector('[data-import-sr]'),
  };
}

function applyImportVisual(value) {
  const els = importEls();
  const clamped = Math.max(0, Math.min(100, value));
  const scale = clamped / 100;
  const rounded = String(Math.round(clamped));

  if (els.topBar) {
    els.topBar.style.transform = `scaleX(${scale})`;
    els.topBar.setAttribute('aria-valuenow', rounded);
  }
  if (els.fill) {
    els.fill.style.transform = `scaleX(${scale})`;
  }
  if (els.track) {
    els.track.setAttribute('aria-valuenow', rounded);
  }
  if (els.pct) {
    els.pct.textContent = `${Math.round(clamped)}%`;
  }
}

function setImportCopy(label, hint) {
  const els = importEls();
  if (label) {
    if (els.label) els.label.textContent = label;
    if (els.sr) els.sr.textContent = label;
  }
  if (hint && els.hint) {
    els.hint.textContent = hint;
  }
}

function revealImportUi() {
  if (importUi.visible) return;
  importUi.visible = true;
  const els = importEls();

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
}

function stopImportTrickle() {
  if (importUi.trickleTimer) {
    clearInterval(importUi.trickleTimer);
    importUi.trickleTimer = null;
  }
}

function startImportProgress({
  label = 'Importing your FPL team',
  hint = 'Pulling your squad from Fantasy Premier League.',
} = {}) {
  importUi.value = 0;
  applyImportVisual(0);
  setImportCopy(label, hint);

  if (importUi.showTimer) clearTimeout(importUi.showTimer);
  importUi.showTimer = window.setTimeout(() => {
    revealImportUi();
  }, IMPORT_SHOW_DELAY_MS);

  stopImportTrickle();
  importUi.trickleTimer = window.setInterval(() => {
    if (importUi.value >= 88) return;
    const next = importUi.value + Math.max(0.4, (90 - importUi.value) * 0.05);
    setImportProgress(Math.min(88, next), { immediate: reduceMotion });
  }, reduceMotion ? 400 : 220);
}

function setImportProgress(target, { label, hint, immediate = false } = {}) {
  const next = Math.max(importUi.value, Math.min(100, target));
  if (label || hint) setImportCopy(label, hint);

  if (importUi.anim) {
    importUi.anim.stop();
    importUi.anim = null;
  }

  if (immediate || reduceMotion) {
    importUi.value = next;
    applyImportVisual(next);
    return;
  }

  const from = importUi.value;
  importUi.anim = animate(from, next, {
    duration: Math.min(0.55, 0.18 + Math.abs(next - from) / 160),
    easing: [0.22, 1, 0.36, 1],
    onUpdate: (latest) => {
      importUi.value = latest;
      applyImportVisual(latest);
    },
  });
}

async function completeImportProgress({
  label = 'Team linked',
  hint = 'Opening your Live page.',
} = {}) {
  stopImportTrickle();
  if (importUi.showTimer) {
    clearTimeout(importUi.showTimer);
    importUi.showTimer = null;
  }

  if (!importUi.visible) {
    revealImportUi();
  }

  setImportProgress(100, { label, hint, immediate: reduceMotion });
  if (importUi.anim) {
    try {
      await importUi.anim;
    } catch {
      // cancelled
    }
  }

  const els = importEls();
  if (els.top) els.top.classList.add('is-complete');
  if (els.overlay) els.overlay.classList.remove('is-active');

  await new Promise((resolve) => window.setTimeout(resolve, reduceMotion ? 40 : 220));
}

function failImportProgress() {
  stopImportTrickle();
  if (importUi.showTimer) {
    clearTimeout(importUi.showTimer);
    importUi.showTimer = null;
  }
  if (importUi.anim) {
    importUi.anim.stop();
    importUi.anim = null;
  }

  const els = importEls();
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

  document.body.classList.remove('is-importing');
  document.body.removeAttribute('aria-busy');
  importUi.visible = false;
  importUi.value = 0;
  applyImportVisual(0);
}

function showError(message) {
  const el = document.querySelector('[data-link-error]');
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function formatRank(value) {
  if (value == null) return null;
  return Number(value).toLocaleString('en-GB');
}

function renderMatches(matches) {
  const wrap = document.querySelector('[data-link-results]');
  const list = document.querySelector('[data-link-list]');
  if (!wrap || !list) return;

  list.innerHTML = '';
  matches.forEach((team) => {
    const li = document.createElement('li');
    li.className = 'link-team-item';

    const meta = [];
    if (team.overall_points != null) meta.push(`${team.overall_points} pts`);
    if (team.overall_rank != null) meta.push(`Rank ${formatRank(team.overall_rank)}`);
    meta.push(`ID ${team.id}`);

    li.innerHTML = `
      <div class="link-team-item__copy">
        <p class="link-team-item__name">${escapeHtml(team.team_name)}</p>
        <p class="link-team-item__manager">${escapeHtml(team.manager_name)}</p>
        <p class="link-team-item__meta">${escapeHtml(meta.join(' · '))}</p>
      </div>
      <button type="button" class="mf-btn mf-btn--ink mf-btn--sm" data-link-confirm data-fpl-id="${team.id}">
        <span class="mf-btn__label">Link team</span>
        <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
      </button>
    `;
    list.appendChild(li);
  });

  wrap.hidden = matches.length === 0;

  if (!reduceMotion && matches.length) {
    animate(
      list.querySelectorAll('.link-team-item'),
      { opacity: [0, 1], y: [10, 0] },
      { duration: 0.35, easing: [0.17, 0.55, 0.55, 1] }
    );
  }

  list.querySelectorAll('[data-link-confirm]').forEach((button) => {
    button.addEventListener('click', () => linkTeam(button));
  });
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

async function linkTeam(button) {
  const fplTeamId = Number(button.getAttribute('data-fpl-id'));
  showError('');
  setBusy(button, true, 'Importing');
  startImportProgress();
  try {
    const data = await postJson('fpl/link', {
      fpl_team_id: fplTeamId,
      next: getReturnPath(),
    });
    await completeImportProgress();
    applyAuthRedirect(data.redirect || '/live');
  } catch (error) {
    failImportProgress();
    setBusy(button, false);
    showError(error.message || 'Could not link that team.');
  }
}

function syncSearchLabel() {
  const input = document.querySelector('[data-link-query]');
  const button = document.querySelector('[data-link-search]');
  const label = button?.querySelector('.mf-btn__label');
  if (!label || button?.classList.contains('is-busy')) return;

  const hasValue = String(input?.value || '').trim().length > 0;
  label.textContent = hasValue ? 'Import team' : 'Search';
  if (button.dataset.label != null) {
    button.dataset.label = label.textContent;
  }
}

async function searchTeams(event) {
  event.preventDefault();
  const input = document.querySelector('[data-link-query]');
  const button = document.querySelector('[data-link-search]');
  const query = String(input?.value || '').trim();
  showError('');

  if (!query) {
    showError('Enter your FPL ID.');
    return;
  }

  setBusy(button, true);
  try {
    // Numeric ID: import straight away, then open Live
    if (/^\d+$/.test(query)) {
      setBusy(button, true, 'Importing');
      startImportProgress();
      const data = await postJson('fpl/link', {
        fpl_team_id: Number(query),
        next: getReturnPath(),
      });
      await completeImportProgress();
      applyAuthRedirect(data.redirect || '/live');
      return;
    }

    setBusy(button, true, 'Searching');
    const data = await postJson('fpl/search', { query });
    renderMatches(data.matches || []);
    if (!(data.matches || []).length) {
      showError('No matching teams found.');
    }
    setBusy(button, false);
    syncSearchLabel();
  } catch (error) {
    failImportProgress();
    renderMatches([]);
    showError(error.message || 'Could not find that FPL ID.');
    setBusy(button, false);
    syncSearchLabel();
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  captureReturnFromUrl();

  const session = await fetchSession();
  if (!session?.authenticated) {
    window.location.href = loginUrlWithReturn();
    return;
  }

  applyAuthNav(session);

  const redirect = String(session.redirect || '/link-team').replace(/^\//, '');
  if (redirect !== 'link-team' && !redirect.startsWith('link-team?')) {
    applyAuthRedirect(`/${redirect}`);
    return;
  }

  const card = document.querySelector('[data-link-card]');
  const tabs = document.querySelector('[data-team-tabs]');
  initTeamTabs();
  if (!reduceMotion) {
    if (tabs) {
      animate(tabs, { opacity: [0, 1], y: [-6, 0] }, { duration: 0.35, easing: [0.17, 0.55, 0.55, 1] });
    }
    if (card) {
      animate(card, { opacity: [0, 1], y: [16, 0] }, { duration: 0.45, delay: 0.06, easing: [0.17, 0.55, 0.55, 1] });
    }
  }

  const queryInput = document.querySelector('[data-link-query]');
  queryInput?.addEventListener('input', syncSearchLabel);
  queryInput?.addEventListener('paste', () => {
    requestAnimationFrame(syncSearchLabel);
  });
  syncSearchLabel();

  document.querySelector('[data-link-form]')?.addEventListener('submit', searchTeams);
}

boot();
