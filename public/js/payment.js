import { animate } from './motion.js?v=20260901perf';
import {
  apiUrl,
  applyAuthRedirect,
  captureReturnFromUrl,
  clearReturnPath,
  getReturnPath,
  hideAuthLoading,
  isPremiumActive,
  loginUrlWithReturn,
  postJson,
  setBusy,
  showAuthLoading,
  showFormError,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const errorEl = document.querySelector('[data-form-error]');
const noticeEl = document.querySelector('[data-form-notice]');
const amountEl = document.querySelector('[data-pay-amount]');
const periodEl = document.querySelector('[data-pay-period]');
const noteEl = document.querySelector('[data-pay-note]');
const payBtn = document.querySelector('[data-pay-start]');
const cycleButtons = [...document.querySelectorAll('[data-cycle]')];

let cycle = 'yearly';
let billing = null;

function showNotice(message) {
  if (!noticeEl) return;
  noticeEl.hidden = !message;
  noticeEl.textContent = message || '';
}

function applyCycle(next) {
  cycle = next === 'monthly' ? 'monthly' : 'yearly';
  cycleButtons.forEach((btn) => {
    btn.classList.toggle('is-active', btn.dataset.cycle === cycle);
  });

  const plan = billing?.[cycle];
  if (!plan) return;

  if (amountEl) amountEl.textContent = plan.display_amount || plan.label;
  if (periodEl) periodEl.textContent = plan.display_period || plan.period || '/ month';
  if (noteEl) noteEl.textContent = plan.note || '';
}

async function loadSession() {
  captureReturnFromUrl();
  const response = await fetch(apiUrl('auth/me.php'), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const data = await response.json();

  if (!response.ok || !data.authenticated) {
    window.location.href = loginUrlWithReturn();
    return null;
  }

  if (!data.user?.email_verified) {
    applyAuthRedirect('/verify-email');
    return null;
  }

  if (isPremiumActive(data.user)) {
    applyAuthRedirect(getReturnPath() || data.redirect || '/live');
    return null;
  }

  const emailEl = document.querySelector('[data-user-email]');
  if (emailEl) emailEl.textContent = data.user.email;

  if (data.notice) showNotice(data.notice);

  if (data.subscription_notice) {
    showNotice(data.subscription_notice);
  }

  if (data.billing && data.billing.flutterwave_ready === false) {
    showFormError(errorEl, 'Flutterwave is not configured yet. Add FLW_SECRET_KEY in config/.env');
  }

  if (data.billing?.error) {
    showFormError(errorEl, data.billing.error);
  }

  billing = data.billing || null;
  applyCycle(cycle);

  return data;
}

cycleButtons.forEach((btn) => {
  btn.addEventListener('click', () => applyCycle(btn.dataset.cycle || 'yearly'));
});

if (!reduceMotion) {
  const card = document.querySelector('.auth-card');
  if (card) {
    animate(card, { opacity: [0, 1], y: [18, 0] }, { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] });
  }
}

await loadSession();

document.querySelector('[data-back-plans]')?.addEventListener('click', async (event) => {
  event.preventDefault();
  try {
    const data = await postJson('billing/abandon-checkout.php', {});
    clearReturnPath();
    window.location.href = pageUrl(String(data.redirect || 'join').replace(/^\//, ''));
  } catch {
    window.location.href = pageUrl('join');
  }
});

payBtn?.addEventListener('click', async () => {
  showFormError(errorEl, '');
  showAuthLoading(
    'Opening checkout',
    'We are connecting you securely. This may take a few seconds.'
  );
  setBusy(payBtn, true, 'Opening checkout');
  try {
    const data = await postJson('billing/initiate.php', {
      cycle,
      next: getReturnPath(),
    });
    if (data.redirect) {
      applyAuthRedirect(data.redirect);
      return;
    }
    if (!data.checkout_url) {
      throw new Error('No Flutterwave checkout link returned.');
    }
    window.location.href = data.checkout_url;
  } catch (error) {
    hideAuthLoading();
    showFormError(errorEl, error.message);
    setBusy(payBtn, false);
  }
});
