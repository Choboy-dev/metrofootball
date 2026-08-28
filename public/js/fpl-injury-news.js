import { animate, inView, stagger } from './motion.js';
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
  post: null,
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
    top: document.querySelector('[data-fin-progress]'),
    topBar: document.querySelector('[data-fin-progress-bar]'),
    panel: document.querySelector('[data-fin-load]'),
    fill: document.querySelector('[data-fin-load-fill]'),
    track: document.querySelector('[data-fin-load-track]'),
    label: document.querySelector('[data-fin-load-label]'),
    pct: document.querySelector('[data-fin-load-pct]'),
    sr: document.querySelector('[data-fin-load-sr]'),
    hint: document.querySelector('[data-fin-load-hint]'),
    main: document.querySelector('[data-fin-main]'),
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

function setProgressLabel(text, hint) {
  const els = progressEls();
  if (els.label) els.label.textContent = text;
  if (els.sr) els.sr.textContent = text;
  if (hint && els.hint) els.hint.textContent = hint;
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

function startProgress(label = 'Loading', hint) {
  progressUi.value = 0;
  applyProgressVisual(0);
  setProgressLabel(label, hint);
  if (progressUi.showTimer) clearTimeout(progressUi.showTimer);
  progressUi.showTimer = window.setTimeout(revealProgressUi, PROGRESS_SHOW_DELAY_MS);
  if (progressUi.trickleTimer) clearInterval(progressUi.trickleTimer);
  progressUi.trickleTimer = window.setInterval(() => {
    if (progressUi.value >= 88) return;
    const next = progressUi.value + Math.max(0.35, (90 - progressUi.value) * 0.04);
    setProgress(Math.min(88, next), { immediate: reduceMotion });
  }, reduceMotion ? 500 : 280);
}

function setProgress(target, { immediate = false, label, hint } = {}) {
  if (label) setProgressLabel(label, hint);
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

function finishProgress(label = 'Ready') {
  if (progressUi.trickleTimer) {
    clearInterval(progressUi.trickleTimer);
    progressUi.trickleTimer = null;
  }
  if (progressUi.showTimer) {
    clearTimeout(progressUi.showTimer);
    progressUi.showTimer = null;
  }
  setProgress(100, { immediate: reduceMotion, label });
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
}

function showError(message) {
  const el = document.querySelector('[data-fin-error]');
  if (!el) return;
  el.hidden = !message;
  el.textContent = message || '';
}

function formatOwn(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return `${n.toFixed(1)}%`;
}

function formatPrice(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return `£${n.toFixed(1)}`;
}

function paragraphs(list) {
  return (list || []).map((p) => renderParagraph(p)).join('');
}

function renderParagraph(text) {
  const raw = String(text ?? '');
  const parts = [];
  const linkRe = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g;
  let last = 0;
  let match;
  let linkCount = 0;
  while ((match = linkRe.exec(raw)) !== null) {
    if (linkCount >= 1) {
      break;
    }
    parts.push(escapeHtml(raw.slice(last, match.index)));
    parts.push(
      `<a class="fin-inline-link" href="${escapeHtml(match[2])}" rel="noopener noreferrer" target="_blank">${escapeHtml(match[1])}</a>`
    );
    last = match.index + match[0].length;
    linkCount += 1;
  }
  const tail = linkCount > 0 ? raw.slice(last).replace(linkRe, (_, label) => label) : raw.slice(last);
  parts.push(escapeHtml(tail));
  return `<p>${parts.join('')}</p>`;
}

const FACT_ICONS = {
  match: './images/fin/match-injured.png',
  fixtures: './images/fin/next-fixture.png',
  return: './images/fin/return-date.png',
};

function formatMatchInjured(value) {
  const text = String(value ?? '').trim();
  if (!text) return '';
  if (/^vs\s/i.test(text)) return text;
  return `vs ${text}`;
}

function injuryFactsStrip(player) {
  const facts = [
    {
      label: 'Match injured',
      value: formatMatchInjured(player.injury_match),
      icon: 'match',
    },
    {
      label: 'Fixtures out',
      value: player.fixtures_likely_missed,
      icon: 'fixtures',
    },
    {
      label: 'Return date',
      value: player.expected_return_gw,
      icon: 'return',
    },
  ].filter((fact) => fact.value);

  if (!facts.length) return '';

  return `
    <div class="fin-key-facts" aria-label="Quick injury facts for ${escapeHtml(player.web_name)}">
      ${facts
        .map(
          (fact) => `
        <article class="fin-key-fact">
          <span class="fin-key-fact__icon">
            <img
              class="fin-key-fact__icon-img"
              src="${escapeHtml(FACT_ICONS[fact.icon] || FACT_ICONS.match)}"
              alt=""
              width="22"
              height="22"
              loading="lazy"
              decoding="async"
            >
          </span>
          <div class="fin-key-fact__copy">
            <p class="fin-key-fact__label">${escapeHtml(fact.label)}</p>
            <p class="fin-key-fact__value">${escapeHtml(fact.value)}</p>
          </div>
        </article>`
        )
        .join('')}
    </div>
  `;
}

function avatarFallback(img) {
  if (!img || img.getAttribute('data-fallback') === 'done') return;
  const shirt = img.getAttribute('data-shirt') || '';
  if (shirt && img.getAttribute('data-fallback') !== 'shirt') {
    img.setAttribute('data-fallback', 'shirt');
    img.src = shirt;
    img.classList.add('fin-avatar--shirt');
    return;
  }
  img.setAttribute('data-fallback', 'done');
  img.removeAttribute('src');
  img.classList.add('is-broken');
}

window.finAvatarFallback = avatarFallback;

function statsCard(player) {
  const s = player.stats || {};
  const cells = [
    ['Pts', s.total_points],
    ['G', s.goals],
    ['A', s.assists],
    ['xG', s.xg],
    ['xA', s.xa],
    ['Form', s.form],
    ['Owned by', formatOwn(s.ownership ?? player.ownership)],
    ['Price', formatPrice(s.price ?? player.price)],
  ];
  return `
    <dl class="fin-stats" aria-label="Season stats for ${escapeHtml(player.web_name)}">
      ${cells
        .map(
          ([label, value]) => `
        <div class="fin-stats__item">
          <dt>${escapeHtml(label)}</dt>
          <dd>${escapeHtml(value ?? '—')}</dd>
        </div>`
        )
        .join('')}
    </dl>
  `;
}

function playerSection(player) {
  const photo = player.photo || '';
  const shirt = player.shirt || '';
  const src = photo || shirt;
  const chance =
    player.chance === null || player.chance === undefined
      ? ''
      : `<span class="fin-pill fin-pill--chance">${escapeHtml(String(player.chance))}% chance</span>`;
  const suggestion = player.what_we_suggest || [];

  return `
    <section class="fin-player" id="${escapeHtml(player.slug)}" data-fin-reveal>
      <header class="fin-player__head">
        <div class="fin-player__identity">
          ${
            src
              ? `<img
                  class="fin-avatar${photo ? '' : ' fin-avatar--shirt'}"
                  src="${escapeHtml(src)}"
                  data-shirt="${escapeHtml(shirt)}"
                  alt="${escapeHtml(player.alt_text || player.web_name)}"
                  width="72"
                  height="72"
                  loading="lazy"
                  referrerpolicy="no-referrer"
                  onerror="window.finAvatarFallback(this)"
                >`
              : `<span class="fin-avatar fin-avatar--empty" aria-hidden="true"></span>`
          }
          <div>
            <h2 class="fin-player__title">${escapeHtml(player.heading)}</h2>
            <p class="fin-player__meta">
              ${escapeHtml(player.team_name)} · ${escapeHtml(player.position)} · Owned by ${escapeHtml(formatOwn(player.ownership))}
            </p>
            <div class="fin-pills">
              <span class="fin-pill fin-pill--${escapeHtml(player.status || 'a')}">${escapeHtml(player.status_label || 'Watch')}</span>
              ${chance}
            </div>
          </div>
        </div>
      </header>

      ${statsCard(player)}
      ${injuryFactsStrip(player)}

      <div class="fin-blocks">
        <div class="fin-block">
          <h3>What happened</h3>
          ${paragraphs(player.what_happened)}
        </div>
        <div class="fin-block">
          <h3>When is he expected back?</h3>
          ${paragraphs(player.return_outlook)}
        </div>
        <div class="fin-block">
          <h3>FPL impact</h3>
          ${paragraphs(player.fpl_impact)}
        </div>
        <div class="fin-block">
          <h3>Who benefits</h3>
          ${paragraphs(player.who_benefits)}
        </div>
        ${
          suggestion.length
            ? `<div class="fin-block fin-block--suggest">
                <h3>What do we suggest?</h3>
                ${paragraphs(suggestion)}
              </div>`
            : ''
        }
      </div>
    </section>
  `;
}

function renderToc(post) {
  const nav = document.querySelector('[data-fin-toc]');
  if (!nav) return;
  const items = post.toc || [];
  nav.innerHTML = `
    <h2 class="fin-toc__title">On this page</h2>
    <ol class="fin-toc__list">
      ${items
        .map(
          (item) =>
            `<li><a href="#${escapeHtml(item.id)}">${escapeHtml(item.label)}</a></li>`
        )
        .join('')}
    </ol>
  `;
}

function renderArticle(post) {
  state.post = post;

  document.title = `${post.seo_title || 'FPL injury news'} | Metro Football`;
  const meta = document.querySelector('meta[name="description"]');
  if (meta && post.meta_description) meta.setAttribute('content', post.meta_description);

  const title = document.querySelector('[data-fin-title]');
  const lead = document.querySelector('[data-fin-lead]');
  const kicker = document.querySelector('[data-fin-kicker]');
  const metaLine = document.querySelector('[data-fin-meta]');
  if (title) title.textContent = post.h1 || 'FPL injury news';
  if (lead && post.intro_paragraphs?.[0]) {
    lead.textContent = post.intro_paragraphs[0];
  }
  if (kicker) {
    kicker.textContent = post.gameweek_name
      ? `${post.gameweek_name} injury briefing`
      : 'Injury Risk Digest';
  }
  if (metaLine) {
    const updated = post.updated_at
      ? new Date(post.updated_at).toLocaleString('en-GB', {
          dateStyle: 'medium',
          timeStyle: 'short',
          timeZone: 'Europe/London',
        })
      : '';
    metaLine.hidden = !updated;
    metaLine.textContent = updated ? `Updated ${updated}` : '';
  }

  renderToc(post);

  const body = document.querySelector('[data-fin-body]');
  if (!body) return;

  const introRest = (post.intro_paragraphs || []).slice(1);
  const playersHtml = (post.players || []).map(playerSection).join('');
  body.innerHTML = `
    <div class="fin-intro" data-fin-reveal>
      ${introRest.map((p) => `<p>${escapeHtml(p)}</p>`).join('')}
      <p class="fin-internal">
        Also check our
        <a href="./price-changes">price change predictions</a>
        and
        <a href="./gameweek-recap">gameweek recap</a>
        before you lock your team.
      </p>
    </div>
    ${playersHtml}
    ${renderWatchList(post)}
    <section class="fin-close" data-fin-reveal>
      ${paragraphs(post.closing)}
    </section>
  `;

  const article = document.querySelector('[data-fin-article]');
  if (article) article.hidden = false;
  revealSections();
}

function watchCard(item) {
  const label = typeof item === 'string' ? item : (item.label || item.web_name || '');
  const team = item.team_short || '';
  const pos = item.position || '';
  const status = item.status_label || 'Watch';
  const statusCode = item.status || 'a';
  const news = item.news || '';
  const photo = item.photo || '';
  const shirt = item.shirt || '';
  const src = photo || shirt;
  const meta = [team, pos].filter(Boolean).join(' · ');

  return `
    <article class="fin-watch-card">
      ${
        src
          ? `<img
              class="fin-watch-card__avatar${photo ? '' : ' fin-watch-card__avatar--shirt'}"
              src="${escapeHtml(src)}"
              data-shirt="${escapeHtml(shirt)}"
              alt="${escapeHtml(label)} injury watch"
              width="56"
              height="56"
              loading="lazy"
              referrerpolicy="no-referrer"
              onerror="window.finAvatarFallback(this)"
            >`
          : `<span class="fin-watch-card__avatar fin-watch-card__avatar--empty" aria-hidden="true"></span>`
      }
      <h3 class="fin-watch-card__name">${escapeHtml(label)}</h3>
      ${meta ? `<p class="fin-watch-card__meta">${escapeHtml(meta)}</p>` : ''}
      <span class="fin-pill fin-pill--${escapeHtml(statusCode)}">${escapeHtml(status)}</span>
      ${news ? `<p class="fin-watch-card__news">${escapeHtml(news)}</p>` : ''}
    </article>
  `;
}

function renderWatchList(post) {
  const items = (post.watch_list || []).slice(0, 5);
  if (!items.length) return '';
  return `
    <section class="fin-watch" id="watch-list" data-fin-reveal>
      <h2>Injury watch list</h2>
      <div class="fin-watch__grid">
        ${items.map((item) => watchCard(item)).join('')}
      </div>
    </section>
  `;
}

function revealSections() {
  const nodes = document.querySelectorAll('[data-fin-reveal]');
  if (reduceMotion) {
    nodes.forEach((n) => n.classList.add('is-in'));
    return;
  }
  nodes.forEach((node) => {
    inView(
      node,
      () => {
        animate(
          node,
          { opacity: [0, 1], y: [18, 0] },
          { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] }
        );
        node.classList.add('is-in');
        const blocks = node.querySelectorAll('.fin-block, .fin-stats__item, .fin-watch-card, .fin-key-fact');
        if (blocks.length) {
          animate(
            blocks,
            { opacity: [0, 1], y: [8, 0] },
            { delay: stagger(0.03), duration: 0.3, easing: [0.17, 0.55, 0.55, 1] }
          );
        }
        return () => {};
      },
      { amount: 0.18 }
    );
  });
}

async function loadPost() {
  showError('');
  const empty = document.querySelector('[data-fin-empty]');
  const article = document.querySelector('[data-fin-article]');
  if (empty) empty.hidden = true;
  if (article) article.hidden = true;

  startProgress(
    'Loading FPL injury news',
    'Checking the published Injury Risk Digest for this gameweek.'
  );
  try {
    setProgress(40, { label: 'Checking published post' });
    const res = await fetch(apiUrl('fpl/injury-news'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'We could not load FPL injury news.');
    }

    // Never auto-generate on page visits. One publish per gameweek via CLI / locked API.
    if (data.needs_generate || !data.post) {
      failProgress();
      if (empty) {
        const title = empty.querySelector('[data-fin-empty-title]');
        const copy = empty.querySelector('[data-fin-empty-copy]');
        if (title) {
          title.textContent = `${data.target_name || 'This gameweek'} digest is not live yet`;
        }
        if (copy) {
          copy.textContent =
            'Metro Football writes FPL injury news once for each new gameweek. We do not rebuild it on every visit, so managers always see the same briefing.';
        }
        empty.hidden = false;
      }
      return;
    }

    renderArticle(data.post);
    finishProgress('Digest ready');
  } catch (err) {
    failProgress();
    showError(err?.message || 'We could not load FPL injury news.');
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  try {
    const session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }
  await loadPost();
}

boot();
