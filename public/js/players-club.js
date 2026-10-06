import { initSiteNav } from './site-nav.js?v=20260907pub';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
} from './auth.js?v=20260907pub';
import { animate, stagger } from './motion.js?v=20260901perf';

function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function initFaceFallbacks() {
  if (window.__mfPchFaceBound) return;
  window.__mfPchFaceBound = true;

  document.addEventListener('error', (event) => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;
    if (!img.classList.contains('pp-roster-player__img--face')
      && !img.classList.contains('pp-roster-player__img--shirt')) {
      return;
    }

    const shirt = img.getAttribute('data-pp-shirt');
    if (img.classList.contains('pp-roster-player__img--face') && shirt) {
      img.classList.remove('pp-roster-player__img--face');
      img.classList.add('pp-roster-player__img--shirt');
      img.removeAttribute('data-pp-shirt');
      img.src = shirt;
      return;
    }

    const initial = img.getAttribute('data-pp-initial') || '?';
    const fallback = document.createElement('span');
    fallback.className = 'pp-roster-player__fallback';
    fallback.setAttribute('aria-hidden', 'true');
    fallback.textContent = initial;
    img.replaceWith(fallback);
  }, true);
}

function animateRoster() {
  if (prefersReducedMotion()) return;

  const items = [...document.querySelectorAll('[data-pp-roster-grid] .pp-roster-player')];
  if (!items.length) return;

  items.forEach((el) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(8px)';
  });

  animate(
    items,
    { opacity: 1, transform: 'translateY(0px)' },
    { duration: 0.28, ease: [0.22, 1, 0.36, 1], delay: stagger(0.03) }
  );
}

async function boot() {
  if (window.location.pathname.includes('/public/')) {
    window.location.replace(
      window.location.pathname.replace('/public/', '/')
      + window.location.search
      + window.location.hash
    );
    return;
  }

  initSiteNav();
  bindSignOutButtons();
  initFaceFallbacks();
  animateRoster();

  try {
    const session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }

  bindSignOutButtons();
}

boot();
