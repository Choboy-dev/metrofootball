import { initLinkTargets } from './link-targets.js?v=20260920toc';
import { apiUrl, bindAuthToolLinks, fetchSession, fetchWithTimeout } from './auth.js?v=20260907pub';
import { buildMobileMenuNav, initMobileAccordions } from './mobile-menu.js?v=20260907pub';
import { initStickyAsideOffset } from './sticky-aside.js?v=20260828h';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let motionPromise = null;

function loadAnimate() {
  if (!motionPromise) {
    motionPromise = import('./motion.js?v=20260901perf')
      .then((mod) => mod.animate)
      .catch(() => null);
  }
  return motionPromise;
}

function panelAnimate(target, keyframes, options) {
  if (reduceMotion || !target) return;
  loadAnimate().then((animate) => {
    if (typeof animate === 'function') {
      animate(target, keyframes, options);
    }
  });
}

export async function hydrateGwTipsLabels() {
  const labels = document.querySelectorAll('[data-nav-gw-tips-label]');
  const links = document.querySelectorAll('[data-nav-gw-tips-link]');
  if (!labels.length && !links.length) return;

  try {
    const res = await fetchWithTimeout(apiUrl('fpl/gameweek-tips?meta=1'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }, 8000);
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

  // Free tools must never keep leftover auth gates from old markup/cache.
  document.querySelectorAll('a[href*="fixture-difficulty"], [data-auth-tool-href*="fixture-difficulty"]').forEach((node) => {
    node.removeAttribute('data-auth-tool');
    if (node.hasAttribute('data-auth-tool-href')) {
      const href = node.getAttribute('data-auth-tool-href');
      node.removeAttribute('data-auth-tool-href');
      if (node instanceof HTMLAnchorElement && href && !node.getAttribute('href')) {
        node.setAttribute('href', href);
      }
    }
  });

  initLinkTargets();
  if (!deferGwTips) {
    hydrateGwTipsLabels();
  } else if (typeof options.deferGwTips !== 'boolean') {
    // Pages that defer by markup have no page script that schedules it later.
    scheduleGwTipsHydration();
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
        panelAnimate(panel, { opacity: [0, 1], y: [6, 0] }, { duration: 0.18, easing: [0.17, 0.55, 0.55, 1] });
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
      panelAnimate(
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
    loadAnimate().then((animate) => {
      if (typeof animate !== 'function') {
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

  initStickyAsideOffset();
}
