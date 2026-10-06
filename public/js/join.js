import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  applyAuthRedirect,
  appendReturnQuery,
  bindSignOutButtons,
  captureReturnFromUrl,
  fetchSession,
  getReturnPath,
  hideAuthLoading,
  isPremiumActive,
  pageUrl,
  postJson,
  setBusy,
  showAuthLoading,
} from './auth.js?v=20260907pub';

const CACHE = '20260828f';
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let motionPromise = null;

function loadMotion() {
  if (!motionPromise) {
    motionPromise = import('./motion.js?v=20260901perf')
      .then((mod) => mod)
      .catch(() => null);
  }
  return motionPromise;
}

function showJoinContent() {
  const intro = document.querySelector('.join-intro');
  const items = [
    ...document.querySelectorAll('.compare-reveal'),
    ...document.querySelectorAll('.faq-reveal'),
  ];
  if (intro) {
    intro.style.opacity = '1';
    intro.style.transform = 'none';
  }
  items.forEach((el) => {
    el.style.opacity = '1';
    el.style.transform = 'none';
  });
}

function initJoinPricing() {
  const root = document.querySelector('[data-join-pricing]');
  const toggle = document.querySelector('[data-billing-toggle]');
  const monthlyLabel = document.querySelector('[data-billing-label="monthly"]');
  const yearlyLabel = document.querySelector('[data-billing-label="yearly"]');

  if (!toggle || !root) return;

  const priceNodes = [...root.querySelectorAll('[data-price]')];
  const periodNodes = [...root.querySelectorAll('[data-period]')];
  const noteNodes = [...root.querySelectorAll('[data-billing-note]')];
  const hubPriceNodes = [...root.querySelectorAll('[data-hub-price]')];
  const saveNodes = [...root.querySelectorAll('[data-save-pct]')];

  const applyBilling = (isYearly) => {
    toggle.setAttribute('aria-checked', String(isYearly));
    monthlyLabel?.classList.toggle('is-active', !isYearly);
    yearlyLabel?.classList.toggle('is-active', isYearly);

    const key = isYearly ? 'yearly' : 'monthly';
    priceNodes.forEach((node) => {
      node.textContent = node.getAttribute(`data-${key}`) || node.textContent;
    });
    periodNodes.forEach((node) => {
      node.textContent = node.getAttribute(`data-${key}`) || node.textContent;
    });
    noteNodes.forEach((node) => {
      const note = node.getAttribute(`data-${key}`) || '';
      node.textContent = note;
      node.hidden = !note;
    });
    hubPriceNodes.forEach((node) => {
      node.textContent = node.getAttribute(`data-${key}`) || node.textContent;
    });
    saveNodes.forEach((node) => {
      const save = node.getAttribute(`data-${key}`);
      if (save) node.textContent = `${save}%`;
    });
  };

  applyBilling(toggle.getAttribute('aria-checked') === 'true');

  toggle.addEventListener('click', () => {
    const next = toggle.getAttribute('aria-checked') !== 'true';
    applyBilling(next);
  });
}

function setPanelHeight(panel, height) {
  panel.style.height = typeof height === 'number' ? `${height}px` : height;
}

async function openFaqItem(item) {
  const panel = item.querySelector('.faq-item__panel');
  const trigger = item.querySelector('.faq-item__trigger');
  if (!panel || !trigger) return;

  item.classList.add('is-open');
  trigger.setAttribute('aria-expanded', 'true');
  panel.hidden = false;

  if (reduceMotion) {
    setPanelHeight(panel, 'auto');
    panel.style.opacity = '1';
    return;
  }

  const motion = await loadMotion();
  if (!motion?.animate) {
    setPanelHeight(panel, 'auto');
    panel.style.opacity = '1';
    return;
  }

  const targetHeight = panel.scrollHeight;
  setPanelHeight(panel, 0);
  panel.style.opacity = '0';

  motion
    .animate(
      panel,
      { height: [0, targetHeight], opacity: [0, 1] },
      { duration: 0.3, easing: [0.4, 0, 0.2, 1] }
    )
    .then(() => {
      setPanelHeight(panel, 'auto');
    });
}

async function closeFaqItem(item) {
  const panel = item.querySelector('.faq-item__panel');
  const trigger = item.querySelector('.faq-item__trigger');
  if (!panel || !trigger) return;

  item.classList.remove('is-open');
  trigger.setAttribute('aria-expanded', 'false');

  if (reduceMotion) {
    setPanelHeight(panel, 0);
    panel.style.opacity = '0';
    panel.hidden = true;
    return;
  }

  const motion = await loadMotion();
  if (!motion?.animate) {
    setPanelHeight(panel, 0);
    panel.style.opacity = '0';
    panel.hidden = true;
    return;
  }

  const startHeight = panel.offsetHeight;
  setPanelHeight(panel, startHeight);

  motion
    .animate(
      panel,
      { height: [startHeight, 0], opacity: [1, 0] },
      { duration: 0.3, easing: [0.4, 0, 0.2, 1] }
    )
    .then(() => {
      panel.hidden = true;
      setPanelHeight(panel, 0);
    });
}

function initFaqAccordion() {
  const root = document.querySelector('[data-faq-accordion]');
  if (!root) return;

  const items = [...root.querySelectorAll('.faq-item')];

  items.forEach((item) => {
    const trigger = item.querySelector('.faq-item__trigger');
    const panel = item.querySelector('.faq-item__panel');
    if (!trigger || !panel) return;

    if (item.classList.contains('is-open')) {
      panel.hidden = false;
      setPanelHeight(panel, 'auto');
      panel.style.opacity = '1';
    }

    trigger.addEventListener('click', () => {
      const isOpen = item.classList.contains('is-open');

      if (isOpen) {
        closeFaqItem(item);
        return;
      }

      items.forEach((other) => {
        if (other !== item && other.classList.contains('is-open')) {
          closeFaqItem(other);
        }
      });

      openFaqItem(item);
    });
  });
}

async function revealJoin() {
  const intro = document.querySelector('.join-intro');
  const gridItems = [...document.querySelectorAll('.compare-reveal')];
  const faqItems = [...document.querySelectorAll('.faq-reveal')];

  if (reduceMotion) {
    showJoinContent();
    return;
  }

  const motion = await loadMotion();
  if (!motion?.animate || !motion?.inView) {
    showJoinContent();
    return;
  }

  const { animate, inView, stagger } = motion;

  if (intro) {
    animate(
      intro,
      { opacity: [0, 1], y: [20, 0] },
      { duration: 0.55, easing: [0.17, 0.55, 0.55, 1] }
    );
  }

  const grid = document.querySelector('.compare-grid') || document.querySelector('.join-mobile-plans');
  if (gridItems.length && grid) {
    inView(
      grid,
      () => {
        animate(
          gridItems,
          { opacity: [0, 1], y: [14, 0] },
          {
            duration: 0.45,
            delay: stagger(0.03, { startDelay: 0.05 }),
            easing: [0.17, 0.55, 0.55, 1],
          }
        );
      },
      { amount: 0.12, margin: '0px 0px -8% 0px' }
    );
  }

  const faq = document.querySelector('.join-faq');
  if (faqItems.length && faq) {
    inView(
      faq,
      () => {
        animate(
          faqItems,
          { opacity: [0, 1], y: [14, 0] },
          {
            duration: 0.45,
            delay: stagger(0.08, { startDelay: 0.05 }),
            easing: [0.17, 0.55, 0.55, 1],
          }
        );
      },
      { amount: 0.15, margin: '0px 0px -8% 0px' }
    );
  }
}

function setCtaLabel(cta, label) {
  const labelEl = cta.querySelector('.mf-btn__label');
  if (labelEl) labelEl.textContent = label;
}

async function initAuthAwareJoin() {
  captureReturnFromUrl();
  const session = await fetchSession();
  applyAuthNav(session);
  bindSignOutButtons();

  const returnPath = getReturnPath();

  if (session?.authenticated && isPremiumActive(session.user)) {
    applyAuthRedirect(returnPath || session.redirect || '/live');
    return;
  }

  const ctas = [...document.querySelectorAll('[data-plan-cta]')];
  if (!ctas.length) return;

  ctas.forEach((cta) => {
    const plan = cta.getAttribute('data-plan-cta') === 'premium' ? 'premium' : 'free';
    const registerUrl = appendReturnQuery(pageUrl(`register?plan=${plan}`));
    if (!session?.authenticated) {
      cta.setAttribute('href', registerUrl);
      return;
    }

    if (plan === 'premium') {
      cta.setAttribute('href', appendReturnQuery(pageUrl('payment')));
      setCtaLabel(cta, 'Upgrade');
    } else {
      const destination = returnPath || session.redirect || '/live';
      cta.setAttribute('href', pageUrl(String(destination).replace(/^\//, '')));
      setCtaLabel(cta, 'Continue free');
    }
  });

  ctas.forEach((cta) => {
    cta.addEventListener('click', async (event) => {
      const plan = cta.getAttribute('data-plan-cta') === 'premium' ? 'premium' : 'free';

      if (!session?.authenticated) {
        showAuthLoading(
          'Opening sign up',
          plan === 'premium' ? 'Taking you to Premium sign up.' : 'Taking you to create your account.'
        );
        return;
      }

      event.preventDefault();
      showAuthLoading(
        plan === 'premium' ? 'Opening payment' : 'Updating your plan',
        'Please wait while we get the next step ready.'
      );
      setBusy(cta, true, plan === 'premium' ? 'Opening payment' : 'Please wait');

      try {
        const result = await postJson('billing/select-plan', {
          plan,
          next: getReturnPath(),
        });
        applyAuthRedirect(result.redirect || (plan === 'premium' ? '/payment' : '/live'));
      } catch (error) {
        hideAuthLoading();
        setBusy(cta, false);
        window.alert(error.message || 'Could not update your plan.');
      }
    });
  });
}

try {
  initSiteNav();
  initJoinPricing();
  initFaqAccordion();
  initAuthAwareJoin();
  revealJoin();
} catch (error) {
  console.error('Join page boot failed:', error);
  showJoinContent();
}
