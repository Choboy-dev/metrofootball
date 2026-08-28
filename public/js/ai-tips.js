import { animate, inView, stagger } from './motion.js';
import { initSiteNav } from './site-nav.js';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  apiUrl,
  bindSignOutButtons,
  fetchSession,
  redirectToLogin,
  pageUrl,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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
    top: document.querySelector('[data-tips-progress]'),
    topBar: document.querySelector('[data-tips-progress-bar]'),
    panel: document.querySelector('[data-tips-load]'),
    fill: document.querySelector('[data-tips-load-fill]'),
    track: document.querySelector('[data-tips-load-track]'),
    label: document.querySelector('[data-tips-load-label]'),
    pct: document.querySelector('[data-tips-load-pct]'),
    sr: document.querySelector('[data-tips-load-sr]'),
    main: document.querySelector('[data-tips-main]'),
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

function startProgress(label = 'Building your tips') {
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

async function finishProgress(label = 'Tips ready') {
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

function setError(text) {
  const el = document.querySelector('[data-tips-error]');
  if (!el) return;
  if (!text) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = text;
}

function setNote(key, text) {
  const el = document.querySelector(`[data-tip-note="${key}"]`);
  if (!el) return;
  if (!text) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = text;
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

function handleFaceImgError(img) {
  if (!(img instanceof HTMLImageElement)) return;
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
}

function initFaceFallbacks() {
  if (window.__mfFaceFallbackBound) return;
  window.__mfFaceFallbackBound = true;
  document.addEventListener('error', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLImageElement)) return;
    if (!target.classList.contains('ai-face__img') && !target.classList.contains('ai-face__shirt')) return;
    handleFaceImgError(target);
  }, true);
}

function renderTransfers(section) {
  const body = document.querySelector('[data-tip-body="transfers"]');
  if (!body) return;
  const items = section?.items || [];
  setNote('transfers', section?.note || '');

  if (!items.length) {
    body.innerHTML = `<p class="ai-tip-empty">${escapeHtml(section?.empty || 'No high certainty transfers this week.')}</p>`;
    return;
  }

  body.innerHTML = items.map((item) => {
    const outName = item.out_name || 'Out';
    const inName = item.in_name || 'In';
    const outTeam = item.out_team_short || '';
    const inTeam = item.in_team_short || '';
    const outMeta = [outTeam, item.pos].filter(Boolean).join(' · ');
    const inMeta = [inTeam, item.pos].filter(Boolean).join(' · ');

    return `
    <article class="ai-swap" data-ai-swap>
      <div class="ai-swap__arena" aria-label="Transfer ${escapeHtml(outName)} out for ${escapeHtml(inName)}">
        <div class="ai-swap__player ai-swap__player--out">
          <span class="ai-swap__tag ai-swap__tag--out">Out</span>
          <div class="ai-swap__face ai-swap__face--out">
            ${playerFaceHtml(item.out_photo, item.out_shirt, outName, 'out')}
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
            ${playerFaceHtml(item.in_photo, item.in_shirt, inName, 'in')}
          </div>
          <p class="ai-swap__name">${escapeHtml(inName)}</p>
          ${inMeta ? `<p class="ai-swap__club">${escapeHtml(inMeta)}</p>` : ''}
        </div>
      </div>

      <p class="ai-swap__reason">${escapeHtml(item.reason)}</p>
    </article>
  `;
  }).join('');

  animateTransfers(body);
}

function animateTransfers(body) {
  if (reduceMotion || !body) return;
  const cards = [...body.querySelectorAll('[data-ai-swap]')];
  if (!cards.length) return;

  cards.forEach((card) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(18px)';
  });

  animate(
    cards,
    { opacity: 1, y: 0 },
    {
      duration: 0.45,
      delay: stagger(0.1),
      easing: [0.17, 0.55, 0.55, 1],
    }
  );

  inView(cards, (element) => {
    const outFace = element.querySelector('.ai-swap__face--out');
    const inFace = element.querySelector('.ai-swap__face--in');
    const switchOrb = element.querySelector('.ai-swap__switch-orb');

    if (outFace) {
      animate(
        outFace,
        { opacity: [0.35, 1], scale: [0.92, 1] },
        { duration: 0.5, delay: 0.05, easing: [0.17, 0.55, 0.55, 1] }
      );
    }
    if (inFace) {
      animate(
        inFace,
        { opacity: [0, 1], scale: [0.88, 1] },
        { duration: 0.55, delay: 0.12, easing: [0.17, 0.55, 0.55, 1] }
      );
    }
    if (switchOrb) {
      animate(
        switchOrb,
        { opacity: [0, 1], rotate: [-28, 0], scale: [0.7, 1] },
        { duration: 0.5, delay: 0.08, easing: [0.17, 0.55, 0.55, 1] }
      );
    }
  }, { amount: 0.25 });
}

function fdrLevel(fdr) {
  const n = Number(fdr);
  if (!Number.isFinite(n)) return 3;
  return Math.max(1, Math.min(5, Math.round(n)));
}

function fdrBadgeHtml(fdr, size = 'sm') {
  const level = fdrLevel(fdr);
  return `<span class="mf-fdr mf-fdr--${level} mf-fdr--${size}" title="Fixture difficulty ${level}" aria-label="Fixture difficulty ${level}">${level}</span>`;
}

function captainClubLine(player) {
  return [player?.team_short, player?.pos].filter(Boolean).join(' · ');
}

function captainFixtureLine(player) {
  const label = String(player?.fixture || 'TBC');
  return `
    <p class="ai-cap-card__fixture">
      <span class="ai-cap-card__fx">${escapeHtml(label)}</span>
      ${fdrBadgeHtml(player?.fdr, 'sm')}
    </p>
  `;
}

function renderCaptainCard(player, role) {
  if (!player) return '';
  const isCaptain = role === 'captain';
  const club = captainClubLine(player);
  const score = isCaptain
    ? `<p class="ai-cap-card__score" aria-label="Predicted ${escapeHtml(player.proj)} times 2 equals ${escapeHtml(player.doubled)}">
          <span class="ai-cap-card__score-raw">${escapeHtml(player.proj)}</span>
          <span class="ai-cap-card__score-mul" aria-hidden="true">x 2</span>
          <span class="ai-cap-card__score-sep" aria-hidden="true">=</span>
          <span class="ai-cap-card__score-total">${escapeHtml(player.doubled)}</span>
        </p>`
    : `<p class="ai-cap-card__score ai-cap-card__score--single" aria-label="Predicted ${escapeHtml(player.proj)}">
          <span class="ai-cap-card__score-total">${escapeHtml(player.proj)}</span>
        </p>`;

  return `
    <article class="ai-cap-card ai-cap-card--${role}" data-ai-cap-card>
      <span class="ai-cap-card__tag ai-cap-card__tag--${role}">${isCaptain ? 'Captain' : 'Vice'}</span>
      <div class="ai-cap-card__face ai-cap-card__face--${role}">
        ${playerFaceHtml(player.photo, player.shirt, player.name, role)}
      </div>
      <p class="ai-cap-card__name">${escapeHtml(player.name)}</p>
      ${club ? `<p class="ai-cap-card__meta">${escapeHtml(club)}</p>` : ''}
      ${captainFixtureLine(player)}
      ${score}
      <p class="ai-cap-card__reason">${escapeHtml(player.reason || '')}</p>
    </article>
  `;
}

function renderCaptain(section) {
  const body = document.querySelector('[data-tip-body="captain"]');
  if (!body) return;
  const pick = section?.pick;
  setNote('captain', '');

  if (!pick) {
    body.innerHTML = `<p class="ai-tip-empty">No captain pick yet.</p>`;
    return;
  }

  body.innerHTML = `
    <div class="ai-captain-duo" data-ai-captain-duo>
      ${renderCaptainCard(pick, 'captain')}
      ${section?.vice ? renderCaptainCard(section.vice, 'vice') : ''}
    </div>
  `;

  animateCaptainCards(body);
}

function animateCaptainCards(body) {
  if (reduceMotion || !body) return;
  const cards = [...body.querySelectorAll('[data-ai-cap-card]')];
  if (!cards.length) return;

  cards.forEach((card) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(16px)';
  });

  animate(
    cards,
    { opacity: 1, y: 0 },
    {
      duration: 0.45,
      delay: stagger(0.1),
      easing: [0.17, 0.55, 0.55, 1],
    }
  );
}

function renderDropWatch(section) {
  const body = document.querySelector('[data-tip-body="drop_watch"]');
  if (!body) return;
  const items = section?.items || [];
  setNote('drop_watch', '');

  if (!items.length) {
    body.innerHTML = `<p class="ai-tip-empty">${escapeHtml(section?.empty || 'No drop alerts this week.')}</p>`;
    return;
  }

  body.innerHTML = `
    <div class="ai-drop-grid" data-ai-drop-grid>
      ${items.map((item) => {
        const club = [item.team_short, item.pos].filter(Boolean).join(' · ');
        const fixtures = Array.isArray(item.fixtures) ? item.fixtures.slice(0, 5) : [];
        const fixtureCells = fixtures.map((fx) => `
          <div class="ai-drop-card__gw">
            <span class="ai-drop-card__fx">${escapeHtml(fx.label || 'TBC')}</span>
            ${fdrBadgeHtml(fx.fdr, 'sm')}
          </div>
        `).join('');

        return `
          <article class="ai-drop-card" data-ai-drop-card>
            <div class="ai-drop-card__face">
              ${playerFaceHtml(item.photo, item.shirt, item.name, 'drop')}
            </div>
            <p class="ai-drop-card__name">${escapeHtml(item.name)}</p>
            ${club ? `<p class="ai-drop-card__meta">${escapeHtml(club)}</p>` : ''}
            <div class="ai-drop-card__fixtures" aria-label="Next five fixtures">
              ${fixtureCells}
            </div>
            <p class="ai-drop-card__reason">${escapeHtml(item.reason || '')}</p>
          </article>
        `;
      }).join('')}
    </div>
  `;

  animateDropCards(body);
}

function animateDropCards(body) {
  if (reduceMotion || !body) return;
  const cards = [...body.querySelectorAll('[data-ai-drop-card]')];
  if (!cards.length) return;

  cards.forEach((card) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(16px)';
  });

  animate(
    cards,
    { opacity: 1, y: 0 },
    {
      duration: 0.45,
      delay: stagger(0.08),
      easing: [0.17, 0.55, 0.55, 1],
    }
  );
}

function chipDisplayName(code) {
  const key = String(code || '').toLowerCase();
  const map = {
    freehit: 'Free Hit',
    freehit1: 'Free Hit',
    freehit2: 'Free Hit',
    wildcard: 'Wildcard',
    wildcard1: 'Wildcard',
    wildcard2: 'Wildcard',
    bboost: 'Bench Boost',
    benchboost: 'Bench Boost',
    '3xc': 'Triple Captain',
    triplecaptain: 'Triple Captain',
  };
  return map[key] || '';
}

function chipPanelIcon(play) {
  if (play) {
    return `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M8 5.5v13l11-6.5-11-6.5Z" stroke-linejoin="round"/>
      </svg>
    `;
  }
  return `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
      <path d="M12 3.5 19.5 8v8L12 20.5 4.5 16V8L12 3.5Z" stroke-linejoin="round"/>
      <path d="M9.5 12h5M12 9.5v5" stroke-linecap="round"/>
    </svg>
  `;
}

function renderChip(section) {
  const body = document.querySelector('[data-tip-body="chip"]');
  if (!body) return;
  setNote('chip', '');

  const play = section?.action === 'play';
  const chipName = chipDisplayName(section?.chip);
  const title = play && chipName
    ? chipName
    : (section?.label || 'No chip needed right now');
  const status = play ? 'Play' : 'Hold';
  const reason = section?.why
    || (play
      ? 'This looks like a good week to use a chip.'
      : 'No chip looks worth using this gameweek. Keep them saved.');

  body.innerHTML = `
    <article class="ai-chip-panel ${play ? 'ai-chip-panel--play' : 'ai-chip-panel--hold'}" data-ai-chip-panel>
      <div class="ai-chip-panel__orb" aria-hidden="true">
        ${chipPanelIcon(play)}
      </div>
      <span class="ai-chip-panel__status">${status}</span>
      <h3 class="ai-chip-panel__title">${escapeHtml(title)}</h3>
      <p class="ai-chip-panel__reason">${escapeHtml(reason)}</p>
    </article>
  `;

  animateChipPanel(body);
}

function animateChipPanel(body) {
  if (reduceMotion || !body) return;
  const panel = body.querySelector('[data-ai-chip-panel]');
  if (!panel) return;

  panel.style.opacity = '0';
  panel.style.transform = 'translateY(14px)';
  animate(
    panel,
    { opacity: 1, y: 0 },
    { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] }
  );
}

function renderSnipers(section) {
  const body = document.querySelector('[data-tip-body="snipers"]');
  if (!body) return;
  const items = section?.items || [];
  setNote('snipers', '');

  if (!items.length) {
    body.innerHTML = `<p class="ai-tip-empty">${escapeHtml(section?.empty || 'No differentials found this week.')}</p>`;
    return;
  }

  body.innerHTML = `
    <div class="ai-drop-grid" data-ai-diff-grid>
      ${items.map((item) => {
        const club = [item.team_short, item.pos].filter(Boolean).join(' · ');
        const fixtures = Array.isArray(item.fixtures) ? item.fixtures.slice(0, 5) : [];
        const fixtureCells = fixtures.map((fx) => `
          <div class="ai-drop-card__gw">
            <span class="ai-drop-card__fx">${escapeHtml(fx.label || 'TBC')}</span>
            ${fdrBadgeHtml(fx.fdr, 'sm')}
          </div>
        `).join('');

        return `
          <article class="ai-drop-card" data-ai-diff-card>
            <div class="ai-drop-card__face">
              ${playerFaceHtml(item.photo, item.shirt, item.name, 'diff')}
            </div>
            <p class="ai-drop-card__name">${escapeHtml(item.name)}</p>
            ${club ? `<p class="ai-drop-card__meta">${escapeHtml(club)}</p>` : ''}
            <div class="ai-drop-card__fixtures" aria-label="Next five fixtures">
              ${fixtureCells}
            </div>
            <p class="ai-drop-card__reason">${escapeHtml(item.reason || '')}</p>
          </article>
        `;
      }).join('')}
    </div>
  `;

  animateDiffCards(body);
}

function animateDiffCards(body) {
  if (reduceMotion || !body) return;
  const cards = [...body.querySelectorAll('[data-ai-diff-card]')];
  if (!cards.length) return;

  cards.forEach((card) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(16px)';
  });

  animate(
    cards,
    { opacity: 1, y: 0 },
    {
      duration: 0.45,
      delay: stagger(0.08),
      easing: [0.17, 0.55, 0.55, 1],
    }
  );
}

function revealPanels() {
  const stack = document.querySelector('[data-tips-stack]');
  if (!stack) return;
  stack.hidden = false;

  if (reduceMotion) return;

  const panels = stack.querySelectorAll('[data-tip-panel]');
  panels.forEach((panel) => {
    panel.style.opacity = '0';
    panel.style.transform = 'translateY(14px)';
  });

  inView(panels, (element) => {
    animate(
      element,
      { opacity: 1, y: 0 },
      { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] }
    );
  }, { amount: 0.2 });
}

function renderTips(tips) {
  const sections = tips?.sections || {};
  const eventName = tips?.event?.name || 'Gameweek';
  const teamName = tips?.entry?.team_name || 'your team';

  const eventEl = document.querySelector('[data-tips-event]');
  if (eventEl) eventEl.textContent = eventName;

  const titleEl = document.querySelector('[data-tips-title]');
  if (titleEl) titleEl.textContent = `AI Tips for ${teamName}`;

  renderTransfers(sections.transfers);
  renderCaptain(sections.captain);
  renderDropWatch(sections.drop_watch);
  renderChip(sections.chip);
  renderSnipers(sections.snipers);
  revealPanels();
}

async function loadTips() {
  setError('');
  const stack = document.querySelector('[data-tips-stack]');
  if (stack) stack.hidden = true;

  startProgress('Checking your account');
  setProgress(18, { label: 'Loading your squad' });

  try {
    setProgress(42, { label: 'Building your tips' });
    const response = await fetch(apiUrl('fpl/ai-tips'), {
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
      throw new Error(data.error || 'Could not load AI tips.');
    }
    setProgress(86, { label: 'Almost ready' });
    renderTips(data.tips);
    await finishProgress('Tips ready');
  } catch (error) {
    failProgress();
    setError(error.message || 'Could not load AI tips.');
  }
}

async function boot() {
  initFaceFallbacks();
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

  if (premium) {
    await loadTips();
    const hash = window.location.hash.replace('#', '');
    if (hash) {
      const target = document.getElementById(hash);
      if (target) target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    }
  }
}

boot();
