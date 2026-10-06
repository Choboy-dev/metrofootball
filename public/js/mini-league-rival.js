import { animate, stagger } from './motion.js?v=20260901perf';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  applyPremiumGate,
  apiUrl,
  bindSignOutButtons,
  fetchSession,
  pageUrl,
  redirectToLogin,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const state = {
  leagueSlug: '',
  rivalSlug: '',
  selectedGw: null,
  profile: null,
};

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatNumber(value) {
  const num = Number(value);
  if (!Number.isFinite(num)) return '—';
  return num.toLocaleString('en-GB');
}

function eventIcons(events = {}) {
  const bits = [];
  if (events.goals > 0) {
    bits.push(`<span class="live-event live-event--goal" title="Goals">${events.goals > 1 ? `<span class="live-event__count">${events.goals}</span>` : ''}<img class="live-event__icon" src="/img/live/goal.png" alt="" width="14" height="14" decoding="async"></span>`);
  }
  if (events.assists > 0) {
    bits.push(`<span class="live-event live-event--assist" title="Assists">${events.assists > 1 ? `<span class="live-event__count">${events.assists}</span>` : ''}<img class="live-event__icon" src="/img/live/assist.png" alt="" width="14" height="14" decoding="async"></span>`);
  }
  if (events.yellow > 0) {
    bits.push('<span class="live-event live-event--yellow" title="Yellow card" aria-label="Yellow card"></span>');
  }
  if (events.red > 0) {
    bits.push('<span class="live-event live-event--red" title="Red card" aria-label="Red card"></span>');
  }
  return bits.join('');
}

function bonusShoulderBadge(events = {}) {
  const bonus = Number(events.bonus ?? 0);
  if (bonus <= 0) return '';
  return `<span class="pick-player__badge pick-player__badge--bonus" aria-label="${bonus} bonus point${bonus === 1 ? '' : 's'}" title="Bonus points">+${bonus}</span>`;
}

function slugify(value) {
  return String(value ?? '')
    .toLowerCase()
    .replace(/['’.]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

function playerProfileHref(player) {
  if (player.profile_path) return player.profile_path;
  const club = slugify(player.team_name || player.team_short || '');
  const name = slugify(player.web_name || '');
  if (!club || !name) return null;
  return `/players/${club}/${name}`;
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

  const scored = bench
    ? Number(player.points ?? player.team_points ?? 0)
    : Number(player.team_points ?? player.points ?? 0);
  const events = eventIcons(player.events || {});
  const multiplier = Number(player.multiplier ?? 1);
  const topRight = multiplier > 1
    ? `<span class="pick-player__price" title="Multiplier">x${escapeHtml(String(multiplier))}</span>`
    : `<span class="pick-player__price">${escapeHtml(player.team_short || '')}</span>`;

  const profileHref = playerProfileHref(player);
  const tag = profileHref ? 'a' : 'article';
  const linkAttrs = profileHref
    ? ` class="pick-player pick-player--live pick-player--link" href="${escapeHtml(profileHref)}"`
    : ` class="pick-player pick-player--live"`;
  const playerIdAttr = ` data-player-id="${player.id}"`;

  return `
    <${tag}${linkAttrs}${playerIdAttr}>
      ${pos}
      <div class="pick-player__kit">
        ${badge}
        ${bonusShoulderBadge(player.events || {})}
        ${shirt}
      </div>
      <span class="pick-player__card">
        <span class="pick-player__top">
          <span class="pick-player__name">${escapeHtml(player.web_name)}</span>
          ${topRight}
        </span>
        <span class="pick-player__proj pick-player__proj--single">
          <span class="pick-player__gw pick-player__gw--single">
            <span class="pick-player__score" title="Points">${escapeHtml(String(scored))}</span>
            <span class="pick-player__fx pick-player__fx--events">${events || '<span class="pick-player__fx-empty">-</span>'}</span>
          </span>
        </span>
      </span>
    </${tag}>
  `;
}

function setError(message) {
  const el = document.querySelector('[data-ml-rival-error]');
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function setStatus(message, { loading = false } = {}) {
  const el = document.querySelector('[data-ml-rival-status]');
  if (!el) return;
  el.textContent = message || '';
  el.classList.toggle('is-loading', Boolean(loading));
  el.hidden = !message;
}

function currentGwIndex(profile) {
  const options = profile?.gameweek?.options || [];
  const selected = profile?.gameweek?.selected_id;
  return options.findIndex((option) => Number(option.id) === Number(selected));
}

function renderGameweekNav(profile) {
  const options = profile.gameweek?.options || [];
  const selected = profile.gameweek?.selected_id;
  const idx = currentGwIndex(profile);
  const current = idx >= 0 ? options[idx] : null;
  const squad = profile.squad || {};

  const nameEl = document.querySelector('[data-ml-rival-gw-name]');
  const subEl = document.querySelector('[data-ml-rival-gw-sub]');
  const prev = document.querySelector('[data-ml-rival-gw-prev]');
  const next = document.querySelector('[data-ml-rival-gw-next]');

  if (nameEl) {
    nameEl.textContent = current?.name || (selected ? `Gameweek ${selected}` : 'Gameweek');
  }
  if (subEl) {
    if (squad.active_chip) {
      subEl.textContent = `${squad.active_chip} chip played this gameweek`;
    } else if (squad.hidden) {
      subEl.textContent = 'Squad unlocks after the deadline';
    } else {
      subEl.textContent = 'Actual points from this gameweek';
    }
  }
  if (prev) prev.disabled = idx <= 0;
  if (next) next.disabled = idx < 0 || idx >= options.length - 1;
}

function renderIdentity(profile) {
  const rival = profile.rival || {};
  const league = profile.league || {};
  const isYou = Boolean(rival.is_you);
  const teamName = rival.entry_name || state.rivalSlug;
  const managerName = rival.player_name || 'Manager';

  const root = document.querySelector('[data-ml-rival-identity]');
  const leaguePill = document.querySelector('[data-ml-rival-league-pill]');
  const youBadge = document.querySelector('[data-ml-rival-you-badge]');
  const teamEl = document.querySelector('[data-ml-rival-team-name]');
  const managerEl = document.querySelector('[data-ml-rival-manager-name]');

  if (leaguePill) leaguePill.textContent = league.name || state.leagueSlug;
  if (youBadge) youBadge.hidden = !isYou;
  if (teamEl) {
    teamEl.textContent = teamName;
    teamEl.classList.toggle('is-you', isYou);
  }
  if (managerEl) {
    managerEl.textContent = isYou
      ? `${managerName} · This is your team`
      : managerName;
  }

  if (root) root.hidden = false;

  if (!reduceMotion) {
    animate(
      root,
      { opacity: [0, 1], y: [10, 0] },
      { duration: 0.35, ease: [0.17, 0.55, 0.55, 1] }
    );
  }
}

function bindGameweekNav() {
  const prev = document.querySelector('[data-ml-rival-gw-prev]');
  const next = document.querySelector('[data-ml-rival-gw-next]');

  prev?.addEventListener('click', () => {
    const options = state.profile?.gameweek?.options || [];
    const idx = currentGwIndex(state.profile);
    if (idx <= 0) return;
    loadProfile(options[idx - 1].id);
  });

  next?.addEventListener('click', () => {
    const options = state.profile?.gameweek?.options || [];
    const idx = currentGwIndex(state.profile);
    if (idx < 0 || idx >= options.length - 1) return;
    loadProfile(options[idx + 1].id);
  });
}

function renderSquad(profile, { animateIn = false } = {}) {
  const squad = profile.squad || {};
  const layout = document.querySelector('[data-ml-rival-layout]');
  const hiddenEl = document.querySelector('[data-ml-rival-hidden]');
  const summary = document.querySelector('[data-ml-rival-summary]');
  const extra = document.querySelector('[data-ml-rival-extra]');

  if (summary) summary.hidden = false;

  if (squad.hidden) {
    if (layout) layout.hidden = true;
    if (extra) extra.hidden = true;
    if (hiddenEl) {
      hiddenEl.hidden = false;
      hiddenEl.textContent = squad.reason || 'This squad is not available yet.';
    }
    return;
  }

  if (hiddenEl) hiddenEl.hidden = true;

  ['gkp', 'def', 'mid', 'fwd'].forEach((row) => {
    const rowEl = document.querySelector(`[data-pitch-row="${row}"]`);
    if (!rowEl) return;
    const players = squad.pitch?.[row] || [];
    rowEl.dataset.count = String(players.length);
    rowEl.innerHTML = players.map((player) => playerCard(player)).join('');
  });

  const bench = document.querySelector('[data-ml-rival-bench]');
  if (bench) {
    const benchPlayers = squad.bench || [];
    bench.dataset.count = String(benchPlayers.length);
    bench.innerHTML = benchPlayers.map((player) => playerCard(player, { bench: true })).join('');
  }

  if (layout) layout.hidden = false;

  const gwEl = document.querySelector('[data-ml-rival-stat-gw]');
  const formationEl = document.querySelector('[data-ml-rival-stat-formation]');
  const rankEl = document.querySelector('[data-ml-rival-stat-rank]');
  const totalEl = document.querySelector('[data-ml-rival-stat-total]');

  if (gwEl) gwEl.textContent = formatNumber(squad.gw_points);
  if (formationEl) {
    formationEl.textContent = squad.active_chip
      ? `${squad.formation || '—'} · ${squad.active_chip}`
      : (squad.formation || '—');
  }
  if (rankEl) rankEl.textContent = `#${formatNumber(profile.league_standing?.rank)}`;
  if (totalEl) totalEl.textContent = formatNumber(profile.league_standing?.total);

  renderGameweekNav(profile);

  if (animateIn && !reduceMotion) {
    const chips = document.querySelectorAll('[data-ml-rival-layout] .pick-player--live');
    animate(
      chips,
      { opacity: [0, 1], y: [12, 0] },
      { duration: 0.28, delay: stagger(0.02), easing: [0.17, 0.55, 0.55, 1] }
    );
  }
}

function renderTransfers(profile) {
  const wrap = document.querySelector('[data-ml-rival-transfers-wrap]');
  const list = document.querySelector('[data-ml-rival-transfers]');
  const extra = document.querySelector('[data-ml-rival-extra]');
  if (!wrap || !list) return;

  const transfers = profile.transfers || [];
  if (!transfers.length) {
    wrap.hidden = true;
    list.innerHTML = '';
    updateExtraVisibility();
    return;
  }

  wrap.hidden = false;
  list.innerHTML = transfers.map((move) => `
    <li class="ml-rival-transfer">
      <span class="ml-rival-transfer__out">${escapeHtml(move.player_out)}</span>
      <span class="ml-rival-transfer__arrow" aria-hidden="true">→</span>
      <span class="ml-rival-transfer__in">${escapeHtml(move.player_in)}</span>
    </li>
  `).join('');
  updateExtraVisibility();
}

function renderChips(profile) {
  const wrap = document.querySelector('[data-ml-rival-chips-wrap]');
  const list = document.querySelector('[data-ml-rival-chips]');
  if (!wrap || !list) return;

  const chips = profile.chips_used || [];
  if (!chips.length) {
    wrap.hidden = true;
    list.innerHTML = '';
    updateExtraVisibility();
    return;
  }

  wrap.hidden = false;
  list.innerHTML = chips.map((chip) => `
    <li class="ml-rival-chip">
      <span class="ml-rival-chip__label">${escapeHtml(chip.label)}</span>
      <span class="ml-rival-chip__gw">${escapeHtml(chip.event_name)}</span>
    </li>
  `).join('');
  updateExtraVisibility();
}

function updateExtraVisibility() {
  const extra = document.querySelector('[data-ml-rival-extra]');
  const transfers = document.querySelector('[data-ml-rival-transfers-wrap]');
  const chips = document.querySelector('[data-ml-rival-chips-wrap]');
  if (!extra) return;

  const show = Boolean(
    (transfers && !transfers.hidden)
    || (chips && !chips.hidden)
  );
  extra.hidden = !show;
}

function renderProfile(profile, { animateIn = false } = {}) {
  state.profile = profile;
  state.selectedGw = profile.gameweek?.selected_id ?? null;

  const rival = profile.rival || {};
  const league = profile.league || {};
  const teamName = rival.entry_name || state.rivalSlug;

  document.title = `${teamName} | ${league.name || 'League'} | Metro Football`;

  setStatus('', { loading: false });
  renderIdentity(profile);
  renderGameweekNav(profile);
  renderSquad(profile, { animateIn });
  renderTransfers(profile);
  renderChips(profile);
}

async function loadProfile(gw = null) {
  setError('');
  setStatus('Loading rival profile', { loading: true });

  const params = new URLSearchParams({
    league: state.leagueSlug,
    rival: state.rivalSlug,
  });
  if (gw !== null && gw > 0) {
    params.set('gw', String(gw));
  }

  try {
    const response = await fetch(apiUrl(`fpl/mini-league-rival?${params.toString()}`), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.ok) {
      if (data.redirect && (response.status === 401 || response.status === 422 || response.status === 403)) {
        window.location.href = pageUrl(data.redirect.replace(/^\//, ''));
        return;
      }
      setStatus('');
      setError(data.error || 'We could not load this rival right now.');
      return;
    }

    renderProfile(data.profile, { animateIn: true });
  } catch {
    setStatus('');
    setError('We could not reach the server. Try again in a moment.');
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initTeamTabs();
  bindGameweekNav();

  state.leagueSlug = document.body.dataset.leagueSlug || '';
  state.rivalSlug = document.body.dataset.rivalSlug || '';

  if (!state.leagueSlug || !state.rivalSlug) {
    setStatus('');
    setError('This rival page link is not valid.');
    return;
  }

  const session = await fetchSession();
  if (!session?.authenticated) {
    redirectToLogin();
    return;
  }

  applyAuthNav(session);
  const premium = applyPremiumGate(session, {
    animateReveal: reduceMotion ? null : animate,
  }).premium;

  if (!premium) return;

  if (!session.user?.fpl_team_id) {
    window.location.href = pageUrl('link-team');
    return;
  }

  await loadProfile();
}

boot();
