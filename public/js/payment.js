import { animate } from './motion.js';
import { apiUrl, applyAuthRedirect, captureReturnFromUrl, getReturnPath, isPremiumActive, loginUrlWithReturn, pageUrl, postJson, setBusy, showFormError } from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const errorEl = document.querySelector('[data-form-error]');
const noticeEl = document.querySelector('[data-form-notice]');
const amountEl = document.querySelector('[data-pay-amount]');
const periodEl = document.querySelector('[data-pay-period]');
const noteEl = document.querySelector('[data-pay-note]');
const payBtn = document.querySelector('[data-pay-start]');
const cycleButtons = [...document.querySelectorAll('[data-cycle]')];

let cycle = 'yearly';

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

  if (cycle === 'monthly') {
    if (amountEl) amountEl.textContent = '£2.50';
    if (periodEl) periodEl.textContent = '/ month';
    if (noteEl) noteEl.textContent = 'Billed every month';
  } else {
    if (amountEl) amountEl.textContent = '£2';
    if (periodEl) periodEl.textContent = '/ month';
    if (noteEl) noteEl.textContent = '£22/year billed yearly · 1 month free';
  }
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

  // Already premium: go straight to the intended page.
  if (isPremiumActive(data.user)) {
    applyAuthRedirect(getReturnPath() || data.redirect || '/live');
    return null;
  }

  const emailEl = document.querySelector('[data-user-email]');
  if (emailEl) emailEl.textContent = data.user.email;

  if (data.notice) showNotice(data.notice);

  if (data.billing && data.billing.flutterwave_ready === false) {
    showFormError(errorEl, 'Flutterwave is not configured yet. Add FLW_SECRET_KEY in config/.env');
  }

  return data;
}

cycleButtons.forEach((btn) => {
  btn.addEventListener('click', () => applyCycle(btn.dataset.cycle || 'yearly'));
});

applyCycle('yearly');

if (!reduceMotion) {
  const card = document.querySelector('.auth-card');
  if (card) {
    animate(card, { opacity: [0, 1], y: [18, 0] }, { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] });
  }
}

await loadSession();

payBtn?.addEventListener('click', async () => {
  showFormError(errorEl, '');
  setBusy(payBtn, true);
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
    showFormError(errorEl, error.message);
    setBusy(payBtn, false);
  }
});
