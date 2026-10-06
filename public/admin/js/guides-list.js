import { admFetch, esc, fmtDate } from './admin-shared.js?v=20260902adm4';

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

  document.querySelector('[data-gl-new]')?.addEventListener('click', () => {
    location.href = '/admin/guide-edit.html';
  });

  document.querySelector('[data-gl-back]')?.addEventListener('click', () => {
    location.href = '/admin/';
  });

  await loadPosts();
}

async function loadPosts() {
  const wrap = document.querySelector('[data-gl-table]');
  if (!wrap) return;
  wrap.innerHTML = '<p class="adm-muted">Loading guides…</p>';

  try {
    const data = await admFetch('guides.php');
    const posts = data.posts || [];
    if (!posts.length) {
      wrap.innerHTML = '<p class="adm-muted">No guides yet. Create your first post.</p>';
      return;
    }

    wrap.innerHTML = `
      <table class="adm-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>URL</th>
            <th>Status</th>
            <th>Read time</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${posts.map((p) => {
            const isStatic = p.source === 'static' || p.editable === false;
            const statusLabel = isStatic ? 'published (static)' : p.status;
            const statusClass = p.status === 'published' || isStatic ? 'adm-badge--ok' : '';
            return `
            <tr>
              <td><strong>${esc(p.title)}</strong><br><span class="adm-muted">${esc(p.category)} · ${esc(p.schema_type || 'Article')}</span></td>
              <td><code>/fpl-guides/${esc(p.slug)}</code></td>
              <td><span class="adm-badge ${statusClass}">${esc(statusLabel)}</span></td>
              <td>${esc(String(p.read_mins))} min</td>
              <td>${p.updated_at ? esc(fmtDate(p.updated_at)) : '—'}</td>
              <td class="adm-table__actions">
                ${isStatic ? '' : `<a class="adm-btn-sm" href="/admin/guide-edit.html?id=${p.id}">Edit</a>`}
                ${p.status === 'published' || isStatic ? `<a class="adm-btn-sm" href="/fpl-guides/${esc(p.slug)}" target="_blank" rel="noopener">View</a>` : ''}
                ${isStatic ? '' : `<button type="button" class="adm-btn-sm adm-btn-sm--danger" data-delete="${p.id}">Delete</button>`}
              </td>
            </tr>
          `;
          }).join('')}
        </tbody>
      </table>
    `;

    wrap.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-delete');
        if (!confirm('Delete this guide permanently?')) return;
        await admFetch(`guides.php?id=${id}`, { method: 'DELETE' });
        await loadPosts();
      });
    });
  } catch (err) {
    wrap.innerHTML = `<p class="adm-error">${esc(err.message || 'Could not load guides')}</p>`;
  }
}

boot();
