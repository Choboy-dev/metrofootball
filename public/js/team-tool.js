import { animate } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import { initTeamTabs } from './team-tabs.js';
import {
  applyAuthNav,
  applyPremiumGate,
  bindSignOutButtons,
  fetchSession,
  redirectToLogin,
  pageUrl,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export { isPremiumActive } from './auth.js?v=20260907pub';

function applyGate(session) {
  applyPremiumGate(session, {
    animateReveal: reduceMotion ? null : animate,
  });
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
