import { apiUrl, pageUrl, postJson } from './auth.js';

async function guardPage(allowed) {
  try {
    const response = await fetch(apiUrl('auth/me.php'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    if (!response.ok || !data.authenticated) {
      window.location.href = pageUrl('login');
      return null;
    }

    const redirect = String(data.redirect || '/live').replace(/^\//, '');

    // Unpaid premium always pays first (even before linking a team).
    if (allowed !== 'payment' && redirect === 'payment') {
      window.location.href = pageUrl('payment');
      return null;
    }

    if (allowed !== 'link-team' && redirect === 'link-team') {
      window.location.href = pageUrl('link-team');
      return null;
    }

    if (allowed === 'payment' && (redirect === 'dashboard' || redirect === 'live' || redirect === 'link-team')) {
      window.location.href = pageUrl(redirect);
      return null;
    }

    if (allowed === 'dashboard' && redirect === 'live') {
      window.location.href = pageUrl('live');
      return null;
    }

    return data.user;
  } catch {
    window.location.href = pageUrl('login');
    return null;
  }
}

const page = document.body.dataset.authPage;
const user = await guardPage(page);
const emailEl = document.querySelector('[data-user-email]');
if (user && emailEl) {
  emailEl.textContent = user.email;
}

document.querySelector('[data-logout]')?.addEventListener('click', async (event) => {
  event.preventDefault();
  try {
    await postJson('auth/logout.php', {});
  } catch {
    // still leave
  }
  window.location.href = pageUrl('login');
});
