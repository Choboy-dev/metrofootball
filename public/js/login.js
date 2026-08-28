import { animate } from './motion.js';
import {
  applyAuthRedirect,
  appendReturnQuery,
  captureReturnFromUrl,
  getPlan,
  getReturnPath,
  googleStartUrl,
  initPasswordToggles,
  pageUrl,
  postJson,
  setBusy,
  setPlan,
  showFormError,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const form = document.querySelector('[data-login-form]');
const errorEl = document.querySelector('[data-form-error]');
const plan = setPlan(getPlan());
const googleLink = document.querySelector('[data-google-auth]');
const registerLink = document.querySelector('[data-register-link]');
const params = new URLSearchParams(window.location.search);
const queryError = params.get('error');
const reason = params.get('reason');

initPasswordToggles();
captureReturnFromUrl();

if (reason === 'idle') {
  showFormError(errorEl, 'Your session ended after a period of inactivity. Please sign in again.');
} else if (queryError) {
  showFormError(errorEl, queryError);
}

if (googleLink) {
  googleLink.href = googleStartUrl(plan, 'login', getReturnPath());
}

if (registerLink) {
  registerLink.href = appendReturnQuery(pageUrl('join'));
}

if (!reduceMotion) {
  const card = document.querySelector('.auth-card');
  if (card) {
    animate(card, { opacity: [0, 1], y: [18, 0] }, { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] });
  }
}

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  showFormError(errorEl, '');

  const submit = form.querySelector('[type="submit"]');
  const email = form.querySelector('[name="email"]')?.value?.trim() || '';
  const password = form.querySelector('[name="password"]')?.value || '';

  setBusy(submit, true);
  try {
    const data = await postJson('auth/login.php', {
      email,
      password,
      next: getReturnPath(),
    });
    applyAuthRedirect(data.redirect || '/live');
  } catch (error) {
    showFormError(errorEl, error.message);
  } finally {
    setBusy(submit, false);
  }
});
