import { animate } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initLinkTargets } from './link-targets.js';
import { apiUrl, bindAuthToolLinks, fetchSession } from './auth.js';
import { buildMobileMenuNav, initMobileAccordions } from './mobile-menu.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export async function hydrateGwTipsLabels() {
  const labels = document.querySelectorAll('[data-nav-gw-tips-label]');
  const links = document.querySelectorAll('[data-nav-gw-tips-link]');
  if (!labels.length && !links.length) return;

  try {
    const res = await fetch(apiUrl('fpl/gameweek-tips?meta=1'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await res.json();
    const gw = Number(data?.upcoming_gw);
    const text = Number.isFinite(gw) && gw > 0 ? `Gameweek ${gw} Tips` : 'Gameweek Tips';
    const path = data?.upcoming_path || (Number.isFinite(gw) ? `./fpl-gameweek-${gw}-tips` : '#');
    labels.forEach((label) => {
      label.textContent = text;
    });
    links.forEach((link) => {
      link.setAttribute('href', path);
    });
  } catch {
    // Keep static fallback label and href.
  }
}

export function scheduleGwTipsHydration() {
  const run = () => {
    hydrateGwTipsLabels();
  };
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 3000 });
  } else {
    window.setTimeout(run, 900);
  }
}

function pageDefersGwTips(options = {}) {
  if (typeof options.deferGwTips === 'boolean') {
    return options.deferGwTips;
  }
  return Boolean(document.body?.dataset?.tool || document.body?.dataset?.authPage);
}

export function initSiteNav(options = {}) {
  const sessionOverride = options.session ?? null;
  const deferGwTips = pageDefersGwTips(options);

  initLinkTargets();
  if (!deferGwTips) {
    hydrateGwTipsLabels();
  }

  const header = document.querySelector('[data-header]');
  const mobileMenu = document.querySelector('[data-mobile-menu]');
  const mobileInner = document.querySelector('[data-mobile-inner]');
  const mobileNav = document.querySelector('[data-mobile-nav]');
  const mobileToggle = document.querySelector('[data-mobile-toggle]');
  const menuIcon = document.querySelector('[data-menu-icon]');
  const items = [...document.querySelectorAll('[data-nav-item]')];

  if (!header) return;

  const onScroll = () => {
    header.classList.toggle('is-scrolled', window.scrollY > 10);
  };
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  function closeAllPanels(except = null) {
    items.forEach((item) => {
      if (item === except) return;
      const trigger = item.querySelector('[data-nav-trigger]');
      const panel = item.querySelector('[data-nav-panel]');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
      if (panel) panel.hidden = true;
    });
  }

  items.forEach((item) => {
    const trigger = item.querySelector('[data-nav-trigger]');
    const panel = item.querySelector('[data-nav-panel]');
    if (!trigger || !panel) return;

    let closeTimer = null;

    const open = () => {
      clearTimeout(closeTimer);
      closeTimer = null;
      closeAllPanels(item);
      trigger.setAttribute('aria-expanded', 'true');
      panel.hidden = false;
      if (!reduceMotion) {
        animate(panel, { opacity: [0, 1], y: [6, 0] }, { duration: 0.18, easing: [0.17, 0.55, 0.55, 1] });
      }
    };

    const close = () => {
      clearTimeout(closeTimer);
      closeTimer = null;
      trigger.setAttribute('aria-expanded', 'false');
      panel.hidden = true;
    };

    const scheduleClose = () => {
      clearTimeout(closeTimer);
      closeTimer = setTimeout(close, 160);
    };

    item.addEventListener('mouseenter', open);
    item.addEventListener('mouseleave', scheduleClose);
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      const expanded = trigger.getAttribute('aria-expanded') === 'true';
      if (expanded) close();
      else open();
    });
    trigger.addEventListener('focus', open);
    item.addEventListener('focusout', (event) => {
      if (!item.contains(event.relatedTarget)) scheduleClose();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeAllPanels();
      if (mobileMenu && !mobileMenu.hidden) closeMobile();
    }
  });

  let mobileOpen = false;

  function openMobile() {
    if (!mobileMenu) return;
    closeAllPanels();
    mobileOpen = true;
    mobileMenu.hidden = false;
    document.body.classList.add('is-mobile-menu-open');
    mobileToggle?.setAttribute('aria-expanded', 'true');
    menuIcon?.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    if (!reduceMotion && mobileInner) {
      animate(
        mobileInner,
        { opacity: [0, 1], y: [8, 0] },
        { duration: 0.24, easing: [0.17, 0.55, 0.55, 1] }
      );
    }
  }

  function closeMobile() {
    if (!mobileMenu) return;
    mobileOpen = false;
    mobileToggle?.setAttribute('aria-expanded', 'false');
    menuIcon?.classList.remove('is-open');
    document.body.classList.remove('is-mobile-menu-open');
    document.body.style.overflow = '';
    if (reduceMotion || !mobileInner) {
      mobileMenu.hidden = true;
      return;
    }
    animate(
      mobileInner,
      { opacity: 0, y: 8 },
      { duration: 0.18, easing: [0.4, 0, 1, 1] }
    ).finished.then(() => {
      if (!mobileOpen) mobileMenu.hidden = true;
    });
  }

  mobileToggle?.addEventListener('click', () => {
    if (mobileMenu?.hidden) openMobile();
    else closeMobile();
  });

  if (mobileNav) {
    buildMobileMenuNav(mobileNav);
    initMobileAccordions(mobileNav, { reduceMotion });
    initLinkTargets(mobileNav);
    const sessionPromise = sessionOverride != null
      ? Promise.resolve(sessionOverride)
      : fetchSession();
    sessionPromise.then((session) => {
      bindAuthToolLinks(() => session, mobileMenu || document);
      if (!deferGwTips) hydrateGwTipsLabels();
    });
    mobileNav.querySelectorAll('a[href]').forEach((link) => {
      link.addEventListener('click', () => {
        if (!mobileMenu?.hidden) closeMobile();
      });
    });
  }
}
