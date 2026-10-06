import { admFetch, esc, fmtDate } from './admin-shared.js?v=20260902adm4';
import { animate, stagger } from '/js/motion.js?v=20260901perf';

const state = {
  segments: [],
  selected: new Set(),
  previewCount: 0,
  campaigns: [],
  sending: false,
};

function reduceMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function bodyEl() {
  return document.querySelector('[data-ae-body]');
}

function getBodyHtml() {
  const el = bodyEl();
  if (!el) return '';
  return el.innerHTML.trim();
}

function getBodyText() {
  const el = bodyEl();
  if (!el) return '';
  return (el.innerText || '').replace(/\u00a0/g, ' ').trim();
}

async function boot() {
  try {
    await admFetch('me.php');
  } catch (err) {
    if (err.status === 401 || err.status === 403) {
      location.replace('/admin/login');
      return;
    }
    throw err;
  }

  document.querySelector('[data-ae-back]')?.addEventListener('click', () => {
    location.href = '/admin/';
  });
  document.querySelector('[data-ae-scroll-compose]')?.addEventListener('click', () => {
    document.getElementById('ae-compose')?.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' });
  });

  bindComposer();
  await load();
  playIntro();
}

async function load() {
  const data = await admFetch('campaigns.php');
  state.segments = data.segments || [];
  state.campaigns = data.campaigns || [];
  renderStats();
  renderSegments();
  renderHistory();
  await refreshPreview();
}

function bindComposer() {
  const subject = document.querySelector('[data-ae-subject]');
  const body = bodyEl();
  const ctaLabel = document.querySelector('[data-ae-cta-label]');
  const ctaUrl = document.querySelector('[data-ae-cta-url]');

  [subject, ctaLabel, ctaUrl].forEach((el) => {
    el?.addEventListener('input', () => {
      updatePreview();
      syncSendEnabled();
    });
  });

  body?.addEventListener('input', () => {
    updatePreview();
    syncSendEnabled();
  });
  body?.addEventListener('keyup', () => {
    updatePreview();
    syncSendEnabled();
  });

  document.querySelectorAll('[data-ae-cmd]').forEach((btn) => {
    btn.addEventListener('mousedown', (e) => e.preventDefault());
    btn.addEventListener('click', () => runEditorCommand(btn.getAttribute('data-ae-cmd')));
  });

  document.querySelector('[data-ae-send]')?.addEventListener('click', openConfirm);
  document.querySelector('[data-ae-confirm-cancel]')?.addEventListener('click', closeConfirm);
  document.querySelector('[data-ae-confirm]')?.addEventListener('click', (e) => {
    if (e.target.matches('[data-ae-confirm]')) closeConfirm();
  });
  document.querySelector('[data-ae-confirm-go]')?.addEventListener('click', sendCampaign);
}

function runEditorCommand(cmd) {
  const editor = bodyEl();
  if (!editor || !cmd) return;
  editor.focus();

  if (cmd === 'createLink') {
    const existing = window.getSelection()?.anchorNode?.parentElement?.closest?.('a');
    const current = existing?.getAttribute('href') || 'https://';
    const url = window.prompt('Link URL', current);
    if (url === null) return;
    const trimmed = url.trim();
    if (!trimmed) {
      document.execCommand('unlink');
    } else {
      const href = /^(https?:\/\/|mailto:|\/)/i.test(trimmed) ? trimmed : `https://${trimmed}`;
      document.execCommand('createLink', false, href);
    }
  } else {
    document.execCommand(cmd, false);
  }

  updatePreview();
  syncSendEnabled();
}

function renderStats() {
  const root = document.querySelector('[data-ae-stats]');
  if (!root) return;

  const incomplete = state.segments.find((s) => s.id === 'incomplete_signup')?.count || 0;
  const premium = state.segments.find((s) => s.id === 'premium')?.count || 0;
  const free = state.segments.find((s) => s.id === 'free')?.count || 0;
  const linked = state.segments.find((s) => s.id === 'fpl_linked')?.count || 0;
  const all = state.segments.find((s) => s.id === 'all_users')?.count || 0;

  const cards = [
    { value: all, label: 'Registered users' },
    { value: premium, label: 'Premium active' },
    { value: free, label: 'Free users' },
    { value: linked, label: 'Teams linked' },
    { value: incomplete, label: 'Incomplete signups', alert: incomplete > 0 },
  ];

  root.innerHTML = cards.map((c) => `
    <div class="ae-stat${c.alert ? ' is-alert' : ''}">
      <span class="ae-stat__value">${esc(String(c.value))}</span>
      <span class="ae-stat__label">${esc(c.label)}</span>
    </div>
  `).join('');
}

function renderSegments() {
  const root = document.querySelector('[data-ae-segments]');
  if (!root) return;

  root.innerHTML = state.segments.map((seg) => {
    const active = state.selected.has(seg.id);
    return `
      <button type="button" class="ae-chip${active ? ' is-active' : ''}" data-ae-segment="${esc(seg.id)}" title="${esc(seg.help)}">
        <span>${esc(seg.label)}</span>
        <span class="ae-chip__count">${esc(String(seg.count))}</span>
      </button>
    `;
  }).join('');

  root.querySelectorAll('[data-ae-segment]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-ae-segment');
      if (!id) return;
      if (state.selected.has(id)) state.selected.delete(id);
      else state.selected.add(id);
      renderSegments();
      await refreshPreview();
      syncSendEnabled();
    });
  });
}

async function refreshPreview() {
  const countEl = document.querySelector('[data-ae-count]');
  const noteEl = document.querySelector('[data-ae-count-note]');
  const listEl = document.querySelector('[data-ae-recipients]');
  const segments = [...state.selected];

  if (segments.length === 0) {
    state.previewCount = 0;
    if (countEl) countEl.textContent = '0 recipients';
    if (noteEl) noteEl.textContent = 'Select a segment to preview who will receive this email.';
    if (listEl) listEl.innerHTML = '';
    syncSendEnabled();
    return;
  }

  if (countEl) countEl.textContent = 'Counting…';
  if (noteEl) noteEl.textContent = '';

  try {
    const qs = new URLSearchParams({
      action: 'preview',
      segments: segments.join(','),
    });
    const data = await admFetch(`campaigns.php?${qs.toString()}`);
    const preview = data.preview || { count: 0, recipients: [] };
    state.previewCount = preview.count || 0;
    if (countEl) countEl.textContent = `${preview.count} recipient${preview.count === 1 ? '' : 's'}`;
    if (noteEl) {
      noteEl.textContent = preview.count > (preview.recipients?.length || 0)
        ? `Showing first ${preview.recipients.length}.`
        : 'Ready to send.';
    }
    if (listEl) {
      listEl.innerHTML = (preview.recipients || []).map((r) => `
        <div class="ae-recipient">
          <span class="ae-recipient__email">${esc(r.email)}</span>
          <span class="ae-recipient__meta">${esc(r.source === 'pending' ? 'Incomplete' : (r.name || 'User'))}</span>
        </div>
      `).join('') || '<p class="adm-muted">No matching emails.</p>';
    }
  } catch (err) {
    if (countEl) countEl.textContent = '0 recipients';
    if (noteEl) noteEl.textContent = err.message || 'Could not load audience';
    if (listEl) listEl.innerHTML = '';
    state.previewCount = 0;
  }

  syncSendEnabled();
}

function updatePreview() {
  const html = getBodyHtml();
  const ctaLabel = document.querySelector('[data-ae-cta-label]')?.value.trim() || '';
  const previewBody = document.querySelector('[data-ae-preview-body]');
  const previewCta = document.querySelector('[data-ae-preview-cta]');

  if (previewBody) {
    const greeting = '<p>Dear user,</p>';
    const hasContent = getBodyText() !== '';
    previewBody.innerHTML = greeting + (hasContent ? html : '<p>Your message will appear here.</p>');
  }

  if (previewCta) {
    if (ctaLabel) {
      previewCta.hidden = false;
      previewCta.innerHTML = `<span>${esc(ctaLabel)}</span>`;
    } else {
      previewCta.hidden = true;
      previewCta.innerHTML = '';
    }
  }
}

function syncSendEnabled() {
  const btn = document.querySelector('[data-ae-send]');
  if (!btn) return;
  const subject = document.querySelector('[data-ae-subject]')?.value.trim() || '';
  const body = getBodyText();
  btn.disabled = state.sending || !subject || !body || state.selected.size === 0 || state.previewCount < 1;
}

function openConfirm() {
  const count = state.previewCount;
  const text = document.querySelector('[data-ae-confirm-text]');
  if (text) {
    text.textContent = `This will send to ${count} recipient${count === 1 ? '' : 's'} now using Resend. Double-check the subject and audience before you continue.`;
  }
  const modal = document.querySelector('[data-ae-confirm]');
  if (modal) modal.hidden = false;
}

function closeConfirm() {
  const modal = document.querySelector('[data-ae-confirm]');
  if (modal) modal.hidden = true;
}

async function sendCampaign() {
  if (state.sending) return;
  const errorEl = document.querySelector('[data-ae-error]');
  const successEl = document.querySelector('[data-ae-success]');
  if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
  if (successEl) { successEl.hidden = true; successEl.textContent = ''; }

  const payload = {
    action: 'send',
    subject: document.querySelector('[data-ae-subject]')?.value.trim() || '',
    body_html: getBodyHtml(),
    body_text: getBodyText(),
    cta_label: document.querySelector('[data-ae-cta-label]')?.value.trim() || '',
    cta_url: document.querySelector('[data-ae-cta-url]')?.value.trim() || '',
    segments: [...state.selected],
  };

  state.sending = true;
  syncSendEnabled();
  closeConfirm();
  const sendBtn = document.querySelector('[data-ae-send]');
  if (sendBtn) sendBtn.querySelector('.mf-btn__label').textContent = 'Sending…';

  try {
    const data = await admFetch('campaigns.php', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    const campaign = data.campaign || {};
    if (successEl) {
      successEl.hidden = false;
      successEl.textContent = `Sent ${campaign.sent_count || 0} of ${campaign.recipient_count || 0}. Failed: ${campaign.failed_count || 0}.`;
    }
    document.querySelector('[data-ae-subject]').value = '';
    const editor = bodyEl();
    if (editor) editor.innerHTML = '';
    document.querySelector('[data-ae-cta-label]').value = '';
    document.querySelector('[data-ae-cta-url]').value = '';
    state.selected.clear();
    updatePreview();
    await load();
  } catch (err) {
    if (errorEl) {
      errorEl.hidden = false;
      errorEl.textContent = err.message || 'Send failed';
    }
  } finally {
    state.sending = false;
    if (sendBtn) sendBtn.querySelector('.mf-btn__label').textContent = 'Send email';
    syncSendEnabled();
  }
}

function renderHistory() {
  const root = document.querySelector('[data-ae-history]');
  if (!root) return;

  if (!state.campaigns.length) {
    root.innerHTML = '<p class="adm-muted">No campaigns yet.</p>';
    return;
  }

  root.innerHTML = state.campaigns.map((c) => `
    <article class="ae-history__item">
      <p class="ae-history__subject">${esc(c.subject)} <span class="ae-badge ae-badge--${esc(c.status)}">${esc(c.status)}</span></p>
      <p class="ae-history__meta">
        ${(c.segment_labels || []).map(esc).join(' · ') || 'No segments'}
        <br>
        ${esc(String(c.sent_count || 0))}/${esc(String(c.recipient_count || 0))} sent
        ${c.failed_count ? ` · ${esc(String(c.failed_count))} failed` : ''}
        · ${esc(fmtDate(c.sent_at || c.created_at))}
      </p>
    </article>
  `).join('');
}

function playIntro() {
  if (reduceMotion()) {
    document.querySelectorAll('.ae-stat, .ae-card').forEach((el) => {
      el.style.opacity = '1';
    });
    return;
  }

  const stats = document.querySelectorAll('.ae-stat');
  const cards = document.querySelectorAll('.ae-card');
  if (stats.length) {
    animate(stats, { opacity: [0, 1], y: [10, 0] }, {
      duration: 0.35,
      delay: stagger(0.05),
      easing: [0.17, 0.55, 0.55, 1],
    });
  }
  if (cards.length) {
    animate(cards, { opacity: [0, 1], y: [14, 0] }, {
      duration: 0.4,
      delay: stagger(0.08, { startDelay: 0.12 }),
      easing: [0.17, 0.55, 0.55, 1],
    });
  }
}

boot().catch((err) => {
  console.error(err);
  const root = document.querySelector('[data-ae-stats]');
  if (root) root.innerHTML = `<p class="adm-error">${esc(err.message || 'Could not load emails')}</p>`;
});
