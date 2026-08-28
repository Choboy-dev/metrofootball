import { animate } from './motion.js';
import {
  applyAuthRedirect,
  captureReturnFromUrl,
  getPlan,
  getReturnPath,
  pageUrl,
  postJson,
  setBusy,
  showFormError,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const form = document.querySelector('[data-otp-form]');
const errorEl = document.querySelector('[data-form-error]');
const emailEl = document.querySelector('[data-otp-email]');
const resendBtn = document.querySelector('[data-resend-otp]');
const params = new URLSearchParams(window.location.search);

const email = (params.get('email') || sessionStorage.getItem('mf_otp_email') || '').trim().toLowerCase();
const plan = getPlan();

captureReturnFromUrl();

if (!email) {
  window.location.href = pageUrl(`register?plan=${plan}`);
}

if (emailEl) {
  emailEl.textContent = email;
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
  const code = (form.querySelector('[name="code"]')?.value || '').replace(/\s+/g, '');

  setBusy(submit, true);
  try {
    const data = await postJson('auth/verify-otp.php', {
      email,
      code,
      next: getReturnPath(),
    });
    sessionStorage.removeItem('mf_otp_email');
    applyAuthRedirect(data.redirect || '/live');
  } catch (error) {
    showFormError(errorEl, error.message);
  } finally {
    setBusy(submit, false);
  }
});

resendBtn?.addEventListener('click', async () => {
  showFormError(errorEl, '');
  setBusy(resendBtn, true);
  try {
    await postJson('auth/resend-otp.php', { email });
    showFormError(errorEl, '');
    resendBtn.querySelector('.mf-btn__label').textContent = 'Code sent';
    setTimeout(() => {
      resendBtn.querySelector('.mf-btn__label').textContent = 'Send code again';
      setBusy(resendBtn, false);
    }, 1600);
  } catch (error) {
    showFormError(errorEl, error.message);
    setBusy(resendBtn, false);
  }
});
