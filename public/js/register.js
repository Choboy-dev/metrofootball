import {
  appendReturnQuery,
  bindGoogleAuthLink,
  captureReturnFromUrl,
  getPlan,
  getReturnPath,
  hideAuthLoading,
  initPasswordToggles,
  pageUrl,
  postJson,
  setBusy,
  setPlan,
  showAuthLoading,
  showFormError,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const form = document.querySelector('[data-register-form]');
const errorEl = document.querySelector('[data-form-error]');
const plan = setPlan(getPlan());
const planBadge = document.querySelector('[data-plan-badge]');
const googleLink = document.querySelector('[data-google-auth]');

initPasswordToggles();
captureReturnFromUrl();
bindGoogleAuthLink(googleLink, plan, 'signup');

if (planBadge) {
  planBadge.textContent = plan === 'premium' ? 'Premium plan' : 'Free plan';
}

const loginLink = document.querySelector('[data-login-link]');
if (loginLink) {
  loginLink.href = appendReturnQuery(pageUrl(`login?plan=${plan}`));
}

if (!reduceMotion) {
  import('./motion.js?v=20260901perf')
    .then(({ animate }) => {
      const card = document.querySelector('.auth-card');
      if (card) {
        animate(card, { opacity: [0, 1], y: [18, 0] }, { duration: 0.45, easing: [0.17, 0.55, 0.55, 1] });
      }
    })
    .catch(() => {});
}

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  showFormError(errorEl, '');

  const submit = form.querySelector('[type="submit"]');
  const email = form.querySelector('[name="email"]')?.value?.trim() || '';
  const password = form.querySelector('[name="password"]')?.value || '';

  setBusy(submit, true, 'Creating account');
  showAuthLoading('Creating your account', 'Setting things up for you.');
  try {
    const data = await postJson('auth/register.php', { email, password, plan });
    sessionStorage.setItem('mf_otp_email', data.email);
    const verifyUrl = appendReturnQuery(
      pageUrl(`verify-otp?email=${encodeURIComponent(data.email)}&plan=${plan}`)
    );
    window.location.href = verifyUrl;
  } catch (error) {
    hideAuthLoading();
    showFormError(errorEl, error.message);
    setBusy(submit, false);
  }
});
