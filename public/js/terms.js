import { animate, inView } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';
import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function syncHeaderHeight() {
  const header = document.querySelector('[data-header]');
  if (!header) return;
  document.documentElement.style.setProperty('--header-height', `${header.offsetHeight}px`);
}

function initStickyOffset() {
  syncHeaderHeight();
  window.addEventListener('resize', syncHeaderHeight, { passive: true });
  const header = document.querySelector('[data-header]');
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(syncHeaderHeight).observe(header);
  }
}

function bindToc() {
  const sections = [...document.querySelectorAll('[data-terms-section]')];
  const jumpLinks = [...document.querySelectorAll('[data-terms-jump]')];
  const tocLinks = [...document.querySelectorAll('[data-terms-toc]')];
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
      rootMargin: '-35% 0px -55% 0px',
      threshold: [0.1, 0.35, 0.6],
    }
  );

  sections.forEach((section) => observer.observe(section));
  setActive(sections[0].id);
}

function bindReveal() {
  const hero = document.querySelector('[data-terms-hero]');
  const cards = document.querySelectorAll('[data-terms-reveal]');
  if (reduceMotion) return;

  if (hero) {
    animate(hero, { opacity: [0, 1], y: [14, 0] }, { duration: 0.42, easing: [0.17, 0.55, 0.55, 1] });
  }

  cards.forEach((card) => {
    inView(
      card,
      () => {
        animate(
          card,
          { opacity: [0, 1], y: [12, 0] },
          { duration: 0.34, easing: [0.17, 0.55, 0.55, 1] }
        );
      },
      { amount: 0.2, once: true }
    );
  });
}

async function init() {
  initStickyOffset();
  initSiteNav();
  bindToc();
  bindReveal();

  try {
    const session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }

  bindSignOutButtons();
}

init();
