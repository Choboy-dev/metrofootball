import { animate, inView, stagger } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav } from './site-nav.js';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  recap: null,
  pos: 'all',
  own: 'all',
  sort: 'points',
  session: null,
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
    top: document.querySelector('[data-gwr-progress]'),
    topBar: document.querySelector('[data-gwr-progress-bar]'),
    panel: document.querySelector('[data-gwr-load]'),
    fill: document.querySelector('[data-gwr-load-fill]'),
    track: document.querySelector('[data-gwr-load-track]'),
    label: document.querySelector('[data-gwr-load-label]'),
    pct: document.querySelector('[data-gwr-load-pct]'),
    sr: document.querySelector('[data-gwr-load-sr]'),
    main: document.querySelector('[data-gwr-main]'),
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

function startProgress(label = 'Loading recap') {
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

async function finishProgress(label = 'Recap ready') {
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
}

function showError(message) {
  const el = document.querySelector('[data-gwr-error]');
  if (!el) return;
  el.hidden = !message;
  el.textContent = message || '';
}

function showToast(message) {
  const el = document.querySelector('[data-gwr-toast]');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  window.setTimeout(() => {
    el.hidden = true;
  }, 2200);
}

function clearGwFromUrl() {
  const url = new URL(window.location.href);
  if (!url.searchParams.has('gw')) return;
  url.searchParams.delete('gw');
  window.history.replaceState({}, '', url);
}

function playerFace(player) {
  const initial = escapeHtml((player.web_name || '?').slice(0, 1).toUpperCase());
  if (player.photo) {
    return `<img class="gwr-face__img" src="${escapeHtml(player.photo)}" alt="" width="44" height="56" loading="lazy" decoding="async" data-gwr-shirt="${escapeHtml(player.shirt || '')}" data-gwr-initial="${initial}">`;
  }
  if (player.shirt) {
    return `<img class="gwr-face__shirt" src="${escapeHtml(player.shirt)}" alt="" width="36" height="48" loading="lazy" decoding="async" data-gwr-initial="${initial}">`;
  }
  return `<span class="gwr-face__fallback" aria-hidden="true">${initial}</span>`;
}

function bindFaceFallbacks(root) {
  root.querySelectorAll('img.gwr-face__img, img.gwr-face__shirt').forEach((img) => {
    img.addEventListener('error', () => {
      const shirt = img.getAttribute('data-gwr-shirt');
      if (img.classList.contains('gwr-face__img') && shirt) {
        img.classList.remove('gwr-face__img');
        img.classList.add('gwr-face__shirt');
        img.removeAttribute('data-gwr-shirt');
        img.src = shirt;
        return;
      }
      const initial = img.getAttribute('data-gwr-initial') || '?';
      const span = document.createElement('span');
      span.className = 'gwr-face__fallback';
      span.setAttribute('aria-hidden', 'true');
      span.textContent = initial;
      img.replaceWith(span);
    }, { once: true });
  });
}

function deltaClass(value) {
  if (value > 0.05) return 'is-up';
  if (value < -0.05) return 'is-down';
  return 'is-flat';
}

function formatDelta(value, digits = 1) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  const abs = Math.abs(n).toFixed(digits);
  if (n > 0) return `+${abs}`;
  if (n < 0) return `−${abs}`;
  return abs;
}

function renderHeader(recap) {
  const event = recap.event || {};
  const status = document.querySelector('[data-gwr-status]');
  const title = document.querySelector('[data-gwr-title]');
  const lead = document.querySelector('[data-gwr-lead]');
  if (status) {
    status.textContent = recap.status === 'final'
      ? 'Gameweek report'
      : 'Still being updated as matches finish';
  }
  if (title) title.textContent = `${event.name || 'Gameweek'} Recap`;
  if (lead) {
    lead.textContent = recap.status === 'final'
      ? 'A simple look at who performed well, who didn\'t, and how the week went.'
      : 'Matches are still being played. Scores here will keep updating.';
  }

  const scorersTitle = document.querySelector('[data-gwr-scorers-title]');
  if (scorersTitle) {
    scorersTitle.textContent = `Top Points from GW ${event.id || ''}`.trim();
  }
}

function renderKpis(recap) {
  const root = document.querySelector('[data-gwr-kpis]');
  if (!root) return;
  const k = recap.kpis || {};
  const cards = [
    { label: 'Average score', value: k.average_entry_score ?? '—' },
    { label: 'Highest score', value: k.highest_score ?? '—' },
    { label: 'Players with 10+ points', value: k.hauls_10_plus ?? 0 },
    { label: 'Big players who blanked', value: k.template_blanks ?? 0 },
    { label: 'Matches finished', value: `${k.fixtures_finished ?? 0}/${k.fixtures_total ?? 0}` },
  ];
  root.innerHTML = cards.map((c) => `
    <div class="gwr-kpi">
      <p class="gwr-kpi__value">${escapeHtml(c.value)}</p>
      <p class="gwr-kpi__label">${escapeHtml(c.label)}</p>
    </div>
  `).join('');
}

function highlightCards(recap) {
  const top = recap.top_scorers?.[0] || null;
  const blank = recap.template?.worst?.[0] || recap.template?.blanks?.[0] || null;
  const capt = recap.captain_story || {};
  let mostOwned = capt.most_owned || capt.most_selected || null;
  if (!mostOwned) {
    const pool = [...(capt.most_owned_five || []), ...(recap.template?.players || []), ...(recap.scorer_pool || [])];
    pool.sort((a, b) => (b.ownership || 0) - (a.ownership || 0));
    mostOwned = pool[0] || null;
  }
  let mostCaptained = capt.most_captained || null;
  if (!mostCaptained) {
    mostCaptained = capt.most_owned_five?.[0] || null;
  }

  const cards = [];
  if (top) {
    cards.push({
      player: top,
      label: 'Top scorer',
      detail: `${top.points} points`,
    });
  }
  if (blank) {
    cards.push({
      player: blank,
      label: 'Big blank',
      detail: `${blank.points} points · ${blank.ownership}% owned`,
    });
  }
  if (mostOwned) {
    cards.push({
      player: mostOwned,
      label: 'Most owned',
      detail: `${mostOwned.ownership ?? '—'}% owned · ${mostOwned.points ?? 0} points`,
    });
  }
  if (mostCaptained) {
    cards.push({
      player: mostCaptained,
      label: 'Most captained',
      detail: `${mostCaptained.points ?? 0} points`,
    });
  }

  return cards.slice(0, 4);
}

function renderNarrative(recap) {
  const root = document.querySelector('[data-gwr-narrative]');
  if (!root) return;
  const gw = recap.event?.id || '';
  const cards = highlightCards(recap);
  const heading = `Gameweek ${escapeHtml(gw)} Summary`;
  const resultsBlock = `
    <div class="gwr-beats-block gwr-narrative__block">
      <h3 class="gwr-beats-block__title">Results</h3>
      <div class="gwr-fixtures gwr-fixtures--rich gwr-narrative__fixtures" data-gwr-fixtures></div>
    </div>
  `;

  if (!cards.length) {
    root.innerHTML = `
      <h2 class="gwr-section__title">${heading}</h2>
      ${resultsBlock}
      <div class="gwr-beats-block gwr-narrative__block">
        <h3 class="gwr-beats-block__title">Players</h3>
        <p class="gwr-empty">Player highlights will show here when scores are in.</p>
      </div>
    `;
    return;
  }
  root.innerHTML = `
    <h2 class="gwr-section__title">${heading}</h2>
    ${resultsBlock}
    <div class="gwr-beats-block gwr-narrative__block">
      <h3 class="gwr-beats-block__title">Players</h3>
      <div class="gwr-story-cards">
        ${cards.map((card) => `
          <article class="gwr-story-card">
            <p class="gwr-story-card__label">${escapeHtml(card.label)}</p>
            <div class="gwr-face gwr-face--lg">${playerFace(card.player)}</div>
            <p class="gwr-story-card__name">${escapeHtml(card.player.web_name)}</p>
            <p class="gwr-story-card__sub">${escapeHtml(card.player.team_short)} · ${escapeHtml(card.player.pos_label)}</p>
            <p class="gwr-story-card__detail">${escapeHtml(card.detail)}</p>
          </article>
        `).join('')}
      </div>
    </div>
  `;
  bindFaceFallbacks(root);
}

function renderYou(recap) {
  const section = document.querySelector('[data-gwr-section="you"]');
  const root = document.querySelector('[data-gwr-you]');
  if (!section || !root) return;
  const you = recap.your_gw;
  if (!you?.available) {
    if (state.session?.authenticated && state.session?.user?.needs_team_link) {
      section.hidden = false;
      root.innerHTML = `
        <div class="gwr-you gwr-you--guest">
          <p>Link your team to see your score, captain, and points left on the bench.</p>
          <a class="mf-btn mf-btn--primary mf-btn--sm" href="./link-team">
            <span class="mf-btn__label">Link team</span>
            <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </div>`;
      return;
    }
    if (!state.session?.authenticated) {
      section.hidden = false;
      root.innerHTML = `
        <div class="gwr-you gwr-you--guest">
          <p>Sign in and link your team to unlock your personal gameweek strip.</p>
          <a class="mf-btn mf-btn--ink mf-btn--sm" href="./login">
            <span class="mf-btn__label">Sign in</span>
            <span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </div>`;
      return;
    }
    section.hidden = true;
    root.innerHTML = '';
    return;
  }

  section.hidden = false;
  const fmt = (n) => (n == null || n === 0 ? '—' : Number(n).toLocaleString('en-GB'));
  const best = you.best_player;
  const worst = you.worst_player;
  const playerTile = (label, player, tone) => {
    if (!player) {
      return `
        <article class="gwr-you-player gwr-you-player--${tone}">
          <p class="gwr-you-player__label">${escapeHtml(label)}</p>
          <p class="gwr-empty">No player to show.</p>
        </article>`;
    }
    return `
      <article class="gwr-you-player gwr-you-player--${tone}">
        <p class="gwr-you-player__label">${escapeHtml(label)}</p>
        <div class="gwr-you-player__body">
          <div class="gwr-face gwr-face--lg">${playerFace(player)}</div>
          <div>
            <p class="gwr-you-player__name">${escapeHtml(player.web_name)}</p>
            <p class="gwr-you-player__sub">${escapeHtml(player.team_short)} · ${escapeHtml(player.pos_label)}</p>
          </div>
          <p class="gwr-you-player__pts">${player.points ?? 0}<span>pts</span></p>
        </div>
      </article>`;
  };

  root.innerHTML = `
    <div class="gwr-you">
      <div class="gwr-you__head">
        <div>
          <p class="gwr-you__team">${escapeHtml(you.team_name || 'Your team')}</p>
          <p class="gwr-you__manager">${escapeHtml(you.manager_name || '')}</p>
        </div>
      </div>
      <div class="gwr-you__metrics" role="list">
        <div class="gwr-you-metric" role="listitem">
          <p class="gwr-you-metric__value">${you.gw_points ?? '—'}</p>
          <p class="gwr-you-metric__label">Points</p>
        </div>
        <div class="gwr-you-metric" role="listitem">
          <p class="gwr-you-metric__value">${you.average_score ?? '—'}</p>
          <p class="gwr-you-metric__label">GW average points</p>
        </div>
        <div class="gwr-you-metric" role="listitem">
          <p class="gwr-you-metric__value">${fmt(you.gw_rank)}</p>
          <p class="gwr-you-metric__label">Gameweek rank</p>
        </div>
        <div class="gwr-you-metric" role="listitem">
          <p class="gwr-you-metric__value">${fmt(you.overall_rank)}</p>
          <p class="gwr-you-metric__label">Overall rank</p>
        </div>
      </div>
      <div class="gwr-you__players">
        ${playerTile('Best player', best, 'best')}
        ${playerTile('Worst player', worst, 'worst')}
      </div>
    </div>
  `;
  bindFaceFallbacks(root);
}

function filteredScorers(recap) {
  let list = [...(recap.scorer_pool || recap.top_scorers || [])];
  if (state.pos !== 'all') {
    const pos = Number(state.pos);
    list = list.filter((p) => p.pos === pos);
  }
  if (state.own === 'template') list = list.filter((p) => p.ownership >= 15);
  if (state.own === 'diff') list = list.filter((p) => p.ownership < 10);

  const sorters = {
    points: (a, b) => b.points - a.points || b.bps - a.bps,
    ownership: (a, b) => b.ownership - a.ownership,
    bps: (a, b) => b.bps - a.bps,
    delta: (a, b) => b.delta_ep - a.delta_ep,
  };
  list.sort(sorters[state.sort] || sorters.points);
  return list.slice(0, 15);
}

function renderScorers(recap) {
  const root = document.querySelector('[data-gwr-scorers]');
  if (!root) return;
  const list = filteredScorers(recap);
  if (!list.length) {
    root.innerHTML = '<p class="gwr-empty">No players match these filters.</p>';
    return;
  }
  root.innerHTML = list.map((p, i) => `
    <article class="gwr-scorer" data-gwr-row>
      <span class="gwr-scorer__rank">${i + 1}</span>
      <div class="gwr-face">${playerFace(p)}</div>
      <div class="gwr-scorer__meta">
        <p class="gwr-scorer__name">${escapeHtml(p.web_name)}</p>
        <p class="gwr-scorer__sub">${escapeHtml(p.team_short)} · ${escapeHtml(p.pos_label)} · ${escapeHtml(p.ownership)}% owned</p>
      </div>
      <div class="gwr-scorer__bar" aria-hidden="true">
        <span style="width:${Math.min(100, (p.points / Math.max(1, list[0].points)) * 100)}%"></span>
      </div>
      <div class="gwr-scorer__pts">
        <strong>${p.points}</strong>
      </div>
    </article>
  `).join('');
  bindFaceFallbacks(root);
}

function storyPlayerCard(label, player, detail) {
  const labelHtml = label
    ? `<p class="gwr-story-card__label">${escapeHtml(label)}</p>`
    : '';
  if (!player) {
    return `
      <article class="gwr-story-card" data-gwr-row>
        ${labelHtml}
        <p class="gwr-empty">Not listed yet.</p>
      </article>
    `;
  }
  return `
    <article class="gwr-story-card" data-gwr-row>
      ${labelHtml}
      <div class="gwr-face gwr-face--lg">${playerFace(player)}</div>
      <p class="gwr-story-card__name">${escapeHtml(player.web_name)}</p>
      <p class="gwr-story-card__sub">${escapeHtml(player.team_short || '')} · ${escapeHtml(player.pos_label || '')}</p>
      <p class="gwr-story-card__detail">${escapeHtml(detail)}</p>
    </article>
  `;
}

function renderCaptains(recap) {
  const root = document.querySelector('[data-gwr-captains]');
  if (!root) return;
  const c = recap.captain_story || {};
  const worst = (c.worst_captains || []).slice(0, 5);

  const worstRows = worst.length
    ? worst.map((p) => `
        <li class="gwr-worst-row">
          <span class="gwr-worst-row__name">${escapeHtml(p.web_name)}</span>
          <span class="gwr-worst-row__own">${escapeHtml(p.ownership ?? '—')}%</span>
          <strong class="gwr-worst-row__pts">${p.points ?? 0}</strong>
        </li>
      `).join('')
    : '<li class="gwr-empty">No captain blanks to show.</li>';

  root.innerHTML = `
    <div class="gwr-story-cards gwr-cap-spotlight">
      ${storyPlayerCard(
        'Most owned',
        c.most_owned || c.most_selected,
        `${(c.most_owned || c.most_selected)?.ownership ?? '—'}% owned · ${(c.most_owned || c.most_selected)?.points ?? 0} points`
      )}
      ${storyPlayerCard(
        'Most captained',
        c.most_captained,
        `${c.most_captained?.points ?? 0} points · ${c.most_captained?.ownership ?? '—'}% owned`
      )}
      ${storyPlayerCard(
        'Best captain',
        c.best_captain,
        `${c.best_captain?.points ?? 0} points · ${c.best_captain?.ownership ?? '—'}% owned`
      )}
      <article class="gwr-story-card gwr-story-card--list" data-gwr-row>
        <p class="gwr-story-card__label">Worst captains</p>
        <ul class="gwr-worst-list" aria-label="Worst performing popular captains">
          ${worstRows}
        </ul>
      </article>
    </div>
  `;
  bindFaceFallbacks(root);
}

function renderBonus(recap) {
  const root = document.querySelector('[data-gwr-bonus]');
  if (!root) return;
  const moments = (recap.bonus_moments || []).filter((m) => (m.player?.bonus || 0) > 0);
  if (!moments.length) {
    root.innerHTML = '<p class="gwr-empty">No bonus points to show yet.</p>';
    return;
  }
  root.innerHTML = `<ul class="gwr-bonus-list">${moments.map((m) => {
    const p = m.player || {};
    return `
    <li class="gwr-bonus-item">
      <div class="gwr-face">${playerFace(p)}</div>
      <div class="gwr-bonus-item__meta">
        <p class="gwr-bonus-item__name">${escapeHtml(p.web_name)}</p>
        <p class="gwr-bonus-item__sub">${escapeHtml(p.team_short || '')} · ${escapeHtml(p.pos_label || '')} · ${escapeHtml(p.ownership ?? '—')}% owned</p>
      </div>
      <strong class="gwr-bonus-item__pts">${p.bonus} bonus</strong>
    </li>
  `;
  }).join('')}</ul>`;
  bindFaceFallbacks(root);
}

function teamBadge(team) {
  if (team?.badge) {
    return `<img class="gwr-team-badge" src="${escapeHtml(team.badge)}" alt="" width="36" height="36" loading="lazy">`;
  }
  const short = team?.short || '?';
  return `<span class="gwr-team-badge gwr-team-badge--fallback" aria-hidden="true">${escapeHtml(short.slice(0, 3))}</span>`;
}

function renderBeats(recap) {
  const root = document.querySelector('[data-gwr-beats]');
  const lead = document.querySelector('[data-gwr-beats-lead]');
  if (!root) return;
  const b = recap.beats_misses || {};
  const gw = recap.event?.id || '';
  if (lead) {
    lead.textContent = b.basis
      || `Who scored more or less than the prediction gameweek ${gw}.`;
  }

  const over = (b.over || []).slice(0, 5);
  const upsets = (b.surprise_winners || []).slice(0, 5);

  const overCards = over.length
    ? over.map((p) => {
      const overBy = Math.abs(Number(p.delta_ep) || 0).toFixed(1);
      return storyPlayerCard(
        '',
        p,
        `${p.points ?? 0} points (scored ${overBy} more points than predicted)`
      );
    }).join('')
    : '<p class="gwr-empty">No players beat their prediction yet.</p>';

  let upsetBlock;
  if (!upsets.length) {
    upsetBlock = `
      <div class="gwr-upset-empty" data-gwr-row>
        <div class="gwr-upset-empty__mark" aria-hidden="true">
          <svg viewBox="0 0 64 64" fill="none">
            <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="2" opacity="0.35"/>
            <path d="M18 34c4 6 10 10 14 10s10-4 14-10" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
            <circle cx="24" cy="26" r="2.2" fill="currentColor"/>
            <circle cx="40" cy="26" r="2.2" fill="currentColor"/>
          </svg>
        </div>
        <p class="gwr-upset-empty__title">No surprise winners</p>
        <p class="gwr-upset-empty__copy">The favourites held firm this gameweek. No smaller side beat a bigger team.</p>
      </div>
    `;
  } else {
    upsetBlock = `
      <div class="gwr-upset-grid" style="--gwr-cols: ${upsets.length}" data-gwr-row-group>
        ${upsets.map((u) => `
          <article class="gwr-upset-card" data-gwr-row>
            <div class="gwr-upset-card__fixture" aria-label="${escapeHtml(u.home?.name || '')} ${u.score_h} ${u.score_a} ${escapeHtml(u.away?.name || '')}">
              <div class="gwr-upset-card__side">
                ${teamBadge(u.home)}
                <span>${escapeHtml(u.home?.short || '')}</span>
              </div>
              <p class="gwr-upset-card__score">${u.score_h ?? 0}-${u.score_a ?? 0}</p>
              <div class="gwr-upset-card__side">
                ${teamBadge(u.away)}
                <span>${escapeHtml(u.away?.short || '')}</span>
              </div>
            </div>
            <p class="gwr-upset-card__blurb">${escapeHtml(u.blurb || `${u.winner?.name || 'A team'} beat ${u.loser?.name || 'a bigger side'}.`)}</p>
          </article>
        `).join('')}
      </div>
    `;
  }

  root.innerHTML = `
    <div class="gwr-beats-block">
      <h3 class="gwr-beats-block__title">More points than predicted</h3>
      <div class="gwr-story-cards gwr-story-cards--five">
        ${overCards}
      </div>
    </div>
    <div class="gwr-beats-block">
      <h3 class="gwr-beats-block__title">Surprise winners</h3>
      ${upsetBlock}
    </div>
  `;
  bindFaceFallbacks(root);
}

function renderTemplate(recap) {
  const root = document.querySelector('[data-gwr-template]');
  if (!root) return;
  const t = recap.template || {};
  const best = (t.best || t.hauls || []).slice(0, 5);
  const worst = (t.worst || t.blanks || []).slice(0, 5);

  const rowCards = (players, emptyCopy) => {
    if (!players.length) {
      return `<p class="gwr-empty">${escapeHtml(emptyCopy)}</p>`;
    }
    return players.map((p, i) => storyPlayerCard(
      `#${i + 1}`,
      p,
      `${p.points ?? 0} points · ${p.ownership ?? '—'}% owned`
    )).join('');
  };

  root.innerHTML = `
    <div class="gwr-beats-block">
      <h3 class="gwr-beats-block__title">Best performing popular players</h3>
      <div class="gwr-story-cards gwr-story-cards--five">
        ${rowCards(best, 'No popular players scored big yet.')}
      </div>
    </div>
    <div class="gwr-beats-block">
      <h3 class="gwr-beats-block__title">Worst performing popular players</h3>
      <div class="gwr-story-cards gwr-story-cards--five">
        ${rowCards(worst, 'No popular players scored little yet.')}
      </div>
    </div>
  `;
  bindFaceFallbacks(root);
}

function matchEventIcons(count, kind) {
  if (!count || count < 1) return '';
  const src = kind === 'goal' ? './img/live/goal.png' : './img/live/assist.png';
  const title = kind === 'goal' ? 'Goal' : 'Assist';
  return Array.from({ length: count }, () => (
    `<img class="gwr-match-ev__icon" src="${src}" alt="" title="${title}" width="12" height="12" decoding="async">`
  )).join('');
}

function matchEventsHtml(events, align) {
  const list = events || [];
  if (!list.length) {
    return `<div class="gwr-match-card__quiet gwr-match-card__quiet--${align}" aria-hidden="true"></div>`;
  }
  return `
    <ul class="gwr-match-events gwr-match-events--${align}">
      ${list.map((ev) => {
        const icons = `${matchEventIcons(ev.goals || 0, 'goal')}${matchEventIcons(ev.assists || 0, 'assist')}`;
        return `
          <li class="gwr-match-ev">
            <span class="gwr-match-ev__name">${escapeHtml(ev.web_name)}</span>
            <span class="gwr-match-ev__icons" aria-hidden="true">${icons}</span>
          </li>
        `;
      }).join('')}
    </ul>
  `;
}

function renderFixtures(recap) {
  const root = document.querySelector('[data-gwr-fixtures]');
  if (!root) return;
  const rows = recap.fixtures || [];
  if (!rows.length) {
    root.innerHTML = '<p class="gwr-empty">No match results listed.</p>';
    return;
  }

  const cols = rows.length >= 5 ? 5 : Math.max(1, rows.length);
  root.classList.add('gwr-fixtures--rich');
  root.style.setProperty('--gwr-fx-cols', String(cols));

  root.innerHTML = rows.map((fx) => {
    const score = fx.finished
      ? `${fx.score_h ?? 0}-${fx.score_a ?? 0}`
      : 'vs';
    const homeBadge = fx.home_badge
      ? `<img class="gwr-match-card__badge" src="${escapeHtml(fx.home_badge)}" alt="" width="36" height="36" loading="lazy">`
      : `<span class="gwr-match-card__badge gwr-match-card__badge--fallback">${escapeHtml((fx.home || '?').slice(0, 3))}</span>`;
    const awayBadge = fx.away_badge
      ? `<img class="gwr-match-card__badge" src="${escapeHtml(fx.away_badge)}" alt="" width="36" height="36" loading="lazy">`
      : `<span class="gwr-match-card__badge gwr-match-card__badge--fallback">${escapeHtml((fx.away || '?').slice(0, 3))}</span>`;

    return `
      <article class="gwr-match-card ${fx.finished ? 'is-done' : 'is-pending'}" data-gwr-row>
        <div class="gwr-match-card__scoreline" aria-label="${escapeHtml(fx.home_name || fx.home)} ${score} ${escapeHtml(fx.away_name || fx.away)}">
          <div class="gwr-match-card__club">
            ${homeBadge}
            <span>${escapeHtml(fx.home)}</span>
          </div>
          <p class="gwr-match-card__score">${escapeHtml(score)}</p>
          <div class="gwr-match-card__club">
            ${awayBadge}
            <span>${escapeHtml(fx.away)}</span>
          </div>
        </div>
        <div class="gwr-match-card__body">
          ${matchEventsHtml(fx.home_events, 'home')}
          <div class="gwr-match-card__divider" aria-hidden="true"></div>
          ${matchEventsHtml(fx.away_events, 'away')}
        </div>
      </article>
    `;
  }).join('');
}

function revealSections() {
  const nodes = document.querySelectorAll('[data-gwr-reveal]');
  if (reduceMotion) {
    nodes.forEach((n) => n.classList.add('is-in'));
    return;
  }
  nodes.forEach((node) => {
    inView(node, () => {
      animate(
        node,
        { opacity: [0, 1], y: [16, 0] },
        { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] }
      );
      node.classList.add('is-in');
      const rows = node.querySelectorAll('[data-gwr-row]');
      if (rows.length) {
        animate(
          rows,
          { opacity: [0, 1], y: [10, 0] },
          { delay: stagger(0.04), duration: 0.35, easing: [0.17, 0.55, 0.55, 1] }
        );
      }
      return () => {};
    }, { amount: 0.2 });
  });
}

function renderAll(recap) {
  state.recap = recap;
  renderHeader(recap);
  renderKpis(recap);
  renderNarrative(recap);
  renderYou(recap);
  renderScorers(recap);
  renderCaptains(recap);
  renderBonus(recap);
  renderBeats(recap);
  renderTemplate(recap);
  renderFixtures(recap);
  const board = document.querySelector('[data-gwr-board]');
  if (board) board.hidden = false;
  revealSections();
}

async function loadRecap() {
  showError('');
  startProgress('Loading recap');
  try {
    setProgress(30, { label: 'Loading match scores' });
    const res = await fetch(apiUrl('fpl/gameweek-recap'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    setProgress(70, { label: 'Building the report' });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'We could not load this recap.');
    }
    clearGwFromUrl();
    renderAll(data.recap);
    await finishProgress('Recap ready');
  } catch (err) {
    failProgress();
    showError(err?.message || 'We could not load this recap.');
  }
}

function bindControls() {
  document.querySelectorAll('[data-gwr-pos]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-gwr-pos]').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      state.pos = btn.getAttribute('data-gwr-pos') || 'all';
      if (state.recap) renderScorers(state.recap);
    });
  });
  document.querySelector('[data-gwr-own]')?.addEventListener('change', (e) => {
    state.own = e.target.value || 'all';
    if (state.recap) renderScorers(state.recap);
  });
  document.querySelector('[data-gwr-sort]')?.addEventListener('change', (e) => {
    state.sort = e.target.value || 'points';
    if (state.recap) renderScorers(state.recap);
  });
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  bindControls();
  const [, session] = await Promise.all([
    loadRecap(),
    fetchSession(),
  ]);
  state.session = session;
  applyAuthNav(state.session);
}

boot();
