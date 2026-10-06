import { animate } from './motion.js?v=20260901perf';
import {
  applyAuthRedirect,
  captureReturnFromUrl,
  fetchSession,
  getReturnPath,
  hideAuthLoading,
  pageUrl,
  postJson,
  redirectToLogin,
  setBusy,
  showAuthLoading,
  showFormError,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const emailEl = document.querySelector('[data-verify-email]');
const errorEl = document.querySelector('[data-form-error]');
const noticeEl = document.querySelector('[data-form-notice]');
const resendBtn = document.querySelector('[data-resend-confirmation]');

captureReturnFromUrl();

function showNotice(message) {
  if (!noticeEl) return;
  noticeEl.hidden = !message;
  noticeEl.textContent = message || '';
}

async function boot() {
  const session = await fetchSession();
  if (!session?.authenticated) {
    redirectToLogin();
    return;
  }

  if (session.user?.email_verified) {
    applyAuthRedirect(session.redirect || getReturnPath() || '/live');
    return;
  }

  if (emailEl) {
    emailEl.textContent = session.user?.email || '';
  }

  if (!reduceMotion) {
    const card = document.querySelector('.auth-card');
    if (card) {
      animate(card, { opacity: [0, 1], y: [18, 0] }, { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] });
    }
  }
}

resendBtn?.addEventListener('click', async () => {
  showFormError(errorEl, '');
  showNotice('');
  setBusy(resendBtn, true, 'Sending');
  showAuthLoading('Sending confirmation email', 'This only takes a moment.');
  try {
    const data = await postJson('auth/resend-confirmation.php', {});
    hideAuthLoading();
    showNotice(data.message || 'Confirmation email sent.');
  } catch (error) {
    hideAuthLoading();
    showFormError(errorEl, error.message);
  } finally {
    setBusy(resendBtn, false);
  }
});

boot();
