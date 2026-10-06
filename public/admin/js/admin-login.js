import { admFetch } from './admin-shared.js?v=20260830adm';

const form = document.querySelector('[data-adm-login]');
const errorEl = document.querySelector('[data-adm-error]');

try {
  await admFetch('me.php');
  location.replace('/admin/');
} catch {
  // not signed in
}

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  errorEl.hidden = true;

  const fd = new FormData(form);
  try {
    await admFetch('login.php', {
      method: 'POST',
      body: JSON.stringify({
        email: fd.get('email'),
        password: fd.get('password'),
      }),
    });
    location.replace('/admin/');
  } catch (err) {
    errorEl.textContent = err.message || 'Sign in failed.';
    errorEl.hidden = false;
  }
});
