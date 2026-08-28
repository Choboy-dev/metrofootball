import { animate } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav } from './site-nav.js';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  redirectToLogin,
  pageUrl,
} from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function isPremiumActive(user) {
  return user?.plan === 'premium' && user?.subscription_status === 'active';
}

function upgradePath(user) {
  if (user?.plan === 'premium' && user?.subscription_status !== 'active') {
    return pageUrl('payment');
  }
  return pageUrl('join');
}

function applyGate(session) {
  const freeAccess = document.body.dataset.toolAccess === 'free';
  const lock = document.querySelector('[data-premium-lock]');
  const unlocked = document.querySelector('[data-premium-unlocked]');
  const freeTool = document.querySelector('[data-free-tool]');
  const cta = document.querySelector('[data-upgrade-cta]');
  const user = session?.user;
  const premium = isPremiumActive(user);

  if (cta) {
    cta.setAttribute('href', upgradePath(user));
  }

  if (freeAccess) {
    if (lock) lock.hidden = true;
    if (unlocked) unlocked.hidden = true;
    if (freeTool) freeTool.hidden = false;
    const reveal = freeTool;
    if (reveal && !reduceMotion) {
      animate(reveal, { opacity: [0, 1], y: [14, 0] }, { duration: 0.4, easing: [0.17, 0.55, 0.55, 1] });
    }
    return;
  }

  if (lock) lock.hidden = premium;
  if (unlocked) unlocked.hidden = !premium;

  const reveal = premium ? unlocked : lock;
  if (reveal && !reduceMotion) {
    animate(reveal, { opacity: [0, 1], y: [14, 0] }, { duration: 0.4, easing: [0.17, 0.55, 0.55, 1] });
  }
}

async function boot() {
  initSiteNav();
  bindSignOutButtons();
  initTeamTabs();

  const session = await fetchSession();
  const freeAccess = document.body.dataset.toolAccess === 'free';

  if (!session?.authenticated && !freeAccess) {
    redirectToLogin();
    return;
  }

  applyAuthNav(session || { authenticated: false });
  applyGate(session || { authenticated: false });

  const tabs = document.querySelector('[data-team-tabs]');
  if (tabs && !reduceMotion) {
    animate(tabs, { opacity: [0, 1], y: [-6, 0] }, { duration: 0.35, easing: [0.17, 0.55, 0.55, 1] });
  }
}

boot();
