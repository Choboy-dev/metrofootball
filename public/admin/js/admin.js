import { admFetch, esc, fmtDate, fmtGbp, fmtUsd, renderTrendChart, renderCategoryBars } from './admin-shared.js?v=20260902adm4';

const state = {
  dashboard: null,
  usersPage: 1,
  paymentsPage: 1,
  aiPage: 1,
  userQ: '',
  userFilter: '',
};

const titles = {
  overview: 'Overview',
  users: 'User management',
  revenue: 'Revenue',
  ai: 'AI costs',
  system: 'System',
};

async function boot() {
  try {
    const me = await admFetch('me.php');
    document.querySelector('[data-adm-admin-email]').textContent = me.user?.email || 'Admin';
    renderSystem(me.system);
  } catch (err) {
    if (err.status === 401 || err.status === 403) {
      location.replace('/admin/login');
      return;
    }
    throw err;
  }

  bindNav();
  bindUsersToolbar();
  bindLogout();
  bindModalDismiss();
  await loadDashboard();
  await loadUsers();
  await loadPayments();
  await loadAiUsage();
}

function bindNav() {
  document.querySelectorAll('[data-adm-nav]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const panel = btn.getAttribute('data-adm-nav');
      document.querySelectorAll('[data-adm-nav]').forEach((b) => b.classList.toggle('is-active', b === btn));
      document.querySelectorAll('[data-adm-panel]').forEach((el) => {
        el.hidden = el.getAttribute('data-adm-panel') !== panel;
      });
      document.querySelector('[data-adm-page-title]').textContent = titles[panel] || 'Admin';
    });
  });
}

function bindLogout() {
  document.querySelector('[data-adm-logout]')?.addEventListener('click', async () => {
    await admFetch('logout.php', { method: 'POST', body: '{}' });
    location.replace('/admin/login');
  });
}

function bindUsersToolbar() {
  let timer;
  document.querySelector('[data-adm-user-search]')?.addEventListener('input', (e) => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      state.userQ = e.target.value.trim();
      state.usersPage = 1;
      await loadUsers();
    }, 280);
  });

  document.querySelector('[data-adm-user-filters]')?.addEventListener('click', async (e) => {
    const chip = e.target.closest('[data-adm-user-filter]');
    if (!chip) return;
    state.userFilter = chip.getAttribute('data-adm-user-filter') || '';
    state.usersPage = 1;
    document.querySelectorAll('[data-adm-user-filter]').forEach((btn) => {
      const active = btn === chip;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    await loadUsers();
  });
}

function bindModalDismiss() {
  document.querySelectorAll('[data-adm-modal-close]').forEach((el) => {
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });
}

function userInitials(user) {
  const name = String(user.name || '').trim();
  if (name) {
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase();
  }
  return String(user.email || '??').slice(0, 2).toUpperCase();
}

function avatarStyle(email) {
  let hash = 0;
  const value = String(email || '');
  for (let i = 0; i < value.length; i += 1) hash = value.charCodeAt(i) + ((hash << 5) - hash);
  const hue = Math.abs(hash) % 360;
  return `--adm-avatar-h:${hue};--adm-avatar-s:58%;--adm-avatar-l:46%`;
}

function planLabel(user) {
  if (user.plan === 'premium' && user.subscription_status === 'active') return 'Premium';
  if (user.plan === 'premium') return 'Premium (inactive)';
  return 'Free';
}

function subscriptionLabel(status) {
  if (!status || status === 'none') return 'No subscription';
  return String(status).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function userAvatarHtml(user, sizeClass = '') {
  const cls = ['adm-user-avatar', sizeClass].filter(Boolean).join(' ');
  return `<span class="${cls}" style="${avatarStyle(user.email)}" aria-hidden="true">${esc(userInitials(user))}</span>`;
}

async function loadDashboard() {
  const data = await admFetch('dashboard.php');
  state.dashboard = data.dashboard;
  document.querySelector('[data-adm-updated]').textContent = 'Updated ' + new Date().toLocaleString('en-GB');

  const d = data.dashboard;
  renderKpiGrid(document.querySelector('[data-adm-kpi-overview]'), [
    { label: 'Total users', value: d.users.total, sub: `${d.users.new_7d} new this week` },
    { label: 'Premium active', value: d.users.premium_active, sub: `${d.users.free} on free`, cls: 'adm-kpi--accent' },
    { label: 'Revenue total', value: fmtGbp(d.revenue_gbp.total), sub: `${fmtGbp(d.revenue_gbp.last_30d)} last 30 days`, cls: 'adm-kpi--success' },
    { label: 'MRR estimate', value: fmtGbp(d.revenue_gbp.mrr_estimate), sub: 'Based on active plans' },
    { label: 'AI cost total', value: fmtUsd(d.ai_usd.total), sub: `${fmtUsd(d.ai_usd.last_30d)} last 30 days` },
    { label: 'AI calls logged', value: d.ai_usd.calls_total, sub: `${d.ai_usd.pick_tips_today} pick tips today` },
    { label: 'FPL linked', value: d.users.fpl_linked, sub: `${d.users.email_verified} verified emails` },
    { label: 'Restricted', value: d.users.restricted, sub: `${d.revenue_gbp.pending_payments} pending payments` },
  ]);

  renderKpiGrid(document.querySelector('[data-adm-kpi-revenue]'), [
    { label: 'Total GBP', value: fmtGbp(d.revenue_gbp.total), cls: 'adm-kpi--success' },
    { label: 'Last 30 days', value: fmtGbp(d.revenue_gbp.last_30d) },
    { label: 'Last 7 days', value: fmtGbp(d.revenue_gbp.last_7d) },
    { label: 'Failed payments', value: d.revenue_gbp.failed_payments },
  ]);

  renderKpiGrid(document.querySelector('[data-adm-kpi-ai]'), [
    { label: 'Total USD', value: fmtUsd(d.ai_usd.total) },
    { label: 'Last 30 days', value: fmtUsd(d.ai_usd.last_30d) },
    { label: 'Last 7 days', value: fmtUsd(d.ai_usd.last_7d) },
    { label: 'Calls logged', value: d.ai_usd.calls_total },
  ]);

  renderTrendChart(document.querySelector('[data-adm-chart-signups]'), {
    metaEl: document.querySelector('[data-adm-chart-signups-meta]'),
    items: d.charts.signups_30d,
    valueKey: 'count',
    variant: 'signups',
    title: 'Signups over the last 30 days',
    formatValue: (value) => String(Math.round(value)),
    formatTotal: (value) => `${Math.round(value)} signup${Math.round(value) === 1 ? '' : 's'}`,
  });

  renderTrendChart(document.querySelector('[data-adm-chart-revenue]'), {
    metaEl: document.querySelector('[data-adm-chart-revenue-meta]'),
    items: d.charts.revenue_30d,
    valueKey: 'amount',
    variant: 'revenue',
    title: 'Revenue over the last 30 days',
    formatValue: (value) => fmtGbp(value),
    formatTotal: (value) => fmtGbp(value),
  });

  renderTrendChart(document.querySelector('[data-adm-chart-ai]'), {
    metaEl: document.querySelector('[data-adm-chart-ai-meta]'),
    items: d.charts.ai_cost_30d,
    valueKey: 'cost',
    variant: 'ai',
    title: 'AI cost over the last 30 days',
    formatValue: (value) => fmtUsd(value),
    formatTotal: (value) => fmtUsd(value),
  });

  renderCategoryBars(document.querySelector('[data-adm-ai-services]'), {
    metaEl: document.querySelector('[data-adm-chart-ai-services-meta]'),
    items: (d.ai_by_service || []).map((row) => ({
      label: row.service,
      value: row.cost_usd,
      sub: `${row.calls} calls · ${row.provider}`,
    })),
    formatValue: fmtUsd,
    emptyLabel: 'No AI usage logged yet. New OpenAI and Tavily calls will appear here.',
  });

  renderAudit(data.audit || []);
}

function renderKpiGrid(el, items) {
  if (!el) return;
  el.innerHTML = items.map((item) => `<article class="adm-kpi ${item.cls || ''}"><div class="adm-kpi__label">${esc(item.label)}</div><div class="adm-kpi__value">${esc(String(item.value))}</div>${item.sub ? `<div class="adm-kpi__sub">${esc(item.sub)}</div>` : ''}</article>`).join('');
}

function renderAudit(rows) {
  const el = document.querySelector('[data-adm-audit]');
  if (!el) return;
  if (!rows.length) {
    el.innerHTML = '<p class="adm-empty">No admin actions yet.</p>';
    return;
  }
  el.innerHTML = `<table class="adm-table"><thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th></tr></thead><tbody>${rows.map((r) => `<tr><td>${fmtDate(r.created_at)}</td><td>${esc(r.admin_email)}</td><td>${esc(r.action)}</td><td>${esc(r.target_email || '—')}</td></tr>`).join('')}</tbody></table>`;
}

async function loadUsers() {
  const tableEl = document.querySelector('[data-adm-users-table]');
  tableEl?.classList.add('is-loading');
  try {
    const params = new URLSearchParams({ page: String(state.usersPage) });
    if (state.userQ) params.set('q', state.userQ);
    if (state.userFilter) params.set('filter', state.userFilter);
    const data = await admFetch('users.php?' + params.toString());
    renderUsersTable(data.users);
  } finally {
    tableEl?.classList.remove('is-loading');
  }
}

function userBadges(user) {
  const badges = [];
  if (user.is_admin) badges.push('<span class="adm-badge adm-badge--admin">Admin</span>');
  if (user.is_restricted) badges.push('<span class="adm-badge adm-badge--danger">Restricted</span>');
  if (user.plan === 'premium' && user.subscription_status === 'active') badges.push('<span class="adm-badge adm-badge--premium">Premium</span>');
  else badges.push('<span class="adm-badge adm-badge--free">Free</span>');
  if (user.email_verified) badges.push('<span class="adm-badge adm-badge--ok">Verified</span>');
  else badges.push('<span class="adm-badge adm-badge--muted">Unverified</span>');
  return badges.join('');
}

function renderUsersTable(payload) {
  const el = document.querySelector('[data-adm-users-table]');
  const pagEl = document.querySelector('[data-adm-users-pagination]');
  const summaryEl = document.querySelector('[data-adm-users-summary]');
  const items = payload.items || [];

  if (summaryEl) {
    const filterLabel = {
      '': 'All users',
      premium: 'Premium users',
      restricted: 'Restricted users',
      admin: 'Admin users',
      unverified: 'Unverified users',
    }[state.userFilter] || 'All users';
    summaryEl.textContent = `${payload.total || 0} ${filterLabel.toLowerCase()} · Page ${payload.page || 1} of ${payload.pages || 1}`;
  }

  if (!items.length) {
    el.innerHTML = `<div class="adm-empty adm-empty--users">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke-linecap="round"/></svg>
      <p>No users match your search.</p>
    </div>`;
    pagEl.innerHTML = '';
    return;
  }

  el.innerHTML = `<table class="adm-table adm-table--users">
    <thead>
      <tr>
        <th scope="col">User</th>
        <th scope="col">Status</th>
        <th scope="col">FPL manager</th>
        <th scope="col">Joined</th>
        <th scope="col"><span class="adm-sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>${items.map((u) => `<tr>
      <td>
        <div class="adm-user-cell">
          ${userAvatarHtml(u, 'adm-user-avatar--sm')}
          <div class="adm-user-cell__text">
            <strong class="adm-user-cell__email">${esc(u.email)}</strong>
            <span class="adm-user-cell__name">${esc(u.name || 'No name set')}</span>
          </div>
        </div>
      </td>
      <td><div class="adm-badge-row">${userBadges(u)}</div></td>
      <td>${u.fpl_linked ? `<span class="adm-fpl-name">${esc(u.fpl_manager_name || u.fpl_team_name || 'Linked')}</span>` : '<span class="adm-muted-dash">—</span>'}</td>
      <td><time class="adm-date" datetime="${esc(u.created_at || '')}">${fmtDate(u.created_at)}</time></td>
      <td class="adm-table__actions">
        <button type="button" class="adm-row-action" data-adm-manage="${u.id}">
          <span>Manage</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </td>
    </tr>`).join('')}</tbody>
  </table>`;

  el.querySelectorAll('[data-adm-manage]').forEach((btn) => {
    btn.addEventListener('click', () => openUserModal(Number(btn.getAttribute('data-adm-manage'))));
  });

  pagEl.innerHTML = `<span class="adm-pagination__info">${payload.total} users total</span><span class="adm-pagination__controls">${paginationButtons('users', payload.page, payload.pages)}</span>`;
  bindPagination(pagEl, 'users', payload.page, payload.pages);
}

function userMetaChips(user) {
  const chips = [
    `<span class="adm-meta-chip adm-meta-chip--plan">${esc(planLabel(user))}</span>`,
    `<span class="adm-meta-chip">${esc(subscriptionLabel(user.subscription_status))}</span>`,
  ];
  if (user.email_verified) chips.push('<span class="adm-meta-chip adm-meta-chip--ok">Email verified</span>');
  else chips.push('<span class="adm-meta-chip adm-meta-chip--warn">Email not verified</span>');
  if (user.is_admin) chips.push('<span class="adm-meta-chip adm-meta-chip--admin">Admin</span>');
  if (user.is_restricted) chips.push('<span class="adm-meta-chip adm-meta-chip--danger">Restricted</span>');
  if (user.fpl_linked) chips.push(`<span class="adm-meta-chip">FPL: ${esc(user.fpl_manager_name || user.fpl_team_name || 'Linked')}</span>`);
  chips.push(`<span class="adm-meta-chip adm-meta-chip--muted">Joined ${fmtDate(user.created_at)}</span>`);
  return chips.join('');
}

async function openUserModal(userId) {
  const modal = document.querySelector('[data-adm-modal]');
  const identityEl = document.querySelector('[data-adm-modal-identity]');
  const metaEl = document.querySelector('[data-adm-modal-meta]');
  const bodyEl = document.querySelector('[data-adm-modal-body]');
  const actionsEl = document.querySelector('[data-adm-modal-actions]');

  modal.hidden = false;
  document.body.classList.add('adm-modal-open');
  identityEl.innerHTML = '<div class="adm-user-modal__loading">Loading user…</div>';
  metaEl.innerHTML = '';
  bodyEl.innerHTML = '';
  actionsEl.innerHTML = '';

  const data = await admFetch('user.php?id=' + userId);
  const user = data.user;

  identityEl.innerHTML = `
    ${userAvatarHtml(user)}
    <div class="adm-user-modal__who">
      <h2 class="adm-user-modal__title" id="adm-modal-title">${esc(user.email)}</h2>
      <p class="adm-user-modal__subtitle">${esc(user.name || 'No name set')}</p>
    </div>
  `;

  metaEl.innerHTML = userMetaChips(user);

  bodyEl.innerHTML = `
    <section class="adm-user-section">
      <h3 class="adm-user-section__title">Admin note</h3>
      <p class="adm-user-section__lead">Private note for your team. Not visible to the user.</p>
      <textarea class="adm-input adm-input--area" id="adm-note" rows="4" placeholder="Add context about this account…">${esc(user.admin_note || '')}</textarea>
    </section>
    <section class="adm-user-section">
      <h3 class="adm-user-section__title">Restriction</h3>
      <p class="adm-user-section__lead">${user.is_restricted ? 'This user is currently restricted from using Metro Football.' : 'Restrict access if you need to block this account.'}</p>
      <label class="adm-label" for="adm-restrict-reason">Reason (optional)</label>
      <input class="adm-input" id="adm-restrict-reason" type="text" placeholder="e.g. chargeback, abuse report" value="${esc(user.restricted_reason || '')}">
    </section>
  `;

  const isPremiumActive = user.plan === 'premium' && user.subscription_status === 'active';

  actionsEl.innerHTML = `
    <div class="adm-action-group">
      <h4 class="adm-action-group__title">Primary</h4>
      <div class="adm-action-group__row">
        <button type="button" class="adm-btn adm-btn--primary" data-act="save-note">Save note</button>
      </div>
    </div>
    <div class="adm-action-group">
      <h4 class="adm-action-group__title">Account</h4>
      <div class="adm-action-group__row">
        <button type="button" class="adm-btn" data-act="toggle-restrict">${user.is_restricted ? 'Remove restriction' : 'Restrict user'}</button>
        ${user.email_verified ? '' : '<button type="button" class="adm-btn" data-act="verify-email">Mark email verified</button>'}
        <button type="button" class="adm-btn" data-act="toggle-admin">${user.is_admin ? 'Remove admin role' : 'Grant admin role'}</button>
      </div>
    </div>
    <div class="adm-action-group">
      <h4 class="adm-action-group__title">Billing</h4>
      <div class="adm-action-group__row">
        ${isPremiumActive
    ? '<button type="button" class="adm-btn" data-act="revoke-premium">Revoke premium</button>'
    : '<button type="button" class="adm-btn" data-act="grant-premium">Grant premium</button>'}
      </div>
    </div>
    <div class="adm-action-group adm-action-group--danger">
      <h4 class="adm-action-group__title">Danger zone</h4>
      <div class="adm-action-group__row">
        <button type="button" class="adm-btn adm-btn--danger" data-act="delete">Delete account permanently</button>
      </div>
    </div>
  `;

  actionsEl.querySelector('[data-act="save-note"]')?.addEventListener('click', () => updateUser(userId, { admin_note: document.getElementById('adm-note').value }));
  actionsEl.querySelector('[data-act="toggle-restrict"]')?.addEventListener('click', () => updateUser(userId, {
    is_restricted: !user.is_restricted,
    restricted_reason: document.getElementById('adm-restrict-reason').value,
  }));
  actionsEl.querySelector('[data-act="grant-premium"]')?.addEventListener('click', () => updateUser(userId, { grant_premium: true, billing_cycle: 'monthly' }));
  actionsEl.querySelector('[data-act="revoke-premium"]')?.addEventListener('click', () => updateUser(userId, { revoke_premium: true }));
  actionsEl.querySelector('[data-act="verify-email"]')?.addEventListener('click', () => updateUser(userId, { verify_email: true }));
  actionsEl.querySelector('[data-act="toggle-admin"]')?.addEventListener('click', () => updateUser(userId, user.is_admin ? { remove_admin: true } : { make_admin: true }));
  actionsEl.querySelector('[data-act="delete"]')?.addEventListener('click', async () => {
    if (!confirm('Delete this account permanently? This cannot be undone.')) return;
    await admFetch('user.php?id=' + userId, { method: 'DELETE' });
    closeModal();
    await loadUsers();
    await loadDashboard();
  });
}

async function updateUser(userId, body) {
  await admFetch('user.php?id=' + userId, { method: 'POST', body: JSON.stringify(body) });
  closeModal();
  await loadUsers();
  await loadDashboard();
}

function closeModal() {
  const modal = document.querySelector('[data-adm-modal]');
  if (!modal || modal.hidden) return;
  modal.hidden = true;
  document.body.classList.remove('adm-modal-open');
}

async function loadPayments() {
  const data = await admFetch('payments.php?page=' + state.paymentsPage);
  renderPaymentsTable(data.payments);
}

function paymentAmountLabel(p) {
  const revenue = fmtGbp(p.amount);
  const chargeCurrency = String(p.charge_currency || p.currency || 'GBP').toUpperCase();
  const chargeAmount = Number(p.charge_amount ?? p.amount ?? 0);
  if (chargeCurrency === 'NGN' && chargeAmount > 0 && chargeAmount !== Number(p.amount)) {
    return `${revenue}<span class="adm-payment-charge">Charged ₦${chargeAmount.toLocaleString('en-NG')}</span>`;
  }
  return revenue;
}

function renderPaymentsTable(payload) {
  const el = document.querySelector('[data-adm-payments-table]');
  const pagEl = document.querySelector('[data-adm-payments-pagination]');
  const items = payload.items || [];
  if (!items.length) {
    el.innerHTML = '<p class="adm-empty">No payments yet.</p>';
    pagEl.innerHTML = '';
    return;
  }
  el.innerHTML = `<table class="adm-table adm-table--payments"><thead><tr><th>Date</th><th>User</th><th>Revenue</th><th>Cycle</th><th>Status</th><th>Ref</th></tr></thead><tbody>${items.map((p) => `<tr>
    <td>${fmtDate(p.paid_at || p.created_at)}</td>
    <td>${esc(p.user_email || '—')}</td>
    <td class="adm-payment-amount">${paymentAmountLabel(p)}</td>
    <td>${esc(p.billing_cycle)}</td>
    <td>${esc(p.status)}</td>
    <td style="font-size:0.7rem">${esc(p.tx_ref)}</td>
  </tr>`).join('')}</tbody></table>`;
  pagEl.innerHTML = `<span>Page ${payload.page} of ${payload.pages}</span><span>${paginationButtons('payments', payload.page, payload.pages)}</span>`;
  bindPagination(pagEl, 'payments', payload.page, payload.pages);
}

async function loadAiUsage() {
  const data = await admFetch('ai-usage.php?page=' + state.aiPage);
  renderAiTable(data.usage);
}

function renderAiTable(payload) {
  const el = document.querySelector('[data-adm-ai-table]');
  const pagEl = document.querySelector('[data-adm-ai-pagination]');
  const items = payload.items || [];
  if (!items.length) {
    el.innerHTML = '<p class="adm-empty">No AI calls logged yet.</p>';
    pagEl.innerHTML = '';
    return;
  }
  el.innerHTML = `<table class="adm-table"><thead><tr><th>When</th><th>Service</th><th>Provider</th><th>Model</th><th>User</th><th>Tokens</th><th>USD</th></tr></thead><tbody>${items.map((r) => `<tr>
    <td>${fmtDate(r.created_at)}</td>
    <td>${esc(r.service)}</td>
    <td>${esc(r.provider)}</td>
    <td>${esc(r.model || '—')}</td>
    <td>${esc(r.user_email || '—')}</td>
    <td>${r.tokens_in}/${r.tokens_out}</td>
    <td>${fmtUsd(r.cost_usd)}</td>
  </tr>`).join('')}</tbody></table>`;
  pagEl.innerHTML = `<span>Page ${payload.page} of ${payload.pages}</span><span>${paginationButtons('ai', payload.page, payload.pages)}</span>`;
  bindPagination(pagEl, 'ai', payload.page, payload.pages);
}

function paginationButtons(kind, page, pages) {
  const prev = page > 1 ? `<button type="button" class="adm-btn-sm" data-page-kind="${kind}" data-page="${page - 1}">Prev</button>` : '';
  const next = page < pages ? `<button type="button" class="adm-btn-sm" data-page-kind="${kind}" data-page="${page + 1}">Next</button>` : '';
  return prev + next;
}

function bindPagination(el, kind, page, pages) {
  el.querySelectorAll('[data-page-kind]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const next = Number(btn.getAttribute('data-page'));
      if (kind === 'users') { state.usersPage = next; await loadUsers(); }
      if (kind === 'payments') { state.paymentsPage = next; await loadPayments(); }
      if (kind === 'ai') { state.aiPage = next; await loadAiUsage(); }
    });
  });
}

function renderSystem(system) {
  const el = document.querySelector('[data-adm-system]');
  if (!el || !system) return;
  const row = (label, ok) => `<div class="adm-status-row"><span>${esc(label)}</span><span class="adm-badge ${ok ? 'adm-badge--ok' : 'adm-badge--danger'}">${ok ? 'Connected' : 'Missing'}</span></div>`;
  el.innerHTML = [
    row('OpenAI (' + (system.openai_model || 'model') + ')', system.openai),
    row('Flutterwave billing', system.flutterwave),
    row('Tavily research', system.tavily),
    `<div class="adm-status-row"><span>Environment</span><span>${esc(system.app_env)}</span></div>`,
  ].join('');
}

boot().catch((err) => {
  document.querySelector('[data-adm-updated]').textContent = err.message || 'Failed to load admin dashboard.';
});
