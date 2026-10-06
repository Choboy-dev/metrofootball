import { initSiteNav } from './site-nav.js?v=20260920toc';
import {
  applyAuthNav,
  bindAuthToolLinks,
  bindSignOutButtons,
  fetchSession,
} from './auth.js?v=20260907pub';
import { animate, stagger } from '/js/motion.js?v=20260901perf';

function reduceMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function initGuideShare() {
  const btn = document.querySelector('[data-fg-copy-link]');
  if (!btn) return;

  btn.addEventListener('click', async () => {
    const url = btn.getAttribute('data-url') || window.location.href;
    const copied = btn.querySelector('[data-fg-copied]');
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
      } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
      }
      if (copied) {
        copied.hidden = false;
        clearTimeout(btn._copyTimer);
        btn._copyTimer = setTimeout(() => {
          copied.hidden = true;
        }, 1600);
      }
    } catch {
      window.prompt('Copy this link', url);
    }
  });
}

function initAdjacentNav() {
  const cards = document.querySelectorAll('[data-fg-adjacent] .fg-adjacent__card:not(.fg-adjacent__card--empty)');
  if (!cards.length || reduceMotion()) return;
  animate(
    cards,
    { opacity: [0, 1], y: [14, 0] },
    { delay: stagger(0.08), duration: 0.35, easing: [0.22, 1, 0.36, 1] }
  );
}

async function init() {
  initSiteNav();
  initGuideShare();
  initAdjacentNav();

  let session = null;
  try {
    session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }

  bindSignOutButtons();
  bindAuthToolLinks(session);
}

init();
