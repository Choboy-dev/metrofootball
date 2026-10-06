import {
  oneTapPayloadAttr,
} from './one-tap-fix.js?v=20260904tips9';

export function renderTipsExtras(sections, helpers) {
  const {
    escapeHtml,
    playerFaceHtml,
    fdrBadgeHtml,
    pageUrl,
  } = helpers;

  const ctx = { escapeHtml, playerFaceHtml, fdrBadgeHtml, pageUrl };

  renderBenchOrder(sections.bench_order, ctx);
  renderRoadmap(sections.transfer_roadmap, ctx);
  renderChipPreview(sections.chip_preview, escapeHtml);
  renderSetPieces(sections.set_pieces, ctx);
  renderHitCalculator(sections.hit_calculator, ctx);
  bindShareCard(sections.share_card, escapeHtml);
}

const CHIP_ICONS = {
  freehit: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 8.5A8.5 8.5 0 0 0 8.5 4M4 15.5A8.5 8.5 0 0 0 15.5 20" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  bboost: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 18h16M6 18V9a2 2 0 0 1 2-2h3l1-2h4l1 2h3a2 2 0 0 1 2 2v9" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  '3xc': `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 16V8h2.4c1.4 0 2.3.8 2.3 2 0 .9-.6 1.5-1.5 1.8L13 16h-2l-1.2-2.6H9.2V16H8zm1.5-3.8h.85c.65 0 1-.3 1-.75s-.35-.8-1-.8h-.85v1.55z" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="9"/></svg>`,
  wildcard: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 12a8 8 0 0 1 14.5-4.8M20 12a8 8 0 0 1-14.5 4.8" stroke-linecap="round"/><path d="M18.5 4.5V9H14M5.5 19.5V15H10" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  hold: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>`,
};

function chipIcon(key) {
  return CHIP_ICONS[key] || CHIP_ICONS.hold;
}

function chipSlug(label, key) {
  const raw = String(key || label || '').toLowerCase();
  if (raw.includes('free') || raw === 'freehit') return 'freehit';
  if (raw.includes('bench') || raw === 'bboost') return 'bboost';
  if (raw.includes('triple') || raw === '3xc') return '3xc';
  if (raw.includes('wild') || raw === 'wildcard') return 'wildcard';
  return 'hold';
}

function normalizePlayer(raw) {
  return {
    id: raw.id || 0,
    web_name: raw.name || raw.web_name || raw.value || 'Player',
    pos_label: raw.pos || raw.pos_label || '',
    team_short: raw.team_short || '',
    shirt: raw.shirt || null,
    photo: raw.photo || null,
    now_cost: raw.now_cost ?? raw.price ?? null,
    proj: raw.proj ?? null,
    doubled: raw.doubled ?? null,
    fixture: raw.fixture || '',
    fdr: raw.fdr ?? 3,
    is_captain: !!raw.is_captain,
    is_vice_captain: !!raw.is_vice_captain,
  };
}

function pickPlayerCard(raw, { escapeHtml, fdrBadgeHtml }, opts = {}) {
  const player = normalizePlayer(raw);
  const badge = player.is_captain
    ? '<span class="pick-player__badge" aria-label="Captain">C</span>'
    : player.is_vice_captain
      ? '<span class="pick-player__badge pick-player__badge--vice" aria-label="Vice captain">V</span>'
      : opts.badge
        ? `<span class="pick-player__badge">${escapeHtml(String(opts.badge))}</span>`
        : '';
  const pos = opts.showPos && player.pos_label
    ? `<span class="pick-player__pos">${escapeHtml(player.pos_label)}</span>`
    : '';
  const kitImg = player.photo
    ? `<img class="pick-player__shirt pick-player__shirt--photo" src="${escapeHtml(player.photo)}" alt="" width="52" height="68" loading="lazy" decoding="async">`
    : player.shirt
      ? `<img class="pick-player__shirt" src="${escapeHtml(player.shirt)}" alt="" width="52" height="68" loading="lazy" decoding="async">`
      : `<span class="pick-player__shirt pick-player__shirt--fallback" aria-hidden="true"></span>`;
  const pts = player.proj != null ? Number(player.proj).toFixed(1) : '—';
  const price = player.now_cost != null ? `£${Number(player.now_cost).toFixed(1)}` : '';
  const fxLabel = player.fixture || '';
  const fdr = fxLabel ? fdrBadgeHtml(player.fdr, 'sm') : '';
  const roles = Array.isArray(opts.roles) ? opts.roles.filter(Boolean) : [];
  const isSetpiece = opts.modifier === 'setpiece';

  const cardBody = isSetpiece
    ? (roles.length
      ? `<ul class="pick-player__setpiece-list">${roles.map((role) => setPieceRoleHtml(role, escapeHtml)).join('')}</ul>`
      : `<p class="pick-player__setpiece-empty">No set piece role listed</p>`)
    : `<span class="pick-player__proj pick-player__proj--single">
          <span class="pick-player__gw pick-player__gw--single">
            <span class="pick-player__score" title="Predicted points">${escapeHtml(String(pts))}</span>
            ${fxLabel ? `<span class="pick-player__fx">${escapeHtml(fxLabel)}</span>` : ''}
            ${fdr}
          </span>
        </span>`;

  return `
    <article class="pick-player pick-player--tips${opts.modifier ? ` pick-player--${opts.modifier}` : ''}" data-player-id="${player.id}">
      ${pos}
      <div class="pick-player__kit">
        ${badge}
        ${kitImg}
      </div>
      <span class="pick-player__card">
        <span class="pick-player__top">
          <span class="pick-player__name">${escapeHtml(player.web_name)}</span>
          ${!isSetpiece && price ? `<span class="pick-player__price">${escapeHtml(price)}</span>` : ''}
        </span>
        ${cardBody}
      </span>
    </article>`;
}

function transferSwapHtml(item, helpers, compact = false) {
  const { escapeHtml, playerFaceHtml } = helpers;
  const outName = item.out_name || 'Out';
  const inName = item.in_name || 'In';
  const outMeta = [item.out_team_short, item.pos].filter(Boolean).join(' · ');
  const inMeta = [item.in_team_short, item.pos].filter(Boolean).join(' · ');
  const mod = compact ? ' ai-swap--compact' : '';

  return `
    <article class="ai-swap${mod}" data-ai-swap>
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
    </article>`;
}

function oneTapBtn(payload, message) {
  return `<div class="ai-one-tap-row"><button type="button" class="mf-btn mf-btn--primary mf-btn--sm mf-one-tap-fix" data-one-tap-fix ${oneTapPayloadAttr(payload)} data-one-tap-message="${escapeHtml(message)}"><span class="mf-btn__label">One-tap fix</span><span class="mf-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button></div>`;
}

function renderBenchOrder(section, helpers) {
  const { escapeHtml } = helpers;
  const panel = document.querySelector('[data-tip-panel="bench_order"]');
  const titleEl = panel?.querySelector('.ai-tip-panel__title');
  const body = document.querySelector('[data-tip-body="bench_order"]');
  if (!body) return;

  if (titleEl && section?.title) {
    titleEl.textContent = section.title;
  }
  const items = section?.items || [];
  if (!items.length) {
    body.innerHTML = `<p class="ai-tip-empty">${escapeHtml(section?.empty || 'No bench players found.')}</p>`;
    return;
  }

  const orderIds = items.map((item) => item.id).filter(Boolean);
  const oneTap = oneTapBtn(
    { type: 'bench', order: orderIds },
    'We set your bench order on the Pick page. Press Save to keep it.'
  );

  body.innerHTML = `
    <div class="ai-bench-strip">${items.map((item) => `
      <div class="ai-bench-strip__slot">
        <span class="ai-bench-strip__rank" aria-label="Bench order ${item.order}">${item.order}</span>
        ${pickPlayerCard(item, helpers, { showPos: true, modifier: 'bench' })}
      </div>`).join('')}</div>
    ${oneTap}`;
}

function renderRoadmap(section, helpers) {
  const body = document.querySelector('[data-tip-body="transfer_roadmap"]');
  if (!body) return;
  const { escapeHtml } = helpers;
  const steps = section?.steps || [];

  body.innerHTML = `<ol class="ai-roadmap-track">${steps.map((step, index) => {
    const isHold = step.action === 'hold';
    const content = isHold
      ? `<div class="ai-roadmap-hold">
          <span class="ai-roadmap-hold__icon">${chipIcon('hold')}</span>
          <div>
            <strong>Hold your squad</strong>
            <p>${escapeHtml(step.note || 'No clear move yet. We would save the transfer and wait.')}</p>
          </div>
        </div>`
      : `${transferSwapHtml(step, helpers)}
         <p class="ai-roadmap-step__delta">We predict +${escapeHtml(String(step.proj_delta || 0))} points from this move.</p>`;

    return `
      <li class="ai-roadmap-step${isHold ? ' is-hold' : ''}">
        <div class="ai-roadmap-step__card">
          <h3 class="ai-roadmap-step__label">${escapeHtml(step.label)}</h3>
          ${content}
          ${!isHold && step.note ? `<p class="ai-roadmap-step__note">${escapeHtml(step.note)}</p>` : ''}
        </div>
      </li>`;
  }).join('')}</ol>`;
}

function renderChipPreview(section, escapeHtml) {
  const body = document.querySelector('[data-tip-body="chip_preview"]');
  if (!body) return;
  const current = Number(section?.current_xi ?? 0);
  const rows = section?.rows || [];
  const bestDelta = rows.reduce((max, row) => Math.max(max, Number(row.delta || 0)), 0);

  body.innerHTML = `
    <div class="ai-chip-compare">
      <div class="ai-chip-compare__baseline">
        <span class="ai-chip-compare__baseline-label">Your XI this week</span>
        <strong class="ai-chip-compare__baseline-pts">${escapeHtml(String(current))}<small>pts predicted</small></strong>
      </div>
      <div class="ai-chip-compare__grid">${rows.map((row) => {
        const slug = chipSlug(row.label, row.chip);
        const delta = Number(row.delta || 0);
        const isBest = delta > 0 && delta === bestDelta;
        const predicted = Number(row.projected || 0);

        return `
          <article class="ai-chip-compare__card ai-chip-compare__card--${slug}${isBest ? ' is-best' : ''}">
            <div class="ai-chip-compare__icon-wrap">${chipIcon(slug)}</div>
            <div class="ai-chip-compare__body">
              <div class="ai-chip-compare__head">
                <h3 class="ai-chip-compare__name">${escapeHtml(row.label)}</h3>
                ${isBest ? '<span class="ai-chip-compare__best">Best gain</span>' : ''}
              </div>
              <p class="ai-chip-compare__pts">${escapeHtml(String(predicted))}<small>pts</small></p>
              <p class="ai-chip-compare__delta">${delta >= 0 ? '+' : ''}${escapeHtml(String(delta))} vs your XI</p>
              <p class="ai-chip-compare__note">${escapeHtml(row.note)}</p>
            </div>
          </article>`;
      }).join('')}</div>
    </div>`;
}

function renderSetPieces(section, helpers) {
  const { escapeHtml } = helpers;
  const body = document.querySelector('[data-tip-body="set_pieces"]');
  const panel = document.querySelector('[data-tip-panel="set_pieces"]');
  const titleEl = panel?.querySelector('.ai-tip-panel__title');
  if (titleEl && section?.title) titleEl.textContent = section.title;

  if (!body) return;
  const items = section?.items || [];
  if (!items.length) {
    body.innerHTML = `<p class="ai-tip-empty">${escapeHtml(section?.empty || 'No set piece roles in your XI.')}</p>`;
    return;
  }

  body.innerHTML = `<ul class="ai-setpiece-grid">${items.map((item) => `
    <li class="ai-setpiece-grid__cell">
      ${pickPlayerCard(item, helpers, { showPos: true, modifier: 'setpiece', roles: item.roles || [] })}
    </li>`).join('')}</ul>`;
}

function setPieceRoleHtml(role, escapeHtml) {
  const lower = String(role || '').toLowerCase();
  let kind = 'other';
  if (lower.includes('penalty')) kind = 'pen';
  else if (lower.includes('corner')) kind = 'corner';
  else if (lower.includes('free kick')) kind = 'fk';

  return `<li class="pick-player__setpiece-item pick-player__setpiece-item--${kind}"><span class="pick-player__setpiece-dot" aria-hidden="true"></span><span>${escapeHtml(role)}</span></li>`;
}

function renderHitCalculator(section, helpers) {
  const { escapeHtml } = helpers;
  const body = document.querySelector('[data-tip-body="hit_calculator"]');
  if (!body) return;
  const items = section?.items || [];
  if (!items.length) {
    body.innerHTML = `<p class="ai-tip-empty">${escapeHtml(section?.empty || 'No hit needed this week.')}</p>`;
    return;
  }

  body.innerHTML = `
    <p class="ai-hit-rule">${escapeHtml(section?.rule || '')}</p>
    <ul class="ai-hit-list">${items.map((item) => `
      <li class="ai-hit-card${item.worth_it ? ' is-worth' : ''}">
        ${transferSwapHtml(item, helpers)}
        <div class="ai-hit-card__stats">
          <span class="ai-hit-card__stat ai-hit-card__stat--cost">Hit cost: -${item.hit_cost} pts</span>
          <span class="ai-hit-card__stat ai-hit-card__stat--gain">Two gameweek gain: +${escapeHtml(String(item.gain_two_gw))} pts</span>
        </div>
        <p class="ai-hit-card__verdict">${escapeHtml(item.verdict)}</p>
      </li>`).join('')}</ul>`;
}

function bindShareCard(shareMeta, escapeHtml) {
  const btn = document.querySelector('[data-tips-share]');
  if (!btn || !shareMeta || btn.dataset.bound === '1') return;
  btn.dataset.bound = '1';

  btn.addEventListener('click', async () => {
    try {
      const canvas = document.createElement('canvas');
      canvas.width = 1080;
      canvas.height = 1350;
      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      const grad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
      grad.addColorStop(0, '#0b1220');
      grad.addColorStop(1, '#1e293b');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      ctx.fillStyle = '#ffffff';
      ctx.font = 'bold 52px Inter, Arial, sans-serif';
      ctx.fillText(shareMeta.title || 'My AI plan', 72, 120);

      ctx.font = '36px Inter, Arial, sans-serif';
      ctx.fillStyle = '#e8edf2';
      ctx.fillText(shareMeta.team || 'My team', 72, 190);

      let y = 280;
      const lines = [
        ['Captain', shareMeta.captain],
        ['Transfer', shareMeta.transfer],
        ['Chip', shareMeta.chip],
      ];
      lines.forEach(([label, value]) => {
        if (!value) return;
        ctx.fillStyle = '#94a3b8';
        ctx.font = '28px Inter, Arial, sans-serif';
        ctx.fillText(label, 72, y);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 34px Inter, Arial, sans-serif';
        ctx.fillText(String(value).slice(0, 42), 72, y + 44);
        y += 110;
      });

      ctx.fillStyle = '#94a3b8';
      ctx.font = '24px Inter, Arial, sans-serif';
      ctx.fillText('metrofootball.co.uk/ai-tips', 72, canvas.height - 72);

      canvas.toBlob(async (blob) => {
        if (!blob) return;
        const file = new File([blob], 'metro-football-ai-plan.png', { type: 'image/png' });
        if (navigator.share && navigator.canShare?.({ files: [file] })) {
          await navigator.share({ files: [file], title: shareMeta.title });
          return;
        }
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'metro-football-ai-plan.png';
        a.click();
        URL.revokeObjectURL(url);
      }, 'image/png');
    } catch {
      // Share optional.
    }
  });
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export { oneTapBtn, oneTapPayloadAttr };
