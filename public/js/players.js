import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
  fetchWithTimeout,
} from './auth.js?v=20260907pub';

const RECENT_KEY = 'mf-pp-recent-v1';
const MAX_RECENT = 10;

const state = {
  players: [],
  window: 4,
  position: 'all',
  playerIndex: new Map(),
  clubs: [],
  activeClub: '',
  clubCache: new Map(),
};

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function slugify(text) {
  return String(text || '')
    .toLowerCase()
    .replace(/[''.]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

function playerProfileUrl(player) {
  const club = slugify(player.team_name || player.team_short || '');
  const name = slugify(player.web_name || '');
  if (!club || !name) return '#';
  return `/players/${club}/${name}`;
}

function formatPrice(price) {
  const num = Number(price);
  if (!Number.isFinite(num)) return '';
  return `£${num.toFixed(1)}m`;
}

function formatEventPoints(points) {
  const value = Number(points) || 0;
  return value === 1 ? '1 pt' : `${value} pts`;
}

function readRecentIds() {
  try {
    const raw = localStorage.getItem(RECENT_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .map((id) => Number(id))
      .filter((id) => Number.isInteger(id) && id > 0)
      .slice(0, MAX_RECENT);
  } catch {
    return [];
  }
}

function writeRecentIds(ids) {
  localStorage.setItem(RECENT_KEY, JSON.stringify(ids.slice(0, MAX_RECENT)));
}

function recordPlayerView(playerId) {
  const id = Number(playerId);
  if (!Number.isInteger(id) || id < 1) return;

  const ids = readRecentIds().filter((existingId) => existingId !== id);
  ids.unshift(id);
  writeRecentIds(ids);
  renderRecentSection();
}

function indexPlayers(players) {
  players.forEach((player) => {
    if (player?.id) {
      state.playerIndex.set(Number(player.id), player);
    }
  });
}

function faceHtml(player, classPrefix = 'pp-form-card') {
  const name = escapeHtml(player.web_name || 'Player');
  const initial = escapeHtml(String(player.web_name || '?').slice(0, 1));
  const shirt = player.shirt ? ` data-pp-shirt="${escapeHtml(player.shirt)}"` : '';

  if (player.photo) {
    return `<img class="${classPrefix}__img ${classPrefix}__img--face" src="${escapeHtml(player.photo)}" alt="" width="48" height="48" loading="lazy" decoding="async"${shirt} data-pp-initial="${initial}">`;
  }
  if (player.shirt) {
    return `<img class="${classPrefix}__img ${classPrefix}__img--shirt" src="${escapeHtml(player.shirt)}" alt="" width="36" height="48" loading="lazy" decoding="async" data-pp-initial="${initial}">`;
  }
  return `<span class="${classPrefix}__fallback" aria-hidden="true">${initial}</span>`;
}

function renderFormCard(player, window) {
  const ptsLabel = window === 1 ? 'pt in 1 GW' : `pts in ${window} GWs`;
  const meta = [
    escapeHtml(player.pos_label || '?'),
    escapeHtml(player.team_short || ''),
    escapeHtml(formatPrice(player.price)),
  ].join(' · ');

  return `
    <a
      class="pp-form-card"
      href="${escapeHtml(playerProfileUrl(player))}"
      data-pp-player-id="${escapeHtml(player.id)}"
      data-pp-position="${escapeHtml(player.pos_label || '')}"
      data-pp-name="${escapeHtml(player.web_name || '')}"
    >
      <span class="pp-form-card__face">${faceHtml(player, 'pp-form-card')}</span>
      <span class="pp-form-card__name">${escapeHtml(player.web_name || 'Player')}</span>
      <span class="pp-form-card__meta">${meta}</span>
      <span class="pp-form-card__pts">${escapeHtml(player.form_points)}</span>
      <span class="pp-form-card__label">${escapeHtml(ptsLabel)}</span>
    </a>
  `;
}

function renderRecentRow(player) {
  const meta = [
    escapeHtml(player.pos_label || '?'),
    escapeHtml(player.team_short || ''),
  ].join(' · ');

  return `
    <a
      class="pp-recent-row"
      href="${escapeHtml(playerProfileUrl(player))}"
      data-pp-player-id="${escapeHtml(player.id)}"
      data-pp-name="${escapeHtml(player.web_name || '')}"
    >
      <span class="pp-recent-row__copy">
        <span class="pp-recent-row__name">${escapeHtml(player.web_name || 'Player')}</span>
        <span class="pp-recent-row__meta">${meta}</span>
      </span>
      <span class="pp-recent-row__pts">${escapeHtml(formatEventPoints(player.event_points))}</span>
    </a>
  `;
}

function ownedTagFaceHtml(player) {
  const initial = escapeHtml(String(player.web_name || '?').slice(0, 1));
  const shirt = player.shirt ? ` data-pp-shirt="${escapeHtml(player.shirt)}"` : '';

  if (player.photo) {
    return `<span class="pp-owned__tag-face"><img src="${escapeHtml(player.photo)}" alt="" width="20" height="20" loading="lazy" decoding="async" class="pp-owned__tag-photo"${shirt} data-pp-initial="${initial}"></span>`;
  }
  if (player.shirt) {
    return `<span class="pp-owned__tag-face"><img src="${escapeHtml(player.shirt)}" alt="" width="16" height="20" loading="lazy" decoding="async" class="pp-owned__tag-photo pp-owned__tag-shirt" data-pp-initial="${initial}"></span>`;
  }
  return `<span class="pp-owned__tag-face"><span class="pp-owned__tag-fallback" aria-hidden="true">${initial}</span></span>`;
}

function renderOwnedTag(player) {
  const ownership = Number(player.ownership);
  const title = Number.isFinite(ownership) ? `${ownership}% owned in FPL` : 'Most owned in FPL';

  return `
    <a
      class="pp-owned__tag"
      href="${escapeHtml(playerProfileUrl(player))}"
      title="${escapeHtml(title)}"
      data-pp-player-id="${escapeHtml(player.id)}"
      data-pp-name="${escapeHtml(player.web_name || '')}"
    >
      ${ownedTagFaceHtml(player)}
      <span class="pp-owned__tag-name">${escapeHtml(player.web_name || 'Player')}</span>
    </a>
  `;
}

function renderFormCards() {
  const root = document.querySelector('[data-pp-form-cards]');
  if (!root) return;

  if (!state.players.length) {
    root.innerHTML = '<p class="pp-form-empty">No in-form players yet this season.</p>';
    return;
  }

  root.innerHTML = state.players.map((player) => renderFormCard(player, state.window)).join('');
  applyPositionFilter(state.position);
}

function setMostOwnedLoading(isLoading) {
  const root = document.querySelector('[data-pp-owned-tags]');
  if (!root || !isLoading) return;
  root.innerHTML = '<span class="pp-owned__loading">Loading most owned players</span>';
}

function rosterFaceHtml(player) {
  const initial = escapeHtml(String(player.web_name || '?').slice(0, 1));
  const shirt = player.shirt ? ` data-pp-shirt="${escapeHtml(player.shirt)}"` : '';

  if (player.photo) {
    return `<img class="pp-roster-player__img pp-roster-player__img--face" src="${escapeHtml(player.photo)}" alt="" width="40" height="40" loading="lazy" decoding="async"${shirt} data-pp-initial="${initial}">`;
  }
  if (player.shirt) {
    return `<img class="pp-roster-player__img pp-roster-player__img--shirt" src="${escapeHtml(player.shirt)}" alt="" width="30" height="40" loading="lazy" decoding="async" data-pp-initial="${initial}">`;
  }
  return `<span class="pp-roster-player__fallback" aria-hidden="true">${initial}</span>`;
}

function renderRosterPlayer(player) {
  return `
    <a
      class="pp-roster-player"
      href="${escapeHtml(playerProfileUrl(player))}"
      role="listitem"
      data-pp-player-id="${escapeHtml(player.id)}"
      data-pp-name="${escapeHtml(player.web_name || '')}"
    >
      <span class="pp-roster-player__face">${rosterFaceHtml(player)}</span>
      <span class="pp-roster-player__copy">
        <span class="pp-roster-player__name">${escapeHtml(player.web_name || 'Player')}</span>
        <span class="pp-roster-player__pos">${escapeHtml(player.pos_label || '?')}</span>
      </span>
      <span class="pp-roster-player__price">${escapeHtml(formatPrice(player.price))}</span>
    </a>
  `;
}

function setActiveClubButton(shortName) {
  document.querySelectorAll('[data-pp-club]').forEach((btn) => {
    const active = (btn.dataset.ppClub || '') === shortName;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
  });
}

function setRosterStatus(message, isError = false) {
  const status = document.querySelector('[data-pp-club-roster-status]');
  if (!status) return;
  if (!message) {
    status.hidden = true;
    status.textContent = '';
    status.classList.remove('is-error');
    return;
  }
  status.hidden = false;
  status.textContent = message;
  status.classList.toggle('is-error', isError);
}

function renderClubRoster(club) {
  const section = document.querySelector('[data-pp-club-roster]');
  const title = document.querySelector('[data-pp-club-roster-title]');
  const grid = document.querySelector('[data-pp-club-roster-grid]');
  const badgeWrap = document.querySelector('[data-pp-club-roster-brand]');
  const badge = document.querySelector('[data-pp-club-roster-badge]');
  if (!section || !title || !grid) return;

  const players = Array.isArray(club?.players) ? club.players : [];
  indexPlayers(players);

  title.textContent = club?.name || club?.short_name || 'Club squad';

  if (badgeWrap && badge) {
    if (club?.badge) {
      badge.src = club.badge;
      badge.alt = '';
      badgeWrap.hidden = false;
      badgeWrap.setAttribute('aria-hidden', 'false');
    } else {
      badge.removeAttribute('src');
      badgeWrap.hidden = true;
      badgeWrap.setAttribute('aria-hidden', 'true');
    }
  }

  if (!players.length) {
    grid.innerHTML = '';
    setRosterStatus('No players found for this club right now.');
    section.hidden = false;
    return;
  }

  setRosterStatus('');
  grid.innerHTML = players.map(renderRosterPlayer).join('');
  section.hidden = false;
  section.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
}

async function loadClubRoster(shortName) {
  const code = String(shortName || '').trim().toUpperCase();
  if (!code) return;

  state.activeClub = code;
  setActiveClubButton(code);

  const section = document.querySelector('[data-pp-club-roster]');
  const grid = document.querySelector('[data-pp-club-roster-grid]');
  if (section) section.hidden = false;
  if (grid) grid.innerHTML = '';
  setRosterStatus('Loading squad');

  if (state.clubCache.has(code)) {
    renderClubRoster(state.clubCache.get(code));
    return;
  }

  try {
    const res = await fetchWithTimeout(apiUrl(`fpl/players?club=${encodeURIComponent(code)}`), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.ok || !data.club) {
      throw new Error(data.error || 'Could not load club squad.');
    }

    state.clubCache.set(code, data.club);
    renderClubRoster(data.club);

    const url = new URL(window.location.href);
    url.searchParams.set('club', code);
    window.history.replaceState({}, '', url);
  } catch (error) {
    setRosterStatus(error?.message || 'Could not load club squad.', true);
  }
}

function renderClubs(clubs) {
  const root = document.querySelector('[data-pp-clubs]');
  if (!root) return;

  state.clubs = clubs;

  if (!clubs.length) {
    return;
  }

  root.innerHTML = clubs.map((club) => {
    const badge = club.badge
      ? `<img class="pp-club__badge" src="${escapeHtml(club.badge)}" alt="" width="28" height="28" loading="lazy" decoding="async">`
      : '';
    const code = escapeHtml(club.short_name || '');
    const path = escapeHtml(club.path || clubHubPath(club));
    const title = escapeHtml(club.name || club.short_name || '');

    return `
      <a
        class="pp-club"
        href="${path}"
        title="${title}"
        data-pp-club="${code}"
        data-pp-club-path="${path}"
      >
        ${badge}
        <span class="pp-club__code">${code}</span>
      </a>
    `;
  }).join('');
}

function clubHubPath(club) {
  const slug = slugify(club.name || club.short_name || '');
  return slug ? `/players/${slug}` : '/players';
}

function renderMostOwned(players) {
  const root = document.querySelector('[data-pp-owned-tags]');
  if (!root) return;

  if (!players.length) {
    root.innerHTML = '<span class="pp-owned__loading">Most owned players unavailable right now</span>';
    return;
  }

  indexPlayers(players);
  root.innerHTML = players.map(renderOwnedTag).join('');
}

function renderRecentSection() {
  const list = document.querySelector('[data-pp-recent-list]');
  const ids = readRecentIds();

  if (!list) return;

  if (!ids.length) {
    list.innerHTML = '';
    return;
  }

  fetch(apiUrl(`fpl/players?ids=${ids.join(',')}`))
    .then((res) => res.json().then((data) => ({ res, data })))
    .then(({ res, data }) => {
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Could not load recently viewed players.');
      }

      const players = Array.isArray(data.players) ? data.players : [];
      indexPlayers(players);
      list.innerHTML = players.length ? players.map(renderRecentRow).join('') : '';
    })
    .catch(() => {
      list.innerHTML = '';
    });
}

function applyPositionFilter(position) {
  state.position = position;
  const cards = [...document.querySelectorAll('[data-pp-form-cards] .pp-form-card')];
  if (!cards.length) return;

  const matching = cards.filter((card) => position === 'all' || card.dataset.ppPosition === position);
  cards.forEach((card) => {
    card.hidden = true;
  });
  matching.slice(0, 4).forEach((card) => {
    card.hidden = false;
  });
}

function bindPositionFilters() {
  const filters = document.querySelector('[data-pp-filters]');
  if (!filters) return;

  filters.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-pp-position]');
    if (!btn || !filters.contains(btn)) return;

    filters.querySelectorAll('[data-pp-position]').forEach((filterBtn) => {
      const active = filterBtn === btn;
      filterBtn.classList.toggle('is-active', active);
      filterBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    applyPositionFilter(btn.dataset.ppPosition || 'all');
  });
}

function bindSearch() {
  const input = document.querySelector('[data-pp-search]');
  if (!input) return;

  input.addEventListener('input', () => {
    const query = input.value.trim().toLowerCase();
    document.querySelectorAll('[data-pp-name]').forEach((el) => {
      const name = (el.dataset.ppName || el.textContent || '').toLowerCase();
      const row = el.closest('.pp-form-card, .pp-owned__tag, .pp-recent-row, .pp-roster-player');
      if (!row) return;
      if (!query) {
        row.hidden = false;
        return;
      }
      row.hidden = !name.includes(query);
    });
    if (query) {
      applyPositionFilter('all');
    } else {
      applyPositionFilter(state.position);
    }
  });
}

function bindClubBrowse() {
  const params = new URLSearchParams(window.location.search);
  const initialClub = (params.get('club') || '').trim().toUpperCase();
  if (!initialClub) return;

  const match = state.clubs.find((club) => String(club.short_name || '').toUpperCase() === initialClub);
  if (match?.path) {
    window.location.replace(match.path);
    return;
  }

  const link = document.querySelector(`[data-pp-club="${CSS.escape(initialClub)}"]`);
  const path = link?.getAttribute('href') || link?.dataset.ppClubPath;
  if (path) {
    window.location.replace(path);
  }
}

function bindPlayerViews() {
  document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-pp-player-id]');
    if (!link) return;

    const playerId = link.dataset.ppPlayerId;
    if (!playerId) return;

    recordPlayerView(playerId);
  });
}

function initFaceFallbacks() {
  if (window.__mfPpFaceBound) return;
  window.__mfPpFaceBound = true;

  document.addEventListener('error', (event) => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;
    if (!img.classList.contains('pp-form-card__img--face')
      && !img.classList.contains('pp-form-card__img--shirt')
      && !img.classList.contains('pp-owned__tag-photo')
      && !img.classList.contains('pp-roster-player__img--face')
      && !img.classList.contains('pp-roster-player__img--shirt')) {
      return;
    }

    const shirt = img.getAttribute('data-pp-shirt');
    if ((img.classList.contains('pp-form-card__img--face')
      || img.classList.contains('pp-owned__tag-photo')
      || img.classList.contains('pp-roster-player__img--face'))
      && shirt
      && !img.classList.contains('pp-owned__tag-shirt')
      && !img.classList.contains('pp-roster-player__img--shirt')) {
      if (img.classList.contains('pp-form-card__img--face')) {
        img.classList.remove('pp-form-card__img--face');
        img.classList.add('pp-form-card__img--shirt');
      } else if (img.classList.contains('pp-roster-player__img--face')) {
        img.classList.remove('pp-roster-player__img--face');
        img.classList.add('pp-roster-player__img--shirt');
      } else {
        img.classList.add('pp-owned__tag-shirt');
      }
      img.removeAttribute('data-pp-shirt');
      img.src = shirt;
      return;
    }

    const initial = img.getAttribute('data-pp-initial') || '?';
    if (img.classList.contains('pp-owned__tag-photo')) {
      const fallback = document.createElement('span');
      fallback.className = 'pp-owned__tag-fallback';
      fallback.setAttribute('aria-hidden', 'true');
      fallback.textContent = initial;
      img.replaceWith(fallback);
      return;
    }

    if (img.classList.contains('pp-roster-player__img--face')
      || img.classList.contains('pp-roster-player__img--shirt')) {
      const fallback = document.createElement('span');
      fallback.className = 'pp-roster-player__fallback';
      fallback.setAttribute('aria-hidden', 'true');
      fallback.textContent = initial;
      img.replaceWith(fallback);
      return;
    }

    const fallback = document.createElement('span');
    fallback.className = 'pp-form-card__fallback';
    fallback.setAttribute('aria-hidden', 'true');
    fallback.textContent = initial;
    img.replaceWith(fallback);
  }, true);
}

function setFormLoading(isLoading) {
  const root = document.querySelector('[data-pp-form-cards]');
  const section = document.querySelector('[data-pp-inform]');
  if (section) section.setAttribute('aria-busy', isLoading ? 'true' : 'false');
  if (!root || !isLoading) return;
  root.innerHTML = '<p class="pp-form-loading">Loading in-form players</p>';
}

function setFormError(message) {
  const root = document.querySelector('[data-pp-form-cards]');
  if (!root) return;
  root.innerHTML = `<p class="pp-form-error">${escapeHtml(message)}</p>`;
}

function updateFormLead(window) {
  const lead = document.querySelector('[data-pp-inform-lead]');
  if (!lead) return;
  const count = window > 0 ? window : 4;
  lead.textContent = count === 1
    ? 'Most FPL points over the last gameweek'
    : `Most FPL points over the last ${count} gameweeks`;
}

async function loadInForm() {
  setFormLoading(true);
  setMostOwnedLoading(true);
  try {
    const res = await fetchWithTimeout(apiUrl('fpl/players'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Could not load player form data.');
    }

    const report = data.report || {};
    const inForm = report.in_form || {};
    const mostOwned = Array.isArray(report.most_owned) ? report.most_owned : [];

    state.players = Array.isArray(inForm.players) ? inForm.players : [];
    state.window = Number(inForm.window) || Number(inForm.target_window) || 4;
    indexPlayers(state.players);
    indexPlayers(mostOwned);
    renderMostOwned(mostOwned);
    renderClubs(Array.isArray(report.clubs) ? report.clubs : []);
    updateFormLead(state.window);
    renderFormCards();
  } catch (error) {
    renderMostOwned([]);
    setFormError(error?.message || 'Could not load in-form players.');
  } finally {
    setFormLoading(false);
  }
}

async function boot() {
  if (window.location.pathname.includes('/public/')) {
    window.location.replace(
      window.location.pathname.replace('/public/', '/')
      + window.location.search
      + window.location.hash
    );
    return;
  }

  initSiteNav();
  bindSignOutButtons();
  initFaceFallbacks();
  bindPositionFilters();
  bindSearch();
  bindPlayerViews();

  const sessionTask = fetchSession()
    .then((session) => {
      applyAuthNav(session);
      return session;
    })
    .catch(() => {
      applyAuthNav(null);
      return null;
    });

  await Promise.all([
    sessionTask,
    loadInForm(),
    renderRecentSection(),
  ]);

  bindClubBrowse();
  bindSignOutButtons();
}

boot();
