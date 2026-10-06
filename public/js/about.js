import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindAuthToolLinks,
  bindSignOutButtons,
  fetchSession,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const CACHE = '20260829b';

let motionPromise = null;

function loadMotion() {
  if (!motionPromise) {
    motionPromise = import('./motion.js?v=20260901perf')
      .then((mod) => mod)
      .catch(() => null);
  }
  return motionPromise;
}

function syncHeaderHeight() {
  const header = document.querySelector('[data-header]');
  if (!header) return;
  document.documentElement.style.setProperty('--header-height', `${header.offsetHeight}px`);
}

function bindToc() {
  const sections = [...document.querySelectorAll('[data-about-section]')];
  const jumpLinks = [...document.querySelectorAll('[data-about-jump]')];
  const tocLinks = [...document.querySelectorAll('[data-about-toc]')];
  const allLinks = [...jumpLinks, ...tocLinks];
  if (!sections.length || !allLinks.length) return;

  const setActive = (id) => {
    allLinks.forEach((link) => {
      const match = link.getAttribute('href') === `#${id}`;
      link.classList.toggle('is-active', match);
      if (match) {
        link.setAttribute('aria-current', 'true');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  };

  const observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
      if (!visible.length) return;
      setActive(visible[0].target.id);
    },
    {
      root: null,
      rootMargin: '-32% 0px -55% 0px',
      threshold: [0.12, 0.35, 0.6],
    }
  );

  sections.forEach((section) => observer.observe(section));
  setActive(sections[0].id);
}

async function bindReveal() {
  if (reduceMotion) {
    document.body.classList.add('prefers-static');
    return;
  }

  const motion = await loadMotion();
  if (!motion?.animate || !motion?.inView) {
    document.body.classList.add('prefers-static');
    return;
  }

  const { animate, inView } = motion;
  const hero = document.querySelector('[data-about-hero]');
  if (hero) {
    animate(hero, { opacity: [0, 1], y: [14, 0] }, { duration: 0.42, easing: [0.17, 0.55, 0.55, 1] });
  }

  document.querySelectorAll('[data-about-reveal]').forEach((el) => {
    inView(
      el,
      () => {
        animate(
          el,
          { opacity: [0, 1], y: [12, 0] },
          { duration: 0.34, easing: [0.17, 0.55, 0.55, 1] }
        );
      },
      { amount: 0.18, once: true }
    );
  });
}

async function init() {
  syncHeaderHeight();
  window.addEventListener('resize', syncHeaderHeight, { passive: true });
  const header = document.querySelector('[data-header]');
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(syncHeaderHeight).observe(header);
  }

  initSiteNav();
  bindToc();
  bindReveal();

  let session = null;
  try {
    session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }

  bindAuthToolLinks(() => session);
  bindSignOutButtons();
}

init();
