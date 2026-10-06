import { animate, inView, stagger } from './motion.js?v=20260901perf';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  apiUrl,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const PROGRESS_SHOW_DELAY_MS = 80;

const state = {
  post: null,
  gameweek: null,
};

const progressUi = {
  value: 0,
  visible: false,
  showTimer: null,
  trickleTimer: null,
  anim: null,
};

const FACT_ICONS = {
  fixture: './images/fin/next-fixture.png',
  fdr: './images/fin/fixture-difficulty.png',
  'form-good': './images/fin/form.png',
  'form-mid': './images/fin/form-mid.png',
  'form-poor': './images/fin/form-poor.png',
};

const FACT_ICON_LABELS = {
  fixture: 'Next fixture',
  fdr: 'Fixture difficulty',
  'form-good': 'Good form',
  'form-mid': 'Average form',
  'form-poor': 'Poor form',
};

const FDR_LABELS = {
  1: 'Easiest',
  2: 'Easy',
  3: 'Medium',
  4: 'Hard',
  5: 'Hardest',
};

function fdrLevel(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return 3;
  return Math.max(1, Math.min(5, Math.round(n)));
}

function parseFixtureFdr(tip) {
  const direct = Number(tip?.fixture_fdr);
  if (Number.isFinite(direct) && direct >= 1 && direct <= 5) {
    return direct;
  }
  const match = String(tip?.opponent_rank ?? '').match(/(\d)/);
  return match ? fdrLevel(match[1]) : null;
}

function fdrBadgeHtml(level, size = 'md') {
  const fdr = fdrLevel(level);
  const label = FDR_LABELS[fdr] || 'Medium';
  return `<span class="mf-fdr mf-fdr--${fdr} mf-fdr--${size} fin-key-fact__fdr" title="Fixture difficulty ${label}" aria-label="Fixture difficulty ${label}">${fdr}</span>`;
}

function formatFormValue(value) {
  const text = String(value ?? '').trim();
  if (!text) return '';
  return text.replace(/^form\s+/i, '');
}

function parseFormScore(tip) {
  const fromStats = Number(tip?.stats?.form);
  if (Number.isFinite(fromStats)) {
    return fromStats;
  }
  const fromLine = Number.parseFloat(formatFormValue(tip?.form_line));
  return Number.isFinite(fromLine) ? fromLine : null;
}

function formIconKey(score) {
  if (!Number.isFinite(score)) return 'form-good';
  if (score < 3) return 'form-poor';
  if (score < 6) return 'form-mid';
  return 'form-good';
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function parseGameweekFromPath() {
  const m = window.location.pathname.match(/fpl-gameweek-(\d+)-tips\/?$/i);
  return m ? parseInt(m[1], 10) : null;
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

function isCorruptParagraph(text) {
  const value = String(text ?? '').trim();
  if (!value || value.toLowerCase() === 'closing') return true;
  if (value.includes('"element_id"') || value.includes('element_id":')) return true;
  if (/^\s*[\[{]/.test(value)) return true;
  return false;
}

function paragraphs(list) {
  return (list || [])
    .filter((p) => !isCorruptParagraph(p))
    .map((p) => renderParagraph(p))
    .join('');
}

function renderParagraph(text) {
  const raw = String(text ?? '');
  const parts = [];
  const linkRe = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g;
  let last = 0;
  let match;
  let linkCount = 0;
  while ((match = linkRe.exec(raw)) !== null) {
    if (linkCount >= 1) break;
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

function formatFixtureLine(value) {
  const text = String(value ?? '').trim();
  if (!text) return '';
  if (/^vs\s/i.test(text)) return text;
  return `vs ${text}`;
}

function tipFactsStrip(tip) {
  const fixtureFdr = parseFixtureFdr(tip);
  const facts = [
    { label: 'Next fixture', value: formatFixtureLine(tip.fixture_line), icon: 'fixture', kind: 'text' },
    fixtureFdr
      ? {
          label: 'Fixture difficulty level',
          value: fdrBadgeHtml(fixtureFdr),
          icon: 'fdr',
          kind: 'html',
        }
      : null,
    { label: 'Form', value: formatFormValue(tip.form_line), icon: formIconKey(parseFormScore(tip)), kind: 'stat' },
  ].filter((fact) => fact && fact.value);

  if (!facts.length) return '';

  return `
    <div class="fin-key-facts" aria-label="Quick facts for ${escapeHtml(tip.web_name)}">
      ${facts
        .map((fact) => {
          const iconMarkup =
            fact.icon && FACT_ICONS[fact.icon]
              ? `<img class="fin-key-fact__icon-img" src="${escapeHtml(FACT_ICONS[fact.icon])}" alt="${escapeHtml(FACT_ICON_LABELS[fact.icon] || '')}" width="22" height="22" loading="lazy" decoding="async">`
              : '';
          const valueMarkup =
            fact.kind === 'html'
              ? fact.value
              : escapeHtml(fact.value);

          const valueClass =
            fact.kind === 'html'
              ? ' fin-key-fact__value--fdr'
              : fact.kind === 'stat'
                ? ' fin-key-fact__value--stat'
                : '';

          return `
        <article class="fin-key-fact${fact.icon ? '' : ' fin-key-fact--no-icon'}">
          <span class="fin-key-fact__icon" aria-hidden="true">${iconMarkup}</span>
          <div class="fin-key-fact__copy">
            <p class="fin-key-fact__label">${escapeHtml(fact.label)}</p>
            <p class="fin-key-fact__value${valueClass}">${valueMarkup}</p>
          </div>
        </article>`;
        })
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

function statsCard(tip) {
  const s = tip.stats || {};
  const cells = [
    ['Pts', s.total_points],
    ['G', s.goals],
    ['A', s.assists],
    ['xG', s.xg],
    ['xA', s.xa],
    ['Form', s.form],
    ['Owned by', formatOwn(s.ownership ?? tip.ownership)],
    ['Price', formatPrice(s.price ?? tip.price)],
  ];
  return `
    <dl class="fin-stats" aria-label="Season stats for ${escapeHtml(tip.web_name)}">
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

function whyHeading(tipType) {
  return tipType === 'player_to_avoid' ? 'Why we are wary' : 'Why we like him';
}

function tipSection(tip) {
  const photo = tip.photo || '';
  const shirt = tip.shirt || '';
  const src = photo || shirt;
  const suggestion = tip.what_we_suggest || [];

  return `
    <section class="fin-player" id="${escapeHtml(tip.slug)}" data-fin-reveal>
      <header class="fin-player__head">
        <div class="fin-player__identity">
          ${
            src
              ? `<img class="fin-avatar${photo ? '' : ' fin-avatar--shirt'}" src="${escapeHtml(src)}" data-shirt="${escapeHtml(shirt)}" alt="${escapeHtml(tip.alt_text || tip.web_name)}" width="72" height="72" loading="lazy" referrerpolicy="no-referrer" onerror="window.finAvatarFallback(this)">`
              : `<span class="fin-avatar fin-avatar--empty" aria-hidden="true"></span>`
          }
          <div>
            <h2 class="fin-player__title">${escapeHtml(tip.heading)}</h2>
            <p class="fin-player__meta">
              ${escapeHtml(tip.team_name)} · ${escapeHtml(tip.position)} · Owned by ${escapeHtml(formatOwn(tip.ownership))}
            </p>
          </div>
        </div>
      </header>

      ${statsCard(tip)}
      ${tipFactsStrip(tip)}

      ${
        tip.story_lead
          ? `<p class="fin-player__lead">${escapeHtml(tip.story_lead)}</p>`
          : ''
      }

      <div class="fin-blocks">
        <div class="fin-block">
          <h3>${escapeHtml(whyHeading(tip.tip_type))}</h3>
          ${paragraphs(tip.why_pick)}
        </div>
        <div class="fin-block">
          <h3>FPL impact</h3>
          ${paragraphs(tip.fpl_impact)}
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

function quickPickCard(item) {
  const photo = item.photo || '';
  const shirt = item.shirt || '';
  const src = photo || shirt;
  const meta = [item.team_short, item.position].filter(Boolean).join(' · ');

  return `
    <article class="fin-watch-card">
      ${
        src
          ? `<img class="fin-watch-card__avatar${photo ? '' : ' fin-watch-card__avatar--shirt'}" src="${escapeHtml(src)}" data-shirt="${escapeHtml(shirt)}" alt="${escapeHtml(item.web_name || item.label)}" width="56" height="56" loading="lazy" referrerpolicy="no-referrer" onerror="window.finAvatarFallback(this)">`
          : `<span class="fin-watch-card__avatar fin-watch-card__avatar--empty" aria-hidden="true"></span>`
      }
      <h3 class="fin-watch-card__name">${escapeHtml(item.web_name || item.label)}</h3>
      ${meta ? `<p class="fin-watch-card__meta">${escapeHtml(meta)}</p>` : ''}
      <span class="fin-pill fin-pill--a">${escapeHtml(item.label || 'Pick')}</span>
      ${item.teaser ? `<p class="fin-watch-card__news">${escapeHtml(item.teaser)}</p>` : ''}
    </article>
  `;
}

function renderQuickPicks(post) {
  const items = post.quick_picks || [];
  if (!items.length) return '';
  return `
    <section class="fin-watch" id="quick-picks" data-fin-reveal>
      <h2>Quick picks</h2>
      <div class="fin-watch__grid fin-watch__grid--quick">
        ${items.map((item) => quickPickCard(item)).join('')}
      </div>
    </section>
  `;
}

function renderArchiveNav(data) {
  const archives = data.archive_paths || [];
  const gw = state.gameweek;
  const filtered = archives.filter((row) => row.gw !== gw);
  if (!filtered.length) return '';

  return `
    <nav class="fgt-archive" data-fin-reveal aria-label="Other gameweek tips">
      <h2 class="fgt-archive__title">More Gameweek tips</h2>
      <ul class="fgt-archive__list">
        ${filtered
          .slice(0, 6)
          .map(
            (row) =>
              `<li><a href="${escapeHtml(row.path)}">${escapeHtml(row.label)}</a></li>`
          )
          .join('')}
      </ul>
    </nav>
  `;
}

function renderToc(post) {
  const nav = document.querySelector('[data-fin-toc]');
  if (!nav) return;
  nav.innerHTML = `
    <h2 class="fin-toc__title">On this page</h2>
    <ol class="fin-toc__list">
      ${(post.toc || [])
        .map((item) => `<li><a href="#${escapeHtml(item.id)}">${escapeHtml(item.label)}</a></li>`)
        .join('')}
    </ol>
  `;
}

function renderArticle(post, data) {
  state.post = post;

  document.title = `${post.seo_title || 'FPL Gameweek tips'} | Metro Football`;
  const meta = document.querySelector('meta[name="description"]');
  if (meta && post.meta_description) meta.setAttribute('content', post.meta_description);
  const canonical = document.querySelector('link[rel="canonical"]');
  if (canonical && post.canonical) canonical.setAttribute('href', post.canonical);

  const title = document.querySelector('[data-fin-title]');
  const lead = document.querySelector('[data-fin-lead]');
  const kicker = document.querySelector('[data-fin-kicker]');
  const metaLine = document.querySelector('[data-fin-meta]');
  if (title) title.textContent = post.h1 || 'FPL Gameweek tips';
  if (lead && post.intro_paragraphs?.[0]) lead.textContent = post.intro_paragraphs[0];
  if (kicker) {
    kicker.textContent = post.gameweek_name
      ? `${post.gameweek_name} tips briefing`
      : 'Gameweek tips';
  }
  if (metaLine) {
    const updated = post.updated_at
      ? new Date(post.updated_at).toLocaleString('en-GB', {
          dateStyle: 'medium',
          timeStyle: 'short',
          timeZone: 'Europe/London',
        })
      : '';
    const archiveNote = data.is_upcoming ? '' : ' · Archived tips';
    metaLine.hidden = !updated;
    metaLine.textContent = updated ? `Updated ${updated}${archiveNote}` : archiveNote.trim();
  }

  renderToc(post);

  const body = document.querySelector('[data-fin-body]');
  if (!body) return;

  const introRest = (post.intro_paragraphs || []).slice(1);
  const tipsHtml = (post.tips || []).map(tipSection).join('');
  body.innerHTML = `
    <div class="fin-intro" data-fin-reveal>
      ${introRest.map((p) => `<p>${escapeHtml(p)}</p>`).join('')}
      <p class="fin-internal">
        Also check our
        <a href="./fpl-injury-news">injury risk digest</a>,
        <a href="./price-changes">price change predictions</a>,
        and
        <a href="./gameweek-recap">gameweek recap</a>
        before you lock your team.
      </p>
    </div>
    ${renderQuickPicks(post)}
    ${tipsHtml}
    <section class="fin-close" data-fin-reveal>
      ${paragraphs(post.closing)}
    </section>
    ${renderArchiveNav(data)}
  `;

  const article = document.querySelector('[data-fin-article]');
  if (article) article.hidden = false;
  revealSections();
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
        animate(node, { opacity: [0, 1], y: [18, 0] }, { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] });
        node.classList.add('is-in');
        const blocks = node.querySelectorAll('.fin-block, .fin-stats__item, .fin-watch-card, .fin-key-fact');
        if (blocks.length) {
          animate(blocks, { opacity: [0, 1], y: [8, 0] }, { delay: stagger(0.03), duration: 0.3, easing: [0.17, 0.55, 0.55, 1] });
        }
        return () => {};
      },
      { amount: 0.18 }
    );
  });
}

async function resolveGameweek() {
  const fromPath = parseGameweekFromPath();
  if (fromPath) return fromPath;

  const res = await fetch(apiUrl('fpl/gameweek-tips?meta=1'), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const data = await res.json();
  if (!res.ok || !data.ok) {
    throw new Error(data.error || 'We could not find the upcoming gameweek.');
  }
  const gw = Number(data.upcoming_gw);
  if (!Number.isFinite(gw) || gw < 1) {
    throw new Error('We could not find the upcoming gameweek.');
  }
  const path = data.upcoming_path || `./fpl-gameweek-${gw}-tips`;
  window.location.replace(path);
  return gw;
}

async function loadPost() {
  showError('');
  const empty = document.querySelector('[data-fin-empty]');
  const article = document.querySelector('[data-fin-article]');
  if (article?.dataset.finSsr || empty?.dataset.finSsr) {
    state.gameweek = parseGameweekFromPath();
    return;
  }
  if (empty) empty.hidden = true;
  if (article) article.hidden = true;

  startProgress('Loading FPL Gameweek tips', 'Checking the published tips briefing for this gameweek.');

  try {
    const gw = await resolveGameweek();
    state.gameweek = gw;

    setProgress(40, { label: 'Checking published tips' });
    const res = await fetch(apiUrl(`fpl/gameweek-tips?gw=${gw}`), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'We could not load FPL Gameweek tips.');
    }

    if (data.needs_generate || !data.post) {
      failProgress();
      if (empty) {
        const title = empty.querySelector('[data-fin-empty-title]');
        const copy = empty.querySelector('[data-fin-empty-copy]');
        if (title) {
          title.textContent = `${data.gameweek_name || 'This gameweek'} tips are not live yet`;
        }
        if (copy) {
          copy.textContent = '';
          copy.hidden = true;
        }
        empty.hidden = false;
      }
      return;
    }

    renderArticle(data.post, data);
    finishProgress('Tips ready');
  } catch (err) {
    failProgress();
    showError(err?.message || 'We could not load FPL Gameweek tips.');
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
